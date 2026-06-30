<?php
/**
 * Writes and reads SEO meta title + description for whichever SEO plugin is active.
 *
 * Supported: Yoast SEO, Rank Math, AIOSEO, Squirrly, SEOPress.
 * Fallback:  _ratesight_meta_title / _ratesight_meta_description, rendered via
 *            pre_get_document_title + pre_get_document_title (wp_head) filters.
 *
 * GET and POST use the same read() method — single source of truth.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_SEO_Writer {

	// ── Plugin detection ──────────────────────────────────────────────────────

	public static function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}
	public static function is_rank_math_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}
	public static function is_aioseo_active(): bool {
		return defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) || function_exists( 'aioseo' );
	}
	public static function is_squirrly_active(): bool {
		return defined( 'SQ_VERSION' ) || class_exists( 'SQ_Classes_ObjController' ) || function_exists( 'sq_get_seo_metas' );
	}
	public static function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) || class_exists( 'SeoPress\\SeoPress' );
	}

	/**
	 * Returns the first active SEO plugin slug, or 'none'.
	 * Priority matches detection order in write().
	 */
	public static function active_plugin(): string {
		if ( self::is_yoast_active() )     return 'yoast';
		if ( self::is_rank_math_active() ) return 'rankmath';
		if ( self::is_aioseo_active() )    return 'aioseo';
		if ( self::is_squirrly_active() )  return 'squirrly';
		if ( self::is_seopress_active() )  return 'seopress';
		return 'none';
	}

	/**
	 * Return all detected SEO plugins (for admin UI badge).
	 */
	public static function detected_plugins(): array {
		$detected = array();
		if ( self::is_yoast_active() )     $detected[] = 'Yoast SEO';
		if ( self::is_rank_math_active() ) $detected[] = 'Rank Math';
		if ( self::is_aioseo_active() )    $detected[] = 'AIOSEO';
		if ( self::is_squirrly_active() )  $detected[] = 'Squirrly';
		if ( self::is_seopress_active() )  $detected[] = 'SEOPress';
		return $detected;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Read SEO title + description for a post.
	 * Reads from the same meta keys that write() targets — GET and POST
	 * use this method so they are always in sync.
	 *
	 * Returns: [ 'meta_title' => string, 'meta_description' => string, 'source' => string ]
	 */
	public static function read( int $post_id ): array {
		$title = '';
		$desc  = '';

		if ( self::is_yoast_active() ) {
			$title = (string) get_post_meta( $post_id, '_yoast_wpseo_title',    true );
			$desc  = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}
		if ( self::is_rank_math_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, 'rank_math_title',       true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, 'rank_math_description', true );
		}
		if ( self::is_aioseo_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, '_aioseo_title',       true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, '_aioseo_description', true );
		}
		if ( self::is_squirrly_active() ) {
			$sq = get_post_meta( $post_id, '_squirrly_seo', true );
			if ( is_array( $sq ) || ( is_string( $sq ) && ( $sq = maybe_unserialize( $sq ) ) && is_array( $sq ) ) ) {
				$title = $title ?: (string) ( $sq['seo_title'] ?? $sq['title'] ?? '' );
				$desc  = $desc  ?: (string) ( $sq['seo_desc']  ?? $sq['description'] ?? '' );
			}
		}
		if ( self::is_seopress_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, '_seopress_titles_title', true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, '_seopress_titles_desc',  true );
		}

		// Generic fallback (used when no plugin active).
		if ( $title === '' ) $title = (string) get_post_meta( $post_id, '_ratesight_meta_title',       true );
		if ( $desc  === '' ) $desc  = (string) get_post_meta( $post_id, '_ratesight_meta_description', true );

		return array(
			'meta_title'       => $title,
			'meta_description' => $desc,
			'source'           => self::active_plugin(),
		);
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Write SEO title + description to all active SEO plugins.
	 * Returns array of what was actually stored, for echoing back in POST response.
	 */
	public function write( int $post_id, string $meta_title, string $meta_description ): array {
		if ( $post_id < 1 ) return array( 'written' => false );

		$meta_title       = sanitize_text_field( $meta_title );
		$meta_description = sanitize_textarea_field( $meta_description );
		$wrote            = false;

		if ( self::is_yoast_active() ) {
			update_post_meta( $post_id, '_yoast_wpseo_title',    $meta_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
			$wrote = true;
		}
		if ( self::is_rank_math_active() ) {
			update_post_meta( $post_id, 'rank_math_title',       $meta_title );
			update_post_meta( $post_id, 'rank_math_description', $meta_description );
			$wrote = true;
		}
		if ( self::is_aioseo_active() ) {
			update_post_meta( $post_id, '_aioseo_title',       $meta_title );
			update_post_meta( $post_id, '_aioseo_description', $meta_description );
			$wrote = true;
		}
		if ( self::is_squirrly_active() ) {
			$existing_raw = get_post_meta( $post_id, '_squirrly_seo', true );
			$existing     = array();
			if ( ! empty( $existing_raw ) ) {
				$decoded  = is_array( $existing_raw ) ? $existing_raw : maybe_unserialize( $existing_raw );
				$existing = is_array( $decoded ) ? $decoded : array();
			}
			$existing['seo_title']   = $meta_title;
			$existing['seo_desc']    = $meta_description;
			$existing['title']       = $meta_title;
			$existing['description'] = $meta_description;
			update_post_meta( $post_id, '_squirrly_seo', $existing );
			$wrote = true;
		}
		if ( self::is_seopress_active() ) {
			update_post_meta( $post_id, '_seopress_titles_title', $meta_title );
			update_post_meta( $post_id, '_seopress_titles_desc',  $meta_description );
			$wrote = true;
		}

		// Fallback: store in generic meta. Rendered via pre_get_document_title
		// filter registered in Ratesight_Public when no SEO plugin is active.
		if ( ! $wrote ) {
			update_post_meta( $post_id, '_ratesight_meta_title',       $meta_title );
			update_post_meta( $post_id, '_ratesight_meta_description',  $meta_description );
		}

		// Read back what was just stored — caller uses this to verify without a second GET.
		return self::read( $post_id );
	}
}
