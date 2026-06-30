<?php
/**
 * Registers the ratesight_page custom post type.
 *
 * Pages created via the webhook are stored as 'ratesight_page' rather than
 * WordPress's native 'page' type. This means if the plugin is deactivated or
 * removed, all webhook-created pages return 404 automatically — the protection
 * is structural, requiring no license checks or heartbeat calls.
 *
 * URLs are filtered to remove the slug prefix so they look like native pages.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_CPT {

	/** Called via add_action( 'init', $cpt, 'register' ) */
	public function register() {
		self::do_register();
	}

	/** Also callable statically for on-demand registration before wp_insert_post. */
	public static function do_register() {
		if ( post_type_exists( 'ratesight_page' ) ) {
			return;
		}

		register_post_type( 'ratesight_page', array(
			'labels' => array(
				'name'               => 'RS Pages',
				'singular_name'      => 'RS Page',
				'add_new'            => 'Add New RS Page',
				'add_new_item'       => 'Add New RS Page',
				'edit_item'          => 'Edit RS Page',
				'new_item'           => 'New RS Page',
				'view_item'          => 'View RS Page',
				'view_items'         => 'View RS Pages',
				'search_items'       => 'Search RS Pages',
				'not_found'          => 'No RS pages found.',
				'not_found_in_trash' => 'No RS pages found in Trash.',
				'all_items'          => 'All RS Pages',
				'menu_name'          => 'RS Pages',
			),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'hierarchical'        => true,
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => 'rs-page',
				'with_front' => false,
				'pages'      => true,
				'feeds'      => false,
			),
			'query_var'           => true,
			'menu_icon'           => 'dashicons-admin-page',
			'menu_position'       => null,
			'supports'            => array(
				'title',
				'editor',
				'thumbnail',
				'excerpt',
				'page-attributes',
				'custom-fields',
				'revisions',
			),
			'capability_type'     => 'page',
		) );

		// ── RS Page Category taxonomy ─────────────────────────────────────────
		register_taxonomy( 'rs_category', 'ratesight_page', array(
			'label'             => 'RS Categories',
			'labels'            => array(
				'name'          => 'RS Categories',
				'singular_name' => 'RS Category',
				'add_new_item'  => 'Add New RS Category',
				'edit_item'     => 'Edit RS Category',
				'search_items'  => 'Search RS Categories',
				'all_items'     => 'All RS Categories',
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'rs-category', 'with_front' => false ),
		) );

		// Strip rs-page/ from permalink output so URLs look like native pages.
		add_filter( 'post_type_link', array( __CLASS__, 'filter_permalink' ), 10, 2 );

		// Route ratesight_page URLs via request filter (efficiency) and
		// template_redirect fallback (reliability across all permalink structures).
		add_filter( 'request',            array( __CLASS__, 'filter_request'              ) );
		add_action( 'template_redirect',  array( __CLASS__, 'template_redirect_fallback'  ), 1 );

		// Template: if the theme has no single-ratesight_page.php, use ours.
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );

		// ── Sitemap: prevent a standalone ratesight_page section ─────────────
		// class-ratesight-sitemap.php handles injection of sitemap-rs-pages.xml
		// into every sitemap index. These filters stop each system from also
		// generating its own ratesight_page section, which would duplicate URLs.

		// WP core sitemap.
		add_filter( 'wp_sitemaps_post_types', static function ( array $types ) {
			unset( $types['ratesight_page'] );
			return $types;
		} );

		// Yoast SEO.
		add_filter( 'wpseo_sitemap_exclude_post_type', static function ( bool $excluded, string $post_type ) {
			return $post_type === 'ratesight_page' ? true : $excluded;
		}, 10, 2 );

		// Rank Math.
		add_filter( 'rank_math/sitemap/exclude_post_type', static function ( bool $exclude, string $type ) {
			return $type === 'ratesight_page' ? true : $exclude;
		}, 10, 2 );

		// ── Flush rewrite rules when config version changes ───────────────────
		// Version bumped to 3.7 to register the new sitemap-rs-pages.xml rule
		// (class-ratesight-sitemap.php) before flush_rewrite_rules() runs.
		Ratesight_Sitemap::register_rewrite_rule();
		if ( get_option( 'ratesight_cpt_flushed' ) !== '4.0' ) {
			flush_rewrite_rules( false );
			update_option( 'ratesight_cpt_flushed', '4.0' );
		}
	}

	/**
	 * Primary route: modify query vars before WP_Query runs.
	 * The `request` filter is the correct place to override query vars — it's
	 * designed for this and works across all permalink structures.
	 */
	public static function filter_permalink( string $link, \WP_Post $post ): string {
		if ( $post->post_type !== 'ratesight_page' ) return $link;
		$base        = trim( (string) Ratesight_Options::get( 'rs_page_base' ), '/' );
		$replacement = $base !== '' ? '/' . $base . '/' : '/';
		return str_replace( '/rs-page/', $replacement, $link );
	}

	private static function strip_base_prefix( string $path ): ?string {
		$base = trim( (string) Ratesight_Options::get( 'rs_page_base' ), '/' );
		if ( $base === '' ) return $path;
		if ( str_starts_with( $path, $base . '/' ) ) return substr( $path, strlen( $base ) + 1 );
		if ( $path === $base ) return null;
		return null;
	}

	public static function filter_request( array $query_vars ): array {
		if ( is_admin() || ! Ratesight_License::is_valid() ) return $query_vars;

		$slug = $query_vars['pagename'] ?? $query_vars['name'] ?? null;
		if ( ! $slug ) {
			$path = trim( wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
			if ( ! $path || str_contains( $path, 'wp-admin' ) || str_contains( $path, 'wp-json' ) ) return $query_vars;
			$slug = $path;
		}

		$slug = self::strip_base_prefix( $slug );
		if ( $slug === null ) return $query_vars;
		$slug = trim( $slug, '/' );
		if ( str_contains( $slug, '/' ) ) $slug = substr( $slug, strrpos( $slug, '/' ) + 1 );
		$slug = sanitize_title( $slug );
		if ( ! $slug ) return $query_vars;

		$native = get_page_by_path( $slug );
		if ( $native instanceof \WP_Post && $native->post_status === 'publish' ) return $query_vars;

		global $wpdb;
		$post_id = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'ratesight_page' AND post_status = 'publish' LIMIT 1",
			$slug
		) );
		if ( ! $post_id ) return $query_vars;

		unset( $query_vars['pagename'], $query_vars['name'], $query_vars['page'] );
		$query_vars['post_type'] = 'ratesight_page';
		$query_vars['p']         = $post_id;
		return $query_vars;
	}

	public static function template_redirect_fallback(): void {
		if ( ! is_404() || is_admin() || ! Ratesight_License::is_valid() ) return;

		$path = trim( wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		$path = self::strip_base_prefix( $path );
		if ( $path === null ) return;

		$slug = sanitize_title( basename( $path ) ?: $path );
		if ( ! $slug ) return;

		global $wpdb, $wp_query;
		$post_id = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'ratesight_page' AND post_status = 'publish' LIMIT 1",
			$slug
		) );
		if ( ! $post_id ) return;

		$wp_query->query( array( 'p' => $post_id, 'post_type' => 'ratesight_page' ) );
		if ( ! $wp_query->have_posts() ) return;

		$wp_query->is_404      = false;
		$wp_query->is_single   = true;
		$wp_query->is_singular = true;
		$wp_query->is_page     = false;

		$GLOBALS['post'] = $wp_query->post;
		setup_postdata( $wp_query->post );
		status_header( 200 );

		$template = locate_template( array( 'single-ratesight_page.php', 'single.php', 'index.php' ) );
		if ( ! $template ) {
			$template = plugin_dir_path( dirname( __FILE__ ) ) . 'public/templates/single-ratesight_page.php';
		}
		include $template;
		exit;
	}

	/** @deprecated stubs */
	public static function add_query_vars( array $vars ): array { return $vars; }
	public static function pre_get_posts_routing( \WP_Query $q ): void {}
	public static function handle_404_fallback(): void {}
	public static function handle_request( \WP $wp ): void {}

		public static function template_include( string $template ): string {
		if ( ! is_singular( 'ratesight_page' ) ) {
			return $template;
		}

		// Check if the theme already provides a specific template — respect it.
		$theme_template = locate_template( array(
			'single-ratesight_page.php',
			'single.php',
		) );

		if ( $theme_template ) {
			return $theme_template;
		}

		// Fall back to the plugin's own template.
		$plugin_template = plugin_dir_path( dirname( __FILE__ ) ) . 'public/templates/single-ratesight_page.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}
}
