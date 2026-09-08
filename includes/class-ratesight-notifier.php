<?php
/**
 * Compatibility shell for retired WordPress operational email notifications.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Notifier {

	const OPT_EMAIL   = 'ratesight_notify_email';
	const OPT_ENABLED = 'ratesight_notify_enabled';
	const CRON_HOOK   = 'ratesight_daily_digest';

	// ─── Setup ────────────────────────────────────────────────────────────────

	public static function schedule(): void {
		self::unschedule();
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ─── Immediate alerts ─────────────────────────────────────────────────────

	/**
	 * Send an immediate alert — used for OAuth disconnections.
	 */
	public static function alert( string $subject, string $body ): void {
		unset( $subject, $body );
	}

	// ─── Daily digest ─────────────────────────────────────────────────────────

	public static function send_digest(): void {
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	public static function is_enabled(): bool {
		return false;
	}

	public static function get_email(): string {
		$email = get_option( self::OPT_EMAIL, '' );
		return $email ?: get_option( 'admin_email', '' );
	}

}
