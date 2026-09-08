<?php
/**
 * Signed, dashboard-managed plugin updates.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Release_Update {
	public const CONTRACT            = 'ratesight-plugin-release-v1';
	public const MAX_MANIFEST_BYTES  = 4096;
	public const MAX_PACKAGE_BYTES   = 26214400;
	public const MAX_EXTRACTED_BYTES = 67108864;
	public const MAX_PACKAGE_ENTRIES = 500;
	public const PLUGIN_BASENAME     = 'ratesight/ratesight.php';

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/plugin-update', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'handle' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );
	}

	public static function handle( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$action = is_array( $params ) ? (string) ( $params['action'] ?? '' ) : '';
		if ( ! in_array( $action, array( 'preflight', 'apply' ), true ) ) {
			return self::error( 'rs_release_action_invalid', 400 );
		}

		$manifest = self::validated_manifest( $params );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$preflight = self::preflight_result( $manifest );
		if ( $action === 'preflight' ) {
			return new \WP_REST_Response( $preflight, 200 );
		}
		if ( ( $params['confirm'] ?? null ) !== true ) {
			return self::error( 'rs_release_confirmation_required', 409 );
		}
		if ( ! $preflight['eligible'] ) {
			return new \WP_REST_Response( $preflight, 409 );
		}

		return self::apply_release( $manifest, $preflight );
	}

	private static function validated_manifest( $params ) {
		$manifest_json = is_array( $params ) ? (string) ( $params['manifestJson'] ?? '' ) : '';
		$signature     = is_array( $params ) ? (string) ( $params['signature'] ?? '' ) : '';
		if ( $manifest_json === '' || strlen( $manifest_json ) > self::MAX_MANIFEST_BYTES || ! preg_match( '#^[A-Za-z0-9+/]{64,128}={0,2}$#', $signature ) ) {
			return self::error( 'rs_release_manifest_invalid', 400 );
		}
		if ( ! Ratesight_Pairing::verify_control_plane_signature( $manifest_json, $signature ) ) {
			return self::error( 'rs_release_signature_invalid', 403 );
		}

		$manifest = json_decode( $manifest_json, true );
		if ( ! is_array( $manifest ) || ( $manifest['contract'] ?? '' ) !== self::CONTRACT ) {
			return self::error( 'rs_release_manifest_invalid', 400 );
		}

		$version             = (string) ( $manifest['version'] ?? '' );
		$sha256             = (string) ( $manifest['sha256'] ?? '' );
		$asset_url          = (string) ( $manifest['assetUrl'] ?? '' );
		$min_php            = (string) ( $manifest['minPhp'] ?? '' );
		$min_wordpress      = (string) ( $manifest['minWordPress'] ?? '' );
		$min_managed_version = (string) ( $manifest['minManagedVersion'] ?? '' );
		$version_pattern    = '/^[0-9]+\.[0-9]+\.[0-9]+$/';
		$asset_pattern      = '#^https://github\.com/studiosight/ratesightwp/releases/download/v' . preg_quote( $version, '#' ) . '/ratesight-' . preg_quote( $version, '#' ) . '-[a-f0-9]{7,40}\.zip$#';
		if ( ! preg_match( $version_pattern, $version ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $min_php ) || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $min_wordpress ) || ! preg_match( $version_pattern, $min_managed_version ) || ! preg_match( $asset_pattern, $asset_url ) ) {
			return self::error( 'rs_release_manifest_invalid', 400 );
		}

		return array(
			'version'           => $version,
			'sha256'           => $sha256,
			'assetUrl'          => $asset_url,
			'minPhp'            => $min_php,
			'minWordPress'      => $min_wordpress,
			'minManagedVersion' => $min_managed_version,
			'manifestDigest'    => hash( 'sha256', $manifest_json ),
		);
	}

	private static function preflight_result( array $manifest ): array {
		$current    = defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0';
		$wp_version = (string) get_bloginfo( 'version' );
		$reasons    = array();
		if ( ! Ratesight_Pairing::is_connected() ) $reasons[] = 'dashboard_pairing_required';
		if ( version_compare( $manifest['version'], $current, '<=' ) ) $reasons[] = 'target_not_newer';
		if ( version_compare( $current, $manifest['minManagedVersion'], '<' ) ) $reasons[] = 'managed_bridge_too_old';
		if ( version_compare( PHP_VERSION, $manifest['minPhp'], '<' ) ) $reasons[] = 'php_too_old';
		if ( version_compare( $wp_version, $manifest['minWordPress'], '<' ) || version_compare( $wp_version, '6.3', '<' ) ) $reasons[] = 'wordpress_rollback_unavailable';
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'automatic_updater' ) ) $reasons[] = 'file_modifications_disabled';
		if ( ! defined( 'RATESIGHT_PLUGIN_DIR' ) || basename( untrailingslashit( RATESIGHT_PLUGIN_DIR ) ) !== 'ratesight' ) $reasons[] = 'noncanonical_plugin_directory';
		return array(
			'ok'               => true,
			'contract'         => self::CONTRACT,
			'eligible'         => $reasons === array(),
			'currentVersion'   => $current,
			'targetVersion'    => $manifest['version'],
			'blockedBy'        => $reasons,
			'applySupported'   => true,
			'rollbackSupported' => version_compare( $wp_version, '6.3', '>=' ),
		);
	}

	private static function apply_release( array $manifest, array $preflight ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

		if ( ! \WP_Upgrader::create_lock( 'ratesight_plugin_update', 300 ) ) {
			return self::error( 'rs_release_update_locked', 409 );
		}

		$package = null;
		try {
			$package = download_url( $manifest['assetUrl'], 60 );
			if ( is_wp_error( $package ) || ! is_string( $package ) ) {
				return self::error( 'rs_release_download_failed', 502 );
			}
			$size = filesize( $package );
			if ( false === $size || $size < 1 || $size > self::MAX_PACKAGE_BYTES || ! hash_equals( $manifest['sha256'], (string) hash_file( 'sha256', $package ) ) ) {
				return self::error( 'rs_release_package_integrity_failed', 422 );
			}

			$inspection = self::inspect_package( $package, $manifest['version'] );
			if ( is_wp_error( $inspection ) ) {
				return $inspection;
			}

			$offer = (object) array(
				'id'          => 'ratesight-managed-' . $manifest['version'],
				'slug'        => 'ratesight',
				'plugin'      => self::PLUGIN_BASENAME,
				'new_version' => $manifest['version'],
				'package'     => $package,
				'url'         => 'https://github.com/studiosight/ratesightwp',
			);
			$updates_before = get_site_transient( 'update_plugins' );
			$updates        = is_object( $updates_before ) ? clone $updates_before : new \stdClass();
			$updates->response = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
			$updates->response[ self::PLUGIN_BASENAME ] = $offer;
			set_site_transient( 'update_plugins', $updates );
			$allow = static function ( $allowed, $item ) use ( $offer ) {
				return is_object( $item ) && ( $item->id ?? '' ) === $offer->id ? true : $allowed;
			};
			add_filter( 'auto_update_plugin', $allow, PHP_INT_MAX, 2 );
			try {
				$result = ( new \WP_Automatic_Updater() )->update( 'plugin', $offer );
			} finally {
				remove_filter( 'auto_update_plugin', $allow, PHP_INT_MAX );
				if ( false === $updates_before ) delete_site_transient( 'update_plugins' );
				else set_site_transient( 'update_plugins', $updates_before );
			}
			if ( is_wp_error( $result ) || true !== $result ) {
				return self::error( 'rs_release_update_failed', 500 );
			}

			$installed = get_plugin_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_BASENAME, false, false );
			if ( (string) ( $installed['Version'] ?? '' ) !== $manifest['version'] ) {
				return self::error( 'rs_release_post_update_version_mismatch', 500 );
			}
			update_option( 'ratesight_plugin_update_receipt', array(
				'from_version'   => $preflight['currentVersion'],
				'to_version'     => $manifest['version'],
				'manifest_digest' => $manifest['manifestDigest'],
				'applied_at'     => time(),
			), false );
			return new \WP_REST_Response( array(
				'ok'               => true,
				'contract'         => self::CONTRACT,
				'applied'          => true,
				'fromVersion'      => $preflight['currentVersion'],
				'targetVersion'    => $manifest['version'],
				'verifiedOnDisk'   => true,
				'readbackRequired' => true,
			), 200 );
		} finally {
			if ( is_string( $package ) && file_exists( $package ) ) wp_delete_file( $package );
			\WP_Upgrader::release_lock( 'ratesight_plugin_update' );
		}
	}

	private static function inspect_package( string $package, string $version ) {
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return self::error( 'rs_release_filesystem_unavailable', 503 );
		}
		$directory = trailingslashit( get_temp_dir() ) . 'ratesight-inspect-' . wp_generate_uuid4();
		if ( ! wp_mkdir_p( $directory ) ) {
			return self::error( 'rs_release_inspection_failed', 500 );
		}
		try {
			$result = unzip_file( $package, $directory );
			if ( is_wp_error( $result ) ) return self::error( 'rs_release_archive_invalid', 422 );
			return self::validate_extracted_package( $directory, $version );
		} finally {
			$wp_filesystem->delete( $directory, true );
		}
	}

	public static function validate_extracted_package( string $directory, string $version ) {
		$roots = array_values( array_diff( scandir( $directory ) ?: array(), array( '.', '..' ) ) );
		if ( $roots !== array( 'ratesight' ) || ! is_dir( $directory . '/ratesight' ) || is_link( $directory . '/ratesight' ) ) {
			return self::error( 'rs_release_archive_layout_invalid', 422 );
		}

		$count = 0;
		$bytes = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory . '/ratesight', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			$count++;
			if ( $count > self::MAX_PACKAGE_ENTRIES || $entry->isLink() ) return self::error( 'rs_release_archive_layout_invalid', 422 );
			if ( $entry->isFile() ) {
				$bytes += $entry->getSize();
				if ( $bytes > self::MAX_EXTRACTED_BYTES ) return self::error( 'rs_release_archive_too_large', 422 );
			}
		}

		$main = $directory . '/' . self::PLUGIN_BASENAME;
		if ( ! is_file( $main ) || is_link( $main ) ) return self::error( 'rs_release_archive_layout_invalid', 422 );
		$data = get_plugin_data( $main, false, false );
		if ( (string) ( $data['Name'] ?? '' ) !== 'Ratesight' || (string) ( $data['Version'] ?? '' ) !== $version ) {
			return self::error( 'rs_release_embedded_version_mismatch', 422 );
		}
		return true;
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Ratesight managed plugin update failed.', array( 'status' => $status ) );
	}
}
