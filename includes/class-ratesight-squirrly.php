<?php
/**
 * Squirrly SEO storage adapter: write the value Squirrly actually SERVES.
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
 * WHAT SQUIRRLY 14.x ACTUALLY READS (source-verified against 14.2.3 and 14.2.6)
 * ----------------------------------------------------------------------------
 * 1. `{$wpdb->prefix}qss`: Squirrly's own table (`_SQ_DB_` = 'qss'), one row
 *    per URL hash (md5(ID) for posts and pages, md5(post_type . ID) for custom
 *    types), column `seo` holding a PHP-serialised array of
 *    SQ_Models_Domain_Sq::toArray(). SQ_Models_Domain_Post::getSq() loads it
 *    via SQ_Models_Qss::getSqSeo(). The Title and Description services emit
 *    `$post->sq->title` / `$post->sq->description`, escaped at render time.
 * 2. `_sq_title` / `_sq_description` post meta: SQ_Models_Domain_Sq::getTitle()
 *    and ::getDescription() fall back to these while the row's own value is
 *    empty. getSq() sets post_id BEFORE it calls toArray(), so this fallback is
 *    consulted before any Automation pattern.
 * 3. Only when both are empty does Squirrly fill the field from the post-type
 *    Automation pattern (sq_auto_pattern on) or from post_title/post_excerpt.
 *
 * FIELD-PRESERVING WRITES (3.14.1)
 * --------------------------------
 * write() takes null for a field the caller did not send and then leaves that
 * field alone in both layers. A field whose value already equals the stored
 * effective value (compared after HTML entity decoding, because Squirrly's own
 * editor stores esc_html + ent2ncr output) is also left alone. Before 3.14.1
 * update-page rewrote an omitted title from `_yoast_wpseo_title ?: rank_math_title`,
 * which on a Squirrly-only site is '' and BLANKED the Squirrly title, handing
 * the page to its Automation pattern.
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
	 * end reads? False on a Squirrly build whose internals moved: callers then
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
	 * The stored value of each field and the layer it comes from, per field:
	 * 'qss' (the row), 'postmeta' (_sq_* fallback) or 'none' (Squirrly serves
	 * its Automation pattern or the post's own title/excerpt).
	 *
	 * @return array{title:array{value:string,store:string},description:array{value:string,store:string},native_read:bool}
	 */
	public static function fields( int $post_id ): array {
		$out = array(
			'title'       => array( 'value' => '', 'store' => 'none' ),
			'description' => array( 'value' => '', 'store' => 'none' ),
			'native_read' => false,
		);
		if ( $post_id < 1 ) return $out;

		$row = array( 'title' => '', 'description' => '' );
		if ( self::has_native_store() ) {
			try {
				$sq = self::stored_sq( $post_id );
				if ( $sq !== null ) {
					$row['title']       = (string) ( $sq->title ?? '' );
					$row['description'] = (string) ( $sq->description ?? '' );
					$out['native_read'] = true;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Squirrly internals moved: fall through to post meta.
			}
		}

		$meta = array(
			'title'       => (string) get_post_meta( $post_id, self::META_TITLE, true ),
			'description' => (string) get_post_meta( $post_id, self::META_DESC, true ),
		);
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( $row[ $field ] !== '' ) {
				$out[ $field ] = array( 'value' => $row[ $field ], 'store' => 'qss' );
			} elseif ( $meta[ $field ] !== '' ) {
				$out[ $field ] = array( 'value' => $meta[ $field ], 'store' => 'postmeta' );
			}
		}
		return $out;
	}

	/**
	 * Read the SEO title + description Squirrly would serve for a post.
	 *
	 * qss row first (what the front end reads), post meta second (what Squirrly
	 * falls back to). Returns raw stored values, no pattern expansion, because
	 * a pattern is not a value anyone wrote.
	 *
	 * @return array{meta_title:string,meta_description:string,store:string}
	 *         store: 'qss' | 'postmeta' | 'none'
	 */
	public static function read( int $post_id ): array {
		$f     = self::fields( $post_id );
		$store = 'none';
		if ( $f['title']['store'] === 'qss' || $f['description']['store'] === 'qss' ) {
			$store = 'qss';
		} elseif ( $f['title']['store'] === 'postmeta' || $f['description']['store'] === 'postmeta' ) {
			$store = 'postmeta';
		}
		return array(
			'meta_title'       => $f['title']['value'],
			'meta_description' => $f['description']['value'],
			'store'            => $store,
		);
	}

	/**
	 * What change control needs to know before it writes through Squirrly
	 * (GET /update-page `squirrly` block, since 3.14.1). Values are raw stored
	 * bytes; the dashboard decodes HTML entities before comparing with the page.
	 *
	 * emits_title / emits_description: Squirrly prints this field on this page
	 * (options sq_auto_metas and sq_auto_title / sq_auto_description, and the
	 * page's own doseo / do_metas). null when it cannot be determined.
	 */
	public static function describe( int $post_id ): array {
		$fields = self::fields( $post_id );
		$out    = array(
			'active'             => self::is_active(),
			'version'            => defined( 'SQ_VERSION' ) ? (string) constant( 'SQ_VERSION' ) : null,
			'native_store'       => self::has_native_store(),
			'native_read'        => $fields['native_read'],
			'title'              => $fields['title'],
			'description'        => $fields['description'],
			'auto_pattern'       => null,
			'emits_title'        => null,
			'emits_description'  => null,
			'legacy_key_present' => $post_id > 0 && get_post_meta( $post_id, '_squirrly_seo', true ) !== '',
			'field_preserving'   => true,
		);

		$opt = static function ( string $key ) {
			if ( ! class_exists( 'SQ_Classes_Helpers_Tools' ) || ! method_exists( 'SQ_Classes_Helpers_Tools', 'getOption' ) ) return null;
			try {
				return SQ_Classes_Helpers_Tools::getOption( $key );
			} catch ( \Throwable $e ) {
				return null;
			}
		};
		$metas = $opt( 'sq_auto_metas' );
		$title = $opt( 'sq_auto_title' );
		$desc  = $opt( 'sq_auto_description' );
		$auto  = $opt( 'sq_auto_pattern' );
		$out['auto_pattern'] = $auto === null ? null : (bool) $auto;

		$page_on = null;
		if ( $post_id > 0 && self::has_native_store() ) {
			try {
				$post = self::post_domain( $post_id );
				$sq   = ( is_object( $post ) && method_exists( $post, 'getSq' ) ) ? $post->getSq() : null;
				if ( $sq ) {
					$page_on = (bool) $sq->doseo && (bool) $sq->do_metas;
				}
			} catch ( \Throwable $e ) {
				$page_on = null;
			}
		}
		if ( $metas !== null && $title !== null && $desc !== null ) {
			$global_title = (bool) $metas && (bool) $title;
			$global_desc  = (bool) $metas && (bool) $desc;
			$out['emits_title']       = $page_on === null ? ( $global_title ? null : false ) : ( $global_title && $page_on );
			$out['emits_description'] = $page_on === null ? ( $global_desc ? null : false ) : ( $global_desc && $page_on );
		}
		return $out;
	}

	/**
	 * Write the SEO title and/or description into the store Squirrly serves.
	 * null leaves that field untouched in both layers; a value equal to the
	 * stored effective value is not rewritten.
	 *
	 * Both layers are attempted for a changed field; the return value says
	 * exactly which landed, so a caller never has to assume. `qss` false with
	 * `postmeta` true means a changed field reached only Squirrly's fallback
	 * layer: report it, do not round it up to success.
	 *
	 * @return array{qss:bool,postmeta:bool,native:bool,note:string,fields:array<string,string>}
	 */
	public static function write( int $post_id, ?string $meta_title, ?string $meta_description ): array {
		$result = array(
			'qss'      => false,
			'postmeta' => false,
			'native'   => self::has_native_store(),
			'note'     => '',
			'fields'   => array(),
		);

		if ( $post_id < 1 || ! self::is_active() ) {
			$result['note'] = 'squirrly not active, nothing written';
			return $result;
		}

		$current = self::fields( $post_id );
		$changes = array();
		foreach ( array( 'title' => $meta_title, 'description' => $meta_description ) as $field => $value ) {
			if ( $value === null ) {
				$result['fields'][ $field ] = 'omitted';
			} elseif ( self::same( $current[ $field ]['value'], $value ) ) {
				$result['fields'][ $field ] = 'unchanged';
			} else {
				$changes[ $field ]          = $value;
				$result['fields'][ $field ] = 'written';
			}
		}

		if ( ! $changes ) {
			// Nothing to change: the stored effective values already are the requested ones. qss is
			// true only when those values were read from Squirrly's own row through its models; with
			// the models unreachable only the post-meta layer is known to hold them.
			$result['qss']      = $current['native_read'];
			$result['postmeta'] = true;
			$result['note']     = $current['native_read']
				? 'squirrly values unchanged, nothing written'
				: 'squirrly values unchanged in _sq_* post meta; squirrly models not reachable, served store not confirmed';
			return $result;
		}

		// Layer 2 first: post meta is unconditional and cannot fail on a
		// Squirrly internals change, so the value is at least recorded where
		// Squirrly's own fallback reads it.
		if ( array_key_exists( 'title', $changes ) ) update_post_meta( $post_id, self::META_TITLE, $changes['title'] );
		if ( array_key_exists( 'description', $changes ) ) update_post_meta( $post_id, self::META_DESC, $changes['description'] );
		$result['postmeta'] = true;

		// Layer 1: the row the front end actually reads.
		if ( $result['native'] ) {
			try {
				$result['qss']  = self::write_qss( $post_id, $changes );
				$result['note'] = $result['qss']
					? 'wrote squirrly qss row (served store) + _sq_* post meta for: ' . implode( ', ', array_keys( $changes ) )
					: 'squirrly qss row write did not verify, only _sq_* post meta was stored';
			} catch ( \Throwable $e ) {
				$result['note'] = 'squirrly qss row unavailable (' . $e->getMessage() . '), only _sq_* post meta was stored';
			}
		} else {
			$result['note'] = 'squirrly models not reachable, only _sq_* post meta was stored';
		}

		return $result;
	}

	/** Equal as text: Squirrly's editor stores esc_html( ent2ncr() ) output, we store plain text. */
	public static function same( string $a, string $b ): bool {
		$decode = static function ( string $v ): string {
			return trim( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		};
		return $decode( $a ) === $decode( $b );
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
	 * post-meta fallback), i.e. the row as saved, which is what we must edit
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
	 * Update (or insert) the qss row for this post, changing only the fields in
	 * $changes and preserving every other field in it, then RE-READ to confirm
	 * the values are really in the store. An unverified write returns false:
	 * the caller must not report it as one.
	 *
	 * @param array<string,string> $changes 'title' and/or 'description'.
	 */
	private static function write_qss( int $post_id, array $changes ): bool {
		$post = self::post_domain( $post_id );
		if ( $post === null ) return false;

		$qss = SQ_Classes_ObjController::getClass( 'SQ_Models_Qss' );
		if ( ! $qss || ! method_exists( $qss, 'getSqSeo' ) || ! method_exists( $qss, 'updateSqSeo' ) ) return false;

		$sq = $qss->getSqSeo( $post->hash );
		if ( ! $sq ) return false;

		// Edit in place. Everything we do not touch (the other text field,
		// noindex, canonical, og:*, jsonld, innerlinks) is written back exactly
		// as Squirrly stored it.
		foreach ( $changes as $field => $value ) {
			$sq->$field = $value;
		}

		$qss->updateSqSeo( $post, $sq );

		// Trust nothing: read the row back out of the table.
		$after = $qss->getSqSeo( $post->hash );
		if ( ! $after ) return false;

		foreach ( $changes as $field => $value ) {
			if ( (string) ( $after->$field ?? '' ) !== $value ) return false;
		}
		return true;
	}
}
