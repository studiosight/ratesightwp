<?php
/**
 * Ratesight Link Manager
 *
 * Responsibilities
 * ─────────────────
 * 1. Scan all published ratesight_page posts and build an inbound / outbound
 *    link map stored in the ratesight_link_cache table.
 * 2. Detect orphaned pages (0 inbound links) and broken outbound URLs.
 * 3. Generate keyword-matched link suggestions between RS pages, then pass
 *    them through the Ratesight AI Worker for a relevance score.
 * 4. Insert a confirmed link into post_content, preserving manually-added
 *    links across subsequent webhook content updates.
 * 5. Invalidate the cache for a specific post when its content changes.
 *
 * Design decisions
 * ─────────────────
 * - All heavy work (scanning, broken-link checking) runs as background cron
 *   or in dedicated AJAX handlers — never on page load.
 * - AI is called once per suggestion batch (not per link) to keep costs low.
 * - Manual links are stored in post meta (_rs_manual_links) so they survive
 *   webhook content overwrites via Ratesight_Webhook_Handler.
 * - Anchor text diversity is tracked site-wide and warned at 5+ uses of the
 *   same phrase pointing to the same destination.
 * - Link velocity: a per-session insert counter warns at 20+ inserts.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Link_Manager {

	// ─── Meta / option keys ──────────────────────────────────────────────────
	const META_MANUAL_LINKS = '_rs_manual_links';   // array of {anchor, url, position}
	const OPT_SCAN_LOCK     = 'ratesight_link_scan_running';
	const OPT_BROKEN_LOCK   = 'ratesight_link_broken_running';

	// AI relevance threshold — suggestions below this score are hidden.
	const AI_MIN_SCORE = 7;  // Only show genuinely relevant suggestions

	// ─── Cache invalidation ──────────────────────────────────────────────────

	/**
	 * Called by trashed_post and before_delete_post.
	 * 1. Saves the post's URL to wp_options so we can 301 it after it's gone.
	 * 2. Removes the cache row.
	 * 3. Marks any post that linked TO this one as stale.
	 */
	public static function on_post_removed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'ratesight_page' ) return;

		// ── Save URL for redirect BEFORE the post is gone ─────────────────────
		// get_permalink() still works here because the post exists.
		$url = get_permalink( $post_id );
		if ( $url ) {
			$path = trim( wp_make_link_relative( $url ), '/' );
			if ( $path ) {
				$redirects = get_option( 'ratesight_rs_redirects', array() );
				// Only store once — don't overwrite an existing redirect destination.
				if ( ! isset( $redirects[ $path ] ) ) {
					$redirects[ $path ] = array(
						'post_id'    => $post_id,
						'title'      => $post->post_title,
						'removed_at' => current_time( 'mysql' ),
						'redirect_to' => '', // Empty = redirect to homepage; admin can set a custom destination.
					);
					update_option( 'ratesight_rs_redirects', $redirects, false );
				}
			}
		}

		// ── Remove cache row ───────────────────────────────────────────────────
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		delete_transient( 'rs_link_suggest_' . $post_id );

		// ── Mark linking pages stale ───────────────────────────────────────────
		if ( ! $url ) return;
		$pattern   = '%' . $wpdb->esc_like( $url ) . '%';
		$stale_ids = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT post_id FROM `{$table}` WHERE outbound_urls LIKE %s",
			$pattern
		) );
		foreach ( $stale_ids as $sid ) {
			$wpdb->update( $table, array( 'stale' => 1 ), array( 'post_id' => (int) $sid ), array( '%d' ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}

	/**
	 * Handle 301 redirects for deleted/trashed RS pages.
	 * Hooked to template_redirect at priority 1 — fires before any template load.
	 */
	public static function handle_redirects(): void {
		$redirects = get_option( 'ratesight_rs_redirects', array() );
		if ( empty( $redirects ) ) return;

		$raw  = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$path = trim( wp_make_link_relative( home_url( $raw ) ), '/' );
		// Strip query string.
		$path = strtok( $path, '?' );

		// Try multiple key formats — stored keys may include leading/trailing slashes
		// depending on which code path created them.
		$entry = $redirects[ $path ]              // no slashes (legacy)
			?? $redirects[ '/' . $path . '/' ]   // /slug/ (API-created)
			?? $redirects[ '/' . $path ]          // /slug
			?? $redirects[ $path . '/' ]          // slug/
			?? null;

		if ( ! $entry ) return;

		// A recovery redirect must never shadow a real page. If a published post
		// now resolves at this exact path (e.g. the page was recreated), let
		// WordPress render it instead of redirecting — the redirect self-heals.
		$live_id = url_to_postid( home_url( $path ) );
		if ( $live_id && get_post_status( $live_id ) === 'publish' ) {
			return;
		}

		$redirect_to = $entry['redirect_to'] ?? '';
		$destination = $redirect_to ? $redirect_to : home_url( '/' );
		$code        = in_array( (int) ( $entry['code'] ?? 301 ), array( 301, 302 ), true ) ? (int) $entry['code'] : 301;

		wp_safe_redirect( $destination, $code );
		Ratesight_Redirect_Serve_Log::log( '/' . $path . '/', $destination, 'explicit', 1.0 );
		exit;
	}

	/**
	 * Get all stored redirects (for the admin UI).
	 */
	public static function get_redirects(): array {
		return get_option( 'ratesight_rs_redirects', array() );
	}

	/**
	 * Update the redirect destination for a stored redirect.
	 */
	public static function update_redirect( string $path, string $destination ): void {
		$redirects = get_option( 'ratesight_rs_redirects', array() );
		if ( isset( $redirects[ $path ] ) ) {
			$redirects[ $path ]['redirect_to'] = esc_url_raw( $destination );
			update_option( 'ratesight_rs_redirects', $redirects, false );
		}
	}

	/**
	 * Delete a stored redirect (e.g. if the page was restored).
	 */
	public static function delete_redirect( string $path ): void {
		$redirects = get_option( 'ratesight_rs_redirects', array() );
		unset( $redirects[ $path ] );
		update_option( 'ratesight_rs_redirects', $redirects, false );
	}
	public static function invalidate( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->update( $table, array( 'stale' => 1 ), array( 'post_id' => $post_id ), array( '%d' ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Also clear cached AI suggestions for this post.
		delete_transient( 'rs_link_suggest_' . $post_id );
	}

	// ─── Full site scan ──────────────────────────────────────────────────────

	/**
	 * Scan all published ratesight_page posts.
	 * Builds inbound / outbound counts and stores them in the link_cache table.
	 * Safe to run multiple times — uses REPLACE INTO.
	 *
	 * Returns array( 'scanned' => int, 'orphans' => int ).
	 */
	public static function scan_all(): array {
		if ( get_option( self::OPT_SCAN_LOCK ) ) {
			return array( 'error' => 'Scan already in progress.' );
		}
		update_option( self::OPT_SCAN_LOCK, 1, false );
		@set_time_limit( 120 ); // phpcs:ignore

		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';

		// Get all published IDs — lightweight, no content loaded yet.
		$post_ids = array_map( 'intval', $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page', 'ratesight_page')"
		) ); // phpcs:ignore

		if ( empty( $post_ids ) ) {
			delete_option( self::OPT_SCAN_LOCK );
			return array( 'scanned' => 0, 'orphans' => 0 );
		}

		// Build permalink → post_id map using a single DB query (no get_permalink loop).
		// get_permalink() fires many filters and queries — too slow at 4500+ posts.
		// We build the URL from post_name and parent hierarchy directly for RS pages,
		// and use WP_Query for the rest to warm the cache efficiently.
		$url_to_id = array();
		$home      = trailingslashit( home_url() );

		// Fetch name + parent for all our IDs in one query.
		$meta_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID, post_name, post_type, post_parent
			 FROM {$wpdb->posts}
			 WHERE ID IN (" . implode( ',', array_map( 'intval', $post_ids ) ) . ")",  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- post IDs are cast to int
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Call get_permalink in chunks to avoid blowing memory — warm object cache 100 at a time.
		$chunks = array_chunk( $post_ids, 100 );
		foreach ( $chunks as $chunk ) {
			get_posts( array(
				'post__in'       => $chunk,
				'post_type'      => array( 'post', 'page', 'ratesight_page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			) );
			foreach ( $chunk as $pid ) {
				$url = get_permalink( $pid );
				if ( $url ) {
					$url_to_id[ trailingslashit( $url ) ]   = $pid;
					$url_to_id[ untrailingslashit( $url ) ] = $pid;
				}
			}
			// Free WP object cache pressure between chunks.
			// Flush post object cache between chunks to reduce memory pressure.
			wp_cache_delete( 'last_changed', 'posts' );
		}

		// Extract links chunk by chunk — never hold all post content in memory at once.
		$outbound_map   = array(); // post_id → links[]
		$internal_links = array(); // post_id → target_post_id[]
		$inbound_count  = array_fill_keys( $post_ids, 0 );

		foreach ( array_chunk( $post_ids, 100 ) as $chunk ) {
			// Load content for this chunk only.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $chunk contains only ints from get_col, safe.
			$content_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE ID IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ")",  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- chunk IDs are cast to int
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $content_rows as $row ) {
				$pid   = (int) $row['ID'];
				$links = self::extract_links_rich( $row['post_content'], $url_to_id );
				$outbound_map[ $pid ] = $links;
				foreach ( $links as $link ) {
					if ( $link['is_internal'] && $link['target_post_id'] && $link['target_post_id'] !== $pid ) {
						$inbound_count[ $link['target_post_id'] ] = ( $inbound_count[ $link['target_post_id'] ] ?? 0 ) + 1;
					}
				}
			}
			unset( $content_rows ); // Free memory
		}

		// Write results to cache table in chunks.
		$scanned = 0;
		foreach ( array_chunk( $post_ids, 200 ) as $chunk ) {
			foreach ( $chunk as $post_id ) {
				$outbound_links = $outbound_map[ $post_id ] ?? array();
				$wpdb->replace( $table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					'post_id'        => $post_id,
					'inbound_count'  => $inbound_count[ $post_id ] ?? 0,
					'outbound_count' => count( $outbound_links ),
					'outbound_urls'  => wp_json_encode( $outbound_links ),
					'broken_count'   => -1,
					'broken_urls'    => null,
					'scanned_at'     => current_time( 'mysql' ),
					'stale'          => 0,
				), array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d' ) );
				$scanned++;
			}
		}

		delete_option( self::OPT_SCAN_LOCK );
		update_option( 'ratesight_link_last_scan', current_time( 'mysql' ) );

		$orphans = count( array_filter( $inbound_count, fn( $v ) => $v === 0 ) );
		return array( 'scanned' => $scanned, 'orphans' => $orphans );
	}

	// ─── Broken link checker ─────────────────────────────────────────────────

	/**
	 * Check outbound URLs for a single post.
	 * Stores rich per-link objects: {url, anchor, code, status, checked_at}.
	 * Preserves existing ignored/unlinked/replaced entries.
	 * Skips internal links (known good) and already-ignored entries.
	 * Capped at 30 external URLs per call.
	 */
	public static function check_broken( int $post_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) return array( 'error' => 'Post not in link cache. Run a scan first.' );

		$outbound = json_decode( $row['outbound_urls'] ?? '[]', true ) ?: array();

		// Build existing status map to preserve user decisions.
		$existing_statuses = array();
		$existing_broken   = json_decode( $row['broken_urls'] ?? '[]', true ) ?: array();
		foreach ( $existing_broken as $b ) {
			if ( ! empty( $b['url'] ) ) {
				$existing_statuses[ $b['url'] ] = $b;
			}
		}

		$checked     = 0;
		$broken_list = array();
		$home      = home_url();

		// Build exclusion list from settings.
		$excluded_raw = array_filter( array_map(
			'trim',
			explode( "\n", get_option( 'ratesight_link_excluded_domains', '' ) )
		) );

		// Backwards compat: outbound may be plain URL strings or rich objects.
		foreach ( array_slice( $outbound, 0, 50 ) as $item ) {
			$url    = is_array( $item ) ? ( $item['url']    ?? '' ) : $item;
			$anchor = is_array( $item ) ? ( $item['anchor'] ?? '' ) : '';
			if ( ! $url ) continue;

			// Preserve existing non-broken statuses (ignored, unlinked, replaced).
			if ( isset( $existing_statuses[ $url ] ) ) {
				$prev = $existing_statuses[ $url ];
				if ( in_array( $prev['status'] ?? '', array( 'ignored', 'unlinked', 'replaced' ), true ) ) {
					$broken_list[] = $prev;
					continue;
				}
			}

			// Skip internal — always valid if the post exists.
			if ( str_starts_with( $url, $home ) ) continue;

			// Skip non-HTTP schemes — tel:, mailto:, javascript:, #anchors are not web URLs.
			if ( preg_match( '/^(tel:|mailto:|javascript:|callto:|sms:|#)/i', $url ) ) continue;

			// Skip excluded domains.
			$url_host = strtolower( preg_replace( '/^www\./', '', wp_parse_url( $url, PHP_URL_HOST ) ?? '' ) );
			$is_excluded = false;
			foreach ( $excluded_raw as $ex ) {
				$ex = strtolower( preg_replace( '/^www\./', '', trim( $ex ) ) );
				if ( $ex && ( $url_host === $ex || str_ends_with( $url_host, '.' . $ex ) ) ) {
					$is_excluded = true;
					break;
				}
			}
			if ( $is_excluded ) continue;

			if ( $checked >= 30 ) break; // Hard cap for timeout protection.
			$checked++;

			$code = self::head_url( $url );
			if ( $code === 0 || $code >= 400 ) {
				// 403 often means bot-protection rather than a genuinely broken page.
				$status = ( $code === 403 ) ? 'possibly_blocked' : 'broken';
				$broken_list[] = array(
					'url'        => $url,
					'anchor'     => $anchor,
					'code'       => $code,
					'status'     => $status,
					'checked_at' => current_time( 'mysql' ),
				);
			}
		}

		$broken_count = count( array_filter( $broken_list, fn( $b ) => ( $b['status'] ?? '' ) === 'broken' ) );

		$wpdb->update( $table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			'broken_count' => $broken_count,
			'broken_urls'  => wp_json_encode( $broken_list ),
		), array( 'post_id' => $post_id ), array( '%d', '%s' ), array( '%d' ) );

		return array(
			'checked' => $checked,
			'broken'  => array_values( array_filter( $broken_list, fn( $b ) => ( $b['status'] ?? '' ) === 'broken' ) ),
		);
	}

	// ─── Broken link actions ─────────────────────────────────────────────────

	/**
	 * Ignore a broken URL — mark as acceptable, hide from broken count.
	 */
	public static function ignore_broken( int $post_id, string $url ): array {
		return self::update_broken_status( $post_id, $url, 'ignored' );
	}

	/**
	 * Unlink: remove the <a> tag from content but keep the anchor text.
	 */
	public static function unlink_url( int $post_id, string $url ): array {
		$post = get_post( $post_id );
		if ( ! $post ) return array( 'ok' => false, 'message' => 'Post not found.' );

		$new_content = preg_replace(
			'/<a[^>]+href=["\']' . preg_quote( $url, '/' ) . '["\'][^>]*>(.*?)<\/a>/is',
			'$1',
			$post->post_content
		);

		if ( $new_content === $post->post_content ) {
			return array( 'ok' => false, 'message' => 'Link not found in content.' );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
		self::update_broken_status( $post_id, $url, 'unlinked' );
		self::invalidate( $post_id );

		// Remove from manual links meta if present.
		self::remove_manual_link( $post_id, $url );

		return array( 'ok' => true, 'message' => 'Link removed, anchor text kept.' );
	}

	/**
	 * Auto-fix suggestions for a broken link.
	 * Extracts search keywords from BOTH the anchor text AND the broken URL itself.
	 * Searches all post types (RS pages, posts, pages) for internal matches first.
	 * Falls back to Wayback Machine if nothing internal found.
	 */
	public static function auto_fix_suggestions( int $post_id, string $url ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';

		// Get anchor text from stored broken_urls data.
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT broken_urls FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links  = json_decode( $row['broken_urls'] ?? '[]', true ) ?: array();
		$anchor = '';
		foreach ( $links as $l ) {
			if ( ( $l['url'] ?? '' ) === $url ) { $anchor = $l['anchor'] ?? ''; break; }
		}
		if ( ! $anchor ) {
			$post   = get_post( $post_id );
			$anchor = $post ? self::get_anchor_for_url( $post->post_content, $url ) : '';
		}

		// Build search terms from BOTH anchor text AND the URL itself.
		// e.g. coolsculptingchulavista.com → ["coolsculpting", "chula", "vista"]
		$anchor_keywords = $anchor ? self::extract_keywords_static( $anchor ) : array();
		$url_keywords    = self::extract_keywords_from_url( $url );
		$search_keywords = array_values( array_unique( array_merge( $anchor_keywords, $url_keywords ) ) );

		$suggestions = array();

		// ── 0. Follow redirect chain — highest confidence if URL moved ────────
		// e.g. coolsculptingchulavista.com → 301 → coolsculptingsd.com (still live)
		$redirect_dest = self::follow_redirect_chain( $url );
		if ( $redirect_dest ) {
			// Score based on how closely the final destination relates to the broken URL keywords.
			$dest_lower   = strtolower( $redirect_dest );
			$keyword_hits = count( array_filter( $search_keywords, fn( $k ) => strpos( $dest_lower, $k ) !== false ) );
			$confidence   = min( 92, 70 + ( $keyword_hits * 8 ) );
			$suggestions[] = array(
				'type'       => 'redirect',
				'url'        => $redirect_dest,
				'label'      => 'Redirect destination',
				'reason'     => 'The broken URL now redirects here — this may be the updated version of the same page',
				'preferred'  => true,
				'confidence' => $confidence,
			);
		}

		// ── 1. Internal match across ALL post types ───────────────────────────
		if ( ! empty( $search_keywords ) ) {
			// Try combinations: all keywords, then top 3, then top 2.
			$search_attempts = array_filter( array_unique( array(
				implode( ' ', array_slice( $search_keywords, 0, 4 ) ),
				implode( ' ', array_slice( $search_keywords, 0, 3 ) ),
				implode( ' ', array_slice( $search_keywords, 0, 2 ) ),
			) ) );

			$found_ids = array();
			foreach ( $search_attempts as $search ) {
				$candidates = get_posts( array(
					'post_type'      => array( 'ratesight_page', 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					's'              => $search,
					'exclude'        => array( $post_id ),
					'fields'         => 'ids',
				) );
				foreach ( $candidates as $cid ) {
					if ( ! in_array( $cid, $found_ids, true ) ) $found_ids[] = $cid;
				}
				if ( count( $found_ids ) >= 3 ) break; // Enough results, stop trying
			}

			foreach ( array_slice( $found_ids, 0, 5 ) as $cid ) {
				$cp   = get_post( $cid );
				$curl = get_permalink( $cid );
				if ( ! $cp || ! $curl ) continue;
				$type  = array( 'ratesight_page' => 'RS Page', 'post' => 'Blog Post', 'page' => 'Page' )[ $cp->post_type ] ?? $cp->post_type;

				// Confidence = how many keywords from the broken URL appear in the found page's title.
				$title_lower    = strtolower( $cp->post_title );
				$keyword_hits   = count( array_filter( $search_keywords, fn( $k ) => strpos( $title_lower, $k ) !== false ) );
				$anchor_hit     = $anchor && stripos( $title_lower, strtolower( $anchor ) ) !== false ? 1 : 0;
				$confidence     = min( 95, 45 + ( $keyword_hits * 15 ) + ( $anchor_hit * 20 ) );

				$suggestions[] = array(
					'type'       => 'internal',
					'url'        => $curl,
					'label'      => $cp->post_title,
					'reason'     => "Internal {$type} — keeps visitors on your site",
					'preferred'  => true,
					'confidence' => $confidence,
				);
			}
		}

		// ── 2. Wayback Machine archive ────────────────────────────────────────
		$wayback = wp_remote_get(
			'https://archive.org/wayback/available?url=' . rawurlencode( $url ),
			array( 'timeout' => 8 )
		);
		if ( ! is_wp_error( $wayback ) ) {
			$wb      = json_decode( wp_remote_retrieve_body( $wayback ), true );
			$archived = $wb['archived_snapshots']['closest']['url'] ?? null;
			if ( $archived && ! empty( $wb['archived_snapshots']['closest']['available'] ) ) {
				$suggestions[] = array(
					'type'       => 'archive',
					'url'       => $archived,
					'label'     => 'Archived version (Wayback Machine)',
					'reason'    => 'Preserved copy of the original page',
					'preferred'  => false,
					'confidence' => 35,
				);
			}
		}

		// Sort highest confidence first.
		usort( $suggestions, fn( $a, $b ) => ( $b['confidence'] ?? 0 ) <=> ( $a['confidence'] ?? 0 ) );

		return array( 'anchor' => $anchor, 'suggestions' => $suggestions );
	}

	/**
	 * Follow a redirect chain up to 5 hops.
	 * Returns the final destination URL if it returns 2xx and is different from the source.
	 * Returns null if the chain leads nowhere useful (4xx, 5xx, loop, timeout).
	 */
	private static function follow_redirect_chain( string $url, int $max_hops = 5 ): ?string {
		$visited = array();
		$current = $url;

		for ( $i = 0; $i < $max_hops; $i++ ) {
			if ( in_array( $current, $visited, true ) ) break; // Loop
			$visited[] = $current;

			$response = wp_remote_head( $current, array(
				'timeout'     => 8,
				'redirection' => 0, // Manual redirect tracking
				'user-agent'  => 'Mozilla/5.0 (compatible; Ratesight/1.0)',
			) );

			if ( is_wp_error( $response ) ) return null;

			$code     = wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( $code >= 200 && $code < 300 ) {
				// Arrived at a live page — only useful if different from the broken URL.
				return $current !== $url ? $current : null;
			}

			if ( $code >= 300 && $code < 400 && $location ) {
				// Handle relative redirects.
				if ( str_starts_with( $location, '/' ) ) {
					$parsed  = wp_parse_url( $current );
					$current = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . $location;
				} elseif ( str_starts_with( $location, 'http' ) ) {
					$current = $location;
				} else {
					break;
				}
				continue;
			}

			break; // 4xx, 5xx or no location header — dead end.
		}

		return null;
	}

	/**
	 * Extract meaningful keywords from a URL.
	 * e.g. https://coolsculptingchulavista.com/services/body-contouring/
	 *   → ["coolsculpting", "chula", "vista", "services", "contouring"]
	 */
	private static function extract_keywords_from_url( string $url ): array {
		$host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
		$path = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );

		// Strip common TLDs, www, and separators.
		$host = preg_replace( '/^www\./', '', $host );
		$host = preg_replace( '/\.(com|net|org|co|uk|us|ca|io|gov|edu|info|biz)$/', '', $host );

		// Split domain and path on non-alphanumeric chars.
		$text  = preg_replace( '/[^a-z0-9]+/', ' ', $host . ' ' . $path );
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		// Keep words 4+ chars; skip purely numeric tokens.
		return array_values( array_filter(
			$words,
			fn( $w ) => strlen( $w ) >= 4 && ! is_numeric( $w )
		) );
	}

	/**
	 * Apply a replacement URL for a broken link.
	 */
	public static function replace_broken( int $post_id, string $old_url, string $new_url ): array {
		$post = get_post( $post_id );
		if ( ! $post ) return array( 'ok' => false, 'message' => 'Post not found.' );

		// Resolve relative new_url to absolute.
		if ( str_starts_with( $new_url, '/' ) ) {
			$new_url = untrailingslashit( home_url() ) . $new_url;
		}

		// Try to find and replace the link — attempt multiple URL variants in case
		// the stored URL differs from the HTML (trailing slash, encoding, etc.).
		$variants = array_unique( array(
			$old_url,
			trailingslashit( $old_url ),
			untrailingslashit( $old_url ),
		) );

		$new_content = $post->post_content;
		$replaced    = false;

		foreach ( $variants as $try_url ) {
			$escaped = preg_quote( $try_url, '/' );
			$result  = preg_replace(
				'/(<a[^>]+)href=(["\'])' . $escaped . '\2([^>]*>)/i',
				'$1href="' . esc_url( $new_url ) . '"$3',
				$new_content
			);
			if ( is_string( $result ) && $result !== $new_content ) {
				$new_content = $result;
				$replaced    = true;
				break;
			}
		}

		if ( ! $replaced ) {
			// Last resort: look for any <a> tag whose href contains the URL (ignoring protocol/www).
			$bare = preg_replace( '/^https?:\/\/(www\.)?/', '', rtrim( $old_url, '/' ) );
			$escaped_bare = preg_quote( $bare, '/' );
			$result = preg_replace(
				'/(<a[^>]+href=["\'])https?:\/\/(?:www\.)?' . $escaped_bare . '[\/]?(["\'][^>]*>)/i',
				'$1' . esc_url( $new_url ) . '$2',
				$new_content
			);
			if ( is_string( $result ) && $result !== $new_content ) {
				$new_content = $result;
				$replaced    = true;
			}
		}

		if ( ! $replaced ) {
			return array( 'ok' => false, 'message' => 'Could not find the original link in the post content. The page may have already been updated.' );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
		self::update_broken_status( $post_id, $old_url, 'replaced', $new_url );
		self::invalidate( $post_id );
		self::update_manual_link_url( $post_id, $old_url, $new_url );

		return array( 'ok' => true, 'message' => 'Link replaced.' );
	}

	/**
	 * Fix link targets on a single post:
	 *   - External links get target="_blank" rel="noopener noreferrer"
	 *   - Internal links have target="_blank" removed
	 * Returns number of links changed.
	 */
	public static function fix_link_targets( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) return 0;

		$home    = home_url();
		$content = $post->post_content;
		if ( ! $content ) return 0;
		$changes = 0;

		$new_content = preg_replace_callback(
			'/<a(\s[^>]*)>/i',
			function( $m ) use ( $home, &$changes ) {
				$attrs = $m[1];
				if ( ! preg_match( '/\bhref=["\']([^"\']*)["\']/', $attrs, $hm ) ) {
					return '<a' . $attrs . '>';
				}
				$href = $hm[1];
				if ( preg_match( '/^(tel:|mailto:|javascript:|callto:|sms:|#)/i', $href ) ) {
					return '<a' . $attrs . '>';
				}
				$is_internal = str_starts_with( $href, $home )
					|| str_starts_with( $href, '/' )
					|| ( ! str_starts_with( $href, 'http' ) );

				if ( $is_internal ) {
					$new = preg_replace( '/\s+target=["\'][^"\']*["\']/', '', $attrs );
					if ( $new !== $attrs ) $changes++;
					return '<a' . $new . '>';
				}

				$new = $attrs;
				if ( ! preg_match( '/\btarget=/', $new ) ) {
					$new .= ' target="_blank"';
					$changes++;
				} elseif ( ! preg_match( '/\btarget=["\']_blank["\']/', $new ) ) {
					$new = preg_replace( '/\btarget=["\'][^"\']*["\']/', 'target="_blank"', $new );
					$changes++;
				}
				if ( ! preg_match( '/\brel=/', $new ) ) {
					$new .= ' rel="noopener noreferrer"';
				} elseif ( ! preg_match( '/\brel=["\'][^"\']*noopener/', $new ) ) {
					$new = preg_replace( '/\brel=["\']([^"\']*)["\']/', 'rel="$1 noopener noreferrer"', $new );
				}
				return '<a' . $new . '>';
			},
			$content
		);

		if ( $changes > 0 && is_string( $new_content ) && $new_content !== $content ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
			self::invalidate( $post_id );
		}
		return $changes;
	}

	/**
	 * Fix link targets across all published posts/pages/RS pages.
	 */
	public static function fix_all_link_targets(): array {
		global $wpdb;
		@set_time_limit( 120 ); // phpcs:ignore
		$post_ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('ratesight_page', 'post', 'page')"
		); // phpcs:ignore
		$pages_fixed = 0; $total_changes = 0;
		foreach ( $post_ids as $post_id ) {
			$n = self::fix_link_targets( (int) $post_id );
			$total_changes += $n;
			if ( $n > 0 ) $pages_fixed++;
		}
		return array( 'pages_fixed' => $pages_fixed, 'total_changes' => $total_changes );
	}

	/**
	 * Return every ignored link across all scanned posts.
	 */
	public static function get_all_ignored(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT lc.post_id, lc.broken_urls, p.post_title, p.post_type
			 FROM `{$table}` lc
			 INNER JOIN {$wpdb->posts} p ON p.ID = lc.post_id
			 WHERE lc.broken_urls IS NOT NULL
			   AND p.post_status = 'publish'
			 ORDER BY p.post_title ASC",
			ARRAY_A
		); // phpcs:ignore

		$result = array();
		foreach ( $rows as $row ) {
			$links = json_decode( $row['broken_urls'] ?? '[]', true ) ?: array();
			foreach ( $links as $b ) {
				if ( ( $b['status'] ?? '' ) !== 'ignored' ) continue;
				$result[] = array(
					'post_id'    => (int) $row['post_id'],
					'post_title' => $row['post_title'],
					'post_type'  => $row['post_type'],
					'url'        => $b['url']    ?? '',
					'anchor'     => $b['anchor'] ?? '',
					'code'       => $b['code']   ?? 0,
				);
			}
		}
		return $result;
	}

	/**
	 * Unignore a previously ignored URL — sets status back to 'broken'.
	 */
	public static function unignore_broken( int $post_id, string $url ): array {
		return self::update_broken_status( $post_id, $url, 'broken' );
	}

	/**
	 * Return every active broken link across all scanned posts — for the global view.
	 * Each item: { post_id, post_title, post_type, url, anchor, code }
	 */
	public static function get_all_broken(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT lc.post_id, lc.broken_urls, p.post_title, p.post_type
			 FROM `{$table}` lc
			 INNER JOIN {$wpdb->posts} p ON p.ID = lc.post_id
			 WHERE lc.broken_count > 0
			   AND p.post_status = 'publish'
			 ORDER BY p.post_title ASC",
			ARRAY_A
		); // phpcs:ignore

		$result = array();
		foreach ( $rows as $row ) {
			$broken = json_decode( $row['broken_urls'] ?? '[]', true ) ?: array();
			foreach ( $broken as $b ) {
				$status = $b['status'] ?? 'broken';
				if ( $status !== 'broken' && $status !== 'possibly_blocked' ) continue;
				$url = $b['url'] ?? '';
				if ( preg_match( '/^(tel:|mailto:|javascript:|callto:|sms:|#)/i', $url ) ) continue;
				$result[] = array(
					'post_id'          => (int) $row['post_id'],
					'post_title'       => $row['post_title'],
					'post_type'        => $row['post_type'],
					'url'              => $url,
					'anchor'           => $b['anchor'] ?? '',
					'code'             => $b['code']   ?? 0,
					'possibly_blocked' => $status === 'possibly_blocked',
				);
			}
		}
		return $result;
	}
	public static function get_broken_detail( int $post_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT broken_urls, outbound_urls FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) return array();

		$broken   = json_decode( $row['broken_urls']   ?? '[]', true ) ?: array();
		$outbound = json_decode( $row['outbound_urls']  ?? '[]', true ) ?: array();

		// Annotate with authority classification for ALL outbound links.
		$annotated_outbound = array();
		foreach ( $outbound as $link ) {
			$url  = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$annotated_outbound[] = array(
				'url'        => $url,
				'anchor'     => is_array( $link ) ? ( $link['anchor'] ?? '' ) : '',
				'is_internal' => is_array( $link ) ? ( $link['is_internal'] ?? false ) : str_starts_with( $url, home_url() ),
				'authority'  => self::classify_external( $url ),
			);
		}

		return array(
			'broken'   => $broken,
			'outbound' => $annotated_outbound,
		);
	}

	// ─── External link authority classification ───────────────────────────────

	/**
	 * Classify an external URL.
	 * Returns: 'internal' | 'authority' | 'neutral' | 'caution'
	 *
	 * caution = unknown domain that might be a competitor or low-quality site.
	 */
	public static function classify_external( string $url ): string {
		if ( str_starts_with( $url, home_url() ) ) return 'internal';

		$host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
		$host = preg_replace( '/^www\./', '', $host );

		// TLD-based authority (government, education, international orgs).
		if ( preg_match( '/\.(gov|edu|mil|nhs\.uk|gov\.uk|gov\.au|gc\.ca)$/', $host ) ) {
			return 'authority';
		}

		// Curated authority domains.
		static $authority_domains = array(
			'wikipedia.org', 'wikimedia.org',
			'who.int', 'cdc.gov', 'nih.gov', 'mayoclinic.org', 'webmd.com',
			'bbb.org', 'ftc.gov', 'usa.gov',
			'fmcsa.dot.gov', 'dot.gov',                   // moving industry
			'acim.org', 'amsa.org',                        // moving associations
			'yelp.com', 'google.com', 'maps.google.com',  // review platforms
			'facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com',
			'reuters.com', 'apnews.com', 'nytimes.com', 'wsj.com',
			'forbes.com', 'businessinsider.com', 'inc.com',
			'archive.org', 'web.archive.org',
		);

		foreach ( $authority_domains as $d ) {
			if ( $host === $d || str_ends_with( $host, '.' . $d ) ) {
				return 'authority';
			}
		}

		// Extra approved domains from plugin settings.
		$approved = array_filter( array_map(
			'trim',
			explode( "\n", Ratesight_Options::get( 'link_approved_domains' ) ?? '' )
		) );
		foreach ( $approved as $d ) {
			$d = strtolower( preg_replace( '/^www\./', '', $d ) );
			if ( $host === $d || str_ends_with( $host, '.' . $d ) ) {
				return 'authority';
			}
		}

		return 'caution'; // Unknown — flag for review.
	}

	/**
	 * Cron job: check broken links for one batch of unchecked posts.
	 * Called by ratesight_check_broken_links cron, 20 posts per run.
	 */
	public static function cron_check_broken(): void {
		if ( get_option( self::OPT_BROKEN_LOCK ) ) return;
		update_option( self::OPT_BROKEN_LOCK, 1, false );

		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';

		// Process up to 100 pages per cron run (increased from 20).
		// This acts as a resume mechanism if the browser-based check was interrupted.
		$unchecked = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT post_id FROM `{$table}` WHERE broken_count = -1 ORDER BY post_id ASC LIMIT %d",
			100
		) );

		foreach ( $unchecked as $post_id ) {
			self::check_broken( (int) $post_id );
		}

		// If there are still unchecked pages, schedule another immediate run.
		if ( count( $unchecked ) === 100 ) {
			wp_schedule_single_event( time() + 30, 'ratesight_check_broken_links' );
		}

		delete_option( self::OPT_BROKEN_LOCK );
	}

	// ─── Link suggestions ────────────────────────────────────────────────────

	/**
	 * Get AI-scored link suggestions for a given source post.
	 * Results are cached as a transient for 24 hours.
	 *
	 * Returns array of suggestions:
	 *   [ source_post_id, target_post_id, target_title, target_url,
	 *     anchor_text, score, reason, anchor_in_content ]
	 */
	public static function get_suggestions( int $source_post_id ): array|\WP_Error {
		$cached = get_transient( 'rs_link_suggest_' . $source_post_id );
		if ( $cached !== false ) return $cached;

		$source = get_post( $source_post_id );
		if ( ! $source || $source->post_status !== 'publish' ) {
			return new \WP_Error( 'no_post', 'Post not found.' );
		}

		global $wpdb;

		// Single query: fetch ID, title, excerpt for all candidate pages.
		// Excerpt captures what the page is actually about — used for anchor matching.
		$rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID, post_title, post_excerpt, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('ratesight_page', 'post')
			   AND ID != %d
			 LIMIT 800",
			$source_post_id
		), ARRAY_A ); // phpcs:ignore

		if ( empty( $rows ) ) return array();

		// Identify already-linked targets so we don't re-suggest them.
		$existing_links = self::extract_links( $source->post_content );
		$existing_urls  = array_map( 'trailingslashit', $existing_links );

		$plain_source = wp_strip_all_tags( $source->post_content );

		$candidates = array();
		foreach ( $rows as $r ) {
			$target_id      = (int) $r['ID'];
			$target_title   = $r['post_title'];
			$target_excerpt = wp_strip_all_tags( $r['post_excerpt'] ?? '' );

			// Build anchor candidates from target's title AND excerpt —
			// this grounds the link text in what the target page actually covers.
			$anchor = self::find_anchor_from_target( $target_title, $target_excerpt, $plain_source );
			if ( ! $anchor ) continue;

			$candidates[] = array(
				'source_id'         => $source_post_id,
				'source_title'      => $source->post_title,
				'target_id'         => $target_id,
				'target_title'      => $target_title,
				'target_excerpt'    => $target_excerpt,
				'target_url'        => null, // resolved below for shortlist only
				'anchor_text'       => $anchor,
				'anchor_in_content' => self::anchor_exists_in_content( $anchor, $source->post_content ),
			);
		}

		if ( empty( $candidates ) ) {
			set_transient( 'rs_link_suggest_' . $source_post_id, array(), HOUR_IN_SECONDS );
			return array();
		}

		// Sort by anchor phrase length (longer = more specific = better) and cap before expensive ops.
		usort( $candidates, fn( $a, $b ) => strlen( $b['anchor_text'] ) <=> strlen( $a['anchor_text'] ) );
		$candidates = array_slice( $candidates, 0, 30 );

		// Resolve permalinks only for the shortlisted candidates (30 max).
		foreach ( $candidates as &$c ) {
			$url = get_permalink( $c['target_id'] );
			if ( ! $url ) { $c['_skip'] = true; continue; }
			if ( in_array( trailingslashit( $url ), $existing_urls, true ) ) { $c['_skip'] = true; continue; }
			$c['target_url'] = $url;
		}
		unset( $c );
		$candidates = array_values( array_filter( $candidates, fn( $c ) => empty( $c['_skip'] ) ) );

		if ( empty( $candidates ) ) {
			set_transient( 'rs_link_suggest_' . $source_post_id, array(), HOUR_IN_SECONDS );
			return array();
		}

		// AI scoring pass — one batch call on the shortlist.
		$scored = self::ai_score_candidates( $source->post_title, $candidates );
		if ( is_wp_error( $scored ) ) return $scored;

		$scored = array_filter( $scored, fn( $s ) => ( $s['score'] ?? 0 ) >= self::AI_MIN_SCORE );
		usort( $scored, fn( $a, $b ) => ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 ) );
		$scored = array_values( array_slice( $scored, 0, 8 ) );  // Max 8 suggestions

		$scored = self::annotate_diversity( $scored );

		set_transient( 'rs_link_suggest_' . $source_post_id, $scored, DAY_IN_SECONDS );
		return $scored;
	}

	// ─── Link insertion ──────────────────────────────────────────────────────

	/**
	 * Insert a link into a post's content.
	 *
	 * Only inserts on the FIRST occurrence of anchor_text (case-insensitive,
	 * whole-word match) that is not already inside an <a> tag.
	 * Stores the link in _rs_manual_links meta so it survives webhook updates.
	 *
	 * Returns array( 'ok' => bool, 'message' => string ).
	 */
	public static function insert_link( int $post_id, string $anchor_text, string $target_url ): array {
		$post = get_post( $post_id );
		if ( ! $post ) return array( 'ok' => false, 'message' => 'Post not found.' );

		$content = $post->post_content;

		// Ensure anchor text actually appears in content outside an existing <a>.
		if ( ! self::anchor_exists_in_content( $anchor_text, $content ) ) {
			return array( 'ok' => false, 'message' => "\"$anchor_text\" was not found in the post content outside an existing link." );
		}

		// Use get_permalink() to ensure we always use the clean URL, not internal rs-page/ slug.
		$target_post = url_to_postid( $target_url );
		if ( $target_post ) {
			$canonical_url = get_permalink( $target_post );
			if ( $canonical_url ) $target_url = $canonical_url;
		}

		$link_html   = '<a href="' . esc_url( $target_url ) . '">' . esc_html( $anchor_text ) . '</a>';
		$new_content = self::replace_first_anchor( $anchor_text, $link_html, $content );

		if ( $new_content === $content ) {
			return array( 'ok' => false, 'message' => 'Could not insert link — anchor text may be inside an existing tag.' );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );

		// Persist in meta so webhook updates can re-apply.
		self::store_manual_link( $post_id, $anchor_text, $target_url );

		// Invalidate link cache for both source and target.
		self::invalidate( $post_id );
		if ( $target_post ) self::invalidate( $target_post );

		return array( 'ok' => true, 'message' => 'Link inserted.' );
	}

	/**
	 * Re-apply all manually-stored links after a webhook content update.
	 * Called by Ratesight_Webhook_Handler after writing new post_content.
	 */
	public static function reapply_manual_links( int $post_id ): void {
		$manual = get_post_meta( $post_id, self::META_MANUAL_LINKS, true );
		if ( empty( $manual ) || ! is_array( $manual ) ) return;

		$post = get_post( $post_id );
		if ( ! $post ) return;

		$content = $post->post_content;
		$changed = false;

		foreach ( $manual as $link ) {
			$anchor = $link['anchor'] ?? '';
			$url    = $link['url']    ?? '';
			if ( ! $anchor || ! $url ) continue;

			// Skip if anchor text no longer exists in content (content changed too much).
			if ( ! self::anchor_exists_in_content( $anchor, $content ) ) continue;

			$link_html   = '<a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a>';
			$new_content = self::replace_first_anchor( $anchor, $link_html, $content );

			if ( $new_content !== $content ) {
				$content = $new_content;
				$changed = true;
			}
		}

		if ( $changed ) {
			// Use wpdb directly to avoid triggering post_updated hooks in a loop.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ), array( '%s' ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			clean_post_cache( $post_id );
		}
	}

	// ─── Report data ─────────────────────────────────────────────────────────

	/**
	 * Return the full link report for the admin tab.
	 * Includes cache status, stale flag, orphan flag per post.
	 */
	public static function get_report(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';

		// Single LEFT JOIN — cached AND uncached posts in one query, no PHP loop.
		// NULLs in lc.* columns = not yet scanned.
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				p.ID              AS post_id,
				p.post_title,
				p.post_type,
				lc.inbound_count,
				lc.outbound_count,
				lc.broken_count,
				lc.scanned_at,
				lc.stale
			 FROM {$wpdb->posts} p
			 LEFT JOIN `{$table}` lc ON lc.post_id = p.ID
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('post', 'page', 'ratesight_page')
			 ORDER BY ISNULL(lc.inbound_count) ASC, lc.inbound_count ASC, p.post_title ASC",
			ARRAY_A
		); // phpcs:ignore

		return $rows ?: array();
	}

	// ─── AI scoring ──────────────────────────────────────────────────────────

	private static function ai_score_candidates( string $source_title, array $candidates ): array|\WP_Error {
		// Load domain rules to guide scoring.
		$approved = array_filter( array_map( 'trim', explode( "\n", get_option( 'ratesight_link_approved_domains', '' ) ) ) );
		$excluded = array_filter( array_map( 'trim', explode( "\n", get_option( 'ratesight_link_excluded_domains', '' ) ) ) );

		$domain_rules = '';
		if ( ! empty( $approved ) ) {
			$domain_rules .= "\nApproved external sources (score highly if linking to these makes sense): " . implode( ', ', $approved );
		}
		if ( ! empty( $excluded ) ) {
			$domain_rules .= "\nNever suggest linking to these domains (score 1 if the target URL is on these): " . implode( ', ', $excluded );
		}

		// Build a compact prompt — one batch call for all candidates.
		$list = '';
		foreach ( $candidates as $i => $c ) {
			$excerpt_note = ! empty( $c['target_excerpt'] ) ? ' | About: "' . wp_trim_words( $c['target_excerpt'], 12 ) . '"' : '';
			$list .= sprintf(
				"%d. Source: \"%s\" | Target: \"%s\"%s | Anchor: \"%s\"\n",
				$i + 1,
				$source_title,
				$c['target_title'],
				$excerpt_note,
				$c['anchor_text']
			);
		}

		$prompt = "You are an internal linking SEO assistant. For each proposed link below, return a JSON array of objects with keys: index (1-based), score (integer 1-10), reason (one sentence). Score 10 = perfect topical match, 1 = completely unrelated. Be strict — a score of 6+ means you would confidently recommend this link.{$domain_rules}\n\n$list\n\nReturn ONLY valid JSON, no explanation.";

		$result = Ratesight_AI_Client::prompt( $prompt, array(), 30 );

		if ( ! $result['ok'] ) {
			// AI unavailable — score by anchor/overlap quality rather than giving everything a neutral 7.
			return array_map( function( $c ) {
				$anchor_words = str_word_count( $c['anchor_text'] ?? '' );
				$overlap      = count( $c['overlap_words'] ?? array() );
				// Multi-word anchors with good overlap get higher scores.
				$score = min( 8, 4 + $anchor_words + min( 3, $overlap - 2 ) );
				return array_merge( $c, array(
					'score'  => max( 1, $score ),
					'reason' => 'Scored by keyword overlap (AI unavailable).',
				) );
			}, $candidates );
		}

		// Strip code fences and parse AI response.
		$json_str = preg_replace( '/^```(?:json)?\s*/m', '', $result['reply'] );
		$json_str = preg_replace( '/\s*```$/m', '', trim( $json_str ) );
		$scores   = json_decode( $json_str, true );

		if ( ! is_array( $scores ) ) {
			return array_map( fn( $c ) => array_merge( $c, array( 'score' => 7, 'reason' => 'AI response parse error.' ) ), $candidates );
		}

		// Merge scores back into candidates by index.
		$score_map = array();
		foreach ( $scores as $s ) {
			if ( isset( $s['index'] ) ) {
				$score_map[ (int) $s['index'] - 1 ] = $s;
			}
		}

		foreach ( $candidates as $i => &$c ) {
			$s         = $score_map[ $i ] ?? array();
			$c['score']  = isset( $s['score'] ) ? (int) $s['score'] : 7;
			$c['reason'] = $s['reason'] ?? '';
		}
		unset( $c );

		return $candidates;
	}

	// ─── Anchor diversity ────────────────────────────────────────────────────

	private static function annotate_diversity( array $suggestions ): array {
		global $wpdb;

		// Count how many RS pages already link the same anchor → same target.
		foreach ( $suggestions as &$s ) {
			$anchor     = $s['anchor_text'];
			$target_url = trailingslashit( $s['target_url'] );
			$pattern    = '%' . $wpdb->esc_like( 'href="' . $target_url ) . '%';

			// Quick scan: count posts that already contain this anchor linked to this URL.
			$count = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'ratesight_page'
				   AND post_status = 'publish'
				   AND post_content LIKE %s
				   AND post_content LIKE %s",
				'%' . $wpdb->esc_like( $anchor ) . '%',
				$pattern
			) );

			$s['diversity_count']   = $count;
			$s['diversity_warning'] = $count >= 5;
		}
		unset( $s );

		return $suggestions;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Extract all href values from post_content.
	 * Returns plain URLs — kept for backward compat with suggestion logic.
	 */
	public static function extract_links( string $content ): array {
		$rich = self::extract_links_rich( $content, array() );
		return array_values( array_unique( array_column( $rich, 'url' ) ) );
	}

	/**
	 * Extract links as rich objects: {url, anchor, is_internal, target_post_id}.
	 * Used by scan_all and check_broken to capture anchor text alongside href.
	 */
	public static function extract_links_rich( string $content, array $url_to_id ): array {
		if ( ! $content ) return array();
		$home = home_url();

		preg_match_all(
			'/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i',
			$content, $matches, PREG_SET_ORDER
		);

		$seen  = array();
		$links = array();
		foreach ( $matches as $m ) {
			$url    = $m[1] ?? '';
			$anchor = trim( wp_strip_all_tags( $m[2] ?? '' ) );
			if ( ! $url || isset( $seen[ $url ] ) ) continue;
			$seen[ $url ] = true;

			$is_internal = str_starts_with( $url, $home );
			$target_id   = null;
			if ( $is_internal ) {
				$target_id = $url_to_id[ trailingslashit( $url ) ]
					?? $url_to_id[ untrailingslashit( $url ) ]
					?? null;
			}
			$links[] = array(
				'url'            => $url,
				'anchor'         => $anchor,
				'is_internal'    => $is_internal,
				'target_post_id' => $target_id,
			);
		}
		return $links;
	}

	/** Get anchor text for a specific href from post content. */
	private static function get_anchor_for_url( string $content, string $url ): string {
		preg_match( '/<a[^>]+href=["\']' . preg_quote( $url, '/' ) . '["\'][^>]*>([\s\S]*?)<\/a>/i', $content, $m );
		return trim( wp_strip_all_tags( $m[1] ?? '' ) );
	}

	/** Update the status of a single entry in the broken_urls JSON. */
	private static function update_broken_status( int $post_id, string $url, string $status, string $replacement = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT broken_urls FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = json_decode( $row['broken_urls'] ?? '[]', true ) ?: array();
		$found = false;
		foreach ( $links as &$l ) {
			if ( ( $l['url'] ?? '' ) === $url ) {
				$l['status'] = $status;
				if ( $replacement ) $l['replacement_url'] = $replacement;
				$found = true;
				break;
			}
		}
		unset( $l );
		if ( ! $found ) $links[] = array( 'url' => $url, 'anchor' => '', 'code' => 0, 'status' => $status );

		$broken_count = count( array_filter( $links, fn( $b ) => ( $b['status'] ?? '' ) === 'broken' ) );
		$wpdb->update( $table,  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			array( 'broken_count' => $broken_count, 'broken_urls' => wp_json_encode( $links ) ),
			array( 'post_id' => $post_id ), array( '%d', '%s' ), array( '%d' )
		);
		return array( 'ok' => true );
	}

	/** Remove a URL from _rs_manual_links meta. */
	private static function remove_manual_link( int $post_id, string $url ): void {
		$links = get_post_meta( $post_id, self::META_MANUAL_LINKS, true ) ?: array();
		update_post_meta( $post_id, self::META_MANUAL_LINKS,
			array_values( array_filter( $links, fn( $l ) => ( $l['url'] ?? '' ) !== $url ) )
		);
	}

	/** Update URL in _rs_manual_links meta after replacement. */
	private static function update_manual_link_url( int $post_id, string $old_url, string $new_url ): void {
		$links = get_post_meta( $post_id, self::META_MANUAL_LINKS, true ) ?: array();
		foreach ( $links as &$l ) {
			if ( ( $l['url'] ?? '' ) === $old_url ) $l['url'] = $new_url;
		}
		unset( $l );
		update_post_meta( $post_id, self::META_MANUAL_LINKS, $links );
	}

	/** Public wrapper for extract_keywords (usable as static from outside). */
	public static function extract_keywords_static( string $text ): array {
		return self::extract_keywords( $text );
	}

	/**
	 * Extract meaningful keywords from text.
	 * Strips stop words, returns lowercase unique words of length >= 4.
	 */
	private static function extract_keywords( string $text ): array {
		static $stop_words = array(
			// Common English
			'this','that','with','from','have','been','will','your','they',
			'more','also','what','when','where','which','their','there',
			'about','after','before','other','into','over','some','such',
			'than','then','them','were','these','those','would','could',
			'should','each','make','many','most','much','only','said',
			'same','than','well','very','just','even','both','here',
			// Too generic to be useful anchors
			'tips','help','home','area','move','pack','local','best',
			'here','find','need','want','know','free','fast','easy',
			'time','work','good','great','right','sure','made','make',
			'used','uses','used','need','keep','take','give','come',
			'back','give','come','last','look','like','just','into',
			'more','also','than','well','even','some','such','most',
			'many','much','each','only','very','over','back','also',
		);

		$text  = strtolower( wp_strip_all_tags( $text ) );
		$text  = preg_replace( '/[^a-z0-9\s-]/', ' ', $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		// Minimum 5 chars and not a stop word.
		$words = array_filter( $words, fn( $w ) => strlen( $w ) >= 5 && ! in_array( $w, $stop_words, true ) );
		return array_values( array_unique( $words ) );
	}

	/**
	 * Find the best anchor text by looking for phrases from the TARGET page's
	 * title and excerpt within the SOURCE page's content.
	 *
	 * Strategy:
	 *  1. If full target title appears verbatim in source → use it.
	 *  2. Extract 2-3 word ngrams from target title + excerpt.
	 *  3. Find the longest one present in the source (outside existing links).
	 *  4. Single meaningful words (7+ chars) as a last resort.
	 */
	private static function find_anchor_from_target( string $target_title, string $target_excerpt, string $plain_source ): ?string {
		$lower_source = strtolower( $plain_source );

		// 1. Full title match.
		if ( strlen( $target_title ) >= 5 && stripos( $plain_source, $target_title ) !== false ) {
			return $target_title;
		}

		// 2. Extract ngrams from title + excerpt.
		$text = strtolower( wp_strip_all_tags( $target_title . ' ' . $target_excerpt ) );
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$words = array_values( array_filter(
			preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ),
			fn( $w ) => strlen( $w ) >= 4
		) );

		static $stop = array(
			'this','that','with','from','have','been','will','your','they','more','also',
			'what','when','where','which','their','there','about','after','before','other',
			'into','over','some','such','than','then','them','were','these','those','would',
			'could','should','each','many','most','much','only','just','even','both','here',
			'tips','help','home','area','move','pack','local','best','find','need','want',
			'know','free','fast','easy','time','work','good','great','right','sure',
		);

		// Build candidate phrases: 3-word, 2-word, 1-word (long only).
		$phrases = array();
		$n = count( $words );
		for ( $i = 0; $i < $n - 2; $i++ ) {
			$p = implode( ' ', array_slice( $words, $i, 3 ) );
			if ( ! in_array( $words[ $i ], $stop, true ) ) $phrases[] = $p;
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			if ( ! in_array( $words[ $i ], $stop, true ) && ! in_array( $words[ $i + 1 ], $stop, true ) ) {
				$phrases[] = implode( ' ', array_slice( $words, $i, 2 ) );
			}
		}
		foreach ( $words as $w ) {
			if ( strlen( $w ) >= 7 && ! in_array( $w, $stop, true ) ) $phrases[] = $w;
		}

		// Sort by descending length — prefer longer, more specific phrases.
		usort( $phrases, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		foreach ( $phrases as $phrase ) {
			if ( strlen( $phrase ) >= 5 && strpos( $lower_source, $phrase ) !== false ) {
				return $phrase;
			}
		}

		return null;
	}

	/**
	 * Check that anchor text appears in content OUTSIDE an existing <a> tag.
	 */
	public static function anchor_exists_in_content( string $anchor, string $content ): bool {
		// Remove all <a>...</a> blocks, then check if anchor still exists.
		$stripped = preg_replace( '/<a[^>]*>.*?<\/a>/is', '', $content );
		return stripos( wp_strip_all_tags( $stripped ), $anchor ) !== false;
	}

	/**
	 * Replace the FIRST occurrence of anchor_text (outside <a> tags) with link_html.
	 */
	private static function replace_first_anchor( string $anchor, string $link_html, string $content ): string {
		// Split content on <a> tags to avoid replacing text inside existing links.
		$parts = preg_split( '/(<a[\s\S]*?<\/a>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$done  = false;

		foreach ( $parts as &$part ) {
			if ( $done ) break;
			if ( preg_match( '/^<a/i', $part ) ) continue; // inside a link — skip

			$pos = stripos( $part, $anchor );
			if ( $pos === false ) continue;

			// Whole-word check.
			$before = $pos > 0 ? $part[ $pos - 1 ] : ' ';
			$after  = isset( $part[ $pos + strlen( $anchor ) ] ) ? $part[ $pos + strlen( $anchor ) ] : ' ';
			if ( ctype_alnum( $before ) || ctype_alnum( $after ) ) continue;

			$part = substr_replace( $part, $link_html, $pos, strlen( $anchor ) );
			$done = true;
		}
		unset( $part );

		return implode( '', $parts );
	}

	/**
	 * Store a manually-inserted link in post meta.
	 */
	private static function store_manual_link( int $post_id, string $anchor, string $url ): void {
		$links = get_post_meta( $post_id, self::META_MANUAL_LINKS, true ) ?: array();

		// Deduplicate by anchor+url.
		foreach ( $links as $existing ) {
			if ( $existing['anchor'] === $anchor && $existing['url'] === $url ) return;
		}

		$links[] = array( 'anchor' => $anchor, 'url' => $url, 'added' => current_time( 'mysql' ) );
		update_post_meta( $post_id, self::META_MANUAL_LINKS, $links );
	}

	/**
	 * HTTP HEAD request with GET fallback. Returns status code or 0 on error.
	 */
	private static function head_url( string $url ): int {
		$r = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0' ) );
		if ( is_wp_error( $r ) ) {
			$r = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0' ) );
		}
		return is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
	}
}
