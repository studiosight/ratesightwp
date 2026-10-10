<?php
/** Private core-version evidence; never changes public version masking. */
defined( 'ABSPATH' ) || die;

class Ratesight_WordPress_Compatibility {
	public const CONTRACT = 'ratesight-wordpress-compatibility-v1';

	public static function core_version(): ?string {
		$file = ABSPATH . ( defined( 'WPINC' ) ? WPINC : 'wp-includes' ) . '/version.php';
		if ( ! is_readable( $file ) ) return null;
		try {
			if ( function_exists( 'wp_get_wp_version' ) ) {
				$version = wp_get_wp_version();
			} else {
				// WordPress <6.7: load core's version in LOCAL scope, not the masked global.
				$wp_version = null;
				require $file;
				$version = $wp_version;
			}
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_string( $version ) || ! preg_match( '/^([0-9]+)\.[0-9]+(?:\.[0-9]+)?(?:-[A-Za-z0-9.-]+)?$/D', $version, $matches ) ) return null;
		if ( (int) $matches[1] < 3 || (int) $matches[1] > 30 ) return null;
		return $version;
	}

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/wordpress-compatibility', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( self::class, 'handle' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_read' ),
		) );
	}

	public static function handle( \WP_REST_Request $request ) {
		if ( ! Ratesight_Pairing::is_connected() ) {
			return new \WP_Error( 'rs_dashboard_pairing_required', 'Dashboard pairing is required.', array( 'status' => 403 ) );
		}
		return new \WP_REST_Response( array(
			'contract' => self::CONTRACT,
			'oid' => (string) Ratesight_Options::get( 'code_id' ),
			'pluginVersion' => defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : null,
			'wordpressVersion' => self::core_version(),
			'source' => 'wordpress_core',
		), 200 );
	}
}
