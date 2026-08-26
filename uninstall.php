<?php
/**
 * Runs when the plugin is deleted via WP admin.
 * Drops all plugin tables and removes all options, transients, post meta,
 * and cron events. Leaves no trace in the database.
 *
 * @package Ratesight
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || die;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ratesight-installation.php';

$ratesight_uninstall_status = Ratesight_Installation::status(
	is_string( WP_UNINSTALL_PLUGIN ) ? WP_UNINSTALL_PLUGIN : ''
);
if ( ! $ratesight_uninstall_status['destructiveUninstallAllowed'] ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ratesight-options.php';

global $wpdb;
$ratesight_schema_options = array_map(
	static fn( array $definition ): string => $definition['name'],
	Ratesight_Options::schema()
);
$ratesight_shared_state = Ratesight_Installation::shared_state_inventory( $ratesight_schema_options );

// ── RS Pages (custom post type) ────────────────────────────────────────────
foreach ( $ratesight_shared_state['postTypes'] as $post_type ) {
	$rs_post_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		$post_type
	) );
	foreach ( $rs_post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true ); // true = force delete, bypass trash
	}
}

// ── DB tables ──────────────────────────────────────────────────────────────
foreach ( $ratesight_shared_state['tables'] as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

// ── Options, OAuth/auth state, and retention choice ────────────────────────
foreach ( $ratesight_shared_state['options'] as $opt ) {
	delete_option( $opt );
}

// ── Dynamic auth nonces and transients ─────────────────────────────────────
$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_rs\_%'
	    OR option_name LIKE '_transient_timeout_rs\_%'
	    OR option_name LIKE '_transient_ratesight\_%'
	    OR option_name LIKE '_transient_timeout_ratesight\_%'
	    OR option_name LIKE 'ratesight_auth_nonce\_%'"
); // phpcs:ignore

// ── Post meta ─────────────────────────────────────────────────────────────
foreach ( $ratesight_shared_state['postMeta'] as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore
}

// ── User meta ──────────────────────────────────────────────────────────────
foreach ( $ratesight_shared_state['userMeta'] as $key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) ); // phpcs:ignore
}

// ── Cron events ────────────────────────────────────────────────────────────
foreach ( $ratesight_shared_state['cronHooks'] as $hook ) {
	wp_unschedule_hook( $hook );
}
