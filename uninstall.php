<?php
/**
 * Runs when the plugin is deleted via WP admin.
 * Drops all plugin tables and removes all options, transients, post meta,
 * and cron events. Leaves no trace in the database.
 *
 * @package Ratesight
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || die;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ratesight-options.php';

global $wpdb;

// ── RS Pages (custom post type) ────────────────────────────────────────────
$rs_post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ratesight_page'"
);
foreach ( $rs_post_ids as $post_id ) {
	wp_delete_post( (int) $post_id, true ); // true = force delete, bypass trash
}

// ── DB tables ──────────────────────────────────────────────────────────────
$tables = array(
	'ratesight_logs',
	'ratesight_performance',
	'ratesight_keywords',
	'ratesight_gbp_performance',
	'ratesight_bing_performance',
	'ratesight_bing_keywords',
	'ratesight_link_cache',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

// ── Options from the schema ────────────────────────────────────────────────
Ratesight_Options::delete_all();

// ── OAuth tokens and connection state ─────────────────────────────────────
foreach ( array( 'gbp', 'gsc' ) as $service ) {
	delete_option( "ratesight_{$service}_oauth"             );
	delete_option( "ratesight_{$service}_selection"         );
	delete_option( "ratesight_{$service}_locked"            );
	delete_option( "ratesight_{$service}_revoked"           );
	delete_option( "ratesight_{$service}_disconnect_reason" );
	delete_option( "ratesight_{$service}_refresh_error"     );
	delete_option( "ratesight_{$service}_scope_error"       );
}

// ── Misc runtime options ───────────────────────────────────────────────────
$misc_options = array(
	'ratesight_db_version',
	'ratesight_cpt_flushed',
	'ratesight_gsc_last_sync',
	'ratesight_bing_last_sync',
	'ratesight_gbp_last_sync',
	'ratesight_link_last_scan',
	'ratesight_indexnow_key',
	'ratesight_indexnow_log',
	'ratesight_link_scan_running',
	'ratesight_link_broken_running',
	'ratesight_bulk_queue',
	'ratesight_webhook_secret',
	'ratesight_api_key',
	'ratesight_notify_email',
	'ratesight_notify_enabled',
	'ratesight_rs_redirects',
	'ratesight_deepseek_api_key',
	'ratesight_recovery_actions',
	'ratesight_redirect_health_last',
	'ratesight_redirect_serve_log',
	'ratesight_retain_on_uninstall',
	'ratesight_health_catch_all_urls',
);
foreach ( $misc_options as $opt ) {
	delete_option( $opt );
}

// ── Transients ────────────────────────────────────────────────────────────
$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_rs\_%'
	    OR option_name LIKE '_transient_timeout_rs\_%'
	    OR option_name LIKE '_transient_ratesight\_%'
	    OR option_name LIKE '_transient_timeout_ratesight\_%'"
); // phpcs:ignore

// ── Post meta ─────────────────────────────────────────────────────────────
$meta_keys = array(
	'_rs_content_hash',
	'_rs_show_title',
	'_rs_layout',
	'_rs_manual_links',
	'_rs_schema',
	'_rs_custom_css_url',
);
foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore
}

// ── Cron events ────────────────────────────────────────────────────────────
$cron_hooks = array(
	'ratesight_prune_logs',
	'ratesight_sync_gsc',
	'ratesight_sync_gbp_performance',
	'ratesight_sync_bing',
	'ratesight_retry_pending',
	'ratesight_check_broken_links',
	'ratesight_redirect_health',
	'ratesight_daily_digest',
	'ratesight_process_bulk_queue',
);
foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}
