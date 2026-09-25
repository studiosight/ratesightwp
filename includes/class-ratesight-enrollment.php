<?php
/**
 * Plugin-initiated dashboard enrollment.
 *
 * Two reports share one public dashboard endpoint:
 *  - enrollment (ratesight-wordpress-enrollment-v1) when a Ratesight client id is set;
 *  - installation (ratesight-wordpress-installation-v1) when none is set yet, so the
 *    operator can see the site and connect it to a client from the dashboard.
 *
 * Neither report carries a secret. The dashboard proves ownership of the
 * installation key through /enrollment-challenge before it trusts either one.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Enrollment {
	public const CONTRACT              = 'ratesight-wordpress-enrollment-v1';
	public const CHALLENGE_CONTRACT    = 'ratesight-wordpress-enrollment-challenge-v1';
	public const INSTALLATION_CONTRACT = 'ratesight-wordpress-installation-v1';
	public const CLAIM_CONTRACT        = 'ratesight-wordpress-claim-v1';
	public const RETRY_HOOK            = 'ratesight_enrollment_retry';
	public const DAILY_HOOK            = 'ratesight_installation_report';
	public const MAX_BODY_BYTES        = 4096;
	public const MAX_CLOCK_SKEW        = 300;

	private const DASHBOARD_ENDPOINT          = 'https://dash.ratesight.com/api/public/wordpress/enrollments';
	private const INSTALLATION_OPTION         = 'ratesight_enrollment_installation_id';
	private const PRIVATE_KEY_OPTION          = 'ratesight_enrollment_private_key';
	private const RECEIPT_OPTION              = 'ratesight_enrollment_receipt';
	private const INSTALLATION_RECEIPT_OPTION = 'ratesight_installation_report_receipt';
	private const CLAIM_RECEIPT_OPTION        = 'ratesight_claim_receipt';
	private const UUID_PATTERN                = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/enrollment-challenge', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'handle_challenge' ),
			'permission_callback' => '__return_true',
		) );
		// Authenticated by a pinned control-plane signature over the body, exactly like /pair.
		register_rest_route( 'ratesight/v1', '/claim', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'handle_claim' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Keep the daily installation report scheduled. Safe to call on every load.
	 */
	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::DAILY_HOOK );
		}
	}

	public static function unschedule(): void {
		foreach ( array( self::DAILY_HOOK, self::RETRY_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	public static function handle_challenge( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( $body === '' || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'rs_enrollment_challenge_body_invalid', 400 );
		}

		$payload       = json_decode( $body, true );
		$payload_keys  = is_array( $payload ) ? array_keys( $payload ) : array();
		$expected_keys = array( 'challenge', 'contract', 'installationId', 'requestId' );
		sort( $payload_keys );
		if ( ! is_array( $payload ) || $payload_keys !== $expected_keys ) {
			return self::error( 'rs_enrollment_challenge_shape_invalid', 400 );
		}

		$identity = self::identity();
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$request_id      = (string) $payload['requestId'];
		$installation_id = (string) $payload['installationId'];
		$challenge       = (string) $payload['challenge'];
		if ( self::CHALLENGE_CONTRACT !== $payload['contract']
			|| ! preg_match( '/^wpenr:v1:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_id )
			|| ! hash_equals( $identity['installationId'], $installation_id )
			|| ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			return self::error( 'rs_enrollment_challenge_invalid', 403 );
		}

		$message   = implode( "\n", array( self::CHALLENGE_CONTRACT, $request_id, $installation_id, $challenge ) );
		$signature = '';
		if ( ! openssl_sign( $message, $signature, $identity['privateKey'], OPENSSL_ALGO_SHA256 ) ) {
			return self::error( 'rs_enrollment_challenge_signing_failed', 500 );
		}

		return new \WP_REST_Response( array(
			'contract'       => self::CHALLENGE_CONTRACT,
			'requestId'      => $request_id,
			'installationId' => $installation_id,
			'challenge'      => $challenge,
			'signature'      => self::base64url_encode( $signature ),
		), 200 );
	}

	/**
	 * Dashboard-initiated claim: binds this installation to a Ratesight client id.
	 *
	 * The body is signed by a pinned control-plane key (same verification as /pair).
	 * It must name this installation and this site, be fresh, and never be replayed.
	 * A different existing client id is only replaced when the signed body says
	 * force=true, and never on a site that is already paired.
	 */
	public static function handle_claim( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( $body === '' || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'rs_claim_body_invalid', 400 );
		}

		$signature = (string) $request->get_header( 'x_ratesight_pairing_signature' );
		if ( ! preg_match( '#^[A-Za-z0-9+/]{64,128}={0,2}$#', $signature ) || ! Ratesight_Pairing::verify_control_plane_signature( $body, $signature ) ) {
			return self::error( 'rs_claim_signature_invalid', 403 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || ( $payload['contract'] ?? '' ) !== self::CLAIM_CONTRACT ) {
			return self::error( 'rs_claim_contract_invalid', 400 );
		}

		$oid             = trim( (string) ( $payload['oid'] ?? '' ) );
		$installation_id = (string) ( $payload['installationId'] ?? '' );
		$site            = self::normalize_origin( (string) ( $payload['site'] ?? '' ) );
		$issued_at       = filter_var( $payload['issuedAt'] ?? null, FILTER_VALIDATE_INT );
		$expires_at      = filter_var( $payload['expiresAt'] ?? null, FILTER_VALIDATE_INT );
		$nonce           = (string) ( $payload['nonce'] ?? '' );
		$force           = array_key_exists( 'force', $payload ) ? $payload['force'] : false;

		if ( ! preg_match( '/^[0-9]{1,20}$/', $oid ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $nonce ) || ! is_bool( $force ) || ! preg_match( self::UUID_PATTERN, $installation_id ) ) {
			return self::error( 'rs_claim_payload_invalid', 400 );
		}

		$local_installation = (string) get_option( self::INSTALLATION_OPTION, '' );
		if ( $local_installation === '' || ! hash_equals( $local_installation, $installation_id ) ) {
			return self::error( 'rs_claim_installation_mismatch', 409 );
		}

		$local_site = self::site_origin();
		if ( null === $site || null === $local_site || ! hash_equals( $local_site, $site ) ) {
			return self::error( 'rs_claim_site_mismatch', 409 );
		}

		$now = time();
		if ( false === $issued_at || false === $expires_at || $issued_at > $now + self::MAX_CLOCK_SKEW || $issued_at < $now - self::MAX_CLOCK_SKEW || $expires_at < $now || $expires_at > $issued_at + self::MAX_CLOCK_SKEW ) {
			return self::error( 'rs_claim_expired', 403 );
		}

		$stored_oid = trim( (string) Ratesight_Options::get( 'code_id' ) );
		$changing   = $stored_oid !== '' && ! hash_equals( $stored_oid, $oid );
		if ( $changing && Ratesight_Pairing::is_connected() ) {
			return self::error( 'rs_claim_site_paired', 409 );
		}
		if ( $changing && true !== $force ) {
			return self::error( 'rs_claim_oid_conflict', 409 );
		}

		// Shares the pairing nonce prefix so the existing hourly prune covers it.
		$nonce_option = 'ratesight_pairing_nonce_' . hash( 'sha256', 'claim:' . $nonce );
		if ( ! add_option( $nonce_option, $expires_at, '', false ) ) {
			return self::error( 'rs_claim_replayed', 409 );
		}

		$already = $stored_oid !== '' && ! $changing;
		if ( ! $already ) {
			update_option( Ratesight_Options::option_name( 'code_id' ), $oid, false );
			if ( ! hash_equals( $oid, trim( (string) Ratesight_Options::get( 'code_id' ) ) ) ) {
				return self::error( 'rs_claim_identity_store_failed', 500 );
			}
		}

		update_option( self::CLAIM_RECEIPT_OPTION, array(
			'oid'        => $oid,
			'claimed_at' => $now,
			'forced'     => $changing,
			'nonce_hash' => substr( hash( 'sha256', $nonce ), 0, 20 ),
		), false );

		// Send the enrollment as soon as this response is out of the way.
		delete_option( self::RECEIPT_OPTION );
		self::enroll_soon();

		return new \WP_REST_Response( array(
			'ok'             => true,
			'contract'       => self::CLAIM_CONTRACT,
			'code'           => $already ? 'wordpress_claim_unchanged' : ( $changing ? 'wordpress_claim_replaced' : 'wordpress_claim_recorded' ),
			'installationId' => $installation_id,
			'oid'            => $oid,
			'enrollment'     => 'scheduled',
		), 200 );
	}

	public static function send(): void {
		if ( Ratesight_Pairing::is_connected() ) {
			return;
		}

		$oid = trim( (string) Ratesight_Options::get( 'code_id' ) );
		if ( ! preg_match( '/^[0-9]{1,20}$/', $oid ) ) {
			self::report_installation();
			return;
		}

		$site_origin = self::site_origin();
		$identity    = self::identity();
		if ( null === $site_origin || is_wp_error( $identity ) ) {
			self::store_receipt( $oid, $site_origin, 'identity_unavailable', 0, false );
			self::schedule_retry();
			return;
		}

		$fingerprint = self::fingerprint( $oid, $site_origin );
		$receipt     = get_option( self::RECEIPT_OPTION, array() );
		if ( is_array( $receipt ) && true === ( $receipt['accepted'] ?? false ) && hash_equals( (string) ( $receipt['fingerprint'] ?? '' ), $fingerprint ) ) {
			return;
		}

		$payload = array(
			'contract'       => self::CONTRACT,
			'requestId'      => 'wpenr:v1:' . wp_generate_uuid4(),
			'oid'            => $oid,
			'siteOrigin'     => $site_origin,
			'pluginVersion'  => self::plugin_version(),
			'installationId' => $identity['installationId'],
			'publicKeySpki'  => $identity['publicKeySpki'],
			'issuedAt'       => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'nonce'          => self::base64url_encode( random_bytes( 16 ) ),
			'capabilities'   => array(
				'connectionStatus' => true,
				'pairing'          => true,
				'managedUpdates'   => true,
			),
		);

		$result   = self::post_to_dashboard( $payload );
		$accepted = $result['status'] >= 200 && $result['status'] < 300 && in_array( $result['code'], array( 'wordpress_enrollment_pending_approval', 'wordpress_enrollment_duplicate' ), true );
		self::store_receipt( $oid, $site_origin, $result['code'], $result['status'], $accepted, $fingerprint );
		if ( ! $accepted ) {
			self::schedule_retry();
		}
	}

	/**
	 * Unclaimed installation report: lets the dashboard list this site so an
	 * operator can connect it to a client. Sent on activation and daily.
	 */
	private static function report_installation(): void {
		$site_origin = self::site_origin();
		$identity    = self::identity();
		if ( null === $site_origin || is_wp_error( $identity ) ) {
			self::store_installation_receipt( 'identity_unavailable', 0, false );
			return;
		}

		$payload = array(
			'contract'       => self::INSTALLATION_CONTRACT,
			'requestId'      => 'wpenr:v1:' . wp_generate_uuid4(),
			'siteOrigin'     => $site_origin,
			'siteName'       => self::site_name(),
			'pluginVersion'  => self::plugin_version(),
			'installationId' => $identity['installationId'],
			'publicKeySpki'  => $identity['publicKeySpki'],
			'pairingState'   => Ratesight_Pairing::is_connected() ? 'paired' : 'unpaired',
			'issuedAt'       => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'nonce'          => self::base64url_encode( random_bytes( 16 ) ),
		);

		$result   = self::post_to_dashboard( $payload );
		$accepted = $result['status'] >= 200 && $result['status'] < 300 && 'wordpress_installation_recorded' === $result['code'];
		self::store_installation_receipt( $result['code'], $result['status'], $accepted );
		if ( ! $accepted ) {
			// One retry an hour later; the daily report covers anything longer.
			self::schedule_retry( HOUR_IN_SECONDS );
		}
	}

	/**
	 * @return array{code: string, status: int}
	 */
	private static function post_to_dashboard( array $payload ): array {
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return array( 'code' => 'payload_encoding_failed', 'status' => 0 );
		}

		$response = wp_remote_post( (string) apply_filters( 'ratesight_enrollment_endpoint', self::DASHBOARD_ENDPOINT ), array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'        => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'code' => sanitize_key( $response->get_error_code() ), 'status' => 0 );
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code          = is_array( $response_body ) ? sanitize_key( (string) ( $response_body['code'] ?? '' ) ) : '';
		return array( 'code' => $code !== '' ? $code : 'unexpected_response', 'status' => $status );
	}

	public static function option_updated( string $option, $old_value, $value ): void {
		if ( Ratesight_Options::option_name( 'code_id' ) !== $option || trim( (string) $old_value ) === trim( (string) $value ) ) {
			return;
		}
		delete_option( self::RECEIPT_OPTION );
		self::schedule_retry( 5 );
	}

	public static function status(): array {
		$receipt      = get_option( self::RECEIPT_OPTION, array() );
		$installation = get_option( self::INSTALLATION_RECEIPT_OPTION, array() );
		return array(
			'supported'    => function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_sign' ),
			'accepted'     => is_array( $receipt ) && true === ( $receipt['accepted'] ?? false ),
			'code'         => is_array( $receipt ) ? ( $receipt['code'] ?? null ) : null,
			'httpStatus'   => is_array( $receipt ) ? ( $receipt['http_status'] ?? null ) : null,
			'attemptedAt'  => is_array( $receipt ) && ! empty( $receipt['attempted_at'] ) ? gmdate( 'c', (int) $receipt['attempted_at'] ) : null,
			'installationReport' => array(
				'accepted'    => is_array( $installation ) && true === ( $installation['accepted'] ?? false ),
				'code'        => is_array( $installation ) ? ( $installation['code'] ?? null ) : null,
				'attemptedAt' => is_array( $installation ) && ! empty( $installation['attempted_at'] ) ? gmdate( 'c', (int) $installation['attempted_at'] ) : null,
			),
		);
	}

	public static function schedule_retry( int $delay = 900 ): void {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::RETRY_HOOK );
		}
	}

	/**
	 * Run the enrollment right after the current request finishes when the
	 * server supports it, otherwise through an immediate cron event.
	 */
	private static function enroll_soon(): void {
		$scheduled = wp_next_scheduled( self::RETRY_HOOK );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::RETRY_HOOK );
		}
		wp_schedule_single_event( time(), self::RETRY_HOOK );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			add_action( 'shutdown', array( self::class, 'send_after_response' ), 100 );
		} elseif ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public static function send_after_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$scheduled = wp_next_scheduled( self::RETRY_HOOK );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::RETRY_HOOK );
		}
		self::send();
	}

	private static function identity() {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_get_details' ) ) {
			return self::error( 'rs_enrollment_crypto_unavailable', 503 );
		}

		$installation_id = (string) get_option( self::INSTALLATION_OPTION, '' );
		$private_key_pem  = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		if ( ! preg_match( self::UUID_PATTERN, $installation_id ) || $private_key_pem === '' ) {
			$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
			if ( false === $key || ! openssl_pkey_export( $key, $private_key_pem ) ) {
				return self::error( 'rs_enrollment_identity_generation_failed', 503 );
			}
			$installation_id = wp_generate_uuid4();
			update_option( self::INSTALLATION_OPTION, $installation_id, false );
			update_option( self::PRIVATE_KEY_OPTION, $private_key_pem, false );
		}

		$private_key = openssl_pkey_get_private( $private_key_pem );
		$details     = false !== $private_key ? openssl_pkey_get_details( $private_key ) : false;
		if ( false === $private_key || ! is_array( $details ) || empty( $details['key'] ) ) {
			return self::error( 'rs_enrollment_identity_invalid', 503 );
		}
		$public_key_der = self::pem_to_der( (string) $details['key'] );
		if ( null === $public_key_der ) {
			return self::error( 'rs_enrollment_identity_invalid', 503 );
		}

		return array(
			'installationId' => $installation_id,
			'privateKey'      => $private_key,
			'publicKeySpki'   => self::base64url_encode( $public_key_der ),
		);
	}

	private static function plugin_version(): string {
		return defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0';
	}

	private static function site_name(): string {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$name = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 );
	}

	private static function site_origin(): ?string {
		return self::normalize_origin( home_url( '/' ) );
	}

	private static function normalize_origin( string $value ): ?string {
		$parts = wp_parse_url( trim( $value ) );
		if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return 'https://' . strtolower( rtrim( (string) $parts['host'], '.' ) ) . $port;
	}

	private static function fingerprint( string $oid, string $site_origin ): string {
		return hash( 'sha256', implode( "\n", array( $oid, $site_origin, self::plugin_version() ) ) );
	}

	private static function store_receipt( string $oid, ?string $site_origin, string $code, int $http_status, bool $accepted, string $fingerprint = '' ): void {
		update_option( self::RECEIPT_OPTION, array(
			'oid'         => $oid,
			'site_hash'   => null === $site_origin ? null : substr( hash( 'sha256', $site_origin ), 0, 20 ),
			'code'        => sanitize_key( $code ),
			'http_status' => $http_status,
			'accepted'    => $accepted,
			'fingerprint' => $fingerprint !== '' ? $fingerprint : ( null === $site_origin ? '' : self::fingerprint( $oid, $site_origin ) ),
			'attempted_at'=> time(),
		), false );
	}

	private static function store_installation_receipt( string $code, int $http_status, bool $accepted ): void {
		update_option( self::INSTALLATION_RECEIPT_OPTION, array(
			'code'         => sanitize_key( $code ),
			'http_status'  => $http_status,
			'accepted'     => $accepted,
			'attempted_at' => time(),
		), false );
	}

	private static function pem_to_der( string $pem ): ?string {
		$encoded = preg_replace( '/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem );
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return null;
		}
		$decoded = base64_decode( $encoded, true );
		return false === $decoded ? null : $decoded;
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Ratesight enrollment failed.', array( 'status' => $status ) );
	}
}
