<?php
/**
 * Plugin-initiated dashboard enrollment.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Enrollment {
	public const CONTRACT           = 'ratesight-wordpress-enrollment-v1';
	public const CHALLENGE_CONTRACT = 'ratesight-wordpress-enrollment-challenge-v1';
	public const RETRY_HOOK         = 'ratesight_enrollment_retry';
	public const MAX_BODY_BYTES     = 4096;
	public const VERSION_OPTION     = 'ratesight_enrollment_emitter_version';

	private const DASHBOARD_ENDPOINT = 'https://dash.ratesight.com/api/public/wordpress/enrollments';
	private const INSTALLATION_OPTION = 'ratesight_enrollment_installation_id';
	private const PRIVATE_KEY_OPTION  = 'ratesight_enrollment_private_key';
	private const RECEIPT_OPTION      = 'ratesight_enrollment_receipt';

	/**
	 * Outcomes that end automatic retrying for the current identity and version:
	 * `accepted` (verified, awaiting operator approval), `awaiting_review` (the
	 * dashboard stored it but an operator must resolve the authoritative origin)
	 * and `blocked` (a rejection only an operator can change).
	 */
	private const TERMINAL_OUTCOMES = array( 'accepted', 'awaiting_review', 'blocked', 'retry_exhausted' );

	/** Transient retry budget: backoff ladder and the attempt cap that blocks a site. */
	private const TRANSIENT_DELAYS = array( 900, 1800, 3600, 7200, 14400, 21600 );
	private const MAX_ATTEMPTS     = 8;

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/enrollment-challenge', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'handle_challenge' ),
			'permission_callback' => '__return_true',
		) );
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

	public static function send(): void {
		$oid = trim( (string) Ratesight_Options::get( 'code_id' ) );
		if ( ! preg_match( '/^[0-9]{1,20}$/', $oid ) || Ratesight_Pairing::is_connected() ) {
			return;
		}

		$receipt  = get_option( self::RECEIPT_OPTION, array() );
		$attempts = ( is_array( $receipt ) ? (int) ( $receipt['attempts'] ?? 0 ) : 0 ) + 1;

		$site_origin = self::site_origin();
		$identity    = self::identity();
		if ( null === $site_origin || is_wp_error( $identity ) ) {
			self::store_receipt( $oid, $site_origin, 'identity_unavailable', 0, false, '', 'retrying', $attempts, '' );
			self::schedule_backoff( $attempts );
			return;
		}

		$fingerprint = self::fingerprint( $oid, $site_origin );
		if ( self::is_terminal( $receipt, $fingerprint ) ) {
			return;
		}

		$payload = array(
			'contract'       => self::CONTRACT,
			'requestId'      => 'wpenr:v1:' . wp_generate_uuid4(),
			'oid'            => $oid,
			'siteOrigin'     => $site_origin,
			'pluginVersion'  => defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0',
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
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			self::store_receipt( $oid, $site_origin, 'payload_encoding_failed', 0, false, '', 'retrying', $attempts, '' );
			self::schedule_backoff( $attempts );
			return;
		}

		$response = wp_remote_post( (string) apply_filters( 'ratesight_enrollment_endpoint', self::DASHBOARD_ENDPOINT ), array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'        => $body,
		) );
		if ( is_wp_error( $response ) ) {
			self::store_receipt( $oid, $site_origin, sanitize_key( $response->get_error_code() ), 0, false, '', 'retrying', $attempts, '' );
			self::schedule_backoff( $attempts );
			return;
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code          = is_array( $response_body ) ? sanitize_key( (string) ( $response_body['code'] ?? '' ) ) : '';
		$outcome       = self::classify( $status, $code );
		if ( 'retrying' === $outcome && $attempts >= self::MAX_ATTEMPTS ) {
			// The bounded budget is spent: stop announcing until an operator changes the
			// OID or a shipped plugin version grants a fresh budget.
			$outcome = 'retry_exhausted';
		}
		$accepted      = in_array( $outcome, array( 'accepted', 'awaiting_review' ), true );
		self::store_receipt( $oid, $site_origin, '' !== $code ? $code : 'unexpected_response', $status, $accepted, $fingerprint, $outcome, $attempts, self::blocked_reason( $outcome, $code ) );
		if ( 'retrying' === $outcome ) {
			self::schedule_backoff( $attempts );
		}
	}

	public static function option_updated( string $option, $old_value, $value ): void {
		if ( Ratesight_Options::option_name( 'code_id' ) !== $option || trim( (string) $old_value ) === trim( (string) $value ) ) {
			return;
		}
		delete_option( self::RECEIPT_OPTION );
		self::schedule_retry( 5 );
	}

	public static function status(): array {
		$receipt = get_option( self::RECEIPT_OPTION, array() );
		$outcome = null;
		if ( is_array( $receipt ) ) {
			$outcome = (string) ( $receipt['outcome'] ?? ( ! empty( $receipt['accepted'] ) ? 'accepted' : 'retrying' ) );
		}
		return array(
			'supported'   => function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_sign' ),
			// `accepted` means the dashboard holds a durable record for this installation;
			// `awaiting_review` rows still need an operator, and `blocked` means stop retrying.
			'accepted'    => is_array( $receipt ) && true === ( $receipt['accepted'] ?? false ),
			'outcome'     => $outcome,
			'blocked'     => 'blocked' === $outcome,
			'attempts'    => is_array( $receipt ) ? (int) ( $receipt['attempts'] ?? 0 ) : 0,
			'code'        => is_array( $receipt ) ? ( $receipt['code'] ?? null ) : null,
			'httpStatus'  => is_array( $receipt ) ? ( $receipt['http_status'] ?? null ) : null,
			'attemptedAt' => is_array( $receipt ) && ! empty( $receipt['attempted_at'] ) ? gmdate( 'c', (int) $receipt['attempted_at'] ) : null,
		);
	}

	public static function schedule_retry( int $delay = 900 ): void {
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::RETRY_HOOK );
		}
	}

	/**
	 * Idempotent recovery for already-installed sites.
	 *
	 * WordPress only fires the activation hook on activation, so a site that was
	 * installed before enrollment existed — or that simply upgrades in place — would
	 * never announce itself. The first request after a new plugin version, including
	 * the plugin's own cron, advances the stored emitter version once and grants a
	 * fresh bounded retry budget. Stored settings and pairing are never touched.
	 *
	 * Already-recorded sites are re-announced deliberately: the dashboard answers
	 * with an idempotent duplicate when its record is still live, and with a fresh
	 * enrollment when the previous record expired. A site the dashboard already
	 * classified as blocked stays blocked: only an operator changing the OID
	 * (see option_updated) re-arms it.
	 */
	public static function maybe_recover(): void {
		$version = defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0';
		if ( (string) get_option( self::VERSION_OPTION, '' ) === $version ) {
			return;
		}
		update_option( self::VERSION_OPTION, $version, false );

		// An upgrade must not erase the durable receipt of an already paired site.
		// send() would return early for this site, leaving a deleted receipt empty.
		if ( Ratesight_Pairing::is_connected() ) {
			return;
		}

		$receipt = get_option( self::RECEIPT_OPTION, array() );
		if ( is_array( $receipt ) && 'blocked' === ( $receipt['outcome'] ?? '' ) ) {
			return;
		}

		delete_option( self::RECEIPT_OPTION );
		self::schedule_retry( 120 );
	}

	private static function schedule_backoff( int $attempts ): void {
		$delays = self::TRANSIENT_DELAYS;
		$index  = min( max( 0, $attempts - 1 ), count( $delays ) - 1 );
		self::schedule_retry( $delays[ $index ] );
	}

	/**
	 * Classify one delivery attempt. Only retryable causes stay in the bounded
	 * ladder; a rejection the plugin cannot fix stops announcing immediately.
	 */
	private static function classify( int $status, string $code ): string {
		if ( $status >= 200 && $status < 300 ) {
			if ( in_array( $code, array( 'wordpress_enrollment_pending_approval', 'wordpress_enrollment_duplicate' ), true ) ) {
				return 'accepted';
			}
			if ( 'wordpress_enrollment_review_required' === $code ) {
				return 'awaiting_review';
			}
			// A success code we do not know yet is not proof of anything: keep retrying
			// until the bounded budget is spent.
			return 'retrying';
		}
		if ( 0 === $status || 408 === $status || 429 === $status || $status >= 500 ) {
			return 'retrying';
		}
		return 'blocked';
	}

	private static function blocked_reason( string $outcome, string $code ): string {
		if ( 'blocked' !== $outcome ) {
			return '';
		}
		return '' !== $code ? $code : 'unexpected_response';
	}

	private static function is_terminal( $receipt, string $fingerprint ): bool {
		if ( ! is_array( $receipt ) ) {
			return false;
		}
		$outcome = (string) ( $receipt['outcome'] ?? ( ! empty( $receipt['accepted'] ) ? 'accepted' : '' ) );
		if ( ! in_array( $outcome, self::TERMINAL_OUTCOMES, true ) ) {
			return false;
		}
		return hash_equals( (string) ( $receipt['fingerprint'] ?? '' ), $fingerprint );
	}

	private static function identity() {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_get_details' ) ) {
			return self::error( 'rs_enrollment_crypto_unavailable', 503 );
		}

		$installation_id = (string) get_option( self::INSTALLATION_OPTION, '' );
		$private_key_pem  = (string) get_option( self::PRIVATE_KEY_OPTION, '' );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installation_id ) || $private_key_pem === '' ) {
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

	private static function site_origin(): ?string {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return 'https://' . strtolower( rtrim( (string) $parts['host'], '.' ) ) . $port;
	}

	private static function fingerprint( string $oid, string $site_origin ): string {
		$version = defined( 'RATESIGHT_RELEASE_VERSION' ) ? RATESIGHT_RELEASE_VERSION : '0.0.0';
		return hash( 'sha256', implode( "\n", array( $oid, $site_origin, $version ) ) );
	}

	private static function store_receipt( string $oid, ?string $site_origin, string $code, int $http_status, bool $accepted, string $fingerprint = '', string $outcome = 'retrying', int $attempts = 1, string $blocked_reason = '' ): void {
		update_option( self::RECEIPT_OPTION, array(
			'oid'         => $oid,
			'site_hash'   => null === $site_origin ? null : substr( hash( 'sha256', $site_origin ), 0, 20 ),
			'code'        => sanitize_key( $code ),
			'http_status' => $http_status,
			'accepted'    => $accepted,
			'outcome'     => $outcome,
			'attempts'    => $attempts,
			'blocked_reason' => $blocked_reason,
			'fingerprint' => $fingerprint !== '' ? $fingerprint : ( null === $site_origin ? '' : self::fingerprint( $oid, $site_origin ) ),
			'attempted_at'=> time(),
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
