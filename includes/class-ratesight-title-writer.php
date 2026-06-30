<?php
/**
 * Controls whether the post/page title is shown for a given post.
 *
 * Different themes handle title visibility in completely different ways:
 * some show it via the template unconditionally, some use post meta to
 * toggle it per-post. This class writes to every recognised framework
 * that supports per-post title control.
 *
 * Accepted payload value:
 *   "show_title": true   — show the title (default)
 *   "show_title": false  — hide the title
 *
 * Adding support for a custom theme:
 *
 *   add_action( 'ratesight_title_visibility', function( $post_id, $show ) {
 *       update_post_meta( $post_id, '_my_theme_hide_title', $show ? '0' : '1' );
 *   }, 10, 2 );
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Title_Writer {

	/**
	 * Write title visibility meta for the given post.
	 *
	 * @param int  $post_id
	 * @param bool $show  True = show title, false = hide title.
	 */
	public function write( int $post_id, bool $show ) {
		if ( $post_id < 1 ) {
			return;
		}

		if ( $this->is_divi() ) {
			$this->write_divi( $post_id, $show );
		}

		if ( $this->is_genesis() ) {
			$this->write_genesis( $post_id, $show );
		}

		if ( $this->is_kadence() ) {
			$this->write_kadence( $post_id, $show );
		}

		if ( $this->is_beaver_builder() ) {
			$this->write_beaver_builder( $post_id, $show );
		}

		// Always write the generic key as a fallback for:
		//   (a) unsupported themes that want to read it via the action hook,
		//   (b) preserving the intended value if the theme changes later.
		update_post_meta( $post_id, '_ratesight_show_title', $show ? '1' : '0' );

		/**
		 * Fires after Ratesight has written title visibility meta.
		 *
		 * Use this action to add support for any theme not listed above.
		 *
		 * @param int  $post_id
		 * @param bool $show  True = show title.
		 */
		do_action( 'ratesight_title_visibility', $post_id, $show );
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	private function is_divi() {
		return defined( 'ET_BUILDER_VERSION' )
			|| function_exists( 'et_setup_theme' )
			|| in_array( wp_get_theme()->get_template(), array( 'Divi', 'Extra' ), true );
	}

	private function is_genesis() {
		return function_exists( 'genesis' )
			|| ( defined( 'PARENT_THEME_NAME' ) && strpos( PARENT_THEME_NAME, 'Genesis' ) !== false );
	}

	private function is_kadence() {
		return defined( 'KADENCE_VERSION' ) || function_exists( 'kadence_setup' );
	}

	private function is_beaver_builder() {
		return defined( 'FL_BUILDER_VERSION' ) || class_exists( 'FLBuilder' );
	}

	// -------------------------------------------------------------------------
	// Writers
	// -------------------------------------------------------------------------

	/**
	 * Divi / Extra
	 *
	 * `_et_pb_show_title` controls whether Divi renders the title above the
	 * builder content. Divi 5 is expected to honour the same key.
	 *
	 * Values: 'on' (show) | 'off' (hide)
	 */
	private function write_divi( int $post_id, bool $show ) {
		update_post_meta( $post_id, '_et_pb_show_title', $show ? 'on' : 'off' );
	}

	/**
	 * Genesis Framework
	 *
	 * Genesis uses a "hide" flag rather than a "show" flag, so the logic
	 * is inverted: setting `_genesis_hide_page_title` to '1' hides the title;
	 * deleting it (or setting it to '0') shows the title.
	 */
	private function write_genesis( int $post_id, bool $show ) {
		if ( $show ) {
			delete_post_meta( $post_id, '_genesis_hide_page_title' );
		} else {
			update_post_meta( $post_id, '_genesis_hide_page_title', '1' );
		}
	}

	/**
	 * Kadence Theme
	 *
	 * `_kad_post_title` controls title display per-post.
	 * Values: 'above' (show, above content) | 'hide' (hidden)
	 *
	 * Note: Kadence also supports 'within' (inside the hero/banner area).
	 * We default to 'above' for the shown state as it matches standard
	 * WordPress post behaviour most closely.
	 */
	private function write_kadence( int $post_id, bool $show ) {
		update_post_meta( $post_id, '_kad_post_title', $show ? 'above' : 'hide' );
	}

	/**
	 * Beaver Builder (BB Theme)
	 *
	 * Beaver Builder Theme uses `_fl_page_title_hidden` to suppress the
	 * title rendered by the theme header template.
	 * Values: '1' (hidden) | '' / not set (shown)
	 */
	private function write_beaver_builder( int $post_id, bool $show ) {
		if ( $show ) {
			delete_post_meta( $post_id, '_fl_page_title_hidden' );
		} else {
			update_post_meta( $post_id, '_fl_page_title_hidden', '1' );
		}
	}

	// -------------------------------------------------------------------------
	// Admin badge helper
	// -------------------------------------------------------------------------

	/**
	 * Return names of all detected frameworks that support per-post title control.
	 *
	 * @return string[]
	 */
	public static function detected_themes() {
		$instance  = new self();
		$check_map = array(
			'Divi / Extra'    => 'is_divi',
			'Genesis'         => 'is_genesis',
			'Kadence'         => 'is_kadence',
			'Beaver Builder'  => 'is_beaver_builder',
		);
		$detected = array();
		foreach ( $check_map as $name => $method ) {
			if ( $instance->$method() ) {
				$detected[] = $name;
			}
		}
		return $detected;
	}
}
