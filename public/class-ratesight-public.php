<?php
/**
 * Public-facing functionality.
 *
 * Site-wide script: outputs the exact Ratesight sidebar widget tag when an OID
 * is configured. No toggles, no conditionals — it either loads or it doesn't.
 *
 * Shortcodes: [rs_leave_reviews] and [rs_all_reviews] unchanged.
 *
 * @package    Ratesight
 * @subpackage Ratesight/public
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Public {

	private const WIDGET_BASE = 'https://go.ratesight.com/Widgets/WidgetJS.ashx';

	private ?array $opts = null;

	// ── Fallback SEO title/description rendering ──────────────────────────────
	// Only fires when no recognised SEO plugin is active.
	// Renders _ratesight_meta_title via the document title filter and
	// _ratesight_meta_description as a <meta> tag.
	public static function register_fallback_seo_hooks(): void {
		// Only activate if no known SEO plugin is handling this.
		if ( Ratesight_SEO_Writer::active_plugin() !== 'none' ) return;

		add_filter( 'pre_get_document_title', static function ( string $title ): string {
			if ( ! is_singular() ) return $title;
			$rs_title = get_post_meta( get_the_ID(), '_ratesight_meta_title', true );
			return $rs_title ? (string) $rs_title : $title;
		}, 5 );

		add_action( 'wp_head', static function (): void {
			if ( ! is_singular() ) return;
			$desc = get_post_meta( get_the_ID(), '_ratesight_meta_description', true );
			if ( $desc ) {
				echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
			}
		}, 5 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	public function register_shortcodes(): void {
		add_shortcode( 'rs_leave_reviews', array( $this, 'shortcode_leave_reviews' ) );
		add_shortcode( 'rs_all_reviews',   array( $this, 'shortcode_all_reviews'   ) );
		add_shortcode( 'rs_jobs',          array( $this, 'shortcode_jobs'          ) );
	}

	/**
	 * Output the Ratesight sidebar widget script in the footer.
	 *
	 * Prints the exact tag Ratesight requires:
	 *   <script id="ratesight-sidebar-widget"
	 *           src="https://go.ratesight.com/Widgets/WidgetJS.ashx?typ=10&oid=OID"
	 *           async></script>
	 *
	 * Using wp_footer directly rather than wp_enqueue_script so WordPress does
	 * not append -js to the id attribute, which Ratesight's CDN relies on.
	 */
	/**
	 * Suppress the post title on ratesight_page posts when the per-post or
	 * global "show title" setting is off.
	 *
	 * Uses two layers:
	 *  1. CSS injected into <head> — works for any theme (Divi, Kadence, etc.)
	 *     that renders the title via HTML we can target.
	 *  2. `the_title` filter — returns '' so themes that echo the_title()
	 *     directly also get a blank.
	 *
	 * Only fires on singular ratesight_page views on the front end.
	 */
	public function maybe_suppress_title(): void {
		if ( is_admin() || ! is_singular( 'ratesight_page' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Per-post meta set by Ratesight_Title_Writer (always written).
		$meta = get_post_meta( $post_id, '_ratesight_show_title', true );

		// If meta is missing (e.g. a page created before this field existed),
		// fall back to the global default.
		if ( $meta === '' || $meta === false ) {
			$meta = Ratesight_Options::get( 'default_show_title' ) ? '1' : '0';
		}

		if ( $meta !== '0' ) {
			// title should be visible, but still hide the date
			add_action( 'wp_head', static function (): void {
				echo '<style id="rs-hide-date">'
					. '.post-meta,.entry-meta,.et_pb_post_meta_wrapper,.posted-on,'
					. 'time.entry-date,.meta-date,.post-date{display:none!important;}'
					. '</style>' . "\n";
			} );
			return;
		}

		// Layer 1: CSS. Targets Divi (.entry-title), Kadence, Astra, and most
		// other themes that use standard heading classes for the post title.
		add_action( 'wp_head', static function (): void {
			echo '<style id="rs-hide-title">'
				. '.entry-title,.page-title,.post-title,.et_pb_title_container h1,'
				. 'h1.entry-title,h2.entry-title{display:none!important;}'
				. '</style>' . "\n";
		} );

		// Always hide the post date on ratesight_page posts — these are
		// SEO landing pages, not blog posts, so the date is irrelevant.
		add_action( 'wp_head', static function (): void {
			echo '<style id="rs-hide-date">'
				. '.post-meta,.entry-meta,.et_pb_post_meta_wrapper,.posted-on,'
				. 'time.entry-date,.meta-date,.post-date{display:none!important;}'
				. '</style>' . "\n";
		} );

		// Layer 2: the_title filter. Catches themes that echo get_the_title()
		// directly rather than via a wrapper element we can CSS-target.
		add_filter( 'the_title', static function ( string $title, int $id ) use ( $post_id ): string {
			return ( $id === $post_id && is_singular( 'ratesight_page' ) ) ? '' : $title;
		}, 10, 2 );
	}

	public function enqueue_widgets(): void {
		// Don't load on admin or for logged-in users — gets in the way when editing.
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		$code_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
		if ( $code_id === '' ) {
			return;
		}

		$url = add_query_arg( array(
			'typ' => 10,
			'oid' => $code_id,
		), self::WIDGET_BASE );

		add_action( 'wp_footer', static function () use ( $url ): void {
			if ( ! wp_script_is( 'ratesight-sidebar-widget', 'done' ) ) {
				wp_register_script( 'ratesight-sidebar-widget', esc_url_raw( $url ), array(), null, true );
				wp_enqueue_script( 'ratesight-sidebar-widget' );
			}
		} );
	}

	/**
	 * Inject canonical, Open Graph, and Twitter Card meta tags for ratesight_page
	 * posts. Only fires when no SEO plugin is already handling these tags.
	 *
	 * Hooked to wp_head at priority 5 (before most SEO plugins, which use 1–2).
	 * Bails early if Yoast, RankMath, AIOSEO, or Squirrly are active.
	 */
	public function inject_rs_meta_tags(): void {
		if ( ! is_singular( 'ratesight_page' ) ) return;

		// Bail if a SEO plugin is handling this — they do a better job.
		$active = get_option( 'active_plugins', array() );
		$seo_plugins = array( 'wordpress-seo/wp-seo.php', 'rank-math/rank-math.php', 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'squirrly-seo/squirrly.php' );
		foreach ( $seo_plugins as $plugin ) {
			if ( in_array( $plugin, $active, true ) ) return;
		}

		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		if ( ! $post ) return;

		$canonical   = get_permalink( $post_id );
		$title       = get_post_meta( $post_id, '_yoast_wpseo_title', true )
			?: get_post_meta( $post_id, 'rank_math_title', true )
			?: $post->post_title . ' | ' . get_bloginfo( 'name' );
		$description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
			?: get_post_meta( $post_id, 'rank_math_description', true )
			?: wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 );
		$image       = get_the_post_thumbnail_url( $post_id, 'large' ) ?: '';
		$site_name   = get_bloginfo( 'name' );

		add_action( 'wp_head', static function () use ( $canonical, $title, $description, $image, $site_name ): void {
			// Canonical.
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";

			// Open Graph.
			echo '<meta property="og:type"        content="website" />' . "\n";
			echo '<meta property="og:url"         content="' . esc_url( $canonical ) . '" />' . "\n";
			echo '<meta property="og:title"       content="' . esc_attr( $title ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
			echo '<meta property="og:site_name"   content="' . esc_attr( $site_name ) . '" />' . "\n";
			if ( $image ) {
				echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			}

			// Twitter Card.
			echo '<meta name="twitter:card"        content="' . ( $image ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
			echo '<meta name="twitter:title"       content="' . esc_attr( $title ) . '" />' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
			if ( $image ) {
				echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
			}
		}, 5 );
	}

	/**
	 * Enqueue a per-post stylesheet when one was supplied via the webhook
	 * `custom_css_url` field. Called on wp_enqueue_scripts.
	 */
	public function enqueue_custom_css(): void {
		if ( ! is_singular() ) {
			return;
		}
		$url = (string) get_post_meta( get_the_ID(), '_rs_custom_css_url', true );
		if ( $url === '' ) {
			return;
		}
		wp_enqueue_style( 'rs-custom-css-' . get_the_ID(), $url, array(), null );
	}

	/**
	 * Enqueue shortcode styles early (during wp_enqueue_scripts) so they land
	 * in <head>. Calling wp_enqueue_style inside a shortcode callback fires
	 * after wp_head has already printed, and WordPress silently drops it.
	 */
	public function maybe_enqueue_shortcode_styles(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}
		if ( has_shortcode( $post->post_content, 'rs_leave_reviews' ) ||
		     has_shortcode( $post->post_content, 'rs_all_reviews' ) ) {
			wp_enqueue_style(
				'ratesight-public',
				RATESIGHT_PLUGIN_URL . 'public/css/ratesight-public.css',
				array(), RATESIGHT_VERSION
			);
		}
	}

	// -------------------------------------------------------------------------
	// Shortcodes
	// -------------------------------------------------------------------------

	public function shortcode_leave_reviews(): string {
		$opts    = $this->opts();
		$code_id = trim( $opts['code_id'] );
		if ( $code_id === '' ) {
			return '';
		}

		// Only emit once per page.
		if ( wp_script_is( 'ratesight-carousel-widget', 'done' ) ) {
			return '';
		}
		$src = esc_url( add_query_arg( array( 'typ' => 5, 'oid' => $code_id, 'min' => 4 ), self::WIDGET_BASE ) );
		wp_register_script( 'ratesight-carousel-widget', $src, array(), null, true );
		wp_enqueue_script( 'ratesight-carousel-widget' );
		return '';
	}

	public function shortcode_all_reviews(): string {
		$opts    = $this->opts();
		$code_id = trim( $opts['code_id'] );
		if ( $code_id === '' ) {
			return '';
		}

		// Only emit once per page.
		if ( wp_script_is( 'ratesight-reviews-widget', 'done' ) ) {
			return '';
		}
		$src = esc_url( add_query_arg( array( 'typ' => 6, 'oid' => $code_id, 'min' => 4, 'map' => 'true' ), self::WIDGET_BASE ) );
		wp_register_script( 'ratesight-reviews-widget', $src, array(), null, true );
		wp_enqueue_script( 'ratesight-reviews-widget' );
		return '';
	}

	public function shortcode_jobs(): string {
		$opts    = $this->opts();
		$code_id = trim( $opts['code_id'] );
		if ( $code_id === '' ) {
			return '';
		}

		// Only emit once per page.
		if ( wp_script_is( 'worksight-jobs-widget', 'done' ) ) {
			return '';
		}
		$src = esc_url( 'https://worksight.co/scripts/jobs-page.js?ID=' . rawurlencode( $code_id ) );
		wp_register_script( 'worksight-jobs-widget', $src, array(), null, true );
		wp_enqueue_script( 'worksight-jobs-widget' );
		return '';
	}

	// -------------------------------------------------------------------------

	private function opts(): array {
		if ( null === $this->opts ) {
			$this->opts = Ratesight_Options::get_all();
		}
		return $this->opts;
	}
}
