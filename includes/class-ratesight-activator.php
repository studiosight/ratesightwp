<?php
/**
 * Fired during plugin activation and on version upgrades.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Activator {

	const DB_VERSION = '2.4';

	public static function activate() {
		self::create_or_upgrade_tables();
		Ratesight_Options::migrate_legacy();
		self::schedule_cron();
		self::ensure_loads_first();
		self::clear_squirrly_sitemap_cache();
	}

	/**
	 * Clear Squirrly's sitemap transient cache so the index regenerates
	 * immediately with our entry included rather than serving a stale version.
	 */
	private static function clear_squirrly_sitemap_cache() {
		global $wpdb;
		$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_sitemap\_%'
			    OR option_name LIKE '_transient_timeout_sitemap\_%'"
		);
	}

	/**
	 * Move Ratesight to the front of the active_plugins list so it is always
	 * included before other plugins (e.g. Squirrly) during subsequent requests.
	 *
	 * Squirrly handles robots.txt synchronously at file-load time with no
	 * WordPress hook. Our sq_custom_robots filter must already be registered
	 * when Squirrly's constructor runs, which requires Ratesight to load first.
	 */
	private static function ensure_loads_first() {
		$active  = (array) get_option( 'active_plugins', array() );
		$current = plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' );
		$key     = array_search( $current, $active, true );

		if ( $key !== false && $key !== 0 ) {
			array_splice( $active, $key, 1 );
			array_unshift( $active, $current );
			update_option( 'active_plugins', $active );
		}
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'ratesight_db_version', '1.0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_or_upgrade_tables();
		}

		// Ensure all cron events are scheduled — guards inside schedule_cron()
		// mean this is safe to call on every load (no-ops if already scheduled).
		self::schedule_cron();
	}

	// -------------------------------------------------------------------------

	private static function create_or_upgrade_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Activity log ──────────────────────────────────────────────────────
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		dbDelta( "CREATE TABLE {$log_table} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			received_at     DATETIME            NOT NULL,
			post_id         BIGINT(20) UNSIGNED          DEFAULT NULL,
			title           VARCHAR(400)                 DEFAULT NULL,
			child_category  VARCHAR(200)                 DEFAULT NULL,
			status          VARCHAR(30)         NOT NULL DEFAULT 'pending',
			error_message   TEXT                         DEFAULT NULL,
			notes           TEXT                         DEFAULT NULL,
			raw_payload     LONGTEXT                     DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_received_at (received_at),
			KEY idx_status (status)
		) {$charset_collate};" );

		// ── GSC performance — one row per post per day (historical) ───────────
		// UNIQUE KEY changed from (post_id, date) to allow accumulation.
		// Each sync inserts/updates today's row without touching previous days.
		$perf_table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$perf_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT(20) UNSIGNED NOT NULL,
			url         VARCHAR(500)        NOT NULL,
			date        DATE                NOT NULL,
			impressions INT UNSIGNED        NOT NULL DEFAULT 0,
			clicks      INT UNSIGNED        NOT NULL DEFAULT 0,
			position    DECIMAL(8,2)        NOT NULL DEFAULT 0,
			ctr         DECIMAL(8,4)        NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_post_date (post_id, date),
			KEY idx_post_id (post_id),
			KEY idx_date (date)
		) {$charset_collate};" );

		// ── Keyword rankings — top queries per post per day ───────────────────
		// Enables per-keyword rank tracking over time.
		$kw_table = $wpdb->prefix . 'ratesight_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$kw_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT(20) UNSIGNED NOT NULL,
			date        DATE                NOT NULL,
			query       VARCHAR(500)        NOT NULL,
			impressions INT UNSIGNED        NOT NULL DEFAULT 0,
			clicks      INT UNSIGNED        NOT NULL DEFAULT 0,
			position    DECIMAL(8,2)        NOT NULL DEFAULT 0,
			ctr         DECIMAL(8,4)        NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_post_query_date (post_id, query(200), date),
			KEY idx_post_date (post_id, date),
			KEY idx_date (date)
		) {$charset_collate};" );

		// ── GBP performance snapshots — weekly local visibility ───────────────
		$gbp_perf_table = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$gbp_perf_table} (
			id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			location_id         VARCHAR(200)        NOT NULL,
			date                DATE                NOT NULL,
			search_impressions  INT UNSIGNED        NOT NULL DEFAULT 0,
			maps_impressions    INT UNSIGNED        NOT NULL DEFAULT 0,
			direction_requests  INT UNSIGNED        NOT NULL DEFAULT 0,
			call_clicks         INT UNSIGNED        NOT NULL DEFAULT 0,
			website_clicks      INT UNSIGNED        NOT NULL DEFAULT 0,
			review_count        INT UNSIGNED        NOT NULL DEFAULT 0,
			avg_rating          DECIMAL(3,2)        NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_loc_date (location_id(100), date),
			KEY idx_location (location_id(100)),
			KEY idx_date (date)
		) {$charset_collate};" );

		// ── Bing Webmaster Tools performance ─────────────────────────────────
		$bing_perf_table = $wpdb->prefix . 'ratesight_bing_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$bing_perf_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT(20) UNSIGNED NOT NULL,
			url         VARCHAR(500)        NOT NULL,
			date        DATE                NOT NULL,
			impressions INT UNSIGNED        NOT NULL DEFAULT 0,
			clicks      INT UNSIGNED        NOT NULL DEFAULT 0,
			position    DECIMAL(8,2)        NOT NULL DEFAULT 0,
			ctr         DECIMAL(8,4)        NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_post_date (post_id, date),
			KEY idx_post_id (post_id),
			KEY idx_date (date)
		) {$charset_collate};" );

		$bing_kw_table = $wpdb->prefix . 'ratesight_bing_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$bing_kw_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT(20) UNSIGNED NOT NULL,
			date        DATE                NOT NULL,
			query       VARCHAR(500)        NOT NULL,
			impressions INT UNSIGNED        NOT NULL DEFAULT 0,
			clicks      INT UNSIGNED        NOT NULL DEFAULT 0,
			position    DECIMAL(8,2)        NOT NULL DEFAULT 0,
			ctr         DECIMAL(8,4)        NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_post_query_date (post_id, query(200), date),
			KEY idx_post_date (post_id, date),
			KEY idx_date (date)
		) {$charset_collate};" );

		// ── Link Manager cache ────────────────────────────────────────────────
		$link_table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		dbDelta( "CREATE TABLE {$link_table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id        BIGINT(20) UNSIGNED NOT NULL,
			inbound_count  INT UNSIGNED        NOT NULL DEFAULT 0,
			outbound_count INT UNSIGNED        NOT NULL DEFAULT 0,
			outbound_urls  LONGTEXT                     DEFAULT NULL,
			broken_count   INT                 NOT NULL DEFAULT -1,
			broken_urls    LONGTEXT                     DEFAULT NULL,
			scanned_at     DATETIME                     DEFAULT NULL,
			stale          TINYINT(1)          NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY  uniq_post_id (post_id),
			KEY idx_inbound (inbound_count),
			KEY idx_broken (broken_count)
		) {$charset_collate};" );

		update_option( 'ratesight_db_version', self::DB_VERSION );
	}

	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'ratesight_prune_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'ratesight_prune_logs' );
		}
		if ( ! wp_next_scheduled( 'ratesight_sync_gsc' ) ) {
			wp_schedule_event( time(), 'daily', 'ratesight_sync_gsc' );
		}
		if ( ! wp_next_scheduled( 'ratesight_sync_gbp_performance' ) ) {
			wp_schedule_event( time(), 'daily', 'ratesight_sync_gbp_performance' );
		}

		if ( ! wp_next_scheduled( 'ratesight_sync_bing' ) ) {
			wp_schedule_event( time(), 'daily', 'ratesight_sync_bing' );
		}
		if ( ! wp_next_scheduled( 'ratesight_retry_pending' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ratesight_retry_pending' );
		}
		if ( ! wp_next_scheduled( 'ratesight_check_broken_links' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'ratesight_check_broken_links' );
		}
		if ( ! wp_next_scheduled( 'ratesight_redirect_health' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'ratesight_redirect_health' );
		}
		Ratesight_Notifier::schedule();
	}
}
