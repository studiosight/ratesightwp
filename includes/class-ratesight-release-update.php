<?php
/**
 * Signed release manifest validation and managed-update preflight.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Release_Update {
	public const CONTRACT = 'ratesight-plugin-release-v1';
	public const MAX_MANIFEST_BYTES = 4096;

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/plugin-update-preflight', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'preflight' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );
	}

	public static function preflight( \WP_REST_Request $request ) {
		$params        = $request->get_json_params();
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

		$version       = (string) ( $manifest['version'] ?? '' );
		$sha256       = (string) ( $manifest['sha256'] ?? '' );
		$asset_url    = (string) ( $manifest['assetUrl'] ?? '' );
		$min_php      = (string) ( $manifest['minPhp'] ?? '' );
		$min_wordpress = (string) ( $manifest['minWordPress'] ?? '' );
		$expected_url = "https://github.com/studiosight/ratesightwp/releases/download/v{$version}/ratesight-{$version}.zip";
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $version ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $min_php ) || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $min_wordpress ) || ! hash_equals( $expected_url, $asset_url ) ) {
			return self::error( 'rs_release_manifest_invalid', 400 );
		}

		$current = defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0';
		$wp_version = (string) get_bloginfo( 'version' );
		$reasons = array();
		if ( version_compare( $version, $current, '<=' ) ) $reasons[] = 'target_not_newer';
		if ( version_compare( PHP_VERSION, $min_php, '<' ) ) $reasons[] = 'php_too_old';
		if ( version_compare( $wp_version, $min_wordpress, '<' ) || version_compare( $wp_version, '6.3', '<' ) ) $reasons[] = 'wordpress_rollback_unavailable';
		return new \WP_REST_Response( array(
			'ok'             => true,
			'contract'       => self::CONTRACT,
			'eligible'       => $reasons === array(),
			'currentVersion' => $current,
			'targetVersion'  => $version,
			'blockedBy'      => $reasons,
			'applySupported' => false,
		), 200 );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Ratesight release preflight failed.', array( 'status' => $status ) );
	}
}
