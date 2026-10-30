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

	/**
	 * Pinned control-plane signing keys, in priority order. A signature is accepted
	 * when any pinned key verifies, so a replacement key can be published to the
	 * fleet before the current signing key is retired.
	 */
	private const PUBLIC_KEYS = array(
		// Primary control-plane key (EC P-256).
		<<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8VSHroJ2Cvkg3lc/6GVQousa2mro
HES1pH1qobHjIZFUlMF20ZUZvupGrr/yjiedRN7e/eGlwVKJeDDM+8HVrw==
-----END PUBLIC KEY-----
PEM,
	);

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
			// A recovery secret was generated here but the dashboard has not paired: never "connected".
			'recovery'  => is_array( $receipt ) && ( $receipt['source'] ?? '' ) === 'manual_recovery',
			'pairedAt'  => is_array( $receipt ) && ! empty( $receipt['paired_at'] ) ? gmdate( 'c', (int) $receipt['paired_at'] ) : null,
		);
	}

	/**
	 * The three honest states of a site's Ratesight connection.
	 *   paired      the signed pairing really happened (is_connected())
	 *   registered  the site has its Ratesight ID but is NOT paired: Ratesight cannot act on it yet
	 *   setup       no Ratesight ID yet
	 * A stored ID alone is never "connected"; only is_connected() says that.
	 */
	public static function connection_state(): string {
		if ( self::is_connected() ) {
			return 'paired';
		}
		return trim( (string) Ratesight_Options::get( 'code_id' ) ) !== '' ? 'registered' : 'setup';
	}

	/** The heading and plain line the admin shows for connection_state(). */
	public static function notice(): array {
		switch ( self::connection_state() ) {
			case 'paired':
				return array( 'state' => 'paired', 'heading' => 'Connected', 'line' => "Ratesight is managing this site's services. There is nothing you need to connect here." );
			case 'registered':
				return array( 'state' => 'registered', 'heading' => 'Registered, not paired yet', 'line' => 'Your Ratesight team can finish pairing this site. No action is needed here.' );
			default:
				return array( 'state' => 'setup', 'heading' => 'Setup in progress', 'line' => 'Your Ratesight team can finish connecting this site. No action is needed here.' );
		}
	}

	/**
	 * Administrator reset: forget the pairing so the site is unpaired again.
	 *
	 * Clears exactly: the primary secret, the previous secret and its expiry, the pairing receipt and the
	 * readiness proof. Keeps the Ratesight ID (code_id) and every provider and post setting.
	 *
	 * Mode: a site that was NEVER enforced goes back to legacy (the state before any pairing). A site that
	 * ever enforced stays enforce_v2: set_mode() refuses that downgrade on purpose, and it is the safe side,
	 * because with no secret every signed route is refused (rs_secret_required) until the control plane pairs
	 * the site again. The signed /pair route does not depend on the secret or the mode, so re-pairing still
	 * works from any state. Nothing here can make a site LESS strict than it was.
	 *
	 * @return array{state:string,mode:string,ever_enforced:bool,cleared_key_id:?string}
	 */
	public static function reset_pairing( int $user_id, string $login ): array {
		$old       = (string) get_option( 'ratesight_webhook_secret', '' );
		$cleared   = $old !== '' ? Ratesight_Request_Auth::key_id( $old ) : null;
		$mode_now  = Ratesight_Request_Auth::mode();
		$ever      = (bool) get_option( 'ratesight_auth_ever_enforced', false ) || $mode_now === 'enforce_v2';

		foreach ( array( 'ratesight_webhook_secret', 'ratesight_webhook_secret_previous', 'ratesight_webhook_secret_previous_expires', 'ratesight_pairing_receipt', 'ratesight_auth_v2_readiness' ) as $name ) {
			delete_option( $name );
		}
		$note = 'mode kept: ' . $mode_now;
		if ( ! $ever && $mode_now !== 'legacy' ) {
			$result = Ratesight_Request_Auth::set_mode( 'legacy' );
			$note   = is_wp_error( $result ) ? 'mode kept: ' . $mode_now : 'mode reset to legacy';
		}
		Ratesight_Request_Auth::record_admin_event( $user_id, $login, 'pairing_reset', (string) $cleared, $note );
		return array(
			'state'          => self::connection_state(),
			'mode'           => Ratesight_Request_Auth::mode(),
			'ever_enforced'  => $ever,
			'cleared_key_id' => $cleared,
		);
	}

	/**
	 * Administrator recovery: generate a new secret HERE, to be pasted into the dashboard.
	 *
	 * The receipt says source manual_recovery and carries NO paired_at, so is_connected() stays false
	 * (it needs source dashboard AND paired_at, both written only by the control-plane-signed /pair route).
	 * The secret is returned to the caller once and is stored only in ratesight_webhook_secret.
	 *
	 * @return array{secret:string,key_id:string,previous_grace_expires:?string,mode:string}
	 */
	public static function generate_recovery_secret( int $user_id, string $login ): array {
		$now      = time();
		$previous = (string) get_option( 'ratesight_webhook_secret', '' );
		$secret   = bin2hex( random_bytes( 32 ) );
		$key_id   = Ratesight_Request_Auth::key_id( $secret );
		$expires  = null;
		if ( $previous !== '' ) {
			update_option( 'ratesight_webhook_secret_previous', $previous, false );
			update_option( 'ratesight_webhook_secret_previous_expires', $now + self::PREVIOUS_GRACE, false );
			$expires = gmdate( 'c', $now + self::PREVIOUS_GRACE );
		}
		// The dashboard's signed self-test needs observe or enforce; same step the signed pairing takes.
		if ( Ratesight_Request_Auth::mode() !== 'enforce_v2' ) {
			Ratesight_Request_Auth::set_mode( 'observe_v2' );
		}
		update_option( 'ratesight_webhook_secret', $secret, false );
		delete_option( 'ratesight_auth_v2_readiness' );
		update_option( 'ratesight_pairing_receipt', array( 'source' => 'manual_recovery', 'key_id' => $key_id, 'generated_at' => $now ), false );
		Ratesight_Request_Auth::record_admin_event( $user_id, $login, 'recovery_secret_generated', $key_id, $previous !== '' ? 'previous secret kept 7 days' : '' );
		return array( 'secret' => $secret, 'key_id' => $key_id, 'previous_grace_expires' => $expires, 'mode' => Ratesight_Request_Auth::mode() );
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
		if ( false === $decoded_signature ) {
			return false;
		}

		// Check every pinned key instead of returning on the first match, so the
		// number of pins does not change how long a rejection takes.
		$verified = false;
		foreach ( self::public_keys() as $pin ) {
			$public_key = openssl_pkey_get_public( $pin );
			if ( false === $public_key ) {
				continue;
			}
			if ( 1 === openssl_verify( $body, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256 ) ) {
				$verified = true;
			}
		}

		return $verified;
	}

	/**
	 * Pinned keys to accept, in priority order.
	 *
	 * The `ratesight_pairing_public_key` filter may return a single PEM string
	 * (the historical shape) or a list of them.
	 *
	 * @return string[]
	 */
	private static function public_keys(): array {
		$pins = apply_filters( 'ratesight_pairing_public_key', self::PUBLIC_KEYS );
		if ( is_string( $pins ) ) {
			$pins = array( $pins );
		}
		if ( ! is_array( $pins ) ) {
			return array();
		}

		$keys = array();
		foreach ( $pins as $pin ) {
			if ( is_string( $pin ) && '' !== trim( $pin ) ) {
				$keys[] = $pin;
			}
		}

		return $keys;
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
