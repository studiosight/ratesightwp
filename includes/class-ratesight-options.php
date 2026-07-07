<?php
/**
 * Single source of truth for every Ratesight option.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Options {

	public static function schema() {
		return array(

			// ── Widget / shortcode settings ───────────────────────────────────
			'code_id'              => array( 'name' => 'wp_ratesight_code_id',     'default' => '',        'type' => 'text',   'group' => 'widgets'   ),
			// Per-site signing key (Option C). Paired with the OID above; used to
			// authenticate Worker requests once per-site auth is enabled. Secret —
			// never rendered into public widget markup.
			'site_key'             => array( 'name' => 'wp_ratesight_site_key',    'default' => '',        'type' => 'text',   'group' => 'widgets'   ),
			'campaign_id'          => array( 'name' => 'wp_ratesight_campaign_id', 'default' => '',        'type' => 'text',   'group' => 'widgets'   ),
			'domain_id'            => array( 'name' => 'wp_ratesight_domain_id',   'default' => '',        'type' => 'text',   'group' => 'widgets'   ),
			'review_page'          => array( 'name' => 'wp_ratesight_rv_page',     'default' => 0,         'type' => 'int',    'group' => 'widgets'   ),
			'stars_clr'            => array( 'name' => 'wp_ratesight_stars_clr',   'default' => '#1877F2', 'type' => 'color',  'group' => 'widgets'   ),
			'dark_text'            => array( 'name' => 'wp_ratesight_dark_text',   'default' => 0,         'type' => 'bool',   'group' => 'widgets'   ),
			'dark_text_color'      => array( 'name' => 'wp_ratesight_dark_clr',    'default' => '#666666', 'type' => 'color',  'group' => 'widgets'   ),

			// ── AI SEO Pages settings ─────────────────────────────────────────
			'parent_category'      => array( 'name' => 'ratesight_parent_category',      'default' => 0, 'type' => 'int', 'group' => 'seo_pages' ),
			'rs_page_parent_category' => array( 'name' => 'ratesight_rs_page_parent_cat', 'default' => 0,  'type' => 'int',  'group' => 'seo_pages' ),
			'rs_page_base'            => array( 'name' => 'ratesight_rs_page_base',          'default' => '', 'type' => 'text', 'group' => 'seo_pages' ),
			'link_approved_domains'   => array( 'name' => 'ratesight_link_approved_domains', 'default' => '', 'type' => 'text', 'group' => 'seo_pages' ),
			'link_excluded_domains'   => array( 'name' => 'ratesight_link_excluded_domains', 'default' => '', 'type' => 'text', 'group' => 'seo_pages' ),
			'post_status'          => array( 'name' => 'ratesight_post_status',     'default' => 'publish', 'type' => 'status', 'group' => 'seo_pages' ),
			'post_author'          => array( 'name' => 'ratesight_post_author',     'default' => 1,         'type' => 'int',    'group' => 'seo_pages' ),
			'default_layout'       => array( 'name' => 'ratesight_default_layout',       'default' => 'right-sidebar', 'type' => 'text', 'group' => 'seo_pages' ),
			'default_page_layout'  => array( 'name' => 'ratesight_default_page_layout',  'default' => 'full-width', 'type' => 'text', 'group' => 'seo_pages' ),
			'default_show_title'   => array( 'name' => 'ratesight_default_show_title', 'default' => 1,      'type' => 'bool',   'group' => 'seo_pages' ),
			'log_retention_days'         => array( 'name' => 'ratesight_log_retention',              'default' => 30,  'type' => 'int', 'group' => 'seo_pages' ),
			'performance_retention_days' => array( 'name' => 'ratesight_performance_retention_days', 'default' => 548, 'type' => 'int', 'group' => 'seo_pages' ),

			// Off by default — at ~288 posts/day storing full article payloads
			// would grow the log table by hundreds of MB per month.
			'store_raw_payload'    => array( 'name' => 'ratesight_store_payload',         'default' => 0,            'type' => 'bool',   'group' => 'seo_pages'   ),

			// When enabled, every STATUS_FAILED entry is also written to the PHP
			// error log (WP_DEBUG_LOG destination) for server-level visibility.
			'log_errors_to_wp'     => array( 'name' => 'ratesight_log_errors_to_wp',      'default' => 0,            'type' => 'bool',   'group' => 'seo_pages'   ),

			// Runtime 404 fuzzy-router mode (v3.2.18). 'legacy' = unconstrained slug
			// similarity (pre-3.2.18 behavior, the DEFAULT so upgrading changes
			// nothing); 'same-city-or-hub' = cross-city fuzzy matches are blocked,
			// with a same-service base-hub fallback; 'off' = no fuzzy redirects.
			'fuzzy_mode'           => array( 'name' => 'ratesight_fuzzy_mode',            'default' => 'legacy',     'type' => 'fuzzy_mode', 'group' => 'seo_pages' ),

			// ── GBP CTA / posting settings ────────────────────────────────────
			'gbp_cta_type'     => array( 'name' => 'ratesight_gbp_cta_type',     'default' => 'LEARN_MORE', 'type' => 'text', 'group' => 'connections' ),
			'gbp_post_enabled' => array( 'name' => 'ratesight_gbp_post_enabled', 'default' => 1,            'type' => 'bool', 'group' => 'connections' ),

			// ── Bing Webmaster Tools ──────────────────────────────────────────────
			'bing_api_key'     => array( 'name' => 'ratesight_bing_api_key',      'default' => '', 'type' => 'text', 'group' => 'connections' ),
			'bing_site_url'    => array( 'name' => 'ratesight_bing_site_url',     'default' => '', 'type' => 'text', 'group' => 'connections' ),
			'deepseek_api_key' => array( 'name' => 'ratesight_deepseek_api_key',  'default' => '', 'type' => 'text', 'group' => 'connections' ),

			// ── Reference page (ratesight_page CPT) status ──────────────────
			'page_status'      => array( 'name' => 'ratesight_page_status', 'default' => 'publish', 'type' => 'status', 'group' => 'seo_pages' ),
		);
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	public static function get_all() {
		$values = array();
		foreach ( self::schema() as $key => $def ) {
			$values[ $key ] = get_option( $def['name'], $def['default'] );
		}
		return $values;
	}

	public static function get( string $key ) {
		$def = self::schema()[ $key ] ?? null;
		return $def ? get_option( $def['name'], $def['default'] ) : null;
	}

	public static function option_name( string $key ) {
		return self::schema()[ $key ]['name'] ?? '';
	}

	// -------------------------------------------------------------------------
	// Sanitisation
	// -------------------------------------------------------------------------

	public static function sanitise( $value, string $type ) {
		switch ( $type ) {
			case 'int':    return absint( $value );
			case 'bool':   return $value ? 1 : 0;
			case 'color':  return sanitize_hex_color( $value ) ?? '';
			case 'status':
				return in_array( $value, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $value : 'publish';
			case 'fuzzy_mode':
				if ( in_array( $value, array( 'legacy', 'same-city-or-hub', 'off' ), true ) ) return $value;
				// Absent/invalid input (e.g. ANOTHER form in the same settings group
				// saving — WP passes null for fields it didn't post) must PRESERVE the
				// stored mode, never silently reset a flipped site back to 'legacy'.
				$current = get_option( 'ratesight_fuzzy_mode', 'legacy' );
				return in_array( $current, array( 'legacy', 'same-city-or-hub', 'off' ), true ) ? $current : 'legacy';
			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public static function migrate_legacy() {
		$map = array(
			'maf_vtc_code_id'     => 'wp_ratesight_code_id',
			'maf_vtc_campaign_id' => 'wp_ratesight_campaign_id',
			'maf_vtc_domain_id'   => 'wp_ratesight_domain_id',
			'maf_vtc_rv_page'     => 'wp_ratesight_rv_page',
			'maf_vtc_stars_clr'   => 'wp_ratesight_stars_clr',
			'maf_vtc_dark_text'   => 'wp_ratesight_dark_text',
			'maf_vtc_dark_clr'    => 'wp_ratesight_dark_clr',
		);
		foreach ( $map as $old => $new ) {
			$value = get_option( $old );
			if ( false !== $value ) { update_option( $new, $value ); delete_option( $old ); }
		}
	}

	public static function delete_all() {
		foreach ( self::schema() as $def ) delete_option( $def['name'] );
		delete_option( 'ratesight_db_version' );
	}
}
