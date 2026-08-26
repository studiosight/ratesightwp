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
 * SQUIRRLY WINS THE SERVED SNIPPET (3.2.20). Squirrly does not just add meta
 * tags: it output-buffers the whole page, strips every existing <title> and
 * description, and injects its own. So on a site running Squirrly ALONGSIDE
 * Yoast/Rank Math, the value a visitor and Google see is Squirrly's, not the
 * one the "primary" plugin stored. active_plugin() and read() therefore put
 * Squirrly FIRST — the plugin that renders is the plugin that counts. Probed
 * live 2026-08-26: trekmovers.ca and 4mrticket.com both report yoast/rankmath
 * yet serve a Squirrly-generated snippet. write() is unaffected: it has always
 * written to EVERY active SEO plugin, so those sites keep their Yoast/Rank
 * Math values in sync too.
 *
 * Squirrly's own storage is handled by Ratesight_Squirrly — see that file for
 * why `_squirrly_seo` (what we wrote before 3.2.20) was never served.
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
		return Ratesight_Squirrly::is_active();
	}
	public static function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) || class_exists( 'SeoPress\\SeoPress' );
	}

	/**
	 * Returns the SEO plugin that OWNS THE SERVED SNIPPET, or 'none'.
	 * Squirrly first: it rewrites the finished HTML, so wherever it is active
	 * it overrides whatever another SEO plugin printed (see the file header).
	 */
	public static function active_plugin(): string {
		if ( self::is_squirrly_active() )  return 'squirrly';
		if ( self::is_yoast_active() )     return 'yoast';
		if ( self::is_rank_math_active() ) return 'rankmath';
		if ( self::is_aioseo_active() )    return 'aioseo';
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

		// Squirrly first — it serves the snippet wherever it is active.
		if ( self::is_squirrly_active() ) {
			$sq    = Ratesight_Squirrly::read( $post_id );
			$title = $sq['meta_title'];
			$desc  = $sq['meta_description'];
		}
		if ( self::is_yoast_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, '_yoast_wpseo_title',    true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}
		if ( self::is_rank_math_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, 'rank_math_title',       true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, 'rank_math_description', true );
		}
		if ( self::is_aioseo_active() ) {
			$title = $title ?: (string) get_post_meta( $post_id, '_aioseo_title',       true );
			$desc  = $desc  ?: (string) get_post_meta( $post_id, '_aioseo_description', true );
		}
		// LEGACY READ ONLY. `_squirrly_seo` is a key we invented and Squirrly
		// never reads. It stays in the read path so a value written by an older
		// build is still visible (and so re-applying overwrites it in the real
		// store); nothing writes it any more.
		if ( self::is_squirrly_active() && ( $title === '' || $desc === '' ) ) {
			$legacy = get_post_meta( $post_id, '_squirrly_seo', true );
			if ( is_array( $legacy ) || ( is_string( $legacy ) && ( $legacy = maybe_unserialize( $legacy ) ) && is_array( $legacy ) ) ) {
				$title = $title ?: (string) ( $legacy['seo_title'] ?? $legacy['title'] ?? '' );
				$desc  = $desc  ?: (string) ( $legacy['seo_desc']  ?? $legacy['description'] ?? '' );
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
		$squirrly         = null; // per-layer detail, echoed back so a caller never has to assume

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
		// Squirrly: write the store Squirrly SERVES (its qss row), plus its
		// documented `_sq_title`/`_sq_description` fallback. The old
		// `_squirrly_seo` array is gone — Squirrly never read it, so every
		// write to it was inert. See Ratesight_Squirrly.
		if ( self::is_squirrly_active() ) {
			$squirrly = Ratesight_Squirrly::write( $post_id, $meta_title, $meta_description );
			$wrote    = true;
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
		// NOTE FOR CALLERS: this is a STORE read-back, not proof the page serves
		// the value. Only fetching the live URL proves that.
		$stored = self::read( $post_id );
		if ( $squirrly !== null ) {
			$stored['squirrly'] = $squirrly;
		}
		return $stored;
	}
}
