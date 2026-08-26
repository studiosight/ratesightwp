<?php
/**
 * Squirrly SEO storage adapter — write the value Squirrly actually SERVES.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Until 3.3.1 our Squirrly support wrote a `_squirrly_seo` post meta array
 * (Ratesight_SEO_Writer) and a `_sq_post_meta` array (Ratesight_Page_API).
 * Squirrly SEO reads NEITHER key. Both writes persisted, both read back
 * clean, and neither ever changed one character of the served page. Every
 * `verified:true` on a Squirrly site was self-verification of our own store.
 * Observed 2026-08-26 on drmelindasilva.com (Squirrly 14.2.3): 6 rewritten
 * posts, 0/6 served; /coolsculpting-chula-vista/ served a post_title-derived
 * snippet while the plugin reported our stored value back to us.
 *
 * WHAT SQUIRRLY 14.x ACTUALLY READS (source-verified against 14.2.3)
 * -----------------------------------------------------------------
 * 1. `{$wpdb->prefix}qss` — Squirrly's own table (`_SQ_DB_` = 'qss'), one row
 *    per URL hash, column `seo` holding a serialised SQ_Models_Domain_Sq.
 *    SQ_Models_Domain_Post::getSq() loads it via SQ_Models_Qss::getSqSeo().
 *    THIS IS THE VALUE THE FRONT END SERVES: SQ_Models_Services_Title's
 *    `sq_title` filter emits `$post->sq->title`, and SQ_Models_Frontend
 *    output-buffers the page, strips the theme's <title>, and injects it.
 * 2. `_sq_title` / `_sq_description` post meta — SQ_Models_Domain_Sq::getTitle()
 *    and ::getDescription() fall back to these ("custom values" import path),
 *    but ONLY while the domain's own title is still empty.
 *
 * The order matters: when the qss row's title is empty AND Squirrly's
 * `sq_auto_pattern` option is on, getSq() fills the title from the post-type
 * PATTERN before anything can consult `_sq_title`. That is exactly the
 * post_title-derived snippet we observed. So post meta ALONE is not enough —
 * the qss row is the authoritative write, and post meta is the fallback for
 * installs where the qss write is unavailable.
 *
 * WHAT THIS ADAPTER DOES NOT DO
 * -----------------------------
 * It does NOT hook `sq_title` / `sq_description` to force our value at render
 * time. A permanent render-time override would silently win over a human's
 * later edit in Squirrly's own snippet editor, forever, with no trace. We
 * write the field Squirrly serves and then let last-writer-wins apply, the
 * same as every other SEO plugin we support. Where a write still does not
 * reach the served page, the DASHBOARD says so (served verification) instead
 * of this plugin hiding it.
 *
 * SAFE WHEN SQUIRRLY IS ABSENT: every method is guarded by is_active() and
 * every call into Squirrly's internals is class/method-checked and wrapped,
 * so a Squirrly upgrade that moves its internals degrades to the post-meta
 * path instead of fatalling a client's site.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Squirrly {

	/** Squirrly's documented per-post custom-value keys (its import path reads these). */
	const META_TITLE = '_sq_title';
	const META_DESC  = '_sq_description';

	/**
	 * Is Squirrly SEO running on this site?
	 *
	 * Kept identical to Ratesight_SEO_Writer::is_squirrly_active() so the two
	 * can never disagree about which store to use.
	 */
	public static function is_active(): bool {
		return defined( 'SQ_VERSION' ) || class_exists( 'SQ_Classes_ObjController' ) || function_exists( 'sq_get_seo_metas' );
	}

	/**
	 * Are Squirrly's own models reachable, i.e. can we write the row the front
	 * end reads? False on a Squirrly build whose internals moved — callers then
	 * still get the post-meta write, and honestly report that the canonical
	 * store was not reached.
	 */
	public static function has_native_store(): bool {
		return class_exists( 'SQ_Classes_ObjController' )
			&& method_exists( 'SQ_Classes_ObjController', 'getClass' )
			&& class_exists( 'SQ_Models_Qss' )
			&& class_exists( 'SQ_Models_Frontend' );
	}

	/**
	 * Read the SEO title + description Squirrly would serve for a post.
	 *
	 * qss row first (what the front end reads), post meta second (what Squirrly
	 * falls back to). Returns raw stored values — no pattern expansion, because
	 * a pattern is not a value anyone wrote.
	 *
	 * @return array{meta_title:string,meta_description:string,store:string}
	 *         store: 'qss' | 'postmeta' | 'none'
	 */
	public static function read( int $post_id ): array {
		$title = '';
		$desc  = '';
		$store = 'none';

		if ( $post_id > 0 && self::has_native_store() ) {
			try {
				$sq = self::stored_sq( $post_id );
				if ( $sq !== null ) {
					$t = (string) ( $sq->title ?? '' );
					$d = (string) ( $sq->description ?? '' );
					if ( $t !== '' || $d !== '' ) {
						$title = $t;
						$desc  = $d;
						$store = 'qss';
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Squirrly internals moved — fall through to post meta.
			}
		}

		if ( $post_id > 0 && ( $title === '' || $desc === '' ) ) {
			$meta_title = (string) get_post_meta( $post_id, self::META_TITLE, true );
			$meta_desc  = (string) get_post_meta( $post_id, self::META_DESC,  true );
			if ( $title === '' && $meta_title !== '' ) {
				$title = $meta_title;
				if ( $store === 'none' ) $store = 'postmeta';
			}
			if ( $desc === '' && $meta_desc !== '' ) {
				$desc = $meta_desc;
				if ( $store === 'none' ) $store = 'postmeta';
			}
		}

		return array(
			'meta_title'       => $title,
			'meta_description' => $desc,
			'store'            => $store,
		);
	}

	/**
	 * Write the SEO title + description into the store Squirrly serves.
	 *
	 * Both layers are attempted; the return value says exactly which landed, so
	 * a caller never has to assume. `qss` false with `postmeta` true means the
	 * write is only as good as Squirrly's fallback path (it will be overridden
	 * by a post-type pattern if one is configured) — report it, do not round it
	 * up to success.
	 *
	 * @return array{qss:bool,postmeta:bool,native:bool,note:string}
	 */
	public static function write( int $post_id, string $meta_title, string $meta_description ): array {
		$result = array(
			'qss'      => false,
			'postmeta' => false,
			'native'   => self::has_native_store(),
			'note'     => '',
		);

		if ( $post_id < 1 || ! self::is_active() ) {
			$result['note'] = 'squirrly not active — nothing written';
			return $result;
		}

		// Layer 2 first: post meta is unconditional and cannot fail on a
		// Squirrly internals change, so the value is at least recorded where
		// Squirrly's own fallback reads it.
		update_post_meta( $post_id, self::META_TITLE, $meta_title );
		update_post_meta( $post_id, self::META_DESC,  $meta_description );
		$result['postmeta'] = true;

		// Layer 1: the row the front end actually reads.
		if ( $result['native'] ) {
			try {
				$result['qss'] = self::write_qss( $post_id, $meta_title, $meta_description );
				$result['note'] = $result['qss']
					? 'wrote squirrly qss row (served store) + _sq_title/_sq_description'
					: 'squirrly qss row write did not verify — only _sq_title/_sq_description were stored';
			} catch ( \Throwable $e ) {
				$result['note'] = 'squirrly qss row unavailable (' . $e->getMessage() . ') — only _sq_title/_sq_description were stored';
			}
		} else {
			$result['note'] = 'squirrly models not reachable — only _sq_title/_sq_description were stored';
		}

		return $result;
	}

	// ── Squirrly internals (all guarded) ──────────────────────────────────────

	/**
	 * Squirrly's domain post for a post ID: carries the url_hash its qss row is
	 * keyed by, plus the URL/post_type/term fields updateSqSeo() persists.
	 * Built with Squirrly's OWN getPostDetails() so the hash rule (md5(ID) for
	 * post/page, md5(post_type.ID) for custom types) can never drift from the
	 * one the front end uses.
	 *
	 * @return object|null
	 */
	private static function post_domain( int $post_id ) {
		$wp_post = get_post( $post_id );
		if ( ! $wp_post ) return null;

		$frontend = SQ_Classes_ObjController::getClass( 'SQ_Models_Frontend' );
		if ( ! $frontend || ! method_exists( $frontend, 'getPostDetails' ) ) return null;

		$post = $frontend->getPostDetails( $wp_post );
		if ( ! $post || empty( $post->hash ) ) return null;

		return $post;
	}

	/**
	 * The SEO domain currently STORED for this post (no pattern expansion, no
	 * post-meta fallback) — i.e. the row as saved, which is what we must edit
	 * in place so nothing else in it is lost.
	 *
	 * @return object|null
	 */
	private static function stored_sq( int $post_id ) {
		$post = self::post_domain( $post_id );
		if ( $post === null ) return null;

		$qss = SQ_Classes_ObjController::getClass( 'SQ_Models_Qss' );
		if ( ! $qss || ! method_exists( $qss, 'getSqSeo' ) ) return null;

		$sq = $qss->getSqSeo( $post->hash );
		return $sq ?: null;
	}

	/**
	 * Update (or insert) the qss row for this post, preserving every other
	 * field in it, then RE-READ to confirm the value is really in the store.
	 * An unverified write returns false — the caller must not report it as one.
	 */
	private static function write_qss( int $post_id, string $meta_title, string $meta_description ): bool {
		$post = self::post_domain( $post_id );
		if ( $post === null ) return false;

		$qss = SQ_Classes_ObjController::getClass( 'SQ_Models_Qss' );
		if ( ! $qss || ! method_exists( $qss, 'getSqSeo' ) || ! method_exists( $qss, 'updateSqSeo' ) ) return false;

		$sq = $qss->getSqSeo( $post->hash );
		if ( ! $sq ) return false;

		// Edit in place. Everything we do not touch (noindex, canonical, og:*,
		// jsonld, innerlinks…) is written back exactly as Squirrly stored it.
		$sq->title       = $meta_title;
		$sq->description = $meta_description;

		$qss->updateSqSeo( $post, $sq );

		// Trust nothing: read the row back out of the table.
		$after = $qss->getSqSeo( $post->hash );
		if ( ! $after ) return false;

		return (string) ( $after->title ?? '' ) === $meta_title
			&& (string) ( $after->description ?? '' ) === $meta_description;
	}
}
