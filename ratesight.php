<?php
/**
 * Plugin Name:       Ratesight
 * Plugin URI:        https://ratesight.com
 * Description:       Review widgets, shortcodes, and AI-powered SEO page creation via webhook.
 * Version:           3.2.12
 * Requires at least: 5.9
 * Requires PHP:      8.0
 * Author:            Ratesight
 * Author URI:        https://ratesight.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       ratesight
 * Domain Path:       /languages
 *
 * @package Ratesight
 */

defined( 'WPINC' ) || die;

define( 'RATESIGHT_VERSION', '3.2.12.' . filemtime( __FILE__ ) );
define( 'RATESIGHT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RATESIGHT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RATESIGHT_LOG_TABLE',  'ratesight_logs' );
define( 'RATESIGHT_PERF_TABLE', 'ratesight_performance' );

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, static function (): void {
	require_once RATESIGHT_PLUGIN_DIR . 'includes/class-ratesight-options.php';
	require_once RATESIGHT_PLUGIN_DIR . 'includes/class-ratesight-activator.php';
	Ratesight_Activator::activate();
} );

register_deactivation_hook( __FILE__, static function (): void {
	require_once RATESIGHT_PLUGIN_DIR . 'includes/class-ratesight-deactivator.php';
	Ratesight_Deactivator::deactivate();
} );

// ---------------------------------------------------------------------------
// Autoload & boot
// ---------------------------------------------------------------------------

foreach ( array(
	'includes/class-ratesight-options.php',
	'includes/class-ratesight-loader.php',
	'includes/class-ratesight-i18n.php',
	'includes/class-ratesight-logger.php',
	'includes/class-ratesight-image-uploader.php',
	'includes/class-ratesight-category-handler.php',
	'includes/class-ratesight-rs-category-handler.php',
	'includes/class-ratesight-cpt.php',
	'includes/class-ratesight-seo-writer.php',
	'includes/class-ratesight-layout-writer.php',
	'includes/class-ratesight-title-writer.php',
	'includes/class-ratesight-post-creator.php',
	'includes/class-ratesight-publisher.php',
	'includes/class-ratesight-oauth-client.php',
	'includes/class-ratesight-license.php',
	'includes/class-ratesight-gbp-client.php',
	'includes/class-ratesight-gbp-insights-client.php',
	'includes/class-ratesight-gsc-client.php',
	'includes/class-ratesight-bing-client.php',
	'includes/class-ratesight-indexnow.php',
	'includes/class-ratesight-schema.php',
	'includes/class-ratesight-ai-client.php',
	'includes/class-ratesight-update-detector.php',
	'includes/class-ratesight-link-manager.php',
	'includes/class-ratesight-notifier.php',
	'includes/class-ratesight-bulk-operations.php',
	'includes/class-ratesight-sitemap.php',
	'includes/class-ratesight-webhook-handler.php',
	'includes/class-ratesight-related-links.php',
	'includes/class-ratesight-recovery-log.php',
	'includes/class-ratesight-redirect-health.php',
	'includes/class-ratesight-redirect-serve-log.php',
	'includes/class-ratesight-runtime-404-router.php',
	'includes/class-ratesight-activator.php',
	'includes/class-ratesight.php',
	'admin/class-ratesight-admin.php',
	'public/class-ratesight-public.php',
) as $file ) {
	require_once RATESIGHT_PLUGIN_DIR . $file;
}

// ---------------------------------------------------------------------------
// Early Squirrly compatibility — sq_option_sq_sitemap.
//
// Squirrly generates robots.txt synchronously inside its plugin constructor
// (no WordPress hook, raw file-load-time execution). It reads sq_sitemap via
// SQ_Classes_Helpers_Tools::getOption() which applies sq_option_sq_sitemap.
// Registering here (file level, before class loading) ensures the filter is
// in place if Ratesight's file is included before Squirrly's.
// Ratesight_Activator::ensure_loads_first() guarantees this load order after
// (re)activation by moving Ratesight to position 0 in active_plugins.
// The same filter is registered again via the loader in class-ratesight.php
// to handle the normal sitemap.xml request path (all plugins loaded by then).
// ---------------------------------------------------------------------------

add_filter( 'sq_option_sq_sitemap', static function ( $sitemap_list ) {
	$counts = wp_count_posts( 'ratesight_page' );
	if ( isset( $counts->publish ) && (int) $counts->publish > 0 ) {
		$sitemap_list                     = (array) $sitemap_list;
		$sitemap_list['sitemap-rs-pages'] = [ 'sitemap-rs-pages.xml', 1 ];
	}
	return $sitemap_list;
} );

// ---------------------------------------------------------------------------
// Self-healing Squirrly robots.txt entry.
//
// Squirrly generates robots.txt at plugin-load time before any WordPress hook
// can fire. The only reliable way to ensure our entry is present is to have it
// stored in Squirrly's sq_options database row BEFORE the robots.txt request.
//
// inject_into_squirrly_options() writes it on activation, but if Squirrly
// ever re-saves its options it will be wiped. This init hook silently re-adds
// it whenever it's missing, so it is always present for the NEXT robots.txt
// request regardless of what happened since activation.
// ---------------------------------------------------------------------------

add_action( 'init', static function (): void {
	// Skip on robots.txt requests — we're already too late for this one.
	$uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
	if ( strpos( $uri, 'robots.txt' ) !== false ) {
		return;
	}

	$counts = wp_count_posts( 'ratesight_page' );
	if ( ! isset( $counts->publish ) || (int) $counts->publish === 0 ) {
		return;
	}

	$raw = get_option( 'sq_options' );
	if ( ! $raw ) {
		return; // Squirrly not installed.
	}

	$opts = json_decode( $raw, true );
	if ( ! is_array( $opts ) ) {
		return;
	}

	// Already present — nothing to do.
	if ( isset( $opts['sq_sitemap']['sitemap-rs-pages'] ) ) {
		return;
	}

	$opts['sq_sitemap']['sitemap-rs-pages'] = [ 'sitemap-rs-pages.xml', 1 ];
	update_option( 'sq_options', wp_json_encode( $opts ) );
} );

// ---------------------------------------------------------------------------
// Sitemap hooks — always register, before the license gate.
// Public RS pages should be discoverable regardless of license status.
// ---------------------------------------------------------------------------

$_rs_sitemap = new Ratesight_Sitemap();
add_action( 'init',                     [ $_rs_sitemap, 'register_rewrite'       ] );
add_filter( 'query_vars',               [ $_rs_sitemap, 'add_query_var'          ] );
add_action( 'template_redirect',        [ $_rs_sitemap, 'maybe_serve'            ], 1 );
add_filter( 'robots_txt',               [ $_rs_sitemap, 'add_to_robots'          ], 10, 2 );
add_filter( 'wpseo_sitemap_index',      [ $_rs_sitemap, 'inject_yoast_index'     ] );
add_filter( 'sq_option_sq_sitemap',     [ $_rs_sitemap, 'inject_squirrly_option' ] );
add_filter( 'sq_custom_robots',         [ $_rs_sitemap, 'add_to_squirrly_robots' ] );
// Squirrly intercepts sitemap-rs-pages.xml at init via initSitemap() and fires
// do_feed_{type} before rendering — hook here to serve our XML instead.
add_action( 'do_feed_sitemap-rs-pages', [ $_rs_sitemap, 'serve_squirrly'         ], 1 );
unset( $_rs_sitemap );

// ---------------------------------------------------------------------------
// License gate — if enforcement is on and the license is invalid, the plugin
// registers only a minimal admin notice and stops. Nothing else loads.
// ---------------------------------------------------------------------------

if ( ! Ratesight_License::is_valid() ) {
	add_action( 'admin_notices', static function (): void {
		$code_id     = Ratesight_Options::get( 'code_id' );
		$widgets_url = admin_url( 'admin.php?page=ratesight&tab=widgets' );

		if ( $code_id === '' ) {
			$msg = sprintf(
				'<strong>Ratesight:</strong> Plugin disabled — no Ratesight ID configured. <a href="%s">Enter your ID here.</a>',
				esc_url( $widgets_url )
			);
		} else {
			$msg = sprintf(
				'<strong>Ratesight:</strong> Plugin disabled — license inactive. Check your Ratesight ID on the <a href="%s">Widgets tab</a> or contact <a href="mailto:support@ratesight.com">support@ratesight.com</a>.',
				esc_url( $widgets_url )
			);
		}

		echo '<div class="notice notice-error"><p>' . wp_kses( $msg, array( 'strong' => array(), 'a' => array( 'href' => array() ), 'em' => array() ) ) . '</p></div>';
	} );
	return;
}

( new Ratesight() )->run();
