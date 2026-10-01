<?php
/**
 * Ratesight CRM posts: per-site key, signed settings route and auto-publish log.
 *
 * Since 3.15.2. The Ratesight CRM publisher does not sign its requests. 3.15.1 let an
 * unsigned POST /create-page from the CRM's connecting address follow the Final Post
 * Status when the site turned on "Ratesight CRM posts". 3.15.2 also requires a per-site
 * key that only this site and its CRM webhook know, sent as the `rs_crm_key` query
 * parameter of the webhook URL (or the X-Ratesight-CRM-Key header). A request needs
 * BOTH the address and the key to publish; with either missing it is a new draft, and
 * the key is never accepted from any other address.
 *
 * Endpoint (namespace ratesight/v1):
 *   GET  /crm-publish  signed read: switch state, key fingerprint, limits, recent auto-publishes
 *   POST /crm-publish  signed mutation: { enabled?: bool, require_key?: bool, rotate_key?: bool, dry_run?: bool }
 *                      Returns the CRM webhook URL (with the key) while the switch is on.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_CRM_Publish {

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/crm-publish';
	const KEY_OPTION      = 'ratesight_crm_publisher_key';
	const LOG_OPTION      = 'ratesight_crm_publish_log';
	const LOG_MAX         = 100;
	const QUERY_PARAM     = 'rs_crm_key';
	const HEADER          = 'x_ratesight_crm_key';

	public static function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_read' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_post' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
			),
		) );
	}

	/** The stored key, or '' when none was ever made. */
	public static function key(): string {
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) && preg_match( '/^[A-Za-z0-9_-]{32,64}$/', $key ) ? $key : '';
	}

	/** Make a key when the site has none (or rotate it). Returns the current key. */
	public static function ensure_key( bool $rotate = false ): string {
		$key = self::key();
		if ( $key === '' || $rotate ) {
			$key = rtrim( strtr( base64_encode( random_bytes( 30 ) ), '+/', '-_' ), '=' );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/** Short public fingerprint of the key, safe to show and log. */
	public static function key_fingerprint( string $key = '' ): ?string {
		$key = $key !== '' ? $key : self::key();
		return $key !== '' ? substr( hash( 'sha256', 'rs-crm-key:' . $key ), 0, 12 ) : null;
	}

	/**
	 * The key the request carries: the rs_crm_key query parameter, else the
	 * X-Ratesight-CRM-Key header. Body fields are never read.
	 */
	public static function provided_key( $request ): string {
		$query = method_exists( $request, 'get_query_params' ) ? (array) $request->get_query_params() : array();
		$value = $query[ self::QUERY_PARAM ] ?? '';
		if ( ! is_string( $value ) || $value === '' ) {
			$value = (string) $request->get_header( self::HEADER );
		}
		return is_string( $value ) ? trim( $value ) : '';
	}

	/** 'valid', 'missing' or 'invalid'. Constant-time compare. */
	public static function key_state( $request ): string {
		$provided = self::provided_key( $request );
		if ( $provided === '' ) {
			return 'missing';
		}
		$expected = self::key();
		if ( $expected === '' ) {
			return 'invalid';
		}
		return hash_equals( hash( 'sha256', $expected ), hash( 'sha256', $provided ) ) ? 'valid' : 'invalid';
	}

	/** The webhook URL to configure in the CRM for this site. */
	public static function webhook_url( string $key ): string {
		$base = rest_url( 'ratesight/v1/create-page' );
		return $base . ( strpos( $base, '?' ) === false ? '?' : '&' ) . self::QUERY_PARAM . '=' . rawurlencode( $key );
	}

	/** One row per auto-published CRM post (and per post held back by the daily limit). */
	public static function record( string $event, string $request_id, int $post_id, string $slug, string $post_type, string $status ): void {
		$rows   = get_option( self::LOG_OPTION, array() );
		$rows   = is_array( $rows ) ? $rows : array();
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$rows[] = array(
			'time'        => gmdate( 'c' ),
			'event'       => $event,
			'source'      => 'ratesight_crm',
			'ip'          => filter_var( $remote, FILTER_VALIDATE_IP ) !== false ? $remote : null,
			'request_id'  => $request_id,
			'post_id'     => $post_id,
			'slug'        => $slug,
			'post_type'   => $post_type,
			'status'      => $status,
			'key_id'      => self::key_fingerprint(),
		);
		update_option( self::LOG_OPTION, array_slice( $rows, -self::LOG_MAX ), false );
	}

	public static function status(): array {
		$rows = get_option( self::LOG_OPTION, array() );
		return array(
			'enabled'          => Ratesight_Request_Auth::trusted_publisher_enabled(),
			'effective'        => Ratesight_Request_Auth::mode() !== 'enforce_v2' && Ratesight_Request_Auth::trusted_publisher_enabled() && ( ! Ratesight_Request_Auth::trusted_publisher_key_required() || self::key() !== '' ),
			'key_required'     => Ratesight_Request_Auth::trusted_publisher_key_required(),
			'key_configured'   => self::key() !== '',
			'key_id'           => self::key_fingerprint(),
			'key_transport'    => array( 'query' => self::QUERY_PARAM, 'header' => 'X-Ratesight-CRM-Key' ),
			'addresses'        => Ratesight_Request_Auth::TRUSTED_PUBLISHER_ADDRESSES,
			'max'              => Ratesight_Request_Auth::TRUSTED_PUBLISHER_LIMIT,
			'window_seconds'   => Ratesight_Request_Auth::TRUSTED_PUBLISHER_WINDOW,
			'over_limit'       => 'draft',
			'recent'           => is_array( $rows ) ? array_values( array_slice( $rows, -25 ) ) : array(),
		);
	}

	public static function handle_get( $request ) {
		return new \WP_REST_Response( array( 'ok' => true ) + self::status(), 200 );
	}

	public static function handle_post( $request ) {
		$data    = $request->get_json_params() ?: $request->get_body_params();
		$data    = is_array( $data ) ? $data : array();
		$dry_run = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
		$rotate  = filter_var( $data['rotate_key'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
		$enabled = null;
		if ( array_key_exists( 'enabled', $data ) ) {
			$enabled = filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( $enabled === null ) {
				return new \WP_REST_Response( array( 'ok' => false, 'code' => 'rs_crm_publish_enabled_invalid', 'message' => '"enabled" must be true or false.' ), 422 );
			}
		}
		$require_key = null;
		if ( array_key_exists( 'require_key', $data ) ) {
			$require_key = filter_var( $data['require_key'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( $require_key === null ) {
				return new \WP_REST_Response( array( 'ok' => false, 'code' => 'rs_crm_publish_require_key_invalid', 'message' => '"require_key" must be true or false.' ), 422 );
			}
		}
		$before = self::status();
		unset( $before['recent'] );
		if ( $dry_run ) {
			return new \WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'before' => $before, 'would' => array( 'enabled' => $enabled ?? $before['enabled'], 'require_key' => $require_key ?? $before['key_required'], 'rotate_key' => $rotate || ( ( $enabled ?? $before['enabled'] ) && ( $require_key ?? $before['key_required'] ) && ! $before['key_configured'] ) ) ), 200 );
		}
		if ( $enabled !== null ) {
			// The stored option is the opt-out (3.15.4): enabled:false holds CRM posts as drafts.
			update_option( Ratesight_Request_Auth::TRUSTED_PUBLISHER_SETTING, $enabled ? 0 : 1 );
		}
		if ( $require_key !== null ) {
			update_option( Ratesight_Request_Auth::TRUSTED_PUBLISHER_KEY_REQUIRED, $require_key ? 1 : 0 );
		}
		$on  = Ratesight_Request_Auth::trusted_publisher_enabled();
		// A key is made only when this site requires one (or on rotate_key); the default needs none.
		$key = ( $rotate || ( $on && Ratesight_Request_Auth::trusted_publisher_key_required() ) ) ? self::ensure_key( $rotate ) : self::key();
		$after = self::status();
		unset( $after['recent'] );
		$response = array( 'ok' => true, 'dry_run' => false, 'before' => $before, 'after' => $after );
		if ( $on && $key !== '' ) {
			$response['crm_webhook_url'] = self::webhook_url( $key );
		}
		return new \WP_REST_Response( $response, 200 );
	}
}
