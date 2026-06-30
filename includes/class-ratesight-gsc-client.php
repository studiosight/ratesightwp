<?php
/**
 * Google Search Console API client.
 *
 * Extended to support:
 *   - Historical position tracking (one row per post per day)
 *   - Per-keyword rank tracking (top 10 queries per page per day)
 *   - Trend calculation (position delta vs 7 days and 30 days ago)
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_GSC_Client {

	private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';
	private const QUERY_URL = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';

	// -------------------------------------------------------------------------
	// Setup helpers
	// -------------------------------------------------------------------------

	public static function list_properties(): array|\WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gsc' );
		if ( is_wp_error( $token ) ) return $token;

		$response = self::get( self::SITES_URL, $token );
		if ( is_wp_error( $response ) ) return $response;

		$sites = $response['siteEntry'] ?? array();
		if ( empty( $sites ) ) {
			return new \WP_Error( 'rs_gsc_no_properties', 'No Search Console properties found for this account.' );
		}

		return array_map( static fn( $s ) => array(
			'url'        => $s['siteUrl']         ?? '',
			'permission' => $s['permissionLevel'] ?? '',
		), $sites );
	}

	// -------------------------------------------------------------------------
	// Connection state
	// -------------------------------------------------------------------------

	public static function is_locked() {
		return (bool) get_option( 'ratesight_gsc_locked', false );
	}

	public static function get_selection() {
		return (array) get_option( 'ratesight_gsc_selection', array() );
	}

	public static function lock_selection( string $property_url ) {
		update_option( 'ratesight_gsc_selection', array( 'url' => $property_url ), false );
		update_option( 'ratesight_gsc_locked', 1, false );
	}

	// -------------------------------------------------------------------------
	// Cron: daily sync
	// -------------------------------------------------------------------------

	/**
	 * Called daily by WP-Cron.
	 * Stores a new row per post per day (historical accumulation).
	 * Then fetches top 10 keywords per page and stores those too.
	 */
	public static function sync_performance() {
		if ( ! Ratesight_OAuth_Client::is_connected( 'gsc' ) || ! self::is_locked() ) return;

		$selection = self::get_selection();
		$property  = $selection['url'] ?? '';
		if ( $property === '' ) return;

		$token = Ratesight_OAuth_Client::get_access_token( 'gsc' );
		if ( is_wp_error( $token ) ) return;

		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$kw_table   = $wpdb->prefix . 'ratesight_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$today      = current_time( 'Y-m-d' );

		// ── 1. Page-level performance — query 90 days of per-day data ─────────
		// Returns shape: [ url => [ date => metrics ] ] so we can store one row per day.
		$page_data = self::query_by_page( $property, $token, 90 );
		if ( is_wp_error( $page_data ) ) {
			error_log( 'Ratesight GSC sync error: ' . $page_data->get_error_message() ); // phpcs:ignore
			return;
		}

		// Build a URL → post_id map for the entire site so we can match ANY
		// GSC URL back to a WordPress post — not just Ratesight pages.
		// url_to_postid() is accurate but slow at scale; we batch-query instead.
		$all_posts = get_posts( array(
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$url_to_id = array();
		foreach ( $all_posts as $pid ) {
			$permalink = get_permalink( $pid );
			if ( ! $permalink ) continue;
			$url_to_id[ trailingslashit( $permalink ) ]    = $pid;
			$url_to_id[ untrailingslashit( $permalink ) ]  = $pid;
		}

		$matched_posts = array(); // post_id => url, for keyword fetch

		// Iterate over every URL GSC returned and match to a WP post.
		foreach ( $page_data as $gsc_url => $by_date ) {
			if ( empty( $by_date ) ) continue;

			$post_id = $url_to_id[ $gsc_url ]
				?? $url_to_id[ trailingslashit( $gsc_url ) ]
				?? $url_to_id[ untrailingslashit( $gsc_url ) ]
				?? 0;

			if ( ! $post_id ) continue; // GSC URL doesn't match any WP post — skip.

			$matched_posts[ $post_id ] = $gsc_url;

			// Insert or update one row per day for this post.
			foreach ( $by_date as $date => $row ) {
				$wpdb->replace( $perf_table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					'post_id'     => $post_id,
					'url'         => $gsc_url,
					'date'        => $date,
					'impressions' => $row['impressions'],
					'clicks'      => $row['clicks'],
					'position'    => $row['position'],
					'ctr'         => $row['ctr'],
				), array( '%d', '%s', '%s', '%d', '%d', '%f', '%f' ) );
			}
		}

		// ── 2. Keyword-level data — bulk query for all matched Ratesight pages ─
		$ratesight_post_ids = self::get_ratesight_post_ids();
		$matched_rs_posts   = array();
		foreach ( $matched_posts as $post_id => $url ) {
			if ( in_array( $post_id, $ratesight_post_ids, false ) ) {
				$matched_rs_posts[] = array( 'post_id' => $post_id, 'url' => $url );
			}
		}

		self::sync_keywords_bulk( $property, $token, $matched_rs_posts );

		update_option( 'ratesight_gsc_last_sync', current_time( 'mysql' ) ); // autoload=true so it's always fresh
		delete_transient( 'ratesight_ai_insights' ); // Invalidate cached AI insights

		// ── Prune old performance data ────────────────────────────────────────
		$keep_days = max( 90, (int) Ratesight_Options::get( 'performance_retention_days' ) );
		$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"DELETE FROM `{$perf_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$keep_days
		) );
		$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"DELETE FROM `{$kw_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$keep_days
		) );
	}

	// -------------------------------------------------------------------------
	// Chunked sync — each step is a separate AJAX call so Cloudflare / nginx
	// gateway timeouts (60-100 s) are never reached.
	// -------------------------------------------------------------------------

	/**
	 * Step 1: Fetch page-level GSC data and store matched posts as a transient.
	 * Returns list of [post_id, url] pairs to process in step 2.
	 */
	public static function sync_step_pages(): array|\WP_Error {
		if ( ! Ratesight_OAuth_Client::is_connected( 'gsc' ) || ! self::is_locked() ) {
			return new \WP_Error( 'not_ready', 'GSC not connected or no property locked.' );
		}

		$selection = self::get_selection();
		$property  = $selection['url'] ?? '';
		if ( $property === '' ) {
			return new \WP_Error( 'no_property', 'No GSC property selected.' );
		}

		$token = Ratesight_OAuth_Client::get_access_token( 'gsc' );
		if ( is_wp_error( $token ) ) return $token;

		$page_data = self::query_by_page( $property, $token, 28 );
		if ( is_wp_error( $page_data ) ) return $page_data;

		$post_ids = self::get_ratesight_post_ids();

		global $wpdb;
		$perf_table    = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$today         = current_time( 'Y-m-d' );
		$matched_posts = array();

		foreach ( $post_ids as $post_id ) {
			$url = get_permalink( $post_id );
			if ( ! $url ) continue;

			$row = $page_data[ $url ]
				?? $page_data[ rtrim( $url, '/' ) ]
				?? $page_data[ $url . '/' ]
				?? null;

			if ( ! $row ) continue;

			$matched_posts[] = array( 'post_id' => $post_id, 'url' => $url );

			$wpdb->replace( $perf_table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				'post_id'     => $post_id,
				'url'         => $url,
				'date'        => $today,
				'impressions' => $row['impressions'],
				'clicks'      => $row['clicks'],
				'position'    => $row['position'],
				'ctr'         => $row['ctr'],
			), array( '%d', '%s', '%s', '%d', '%d', '%f', '%f' ) );
		}

		// Store matched posts for legacy compatibility.
		set_transient( 'ratesight_sync_matched_posts', array(
			'property' => $property,
			'posts'    => $matched_posts,
		), 300 );

		// Fetch ALL keywords for all matched pages in one bulk API call.
		// This replaces the old per-post loop in the browser.
		self::sync_keywords_bulk( $property, $token, $matched_posts );

		return $matched_posts;
	}

	/**
	 * Step 2: Fetch keywords for a single post. Called once per post.
	 */
	public static function sync_step_keywords( int $post_id, string $url ) {
		// Legacy stub — bulk keyword sync is now handled in sync_step_pages().
		// Kept so any existing JS calls don't 404.
		return true;
	}

	/**
	 * Fetch ALL keywords for ALL matched pages in a single GSC API call.
	 * Called internally by sync_step_pages() — no separate browser loop needed.
	 */
	private static function sync_keywords_bulk( string $property, string $token, array $matched_posts ): void {
		if ( empty( $matched_posts ) ) return;

		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$api_url    = sprintf( self::QUERY_URL, rawurlencode( $property ) );

		$response = self::post( $api_url, $token, array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'query', 'page' ),
			'rowLimit'   => 25000,
		) );

		if ( is_wp_error( $response ) ) return;

		// Build url → post_id map from matched posts.
		$url_to_post = array();
		foreach ( $matched_posts as $p ) {
			$url_to_post[ $p['url'] ]                    = $p['post_id'];
			$url_to_post[ rtrim( $p['url'], '/' ) ]      = $p['post_id'];
			$url_to_post[ rtrim( $p['url'], '/' ) . '/' ] = $p['post_id'];
		}

		global $wpdb;
		$kw_table = $wpdb->prefix . 'ratesight_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$today    = current_time( 'Y-m-d' );

		foreach ( $response['rows'] ?? array() as $row ) {
			$query    = $row['keys'][0] ?? '';
			$page_url = $row['keys'][1] ?? '';
			$post_id  = $url_to_post[ $page_url ]
				?? $url_to_post[ rtrim( $page_url, '/' ) ]
				?? $url_to_post[ rtrim( $page_url, '/' ) . '/' ]
				?? 0;

			if ( ! $post_id || ! $query ) continue;

			$wpdb->replace( $kw_table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				'post_id'     => $post_id,
				'date'        => $today,
				'query'       => $query,
				'impressions' => (int)   ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
			), array( '%d', '%s', '%s', '%d', '%d', '%f', '%f' ) );
		}
	}

	/**
	 * Step 3: Finalise — update last_sync, prune old data, clear transients.
	 */
	public static function sync_step_finalise() {
		update_option( 'ratesight_gsc_last_sync', current_time( 'mysql' ) );
		delete_transient( 'ratesight_sync_matched_posts' );
		delete_transient( 'ratesight_ai_insights' );
		delete_transient( 'ratesight_gsc_site_overview' );

		global $wpdb;
		$keep_days  = max( 90, (int) Ratesight_Options::get( 'performance_retention_days' ) );
		$perf_table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$kw_table   = $wpdb->prefix . 'ratesight_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$perf_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $keep_days ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$kw_table}`   WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $keep_days ) ); // phpcs:ignore
	}

	// -------------------------------------------------------------------------
	// Data retrieval
	// -------------------------------------------------------------------------

	/**
	 * Get latest performance per post with trend deltas.
	 * @param int $days  Only return posts with data within this many days (7, 30, 90).
	 */
	public static function get_performance_data( int $days = 30 ) {
		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';

		// Simple reliable query — extras (sparklines, prev period, is_new) fetched separately below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				period.post_id,
				period.url,
				period.impressions,
				period.clicks,
				CASE WHEN period.impressions > 0 THEN ( period.clicks / period.impressions ) ELSE 0 END AS ctr,
				cur.position,
				cur.date,
				wp.post_title, wp.post_type,
				oldest.position AS position_start
			 FROM (
			     SELECT post_id, MAX(url) AS url,
			            SUM(impressions) AS impressions, SUM(clicks) AS clicks
			     FROM `{$perf_table}`
			     WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			     GROUP BY post_id
			 ) period
			 INNER JOIN {$wpdb->posts} wp ON wp.ID = period.post_id
			 INNER JOIN `{$perf_table}` cur
			     ON cur.post_id = period.post_id
			     AND cur.date = (
			         SELECT MAX(date) FROM `{$perf_table}` WHERE post_id = period.post_id
			     )
			 LEFT JOIN `{$perf_table}` oldest
			     ON oldest.post_id = period.post_id
			     AND oldest.date = (
			         SELECT MIN(date) FROM `{$perf_table}`
			         WHERE post_id = period.post_id
			         AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			     )
			 ORDER BY period.impressions DESC
			 LIMIT 200",
			$days, $days
		), ARRAY_A );

		if ( empty( $rows ) ) return array();

		$post_ids = array_column( $rows, 'post_id' );
		$ids_in   = implode( ',', array_map( 'intval', $post_ids ) );

		// Prev period totals (simple aggregate, no GROUP_CONCAT).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev_rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT post_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks
			 FROM `{$perf_table}`
			 WHERE post_id IN ({$ids_in})
			   AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			   AND date <  DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 GROUP BY post_id",
			$days * 2, $days
		), ARRAY_A );
		$prev_map = array();
		foreach ( $prev_rows as $p ) {
			$prev_map[ $p['post_id'] ] = array( 'impressions' => $p['impressions'], 'clicks' => $p['clicks'] );
		}

		// Sparklines — separate query with GROUP_CONCAT.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spk_rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT post_id, GROUP_CONCAT( ROUND(position,1) ORDER BY date ASC SEPARATOR ',' ) AS sparkline
			 FROM `{$perf_table}`
			 WHERE post_id IN ({$ids_in})
			   AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			 GROUP BY post_id",
			ARRAY_A
		);
		$spk_map = array();
		foreach ( $spk_rows as $s ) { $spk_map[ $s['post_id'] ] = $s['sparkline']; }

		// Posts with NO data before this period = new rankings.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_ids = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT DISTINCT post_id FROM `{$perf_table}`
			 WHERE post_id IN ({$ids_in})
			   AND date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			$days
		) );
		$has_prev = array_flip( array_map( 'intval', $existing_ids ) );

		// Merge extras into each row.
		foreach ( $rows as &$row ) {
			$pid = (int) $row['post_id'];
			$row['prev_impressions'] = (int) ( $prev_map[ $pid ]['impressions'] ?? 0 );
			$row['prev_clicks']      = (int) ( $prev_map[ $pid ]['clicks']      ?? 0 );
			$row['sparkline']        = $spk_map[ $pid ] ?? '';
			$row['is_new']           = isset( $has_prev[ $pid ] ) ? 0 : 1;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Get top keywords for a specific post with trend data.
	 */
	public static function get_keywords_for_post( int $post_id ) {
		global $wpdb;
		$kw_table = $wpdb->prefix . 'ratesight_keywords';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				k.query, k.impressions, k.clicks, k.position, k.ctr, k.date,
				k7.position AS position_7d,
				k30.position AS position_30d
			 FROM `{$kw_table}` k
			 INNER JOIN (
			     SELECT query, MAX(date) AS max_date
			     FROM `{$kw_table}`
			     WHERE post_id = %d
			     GROUP BY query
			 ) latest ON latest.query = k.query AND latest.max_date = k.date
			 AND k.post_id = %d
			 LEFT JOIN `{$kw_table}` k7
			     ON k7.post_id = %d AND k7.query = k.query
			     AND k7.date = (
			         SELECT MAX(date) FROM `{$kw_table}`
			         WHERE post_id = %d AND query = k.query
			         AND date <= DATE_SUB(k.date, INTERVAL 7 DAY)
			     )
			 LEFT JOIN `{$kw_table}` k30
			     ON k30.post_id = %d AND k30.query = k.query
			     AND k30.date = (
			         SELECT MAX(date) FROM `{$kw_table}`
			         WHERE post_id = %d AND query = k.query
			         AND date <= DATE_SUB(k.date, INTERVAL 30 DAY)
			     )
			 ORDER BY k.impressions DESC
			 LIMIT 5",
			$post_id, $post_id, $post_id, $post_id, $post_id, $post_id
		), ARRAY_A );
	}

	/**
	 * Get historical position data for a post (for trend chart).
	 */
	public static function get_position_history( int $post_id, int $days = 90 ) {
		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT date, position, impressions, clicks
			 FROM `{$perf_table}`
			 WHERE post_id = %d
			 AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 ORDER BY date ASC",
			$post_id, $days
		), ARRAY_A );
	}

	/**
	 * Get a quick site-level snapshot directly from the GSC API.
	 * Used when no Ratesight posts exist yet — shows what's possible.
	 * Cached 24h to avoid unnecessary API calls.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_site_overview(): array|\WP_Error {
		if ( ! self::is_locked() || ! Ratesight_OAuth_Client::is_connected( 'gsc' ) ) {
			return new \WP_Error( 'rs_gsc_not_connected', 'GSC not connected.' );
		}

		$cache_key = 'ratesight_gsc_site_overview_v2';
		$cached    = get_transient( $cache_key );
		if ( $cached ) return $cached;

		$token = Ratesight_OAuth_Client::get_access_token( 'gsc' );
		if ( is_wp_error( $token ) ) return $token;

		$selection = self::get_selection();
		$property  = $selection['url'] ?? '';
		if ( ! $property ) return new \WP_Error( 'rs_gsc_no_property', 'No property locked.' );

		// Get top 20 pages by impressions over last 28 days.
		$data = self::query_by_page( $property, $token, 28 );
		if ( is_wp_error( $data ) ) return $data;

		if ( empty( $data ) ) {
			return new \WP_Error( 'rs_gsc_no_data', 'No data yet — GSC data takes 48 hours to appear after pages are indexed.' );
		}

		// Aggregate stats.
		$total_impressions = array_sum( array_column( $data, 'impressions' ) );
		$total_clicks      = array_sum( array_column( $data, 'clicks' ) );
		$positions         = array_column( $data, 'position' );
		$avg_position      = count( $positions ) ? round( array_sum( $positions ) / count( $positions ), 1 ) : 0;
		$page_count        = count( $data );

		// Opportunity pages — ranked 6-20 with decent impressions, sorted by impression count.
		$opportunities = array_filter( $data, static fn( $r ) => $r['position'] >= 6 && $r['position'] <= 20 && $r['impressions'] >= 100 );
		uasort( $opportunities, static fn( $a, $b ) => $b['impressions'] - $a['impressions'] );
		$top_opps = array_slice( array_values( $opportunities ), 0, 5 );

		// Enrich with post title where we have it.
		foreach ( $top_opps as &$opp ) {
			$post = get_page_by_path( ltrim( wp_parse_url( $opp['url'] ?? '', PHP_URL_PATH ) ?? '', '/' ) );
			$opp['title'] = $post ? get_the_title( $post ) : basename( $opp['url'] ?? '' );
		}
		unset( $opp );

		$result = array(
			'total_impressions' => $total_impressions,
			'total_clicks'      => $total_clicks,
			'avg_position'      => $avg_position,
			'page_count'        => $page_count,
			'opportunities'     => $top_opps,
			'property'          => $property,
		);

		// Always invalidate the site overview cache so it rebuilds with fresh data.
		// Also delete the old v1 key to clean up any stale cached data.
		delete_transient( 'ratesight_gsc_site_overview' );

		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Summary stats for the overview panel.
	 */
	public static function get_overview_stats() {
		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$kw_table   = $wpdb->prefix . 'ratesight_keywords';

		// Totals from the most recent sync date.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				SUM(p.impressions) AS total_impressions,
				SUM(p.clicks)      AS total_clicks,
				AVG(p.position)    AS avg_position,
				AVG(p.ctr)         AS avg_ctr,
				COUNT(*)           AS page_count
			 FROM `{$perf_table}` p
			 INNER JOIN (
			     SELECT post_id, MAX(date) AS max_date FROM `{$perf_table}` GROUP BY post_id
			 ) latest ON latest.post_id = p.post_id AND latest.max_date = p.date",
			ARRAY_A
		);

		// Compare to 30 days ago.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev = $wpdb->get_row(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT SUM(p.impressions) AS total_impressions, SUM(p.clicks) AS total_clicks, AVG(p.position) AS avg_position
			 FROM `{$perf_table}` p
			 WHERE p.date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			 AND p.date = (
			     SELECT MAX(date) FROM `{$perf_table}`
			     WHERE post_id = p.post_id
			     AND date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			 )",
			ARRAY_A
		);

		// Pages ranked top 3, top 10.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rank_counts = $wpdb->get_row(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				SUM(CASE WHEN p.position <= 3  THEN 1 ELSE 0 END) AS top3,
				SUM(CASE WHEN p.position <= 10 THEN 1 ELSE 0 END) AS top10,
				SUM(CASE WHEN p.position > 10 AND p.position <= 20 THEN 1 ELSE 0 END) AS pos11_20
			 FROM `{$perf_table}` p
			 INNER JOIN (
			     SELECT post_id, MAX(date) AS max_date FROM `{$perf_table}` GROUP BY post_id
			 ) latest ON latest.post_id = p.post_id AND latest.max_date = p.date",
			ARRAY_A
		);

		return array(
			'total_impressions'   => (int)   ( $totals['total_impressions'] ?? 0 ),
			'total_clicks'        => (int)   ( $totals['total_clicks']      ?? 0 ),
			'avg_position'        => round( (float) ( $totals['avg_position']    ?? 0 ), 1 ),
			'avg_ctr'             => round( (float) ( $totals['avg_ctr']          ?? 0 ), 2 ),
			'page_count'          => (int)   ( $totals['page_count']         ?? 0 ),
			'top3'                => (int)   ( $rank_counts['top3']          ?? 0 ),
			'top10'               => (int)   ( $rank_counts['top10']         ?? 0 ),
			'pos11_20'            => (int)   ( $rank_counts['pos11_20']      ?? 0 ),
			'prev_impressions'    => (int)   ( $prev['total_impressions']    ?? 0 ),
			'prev_clicks'         => (int)   ( $prev['total_clicks']         ?? 0 ),
			'prev_avg_position'   => round( (float) ( $prev['avg_position']  ?? 0 ), 1 ),
		);
	}

	// -------------------------------------------------------------------------
	// API queries
	// -------------------------------------------------------------------------

	/**
	 * Query aggregate performance by page.
	 */
	private static function query_by_page( string $site_url, string $token, int $days ): array|\WP_Error {
		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$url        = sprintf( self::QUERY_URL, rawurlencode( $site_url ) );

		// Query with both page AND date dimensions so we get one row per page per day.
		// This makes the 7d/30d/90d filters meaningful — without the date dimension
		// every sync would just store a single aggregate row.
		$response = self::post( $url, $token, array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page', 'date' ),
			'rowLimit'   => 25000,
		) );

		if ( is_wp_error( $response ) ) return $response;

		// Return shape: [ page_url => [ date => [metrics] ] ]
		$results = array();
		foreach ( $response['rows'] ?? array() as $row ) {
			$page_url = $row['keys'][0] ?? '';
			$date     = $row['keys'][1] ?? '';
			if ( ! $page_url || ! $date ) continue;

			if ( ! isset( $results[ $page_url ] ) ) {
				$results[ $page_url ] = array();
			}
			$results[ $page_url ][ $date ] = array(
				'url'         => $page_url,
				'date'        => $date,
				'impressions' => (int)   ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
			);
		}

		return $results;
	}

	/**
	 * Query top 10 keywords driving traffic to a specific URL.
	 */
	private static function query_keywords_for_url( string $site_url, string $page_url, string $token, int $days ): array|\WP_Error {
		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$url        = sprintf( self::QUERY_URL, rawurlencode( $site_url ) );

		$response = self::post( $url, $token, array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'query' ),
			'rowLimit'   => 5,
			'dimensionFilterGroups' => array( array(
				'filters' => array( array(
					'dimension'  => 'page',
					'operator'   => 'equals',
					'expression' => $page_url,
				) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) return $response;

		$results = array();
		foreach ( $response['rows'] ?? array() as $row ) {
			$query = $row['keys'][0] ?? '';
			if ( ! $query ) continue;
			$results[] = array(
				'query'       => $query,
				'impressions' => (int)   ( $row['impressions'] ?? 0 ),
				'clicks'      => (int)   ( $row['clicks']      ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				'ctr'         => round( (float) ( $row['ctr']      ?? 0 ) * 100, 2 ),
			);
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	private static function get_ratesight_post_ids(): array {
		global $wpdb;
		// Query wp_posts directly — deterministic regardless of log state.
		$ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type   = 'ratesight_page'
			   AND post_status = 'publish'"
		);
		// Fall back to the activity log for non-CPT blog posts created via webhook.
		$log_table  = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$log_ids    = $wpdb->get_col( // phpcs:ignore
			"SELECT DISTINCT post_id FROM `{$log_table}` WHERE post_id IS NOT NULL AND status = 'success'"
		);
		$merged = array_unique( array_merge(
			array_map( 'intval', $ids ),
			array_map( 'intval', $log_ids )
		) );
		return array_values( $merged );
	}

	private static function get( string $url, string $token ): array|\WP_Error {
		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		return self::parse_response( $response );
	}

	private static function post( string $url, string $token, array $body ): array|\WP_Error {
		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		return self::parse_response( $response );
	}

	private static function parse_response( $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rs_gsc_http', 'GSC request failed: ' . $response->get_error_message() );
		}
		$code    = wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 429 ) {
			$retry_after = $headers['retry-after'] ?? $headers['x-ratelimit-reset'] ?? 60;
			return new \WP_Error( 'rs_gsc_rate_limit', "GSC rate limited — retry after {$retry_after}s. Sync will resume on next scheduled run." );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $decoded['error']['message'] ?? "HTTP {$code}";

			if ( $code === 403 && str_contains( strtolower( $message ), 'insufficient authentication scopes' ) ) {
				update_option( 'ratesight_gsc_scope_error', 1, false );
				return new \WP_Error( 'rs_gsc_scope', 'GSC connection needs reauthorization — the Search Console permission was not granted. Please disconnect and reconnect Google Search Console on the Connections tab.' );
			}

			return new \WP_Error( 'rs_gsc_api', 'GSC API error: ' . $message );
		}
		delete_option( 'ratesight_gsc_scope_error' );
		return $decoded ?? array();
	}
}
