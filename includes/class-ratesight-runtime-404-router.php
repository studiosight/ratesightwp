<?php
/**
 * Runtime 404 smart-router.
 *
 * Hooked to template_redirect at priority 5 (before most themes).
 * On any 404:
 *   1. Yields immediately if an explicit redirect is already set (let handle_redirects() win).
 *   2. Extracts the slug from the requested path.
 *   3. Scores all published posts by slug/title token-overlap (Jaccard) and
 *      falls back to normalised Levenshtein on the slug.
 *   4. If best score >= THRESHOLD → wp_safe_redirect( $url, 301 ).
 *   5. Otherwise → does nothing (WordPress serves its own 404).
 *
 * Never guesses. Never redirects to the homepage.
 *
 * @package Ratesight
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.

defined( 'ABSPATH' ) || die;

class Ratesight_Runtime_404_Router {

	/** Minimum confidence (0–1) required before redirecting. */
	const THRESHOLD = 0.70;

	/** Transient key for the post index cache. */
	const CACHE_KEY = 'ratesight_404_index';

	/** How long to cache the post index (seconds). */
	const CACHE_TTL = 3600;

	// ── Entry point ───────────────────────────────────────────────────────────

	/**
	 * Hooked to template_redirect priority 5.
	 * Only fires when WordPress has already determined this is a 404.
	 */
	public static function maybe_route(): void {
		if ( ! is_404() ) return;

		$raw  = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = trim( wp_make_link_relative( home_url( $raw ) ), '/' );
		if ( $path === '' ) return;

		// 1. If an explicit redirect is already set for this path, bail — the
		//    template_redirect handler at priority 1 (handle_redirects) will fire
		//    before us and serve it. We only need to handle the long tail.
		$explicit = get_option( 'ratesight_rs_redirects', array() );
		$slug     = basename( $path );
		foreach ( array( $slug, '/' . $slug, '/' . $slug . '/', $path, '/' . $path . '/' ) as $try ) {
			if ( isset( $explicit[ $try ] ) ) return;
		}

		if ( $slug === '' ) return;

		// 2. Score every published post against the slug.
		$index              = self::get_post_index();
		[ $url, $score ]    = self::find_best_match( $slug, $index );

		if ( $score < self::THRESHOLD || $url === '' ) return;

		// Never redirect to homepage (catch-all guard).
		$home = trailingslashit( home_url( '/' ) );
		if ( trailingslashit( $url ) === $home ) return;

		wp_safe_redirect( $url, 301 );
		Ratesight_Redirect_Serve_Log::log( '/' . $path . '/', $url, 'fuzzy', round( $score, 4 ) );
		exit;
	}

	// ── Post index ────────────────────────────────────────────────────────────

	/**
	 * Returns a lightweight array of all published posts: slug, title tokens, permalink.
	 * Cached in a transient for CACHE_TTL seconds.
	 */
	private static function get_post_index(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) return $cached;

		global $wpdb;
		$post_types = implode( "','", array_map( 'esc_sql', array( 'post', 'page', 'ratesight_page' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT ID, post_name, post_title
			 FROM `{$wpdb->posts}`
			 WHERE post_status = 'publish'
			   AND post_type IN ('{$post_types}')
			 LIMIT 2000",
			ARRAY_A
		);

		$index = array();
		foreach ( ( $rows ?: array() ) as $row ) {
			$permalink = get_permalink( (int) $row['ID'] );
			if ( ! $permalink ) continue;

			$index[] = array(
				'slug'   => (string) $row['post_name'],
				'tokens' => array_unique( array_merge(
					self::tokenize( $row['post_name'] ),
					self::tokenize( sanitize_title( $row['post_title'] ) )
				) ),
				'url'    => $permalink,
			);
		}

		set_transient( self::CACHE_KEY, $index, self::CACHE_TTL );
		return $index;
	}

	/**
	 * Invalidate the index whenever a post is published, updated, or deleted.
	 * Hooked to transition_post_status and delete_post.
	 */
	public static function invalidate_index(): void {
		delete_transient( self::CACHE_KEY );
	}

	// ── Matching ──────────────────────────────────────────────────────────────

	/**
	 * Score all indexed posts against the query slug.
	 * Returns [ url, score ] of the best match, or [ '', 0 ] if nothing qualifies.
	 */
	private static function find_best_match( string $query_slug, array $index ): array {
		$query_tokens = self::tokenize( $query_slug );
		$best_url     = '';
		$best_score   = 0.0;

		foreach ( $index as $post ) {
			// Exact slug match → certainty.
			if ( $post['slug'] === $query_slug ) {
				return array( $post['url'], 1.0 );
			}

			// Jaccard token overlap (slug words ∩ query words / union).
			$jaccard = self::jaccard( $query_tokens, $post['tokens'] );

			// Normalised Levenshtein on the raw slug (catches typos, truncations).
			$lev = self::normalised_levenshtein( $query_slug, $post['slug'] );

			// Weighted combination: token overlap carries more weight.
			$score = ( $jaccard * 0.65 ) + ( $lev * 0.35 );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_url   = $post['url'];
			}
		}

		return array( $best_url, $best_score );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Split a slug into meaningful tokens (≥ 3 chars, stop-words removed).
	 */
	private static function tokenize( string $slug ): array {
		static $stop = array( 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'not', 'but' );
		$parts  = preg_split( '/[-_\s]+/', strtolower( $slug ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$tokens = array();
		foreach ( $parts as $t ) {
			if ( strlen( $t ) >= 3 && ! in_array( $t, $stop, true ) ) {
				$tokens[] = $t;
			}
		}
		return $tokens;
	}

	/**
	 * Jaccard similarity: |A ∩ B| / |A ∪ B|
	 */
	private static function jaccard( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) ) return 0.0;
		$intersect = count( array_intersect( $a, $b ) );
		$union     = count( array_unique( array_merge( $a, $b ) ) );
		return $union > 0 ? (float) $intersect / $union : 0.0;
	}

	/**
	 * Levenshtein similarity normalised to [0, 1]: 1 = identical, 0 = completely different.
	 */
	private static function normalised_levenshtein( string $a, string $b ): float {
		if ( $a === $b ) return 1.0;
		$max = max( strlen( $a ), strlen( $b ) );
		if ( $max === 0 ) return 1.0;
		return 1.0 - ( levenshtein( $a, $b ) / $max );
	}
}
