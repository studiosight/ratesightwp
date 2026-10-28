<?php
/**
 * License check.
 *
 * Local only: the legacy Worker licence call is retired. A site with a Ratesight ID is
 * treated as licensed (enforcement is off), cached for 24 hours.
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
		// No network call: the legacy Worker licence check is retired. Enforcement is off; a site
		// with a Ratesight ID is treated as licensed.
		$valid = '' !== (string) Ratesight_Options::get( 'code_id' );
		set_transient( self::TRANSIENT_KEY, $valid ? 'valid' : 'invalid', self::CACHE_DURATION );
		return $valid;
	}
}
