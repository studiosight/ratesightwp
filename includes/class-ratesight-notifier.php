<?php
/**
 * Email notifications for operational events.
 *
 * Sends a daily digest of failures, disconnections, and stale syncs.
 * All notifications are optional and off by default.
 *
 * Events that trigger immediate notification (not waiting for digest):
 *   - OAuth disconnect / token revocation
 *   - Webhook endpoint returning 5xx errors consecutively
 *
 * The daily digest covers:
 *   - Failed webhook deliveries in the last 24h (count + sample titles)
 *   - Stale syncs (GSC/GBP/Bing not synced in >48h while connected)
 *   - Orphaned RS pages (zero inbound links)
 *   - Broken links found by cron
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
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
	}

	// ─── Immediate alerts ─────────────────────────────────────────────────────

	/**
	 * Send an immediate alert — used for OAuth disconnections.
	 */
	public static function alert( string $subject, string $body ): void {
		if ( ! self::is_enabled() ) return;
		$email = self::get_email();
		if ( ! $email ) return;
		wp_mail( $email, '[Ratesight] ' . $subject, self::wrap( $body ) );
	}

	// ─── Daily digest ─────────────────────────────────────────────────────────

	public static function send_digest(): void {
		if ( ! self::is_enabled() ) return;
		$email = self::get_email();
		if ( ! $email ) return;

		$lines = array();
		$site  = get_bloginfo( 'name' ) . ' (' . home_url() . ')';

		// ── Failed webhooks in last 24h ────────────────────────────────────────
		global $wpdb;
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$failed = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT title, error_message, received_at FROM `{$log_table}`
			 WHERE status = 'failed' AND received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 ORDER BY received_at DESC LIMIT 10",
			ARRAY_A
		);
		if ( ! empty( $failed ) ) {
			$lines[] = '--- Webhook Failures (' . count( $failed ) . ' in last 24h) ---';
			foreach ( $failed as $f ) {
				$lines[] = '  • ' . ( $f['title'] ?: 'unknown' ) . ': ' . ( $f['error_message'] ?: 'no message' );
			}
			$lines[] = '';
		}

		// ── Stale syncs ────────────────────────────────────────────────────────
		$stale = array();
		$cutoff = strtotime( '-48 hours' );
		foreach ( array( 'gsc' => 'Search Console', 'gbp' => 'Business Profile', 'bing' => 'Bing' ) as $svc => $label ) {
			if ( $svc === 'bing' ) {
				$connected = get_option( 'ratesight_bing_api_key', '' ) !== '';
			} else {
				$connected = Ratesight_OAuth_Client::is_connected( $svc );
			}
			if ( ! $connected ) continue;

			$last_key  = "ratesight_{$svc}_last_sync";
			$last_sync = get_option( $last_key, '' );
			if ( ! $last_sync || strtotime( $last_sync ) < $cutoff ) {
				$stale[] = $label . ( $last_sync ? " (last sync: {$last_sync})" : ' (never synced)' );
			}
		}
		if ( ! empty( $stale ) ) {
			$lines[] = '--- Stale Syncs (>48h since last sync) ---';
			foreach ( $stale as $s ) $lines[] = '  • ' . $s;
			$lines[] = '';
		}

		// ── Disconnected services ──────────────────────────────────────────────
		$disconnected = array();
		foreach ( array( 'gsc' => 'Search Console', 'gbp' => 'Business Profile' ) as $svc => $label ) {
			if ( get_option( "ratesight_{$svc}_revoked" ) ) {
				$reason = get_option( "ratesight_{$svc}_disconnect_reason", 'Token revoked by Google.' );
				$disconnected[] = $label . ': ' . $reason;
			}
		}
		if ( ! empty( $disconnected ) ) {
			$lines[] = '--- Disconnected Services ---';
			foreach ( $disconnected as $d ) $lines[] = '  • ' . $d;
			$lines[] = '';
		}

		// ── Broken links found ─────────────────────────────────────────────────
		$link_table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$link_table}'" ) === $link_table ) { // phpcs:ignore
			$broken_pages = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"SELECT COUNT(*) FROM `{$link_table}` WHERE broken_count > 0" // phpcs:ignore
			);
			if ( $broken_pages > 0 ) {
				$lines[] = "--- Broken Links: {$broken_pages} pages have broken outbound links ---";
				$lines[] = '  Visit the Links tab to review and fix them.';
				$lines[] = '';
			}
		}

		if ( empty( $lines ) ) return; // Nothing to report — skip the email.

		$admin_url = admin_url( 'admin.php?page=ratesight' );
		$body      = "Ratesight daily digest for {$site}\n\n" . implode( "\n", $lines ) . "\nManage: {$admin_url}";

		wp_mail( $email, "[Ratesight] Daily Digest — {$site}", self::wrap( $body ) );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPT_ENABLED, 0 );
	}

	public static function get_email(): string {
		$email = get_option( self::OPT_EMAIL, '' );
		return $email ?: get_option( 'admin_email', '' );
	}

	private static function wrap( string $body ): string {
		return $body . "\n\n---\nSent by the Ratesight plugin. Manage notification settings at: " . admin_url( 'admin.php?page=ratesight&tab=seo-pages' );
	}
}
