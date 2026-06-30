<?php
/**
 * Generates and serves /sitemap-rs-pages.xml and injects it into sitemap
 * indexes and robots.txt across all major SEO plugins.
 *
 * Per-plugin strategy
 * ───────────────────
 * Squirrly    : sq_option_sq_sitemap filter — Squirrly reads its own sq_sitemap
 *               option for BOTH the visual sitemap index AND robots.txt
 *               generation. Adding our entry here handles both in one place,
 *               with no output buffering or caching conflicts.
 *               The same filter is registered early in ratesight.php (file
 *               level, before class loading) so it is in place even on
 *               robots.txt requests that fire at plugin-load time.
 *
 * Yoast SEO   : wpseo_sitemap_index filter — adds our entry to the visual
 *               sitemap index.
 *
 * Everything else (Rank Math, AIOSEO, WP core, no plugin):
 *               WordPress robots_txt filter appends our Sitemap: directive.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Sitemap {

	const QUERY_VAR = 'rs_sitemap';
	const FILENAME  = 'sitemap-rs-pages.xml';

	// ── Hook registration ─────────────────────────────────────────────────────

	public function register_hooks() {
		add_action( 'init',                    [ $this, 'register_rewrite'          ]      );
		add_filter( 'query_vars',              [ $this, 'add_query_var'             ]      );
		add_action( 'template_redirect',       [ $this, 'maybe_serve'              ], 1    );
		add_filter( 'robots_txt',              [ $this, 'add_to_robots'            ], 10, 2 );
		add_filter( 'wpseo_sitemap_index',     [ $this, 'inject_yoast_index'       ]       );
		add_filter( 'sq_option_sq_sitemap',    [ $this, 'inject_squirrly_option'   ]       );
	}

	// ── Rewrite ───────────────────────────────────────────────────────────────

	public function register_rewrite() {
		self::register_rewrite_rule();
	}

	public static function register_rewrite_rule() {
		add_rewrite_rule(
			'^' . preg_quote( self::FILENAME, '/' ) . '$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public function add_query_var( array $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// ── Serve /sitemap-rs-pages.xml ───────────────────────────────────────────

	public function maybe_serve() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! Ratesight_License::is_valid() ) {
			$this->output_xml( [] );
			exit;
		}

		$posts = get_posts( [
			'post_type'      => 'ratesight_page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$this->output_xml( $posts );
		exit;
	}

	private function output_xml( array $posts ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $posts as $post ) {
			$url      = get_permalink( $post );
			$modified = get_the_modified_date( 'Y-m-d', $post );
			if ( ! $url ) {
				continue;
			}
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
			if ( $modified ) {
				echo "\t\t<lastmod>" . esc_html( $modified ) . "</lastmod>\n";
			}
			echo "\t\t<changefreq>monthly</changefreq>\n";
			echo "\t\t<priority>0.8</priority>\n";
			echo "\t</url>\n";
		}

		echo '</urlset>';
	}

	// ── Squirrly ──────────────────────────────────────────────────────────────

	/**
	 * Inject our sitemap into Squirrly's sq_sitemap option.
	 *
	 * Squirrly calls apply_filters('sq_option_sq_sitemap', $value) every time
	 * it reads its sitemap list. That list drives both the visual sitemap index
	 * (sitemap.xml) and the Sitemap: lines in robots.txt, so one filter covers
	 * both without any output buffering or caching conflicts.
	 *
	 * Format: [ 'key' => [ 'filename.xml', status ] ]  status 1 = include.
	 *
	 * @param mixed $sitemap_list
	 */
	public function inject_squirrly_option( $sitemap_list ) {
		$sitemap_list = (array) $sitemap_list;

		if ( ! $this->has_published_pages() ) {
			return $sitemap_list;
		}

		$sitemap_list['sitemap-rs-pages'] = [ self::FILENAME, 1 ];

		return $sitemap_list;
	}

	// ── Yoast SEO ─────────────────────────────────────────────────────────────

	/**
	 * Inject via the documented wpseo_sitemap_index filter.
	 */
	public function inject_yoast_index( string $index ) {
		if ( ! $this->has_published_pages() ) {
			return $index;
		}

		// Guard: don't add a second entry if already present (e.g. called twice).
		if ( strpos( $index, self::FILENAME ) !== false ) {
			return $index;
		}

		$loc  = esc_url( home_url( '/' . self::FILENAME ) );
		$last = $this->last_modified_date();

		$index .= "\t<sitemap>\n\t\t<loc>{$loc}</loc>\n";
		if ( $last ) {
			$index .= "\t\t<lastmod>{$last}</lastmod>\n";
		}
		$index .= "\t</sitemap>\n";

		return $index;
	}

	// ── Squirrly sitemap intercept ────────────────────────────────────────────

	/**
	 * Squirrly intercept handler — do_feed_sitemap-rs-pages.
	 *
	 * Squirrly matches our filename in initSitemap() and fires
	 * do_action('do_feed_{type}') inside feedRequest() before rendering.
	 * We hook here at priority 1 to serve our own XML instead.
	 *
	 * Ensures ratesight_page CPT is registered before querying, because the
	 * license gate in ratesight.php may have returned early without running
	 * (new Ratesight())->run(), leaving the CPT unregistered. Calling
	 * get_permalink() on an unregistered CPT causes a PHP 8 TypeError.
	 */
	public function serve_squirrly() {
		// Guarantee CPT is registered so get_permalink() doesn't fatal.
		if ( ! post_type_exists( 'ratesight_page' ) ) {
			Ratesight_CPT::do_register();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
		}

		// TODO: re-enable license enforcement once testing is complete.
		if ( false && ! Ratesight_License::is_valid() ) {
			$this->output_xml( [] );
			exit;
		}

		$posts = get_posts( [
			'post_type'      => 'ratesight_page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$this->output_xml( $posts );
		exit;
	}

	// ── Squirrly robots.txt ───────────────────────────────────────────────────

	/**
	 * Squirrly sq_custom_robots filter — fires at the end of generateRobots().
	 *
	 * Uses get_option('home') instead of home_url() because this fires at
	 * plugin-load time (before plugins_loaded) where the home_url filter chain
	 * may not be fully safe. get_option('home') is always available from the
	 * WordPress options cache which is populated before plugins load.
	 *
	 * No strict string type hint to avoid PHP 8 TypeErrors if an upstream
	 * sq_custom_robots callback returns an unexpected type.
	 */
	public function add_to_squirrly_robots( $robots ) {
		$robots = (string) $robots;

		if ( ! $this->has_published_pages() ) {
			return $robots;
		}

		// Don't duplicate if sq_option_sq_sitemap already added it.
		if ( strpos( $robots, self::FILENAME ) !== false ) {
			return $robots;
		}

		$home = (string) get_option( 'home' );
		if ( ! $home ) {
			return $robots;
		}

		$robots .= "\nSitemap: " . trailingslashit( $home ) . self::FILENAME . "\n";

		return $robots;
	}

	// ── robots.txt (non-Squirrly) ─────────────────────────────────────────────

	/**
	 * WordPress standard robots_txt filter.
	 * Fires for Rank Math, AIOSEO, WP core, and any plugin that uses it.
	 * Squirrly bypasses this entirely and is handled via sq_option_sq_sitemap.
	 */
	public function add_to_robots( string $output, $public ) {
		if ( ! $public || ! $this->has_published_pages() ) {
			return $output;
		}

		if ( strpos( $output, self::FILENAME ) !== false ) {
			return $output;
		}

		$output .= "\nSitemap: " . home_url( '/' . self::FILENAME ) . "\n";

		return $output;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function has_published_pages() {
		$counts = wp_count_posts( 'ratesight_page' );
		return isset( $counts->publish ) && (int) $counts->publish > 0;
	}

	private function last_modified_date() {
		$posts = get_posts( [
			'post_type'      => 'ratesight_page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );

		if ( empty( $posts ) ) {
			return '';
		}

		return (string) get_the_modified_date( 'Y-m-d\TH:i:sP', $posts[0] );
	}
}
