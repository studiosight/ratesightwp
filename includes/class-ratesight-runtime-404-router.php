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

	/** Valid per-site fuzzy modes (option: fuzzy_mode / ratesight_fuzzy_mode). */
	const MODES = array( 'legacy', 'same-city-or-hub', 'off' );

	/**
	 * Generic trailing geo words: when a city slug ends with one of these, the city
	 * identity needs the PRECEDING token too (union-city vs foster-city, bay-area vs
	 * bay-point). A specific final token (hercules, francisco, ramon) identifies the
	 * city on its own, so slug variants (moving-company vs moving-companies) never
	 * false-trip the guard.
	 */
	const GEO_SUFFIXES = array( 'city', 'area', 'bay', 'beach', 'valley', 'park', 'hill', 'hills', 'point', 'creek', 'island', 'heights', 'grove', 'view' );

	/**
	 * Directional/qualifier particles that make two same-base cities DIFFERENT
	 * (east-palo-alto vs palo-alto, south-san-francisco vs san-francisco). Used by
	 * cities_differ()'s walk-back: when two slugs share the same base city identity
	 * but diverge at a preceding token and either divergent token is one of these,
	 * they are different cities. Plain service-word divergence (moving-companies vs
	 * moving-company) is NOT a city difference.
	 */
	const CITY_QUALIFIERS = array( 'east', 'west', 'north', 'south', 'upper', 'lower', 'new', 'old' );

	// ── Entry point ───────────────────────────────────────────────────────────

	/**
	 * Hooked to template_redirect priority 5.
	 * Only fires when WordPress has already determined this is a 404.
	 */
	public static function maybe_route(): void {
		if ( ! is_404() ) return;

		$mode = self::current_mode();
		if ( $mode === 'off' ) return;

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

		// 2. Decide. Pure logic (unit-tested in tests/test-runtime-404-router.php):
		//    legacy = old behavior; same-city-or-hub = cross-city candidates are
		//    ineligible, with a same-service base-hub fallback; off handled above.
		$index    = self::get_post_index();
		$decision = self::route_decision( $slug, $index, $mode, self::THRESHOLD );

		if ( $decision['action'] === 'refuse' ) {
			// Observability: the ONLY candidate that cleared the threshold was
			// cross-city and the constrained mode blocked it. Log the refusal
			// (type fuzzy-refused, NO redirect served) so the before/after of a
			// site flip is provable from the serve log.
			Ratesight_Redirect_Serve_Log::log( '/' . $path . '/', $decision['refused_url'], 'fuzzy-refused', round( $decision['refused_score'], 4 ), $decision['context'] );
			return;
		}
		if ( $decision['action'] !== 'redirect' ) return;

		$url = $decision['url'];

		// Never redirect to homepage (catch-all guard).
		$home = trailingslashit( home_url( '/' ) );
		if ( trailingslashit( $url ) === $home ) return;

		wp_safe_redirect( $url, 301 );
		Ratesight_Redirect_Serve_Log::log( '/' . $path . '/', $url, 'fuzzy', round( $decision['score'], 4 ), $decision['context'] );
		exit;
	}

	/**
	 * The per-site fuzzy mode. Reads the Ratesight_Options schema entry when the
	 * options class is loaded (always, in the plugin); unknown/empty values fall
	 * back to 'legacy' so this PR changes nothing until a site is explicitly
	 * flipped.
	 */
	public static function current_mode(): string {
		$mode = class_exists( 'Ratesight_Options' ) ? (string) Ratesight_Options::get( 'fuzzy_mode' ) : 'legacy';
		return in_array( $mode, self::MODES, true ) ? $mode : 'legacy';
	}

	// ── Decision core (pure — no WordPress calls; unit-testable) ─────────────

	/**
	 * Decide what the router should do for a 404'd slug.
	 *
	 * @param string $query_slug The 404'd slug (basename of the request path).
	 * @param array  $index      Post index rows: [{slug, tokens, url}].
	 * @param string $mode       'legacy' | 'same-city-or-hub' ('off' never reaches here).
	 * @param float  $threshold  Minimum score to redirect.
	 * @return array {action: 'redirect'|'refuse'|'none', url, score, refused_url,
	 *                refused_score, context: {mode, source_city, target_city,
	 *                fallback_reason?, refused_city?}}
	 */
	public static function route_decision( string $query_slug, array $index, string $mode, float $threshold ): array {
		$none        = array( 'action' => 'none', 'url' => '', 'score' => 0.0, 'refused_url' => '', 'refused_score' => 0.0, 'context' => array( 'mode' => $mode ) );
		$source_city = self::city_of_slug( $query_slug );

		if ( $mode === 'legacy' ) {
			[ $url, $score ] = self::find_best_match( $query_slug, $index );
			if ( $score < $threshold || $url === '' ) return $none;
			return array(
				'action' => 'redirect', 'url' => $url, 'score' => $score,
				'refused_url' => '', 'refused_score' => 0.0,
				'context' => array( 'mode' => 'legacy', 'source_city' => $source_city, 'target_city' => self::city_of_slug( self::slug_of_url( $url ) ) ),
			);
		}

		// same-city-or-hub: candidates whose city differs from the source city are
		// INELIGIBLE (no city A -> city B, ever). City-less candidates stay eligible.
		$eligible = array();
		foreach ( $index as $post ) {
			if ( ! self::cities_differ( $query_slug, (string) $post['slug'] ) ) {
				$eligible[] = $post;
			}
		}

		[ $url, $score ] = self::find_best_match( $query_slug, $eligible );
		if ( $score >= $threshold && $url !== '' ) {
			return array(
				'action' => 'redirect', 'url' => $url, 'score' => $score,
				'refused_url' => '', 'refused_score' => 0.0,
				'context' => array( 'mode' => 'same-city', 'source_city' => $source_city, 'target_city' => self::city_of_slug( self::slug_of_url( $url ) ) ),
			);
		}

		// Same-service base-hub fallback: ONLY for confidently-mapped commercial/
		// office CITY slugs, and only when the hub page actually exists on this
		// site (looked up in the index — never a guessed URL, never a catch-all).
		if ( $source_city !== '' ) {
			$hub_slug = self::service_hub_slug( $query_slug );
			if ( $hub_slug !== '' && $hub_slug !== $query_slug ) {
				foreach ( $index as $post ) {
					if ( (string) $post['slug'] === $hub_slug ) {
						return array(
							'action' => 'redirect', 'url' => $post['url'], 'score' => $score,
							'refused_url' => '', 'refused_score' => 0.0,
							'context' => array(
								'mode' => 'hub', 'source_city' => $source_city, 'target_city' => '',
								'fallback_reason' => 'no same-city candidate >= threshold; same-service base hub',
							),
						);
					}
				}
			}
		}

		// Nothing safe to serve. If legacy WOULD have redirected cross-city, record
		// the refusal for observability; otherwise stay silent (plain 404).
		[ $legacy_url, $legacy_score ] = self::find_best_match( $query_slug, $index );
		if ( $legacy_score >= $threshold && $legacy_url !== '' && self::cities_differ( $query_slug, self::slug_of_url( $legacy_url ) ) ) {
			return array(
				'action' => 'refuse', 'url' => '', 'score' => 0.0,
				'refused_url' => $legacy_url, 'refused_score' => $legacy_score,
				'context' => array(
					'mode' => 'refused', 'source_city' => $source_city,
					'refused_city' => self::city_of_slug( self::slug_of_url( $legacy_url ) ),
					'fallback_reason' => 'cross-city fuzzy match blocked; no same-city or hub target',
				),
			);
		}
		return $none;
	}

	/**
	 * City identity of a `...-{city}-ca` slug ('' when the slug carries no city —
	 * e.g. the base hubs or blog posts). A generic trailing geo word (city, bay,
	 * area...) pulls in the preceding token so union-city != foster-city while
	 * moving-company-hercules == moving-companies-hercules.
	 */
	public static function city_of_slug( string $slug ): string {
		$tokens = self::slug_tokens( $slug );
		if ( count( $tokens ) < 2 || end( $tokens ) !== 'ca' ) return '';
		array_pop( $tokens ); // drop 'ca'
		$last = array_pop( $tokens );
		if ( $last === null || $last === '' ) return '';
		if ( in_array( $last, self::GEO_SUFFIXES, true ) && ! empty( $tokens ) ) {
			$prev = array_pop( $tokens );
			return $prev . '-' . $last;
		}
		return $last;
	}

	/**
	 * Hyphen tokens of a slug, with trailing NUMERIC tokens stripped first so a
	 * WordPress slug-collision suffix ('movers-san-ramon-ca-2') still reads as a
	 * `-ca` city slug instead of silently bypassing the cross-city guard.
	 */
	private static function slug_tokens( string $slug ): array {
		$tokens = preg_split( '/-+/', strtolower( trim( $slug, '/' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		while ( ! empty( $tokens ) && ctype_digit( (string) end( $tokens ) ) ) {
			array_pop( $tokens );
		}
		return $tokens;
	}

	/**
	 * True when BOTH slugs carry a city and the cities differ (cross-city).
	 *
	 * Two-stage check:
	 *   1. Base identity (city_of_slug) differs -> different cities.
	 *   2. SAME base identity: walk the token lists backwards in lockstep from the
	 *      base; while tokens match, keep walking; at the first divergence, if
	 *      EITHER divergent token is a directional qualifier (CITY_QUALIFIERS),
	 *      the cities differ — east-palo-alto is not palo-alto, south-san-francisco
	 *      is not san-francisco. Divergence on plain service words (companies vs
	 *      company) is NOT a city difference, so slug variants of the same city
	 *      stay eligible.
	 */
	public static function cities_differ( string $a, string $b ): bool {
		$ca = self::city_of_slug( $a );
		$cb = self::city_of_slug( $b );
		if ( $ca === '' || $cb === '' ) return false;
		if ( $ca !== $cb ) return true;

		// Same base identity: compare the tokens preceding it, walking backwards.
		$ta = self::slug_tokens( $a );
		$tb = self::slug_tokens( $b );
		array_pop( $ta ); // 'ca'
		array_pop( $tb );
		$i = count( $ta ) - 1;
		$j = count( $tb ) - 1;
		while ( $i >= 0 && $j >= 0 ) {
			if ( $ta[ $i ] === $tb[ $j ] ) { $i--; $j--; continue; }
			return in_array( $ta[ $i ], self::CITY_QUALIFIERS, true ) || in_array( $tb[ $j ], self::CITY_QUALIFIERS, true );
		}
		// One slug ran out: if the longer one's next token is a qualifier, it names
		// a different (qualified) city — east-palo-alto vs palo-alto.
		if ( $i >= 0 && in_array( $ta[ $i ], self::CITY_QUALIFIERS, true ) ) return true;
		if ( $j >= 0 && in_array( $tb[ $j ], self::CITY_QUALIFIERS, true ) ) return true;
		return false;
	}

	/**
	 * Confidently-mapped base service hub for a slug's service family, or ''.
	 * commercial/corporate/business-relocation/business-moving -> commercial-movers;
	 * office-mover/moving/moves -> office-movers. Anything else: no hub (no fuzzy
	 * redirect rather than a wrong guess).
	 */
	public static function service_hub_slug( string $slug ): string {
		$s = strtolower( trim( $slug, '/' ) );
		if ( preg_match( '/^office-(mover|movers|moving|moves)(-|$)/', $s ) ) return 'office-movers';
		if ( preg_match( '/^(commercial-(mover|movers|moving|moves)|corporate-(mover|movers|moving)|corporate-moving-compan(y|ies)|business-relocations?|business-moving)(-|$)/', $s ) ) return 'commercial-movers';
		return '';
	}

	/** Slug (last path segment) of a permalink URL. */
	public static function slug_of_url( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		if ( $path === '' ) return '';
		$parts = explode( '/', $path );
		return (string) end( $parts );
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
