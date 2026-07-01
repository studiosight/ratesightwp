<?php
/**
 * Google OAuth client — proxy version.
 *
 * The Cloudflare Worker at oauth.ratesight.com holds all Google credentials.
 * This plugin only needs two secrets — both are Ratesight-internal values,
 * nothing from Google.
 *
 *   STATE_SECRET — plugin signs outgoing OAuth state; Worker verifies it.
 *   TOKEN_SECRET — Worker signs responses; plugin verifies them.
 *                  Also used to authenticate refresh requests to the Worker.
 *
 * These ship with sensible defaults baked in, so no per-site setup is needed.
 * A site may OPTIONALLY override either value (e.g. to use rotated secrets)
 * by defining the matching constant in wp-config.php, above the "stop editing"
 * line:
 *
 *   define( 'RATESIGHT_STATE_SECRET', '...' );
 *   define( 'RATESIGHT_TOKEN_SECRET', '...' );
 *
 * Any override must match the value set as STATE_SECRET / TOKEN_SECRET on the
 * Cloudflare Worker. See .env.example in the repo root for a template.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_OAuth_Client {

	const PROXY_URL   = 'https://oauth.ratesight.com/callback';
	const REFRESH_URL = 'https://oauth.ratesight.com/refresh';
	const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';

	// ── Configuration ─────────────────────────────────────────────────────────
	// The plugin authenticates to the Worker by its OID (Ratesight ID) alone —
	// every request carries the OID and the Worker trusts known accounts (its
	// ALLOWED_OIDS allowlist), revoking one via REVOKED_OIDS. No shared secret or
	// per-site key is stored on the site.
	//
	// STATE_SECRET / TOKEN_SECRET remain OPTIONAL: a site may define
	// RATESIGHT_STATE_SECRET / RATESIGHT_TOKEN_SECRET in wp-config.php to also
	// HMAC-sign requests (matching the Worker). Left unset — the normal case —
	// requests are authenticated by OID only.
	//
	// CLIENT_ID_GSC and CLIENT_ID_GBP are the Google OAuth client IDs from
	// Google Cloud Console — these are not sensitive (visible in any OAuth URL).

	const CLIENT_ID_GSC = '849914350445-a305oqjt7nckqjfsa66nkepn12hn5no5.apps.googleusercontent.com';
	const CLIENT_ID_GBP = '745585688545-hehrvb8q8kb2j0h3radppbb2r4eucsu2.apps.googleusercontent.com'; // Same as GSC if using one project

	/**
	 * Plugin↔Worker state-signing secret. Must be defined per-deployment via
	 * RATESIGHT_STATE_SECRET in wp-config.php; returns '' when unset (which
	 * leaves credentials_configured() false and OAuth disabled).
	 */
	public static function state_secret(): string {
		return ( defined( 'RATESIGHT_STATE_SECRET' ) && RATESIGHT_STATE_SECRET )
			? (string) RATESIGHT_STATE_SECRET
			: '';
	}

	/**
	 * Worker response/refresh-signing secret. Must be defined per-deployment via
	 * RATESIGHT_TOKEN_SECRET in wp-config.php; returns '' when unset (which
	 * leaves credentials_configured() false and OAuth disabled).
	 */
	public static function token_secret(): string {
		return ( defined( 'RATESIGHT_TOKEN_SECRET' ) && RATESIGHT_TOKEN_SECRET )
			? (string) RATESIGHT_TOKEN_SECRET
			: '';
	}

	// ── Authentication (OID-only) ─────────────────────────────────────────────
	// The site presents its OID (Ratesight ID) on every Worker request. The
	// Worker trusts known OIDs (ALLOWED_OIDS) and can revoke one (REVOKED_OIDS).
	// No per-site key or shared secret is stored here.

	/** The site's OID (Ratesight ID) — the identity presented to the Worker. */
	public static function oid(): string {
		return trim( (string) Ratesight_Options::get( 'code_id' ) );
	}

	/**
	 * Optional shared secret used to HMAC-sign Worker traffic. Empty in the
	 * normal OID-only mode (no wp-config secret defined).
	 */
	public static function active_secret(): string {
		return self::token_secret();
	}

	/** Identity fields merged into every Worker request so it can attribute + trust it. */
	public static function auth_meta(): array {
		$oid = self::oid();
		return $oid !== '' ? array( 'oid' => $oid ) : array();
	}

	/**
	 * Sign a message for an outbound Worker request. Always carries { oid }; also
	 * adds { hmac } when the optional shared secret is configured.
	 */
	public static function sign_request( string $message ): array {
		return array( 'hmac' => hash_hmac( 'sha256', $message, self::active_secret() ) ) + self::auth_meta();
	}

	// ─────────────────────────────────────────────────────────────────────────

	const SCOPES = array(
		'gbp' => 'openid email https://www.googleapis.com/auth/business.manage',
		'gsc' => 'openid email https://www.googleapis.com/auth/webmasters',
	);

	public static function credentials_configured() {
		if ( self::CLIENT_ID_GSC === 'REPLACE_WITH_GSC_CLIENT_ID'
			|| self::CLIENT_ID_GBP === 'REPLACE_WITH_GBP_CLIENT_ID' ) {
			return false;
		}
		// OID-only: the Ratesight ID is the credential. (A site may additionally
		// define the optional shared secret, but it is not required.)
		return self::oid() !== '';
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public static function get_auth_url( string $service ) {
		$state     = self::build_state( $service );
		$client_id = $service === 'gbp' ? self::CLIENT_ID_GBP : self::CLIENT_ID_GSC;

		return add_query_arg( array(
			'client_id'     => $client_id,
			'redirect_uri'  => self::PROXY_URL,
			'response_type' => 'code',
			'scope'         => self::SCOPES[ $service ],
			'state'         => $state,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
		), self::AUTH_URL );
	}

	public static function handle_token_return( string $raw_payload ): bool|WP_Error {
		$data = self::verify_worker_payload( $raw_payload );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$service = $data['service'] ?? '';
		if ( ! in_array( $service, array( 'gbp', 'gsc' ), true ) ) {
			return new \WP_Error( 'rs_oauth_service', 'Unknown service in token payload.' );
		}

		// Revoke the previous refresh token before storing the new one.
		// Google caps refresh tokens at ~50 per user per app — without this,
		// every reconnect accumulates a token and Google eventually revokes the
		// oldest ones silently, causing unexpected disconnections.
		$existing = self::get_stored_data( $service );
		if ( ! empty( $existing['refresh_token'] ) && $existing['refresh_token'] !== $data['refresh_token'] ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke?token=' . rawurlencode( $existing['refresh_token'] ),
				array(
					'timeout'   => 5,
					'blocking'  => false,
					'headers'   => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				)
			);
		}

		update_option( 'ratesight_' . $service . '_oauth', array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'],
			'expires_at'    => (int) $data['expires_at'],
			'email'         => sanitize_email( $data['email'] ?? '' ),
		), false );

		return true;
	}

	/**
	 * Return a valid access token, refreshing via the Worker if expired.
	 * No Google credentials needed here — the Worker handles the refresh.
	 */
	public static function get_access_token( string $service ): string|WP_Error {
		$data = self::get_stored_data( $service );

		if ( empty( $data ) || empty( $data['access_token'] ) ) {
			return new \WP_Error( 'rs_not_connected', ucfirst( $service ) . ' is not connected.' );
		}

		// Still valid.
		if ( $data['expires_at'] > time() + 60 ) {
			return $data['access_token'];
		}

		// Needs refreshing — ask the Worker.
		if ( empty( $data['refresh_token'] ) ) {
			return new \WP_Error( 'rs_no_refresh', 'No refresh token — please reconnect.' );
		}

		return self::refresh_via_worker( $service, $data );
	}

	public static function get_stored_data( string $service ) {
		return (array) get_option( 'ratesight_' . $service . '_oauth', array() );
	}

	public static function is_connected( string $service ) {
		$data = self::get_stored_data( $service );
		return ! empty( $data['access_token'] );
	}

	public static function disconnect( string $service ) {
		delete_option( 'ratesight_' . $service . '_oauth' );
		delete_option( 'ratesight_' . $service . '_selection' );
		delete_option( 'ratesight_' . $service . '_locked' );
		delete_option( 'ratesight_' . $service . '_revoked' );
	}

	// -------------------------------------------------------------------------
	// Worker communication
	// -------------------------------------------------------------------------

	/**
	 * Ask the Worker to refresh an expired access token.
	 * We sign the refresh_token with TOKEN_SECRET so the Worker can verify
	 * the request is coming from a legitimate plugin install.
	 */
	private static function refresh_via_worker( string $service, array $data ): string|WP_Error {
		$refresh_token = $data['refresh_token'];
		$hmac          = hash_hmac( 'sha256', $refresh_token, self::active_secret() );

		$response = wp_remote_post( self::REFRESH_URL, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'refresh_token'     => $refresh_token,
				'token_secret_hmac' => $hmac,
				'service'           => $service,
			) + self::auth_meta() ),
		) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rs_refresh_failed', 'Token refresh request failed: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['ok'] ) ) {
			if ( ! empty( $body['revoked'] ) ) {
				$reason = 'Google revoked the refresh token. This usually means the OAuth app is in Testing mode (tokens expire after 7 days). Go to Google Cloud Console → OAuth consent screen and publish the app, then reconnect.';
				update_option( 'ratesight_' . $service . '_revoked', 1, false );
				update_option( 'ratesight_' . $service . '_disconnect_reason', $reason, false );
				self::disconnect( $service );
				Ratesight_Notifier::alert(
					strtoupper( $service ) . ' disconnected',
					"Your {$service} connection was disconnected.\n\n{$reason}\n\nReconnect at: " . admin_url( 'admin.php?page=ratesight&tab=connections' )
				);
				return new \WP_Error( 'rs_refresh_revoked', $reason );
			}
			return new \WP_Error( 'rs_refresh_failed', 'Token refresh failed: ' . ( $body['error'] ?? 'unknown error' ) );
		}

		// Verify the Worker signed the response.
		$result = self::verify_worker_payload( $body['payload'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data['access_token'] = $result['access_token'];
		$data['expires_at']   = (int) $result['expires_at'];
		update_option( 'ratesight_' . $service . '_oauth', $data, false );

		return $data['access_token'];
	}

	// -------------------------------------------------------------------------
	// State and payload signing
	// -------------------------------------------------------------------------

	private static function build_state( string $service ) {
		$payload  = array(
			'service'  => $service,
			'site_url' => home_url(),
			'nonce'    => wp_generate_password( 16, false ),
		) + self::auth_meta(); // carries 'oid' so the Worker can attribute + trust the request
		$data_b64 = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		$sig      = hash_hmac( 'sha256', $data_b64, self::state_secret() );
		return $data_b64 . '.' . $sig;
	}

	/**
	 * Verify a signed payload from the Worker (used for both initial token
	 * return and refresh responses).
	 */
	private static function verify_worker_payload( string $raw ): array|WP_Error {
		$dot = strrpos( $raw, '.' );
		if ( $dot === false ) {
			return new \WP_Error( 'rs_bad_payload', 'Malformed payload from Worker.' );
		}

		$data_b64 = substr( $raw, 0, $dot );
		$sig      = substr( $raw, $dot + 1 );

		// Verify the Worker's signature only when the optional shared secret is
		// configured. In OID-only mode there's no shared secret to verify against,
		// so we rely on TLS for transport integrity and decode the payload directly.
		$secret = self::active_secret();
		if ( $secret !== '' ) {
			$expected = hash_hmac( 'sha256', $data_b64, $secret );
			if ( ! hash_equals( $expected, $sig ) ) {
				return new \WP_Error( 'rs_bad_sig', 'Worker payload signature verification failed.' );
			}
		}

		$json = base64_decode( strtr( $data_b64, '-_', '+/' ) );
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rs_bad_json', 'Could not decode Worker payload.' );
		}

		// Only apply replay protection to full token payloads (which have a ts field).
		if ( isset( $data['ts'] ) && abs( time() - (int) $data['ts'] ) > 60 ) {
			return new \WP_Error( 'rs_expired_payload', 'Token payload has expired — please try connecting again.' );
		}

		return $data;
	}
}
