<?php
/**
 * Writes post layout meta for whichever theme framework is active.
 *
 * Accepted layout values (from webhook payload):
 *   "full-width"    — no sidebar, content spans full width
 *   "right-sidebar" — content left, sidebar right
 *   "left-sidebar"  — sidebar left, content right
 *
 * Detection order mirrors the SEO writer: we write to every recognised
 * framework that is active. If none is found, a generic fallback meta key
 * is written so a custom theme can read it via the provided action hook.
 *
 * Adding support for a custom theme:
 *
 *   add_filter( 'ratesight_layout_meta', function( $meta, $layout, $post_id ) {
 *       // $layout is 'full-width', 'right-sidebar', or 'left-sidebar'
 *       $map = [ 'full-width' => 'no-sidebar', 'right-sidebar' => 'right' ];
 *       if ( isset( $map[ $layout ] ) ) {
 *           update_post_meta( $post_id, '_my_theme_layout', $map[ $layout ] );
 *       }
 *       return $meta; // must return $meta
 *   }, 10, 3 );
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Layout_Writer {

	/** Normalised layout values accepted from the webhook payload. */
	public const VALID_LAYOUTS = array( 'full-width', 'right-sidebar', 'left-sidebar' );

	/**
	 * Write layout meta to a post.
	 *
	 * @param int    $post_id
	 * @param string $layout  One of: full-width | right-sidebar | left-sidebar
	 */
	public function write( int $post_id, string $layout ) {
		if ( $post_id < 1 ) {
			return;
		}

		$layout = $this->normalise( $layout );
		if ( $layout === '' ) {
			return;
		}

		$wrote = false;

		if ( $this->is_genesis() ) {
			$this->write_genesis( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_generatepress() ) {
			$this->write_generatepress( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_astra() ) {
			$this->write_astra( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_oceanwp() ) {
			$this->write_oceanwp( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_kadence() ) {
			$this->write_kadence( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_neve() ) {
			$this->write_neve( $post_id, $layout );
			$wrote = true;
		}

		if ( $this->is_divi() ) {
			$this->write_divi( $post_id, $layout );
			$wrote = true;
		}

		// Always write the generic key so:
		//   (a) custom themes can read it,
		//   (b) the value is preserved even if the theme changes later.
		update_post_meta( $post_id, '_ratesight_layout', $layout );

		/**
		 * Fires after Ratesight has written layout meta.
		 *
		 * Use this hook to add support for themes not listed above.
		 * The filter receives the $wrote flag; return it unchanged.
		 *
		 * @param bool   $wrote    True if at least one known theme was targeted.
		 * @param string $layout   Normalised layout value.
		 * @param int    $post_id
		 */
		apply_filters( 'ratesight_layout_meta', $wrote, $layout, $post_id );
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	private function is_genesis() {
		return defined( 'PARENT_THEME_NAME' ) && strpos( PARENT_THEME_NAME, 'Genesis' ) !== false
			|| function_exists( 'genesis' );
	}

	private function is_generatepress() {
		return defined( 'GP_PREMIUM_VERSION' ) || function_exists( 'generate_setup' )
			|| wp_get_theme()->get_stylesheet() === 'generatepress';
	}

	private function is_astra() {
		return defined( 'ASTRA_THEME_VERSION' ) || function_exists( 'astra_setup' );
	}

	private function is_oceanwp() {
		return defined( 'OCEANWP_THEME_VERSION' ) || function_exists( 'oceanwp_setup' );
	}

	private function is_kadence() {
		return defined( 'KADENCE_VERSION' ) || function_exists( 'kadence_setup' );
	}

	private function is_neve() {
		return defined( 'NEVE_VERSION' ) || function_exists( 'neve_setup' );
	}

	private function is_divi() {
		// ET_BUILDER_VERSION is defined by the Divi Builder plugin and both
		// the Divi and Extra themes. function et_setup_theme() is the Divi
		// theme's setup hook. Checking the stylesheet covers child themes.
		return defined( 'ET_BUILDER_VERSION' )
			|| function_exists( 'et_setup_theme' )
			|| in_array( wp_get_theme()->get_template(), array( 'Divi', 'Extra' ), true );
	}

	// -------------------------------------------------------------------------
	// Writers
	// -------------------------------------------------------------------------

	/**
	 * Genesis — uses `_genesis_layout`.
	 * Values: full-width-content | content-sidebar | sidebar-content
	 */
	private function write_genesis( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'full-width-content',
			'right-sidebar' => 'content-sidebar',
			'left-sidebar'  => 'sidebar-content',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, '_genesis_layout', $map[ $layout ] );
		}
	}

	/**
	 * GeneratePress — uses `_generate-sidebar-layout-meta`.
	 * Values: no-sidebar | right-sidebar | left-sidebar
	 */
	private function write_generatepress( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'no-sidebar',
			'right-sidebar' => 'right-sidebar',
			'left-sidebar'  => 'left-sidebar',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, '_generate-sidebar-layout-meta', $map[ $layout ] );
		}
	}

	/**
	 * Astra — uses `site-sidebar-layout`.
	 * Values: no-sidebar | right-sidebar | left-sidebar
	 */
	private function write_astra( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'no-sidebar',
			'right-sidebar' => 'right-sidebar',
			'left-sidebar'  => 'left-sidebar',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, 'site-sidebar-layout', $map[ $layout ] );
		}
	}

	/**
	 * OceanWP — uses `ocean_sidebar`.
	 * Values: full-width | default (inherits theme default)
	 */
	private function write_oceanwp( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'full-width',
			'right-sidebar' => 'default',
			'left-sidebar'  => 'left-sidebar',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, 'ocean_sidebar', $map[ $layout ] );
		}
	}

	/**
	 * Kadence — uses `_kad_post_layout`.
	 * Values: fullwidth | normal
	 */
	private function write_kadence( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'fullwidth',
			'right-sidebar' => 'normal',
			'left-sidebar'  => 'left',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, '_kad_post_layout', $map[ $layout ] );
		}
	}

	/**
	 * Neve — uses `neve_meta_sidebar`.
	 * Values: off (hidden) | (empty = theme default, sidebar shown)
	 */
	private function write_neve( int $post_id, string $layout ) {
		$value = ( $layout === 'full-width' ) ? 'off' : '';
		update_post_meta( $post_id, 'neve_meta_sidebar', $value );
		// Neve also supports left sidebar via neve_meta_sidebar_position.
		if ( $layout === 'left-sidebar' ) {
			update_post_meta( $post_id, 'neve_meta_sidebar_position', 'left' );
		}
	}

	/**
	 * Divi (and Extra) — uses `_et_pb_page_layout`.
	 *
	 * Divi 4 values: et_full_width_page | et_right_sidebar | et_left_sidebar
	 *
	 * Divi 5: Elegant Themes have confirmed the same post meta keys will be
	 * honoured for backward compatibility. Verify against your Divi 5 build
	 * once it is GA and update this map if needed.
	 *
	 * Note: title visibility is intentionally NOT set here — that is handled
	 * by Ratesight_Title_Writer so it can be controlled independently of layout.
	 */
	private function write_divi( int $post_id, string $layout ) {
		$map = array(
			'full-width'    => 'et_full_width_page',
			'right-sidebar' => 'et_right_sidebar',
			'left-sidebar'  => 'et_left_sidebar',
		);
		if ( isset( $map[ $layout ] ) ) {
			update_post_meta( $post_id, '_et_pb_page_layout', $map[ $layout ] );
		}
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Normalise the incoming layout string.
	 * Accepts underscores or hyphens, and strips leading/trailing whitespace.
	 * Returns '' if the value is not recognised.
	 */
	private function normalise( string $layout ) {
		$layout = strtolower( trim( str_replace( '_', '-', $layout ) ) );
		return in_array( $layout, self::VALID_LAYOUTS, true ) ? $layout : '';
	}

	/**
	 * Return names of all detected theme frameworks (used in admin badge).
	 *
	 * @return string[]
	 */
	public static function detected_themes() {
		$instance  = new self();
		$detected  = array();
		$check_map = array(
			'Genesis'        => 'is_genesis',
			'GeneratePress'  => 'is_generatepress',
			'Astra'          => 'is_astra',
			'OceanWP'        => 'is_oceanwp',
			'Kadence'        => 'is_kadence',
			'Neve'           => 'is_neve',
			'Divi / Extra'   => 'is_divi',
		);
		foreach ( $check_map as $name => $method ) {
			if ( $instance->$method() ) {
				$detected[] = $name;
			}
		}
		return $detected;
	}
}
