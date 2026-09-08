<?php
/**
 * Dashboard-initiated WordPress pairing.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Pairing {
	public const CONTRACT       = 'ratesight-wordpress-pairing-v1';
	public const MAX_BODY_BYTES = 4096;
	public const MAX_CLOCK_SKEW = 300;
	public const PREVIOUS_GRACE = 7 * DAY_IN_SECONDS;

	private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8VSHroJ2Cvkg3lc/6GVQousa2mro
HES1pH1qobHjIZFUlMF20ZUZvupGrr/yjiedRN7e/eGlwVKJeDDM+8HVrw==
-----END PUBLIC KEY-----
PEM;

	public static function register_route(): void {
		register_rest_route( 'ratesight/v1', '/pair', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( $body === '' || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'rs_pairing_body_invalid', 400 );
		}

		$signature = (string) $request->get_header( 'x_ratesight_pairing_signature' );
		if ( ! preg_match( '#^[A-Za-z0-9+/]{64,128}={0,2}$#', $signature ) ) {
			return self::error( 'rs_pairing_signature_invalid', 403 );
		}
		if ( ! self::verify_control_plane_signature( $body, $signature ) ) {
			return self::error( 'rs_pairing_signature_invalid', 403 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || ( $payload['contract'] ?? '' ) !== self::CONTRACT ) {
			return self::error( 'rs_pairing_contract_invalid', 400 );
		}

		$oid        = trim( (string) ( $payload['oid'] ?? '' ) );
		$site       = self::normalize_origin( (string) ( $payload['site'] ?? '' ) );
		$issued_at  = filter_var( $payload['issuedAt'] ?? null, FILTER_VALIDATE_INT );
		$expires_at = filter_var( $payload['expiresAt'] ?? null, FILTER_VALIDATE_INT );
		$nonce      = (string) ( $payload['nonce'] ?? '' );
		$secret     = (string) ( $payload['secret'] ?? '' );
		$stored_oid = trim( (string) Ratesight_Options::get( 'code_id' ) );
		$local_site = self::normalize_origin( home_url( '/' ) );

		if ( ! preg_match( '/^[0-9]{1,20}$/', $oid ) || ( $stored_oid !== '' && ! hash_equals( $stored_oid, $oid ) ) ) {
			return self::error( 'rs_pairing_oid_mismatch', 409 );
		}
		if ( null === $site || null === $local_site || ! hash_equals( $local_site, $site ) ) {
			return self::error( 'rs_pairing_site_mismatch', 409 );
		}
		$now = time();
		if ( false === $issued_at || false === $expires_at || $issued_at > $now + self::MAX_CLOCK_SKEW || $issued_at < $now - self::MAX_CLOCK_SKEW || $expires_at < $now || $expires_at > $issued_at + self::MAX_CLOCK_SKEW ) {
			return self::error( 'rs_pairing_expired', 403 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			return self::error( 'rs_pairing_payload_invalid', 400 );
		}

		$nonce_option = 'ratesight_pairing_nonce_' . hash( 'sha256', $nonce );
		if ( ! add_option( $nonce_option, $expires_at, '', false ) ) {
			return self::error( 'rs_pairing_replayed', 409 );
		}

		if ( Ratesight_Request_Auth::mode() !== 'enforce_v2' ) {
			$mode_result = Ratesight_Request_Auth::set_mode( 'observe_v2' );
			if ( is_wp_error( $mode_result ) ) {
				return $mode_result;
			}
		}

		$previous = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( $previous !== '' && ! hash_equals( $previous, $secret ) ) {
			update_option( 'ratesight_webhook_secret_previous', $previous, false );
			update_option( 'ratesight_webhook_secret_previous_expires', $now + self::PREVIOUS_GRACE, false );
		}
		update_option( 'ratesight_webhook_secret', $secret, false );
		delete_option( 'ratesight_auth_v2_readiness' );

		if ( $stored_oid === '' ) {
			update_option( Ratesight_Options::option_name( 'code_id' ), $oid, false );
			if ( ! hash_equals( $oid, trim( (string) Ratesight_Options::get( 'code_id' ) ) ) ) {
				return self::error( 'rs_pairing_identity_store_failed', 500 );
			}
		}

		$key_id  = Ratesight_Request_Auth::key_id( $secret );
		$receipt = array(
			'source'     => 'dashboard',
			'key_id'     => $key_id,
			'paired_at'  => $now,
			'nonce_hash' => substr( hash( 'sha256', $nonce ), 0, 20 ),
		);
		update_option( 'ratesight_pairing_receipt', $receipt, false );

		return new \WP_REST_Response( array(
			'ok'       => true,
			'contract' => self::CONTRACT,
			'keyId'    => $key_id,
			'mode'     => Ratesight_Request_Auth::mode(),
		), 200 );
	}

	public static function status(): array {
		$receipt = get_option( 'ratesight_pairing_receipt', array() );
		return array(
			'supported' => function_exists( 'openssl_verify' ),
			'contract'  => self::CONTRACT,
			'source'    => is_array( $receipt ) && ( $receipt['source'] ?? '' ) === 'dashboard' ? 'dashboard' : null,
			'pairedAt'  => is_array( $receipt ) && ! empty( $receipt['paired_at'] ) ? gmdate( 'c', (int) $receipt['paired_at'] ) : null,
		);
	}

	public static function is_connected(): bool {
		$status = self::status();
		$auth = Ratesight_Request_Auth::capability_auth();
		return 'dashboard' === ( $status['source'] ?? null )
			&& ! empty( $status['pairedAt'] )
			&& true === ( $auth['configured'] ?? false )
			&& in_array( $auth['mode'] ?? '', array( 'observe_v2', 'enforce_v2' ), true );
	}

	public static function verify_control_plane_signature( string $body, string $signature ): bool {
		$decoded_signature = base64_decode( $signature, true );
		$public_key        = openssl_pkey_get_public( self::public_key() );
		return false !== $decoded_signature && false !== $public_key && 1 === openssl_verify( $body, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256 );
	}

	private static function public_key(): string {
		return (string) apply_filters( 'ratesight_pairing_public_key', self::PUBLIC_KEY );
	}

	private static function normalize_origin( string $value ): ?string {
		$parts = wp_parse_url( trim( $value ) );
		if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return 'https://' . strtolower( rtrim( (string) $parts['host'], '.' ) ) . $port;
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'Ratesight pairing failed.', array( 'status' => $status ) );
	}
}
