<?php
/**
 * License validation via Ratesight API.
 *
 * Sends the Ratesight ID + site URL to oauth.ratesight.com/validate and caches
 * the result for 24 hours. Fails closed — if the server is unreachable or the
 * response is invalid, the license is treated as inactive.
 *
 * Gated by this class:
 *   - RS Pages (CPT) — pages return 404 when unlicensed
 *   - Webhook endpoint — rejects new post/page creation when unlicensed
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_License {

	// =========================================================================
	// ✅ TO ENABLE LICENSE ENFORCEMENT (when Cloudflare Worker /validate is live)
	//
	// 1. Set LICENSE_ENFORCEMENT = true below
	// 2. Deploy the Cloudflare Worker with the LICENSES KV namespace bound
	// 3. Add license entries to KV for each customer site
	//
	// When enabled: unlicensed sites serve 404 for all RS Pages and the
	// admin shows a notice linking to the Widgets tab.
	// =========================================================================

	const LICENSE_ENFORCEMENT = false; // ← flip to true when Worker is live
	const TRANSIENT_KEY   = 'ratesight_license_status';
	const CACHE_DURATION  = DAY_IN_SECONDS; // 24 hours
	const VALIDATE_URL    = 'https://oauth.ratesight.com/validate';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the current site has a valid, active license.
	 * Result is cached for 24 hours. Fails closed on any error.
	 */
	public static function is_valid() {
		if ( ! self::LICENSE_ENFORCEMENT ) {
			return true;
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( 'valid' === $cached ) {
			return true;
		}
		if ( 'invalid' === $cached ) {
			return false;
		}

		// No cached result yet (or it expired) — check now and cache it.
		// check_and_cache() fails closed on any error.
		return self::check_and_cache();
	}

	/**
	 * Force a fresh check, bypassing the cache.
	 * Called after the Ratesight ID is saved so the admin sees immediate feedback.
	 */
	public static function refresh() {
		delete_transient( self::TRANSIENT_KEY );
		return self::check_and_cache();
	}

	/**
	 * Clear the cached status (e.g. on plugin deactivation).
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	private static function check_and_cache() {
		$ratesight_id = (string) Ratesight_Options::get( 'code_id' );

		// No ID configured — treat as unlicensed immediately, don't hit the API.
		if ( $ratesight_id === '' ) {
			set_transient( self::TRANSIENT_KEY, 'invalid', self::CACHE_DURATION );
			return false;
		}

		$site_url = home_url();
		// Already carries ratesight_id (the OID), so the Worker can derive the
		// per-site key; just sign with the active secret.
		$hmac     = hash_hmac( 'sha256', $ratesight_id . '|' . $site_url, Ratesight_OAuth_Client::active_secret() );

		$response = wp_remote_post( self::VALIDATE_URL, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'ratesight_id' => $ratesight_id,
				'site_url'     => $site_url,
				'hmac'         => $hmac,
			) ),
		) );

		// Fail closed — any network error or unexpected response = invalid.
		if ( is_wp_error( $response ) ) {
			set_transient( self::TRANSIENT_KEY, 'invalid', self::CACHE_DURATION );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$valid = ( $code === 200 && ! empty( $body['ok'] ) );

		set_transient( self::TRANSIENT_KEY, $valid ? 'valid' : 'invalid', self::CACHE_DURATION );

		return $valid;
	}
}
