<?php
/**
 * Google Business Profile insights client.
 *
 * Handles profile health checks, reviews, and local performance metrics
 * for the Local tab of the Performance Hub.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_GBP_Insights_Client {

	private const INFO_BASE    = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
	private const REVIEWS_BASE = 'https://mybusiness.googleapis.com/v4/';
	private const PERF_BASE    = 'https://businessprofileperformance.googleapis.com/v1/';

	// -------------------------------------------------------------------------
	// Profile health
	// -------------------------------------------------------------------------

	/**
	 * Fetch full location details and return a health audit.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_profile_health(): array|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $token;

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		if ( ! $location_path ) {
			return new \WP_Error( 'rs_gbp_no_location', 'No GBP location locked.' );
		}

		// Get the location-only part for the Business Information API.
		// Stored as accounts/X/locations/Y — the Info API uses locations/Y.
		$parts        = explode( '/', $location_path );
		$loc_id       = end( $parts );
		$loc_resource = 'locations/' . $loc_id;

		// readMask must be comma-separated field names — no nested paths, no duplicates.
		$fields = 'name,title,websiteUri,phoneNumbers,regularHours,categories,serviceItems,profile,storefrontAddress,serviceArea';
		$url    = self::INFO_BASE . $loc_resource . '?readMask=' . rawurlencode( $fields );

		$response = self::get( $url, $token );
		if ( is_wp_error( $response ) ) return $response;

		return self::audit_profile( $response );
	}

	/**
	 * Analyse location data and return scored health checks.
	 */
	private static function audit_profile( array $loc ) {
		$checks = array();
		$score  = 0;
		$total  = 0;

		// Description
		$desc     = $loc['profile']['description'] ?? '';
		$desc_len = mb_strlen( $desc );
		$total++;
		if ( $desc_len >= 250 ) {
			$checks[] = array( 'ok' => true,  'label' => 'Business description', 'detail' => $desc_len . ' characters — good' );
			$score++;
		} elseif ( $desc_len > 0 ) {
			$checks[] = array( 'ok' => 'warn', 'label' => 'Business description', 'detail' => $desc_len . ' chars — aim for 250+' );
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Business description', 'detail' => 'Not set — add a description to improve visibility' );
		}

		// Categories
		$primary     = $loc['categories']['primaryCategory']['displayName'] ?? '';
		$additional  = $loc['categories']['additionalCategories'] ?? array();
		$total++;
		if ( $primary ) {
			$cat_detail = $primary . ( count( $additional ) ? ' + ' . count( $additional ) . ' more' : ' (consider adding more)' );
			$checks[] = array( 'ok' => count( $additional ) > 0, 'label' => 'Categories', 'detail' => $cat_detail );
			if ( count( $additional ) > 0 ) $score++;
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Categories', 'detail' => 'No primary category set' );
		}

		// Website
		$total++;
		if ( ! empty( $loc['websiteUri'] ) ) {
			$checks[] = array( 'ok' => true,  'label' => 'Website URL', 'detail' => $loc['websiteUri'] );
			$score++;
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Website URL', 'detail' => 'No website URL set' );
		}

		// Phone
		$total++;
		$phone = $loc['phoneNumbers']['primaryPhone'] ?? '';
		if ( $phone ) {
			$checks[] = array( 'ok' => true,  'label' => 'Phone number', 'detail' => $phone );
			$score++;
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Phone number', 'detail' => 'No phone number set' );
		}

		// Hours
		$total++;
		$hours = $loc['regularHours']['periods'] ?? array();
		if ( ! empty( $hours ) ) {
			$checks[] = array( 'ok' => true,  'label' => 'Business hours', 'detail' => count( $hours ) . ' days configured' );
			$score++;
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Business hours', 'detail' => 'No hours set — customers may not know when you\'re open' );
		}

		// Services
		$total++;
		$services = $loc['serviceItems'] ?? array();
		if ( count( $services ) >= 3 ) {
			$checks[] = array( 'ok' => true,  'label' => 'Services', 'detail' => count( $services ) . ' services listed' );
			$score++;
		} elseif ( count( $services ) > 0 ) {
			$checks[] = array( 'ok' => 'warn', 'label' => 'Services', 'detail' => count( $services ) . ' service(s) — add more to improve keyword matching' );
		} else {
			$checks[] = array( 'ok' => false, 'label' => 'Services', 'detail' => 'No services listed — add services to match search queries' );
		}

		// Extract service area place names if set (service area businesses have no address).
		$service_area_places = array();
		$sa_places = $loc['serviceArea']['places']['placeInfos'] ?? array();
		foreach ( $sa_places as $place ) {
			$name = $place['displayName'] ?? '';
			if ( $name ) $service_area_places[] = $name;
		}

		return array(
			'checks'           => $checks,
			'score'            => $score,
			'total'            => $total,
			'pct'              => $total > 0 ? round( ( $score / $total ) * 100 ) : 0,
			'name'             => $loc['title'] ?? '',
			'primary_category' => $primary,
			'all_categories'   => array_merge(
				$primary ? array( $primary ) : array(),
				array_map( static fn( $c ) => $c['displayName'] ?? '', $additional )
			),
			'description'      => $desc,
			'services'         => array_slice( array_map( static fn( $s ) => $s['displayName'] ?? $s['freeFormServiceItem']['label']['displayName'] ?? '', $services ), 0, 15 ),
			'service_areas'    => $service_area_places,
			'hours'            => self::format_hours( $loc['regularHours']['periods'] ?? array() ),
			'phone'            => $loc['phoneNumbers']['primaryPhone'] ?? '',
			'website'          => $loc['websiteUri'] ?? '',
		);
	}

	// -------------------------------------------------------------------------
	// Reviews
	// -------------------------------------------------------------------------

	/**
	 * Fetch recent reviews (up to 50) for the locked location.
	 *
	 * @return array|\WP_Error  Array with 'reviews', 'avg_rating', 'total', 'unanswered'
	 */
	public static function get_reviews(): array|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $token;

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		if ( ! $location_path ) {
			return new \WP_Error( 'rs_gbp_no_location', 'No GBP location locked.' );
		}

		$url      = self::REVIEWS_BASE . $location_path . '/reviews?pageSize=50&orderBy=updateTime%20desc';
		$response = self::get( $url, $token );
		if ( is_wp_error( $response ) ) return $response;

		$reviews    = $response['reviews'] ?? array();
		$avg_rating = (float) ( $response['averageRating'] ?? 0 );
		$total      = (int)   ( $response['totalReviewCount'] ?? count( $reviews ) );
		$unanswered = array_filter( $reviews, static fn( $r ) => empty( $r['reviewReply'] ) );

		return array(
			'reviews'    => $reviews,
			'avg_rating' => $avg_rating,
			'total'      => $total,
			'unanswered' => array_values( $unanswered ),
			'unanswered_count' => count( $unanswered ),
		);
	}

	/**
	 * Post a reply to a review.
	 */
	public static function reply_to_review( string $review_name, string $comment ): bool|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $token;

		$url      = self::REVIEWS_BASE . $review_name . '/reply';
		$response = self::post_request( $url, $token, array( 'comment' => sanitize_textarea_field( $comment ) ) );

		if ( is_wp_error( $response ) ) return $response;
		return true;
	}

	/**
	 * Fetch Q&A for the locked location.
	 */
	public static function get_qa(): array|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $token;

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		if ( ! $location_path ) {
			return new \WP_Error( 'rs_gbp_no_location', 'No GBP location locked.' );
		}

		// Q&A API uses mybusinessqanda.googleapis.com (separate from mybusiness v4).
		$parts    = explode( '/', $location_path );
		$loc_id   = end( $parts );
		$url      = 'https://mybusinessqanda.googleapis.com/v1/locations/' . $loc_id . '/questions?pageSize=20&orderBy=upvoteCount';
		$response = self::get( $url, $token );

		// Q&A API requires separate approval — return empty gracefully if not available.
		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( strpos( $msg, '404' ) !== false || strpos( $msg, '403' ) !== false ) {
				return array(
					'questions'        => array(),
					'total'            => 0,
					'unanswered'       => array(),
					'unanswered_count' => 0,
					'unavailable'      => true,
				);
			}
			// Unexpected error — log it so it's visible.
			error_log( '[Ratesight] GBP Q&A error: ' . $msg ); // phpcs:ignore
			return $response;
		}

		$questions  = $response['questions'] ?? array();
		$unanswered = array_filter( $questions, static fn( $q ) => empty( $q['topAnswers'] ) );

		return array(
			'questions'        => $questions,
			'total'            => count( $questions ),
			'unanswered'       => array_values( $unanswered ),
			'unanswered_count' => count( $unanswered ),
		);
	}

	/**
	 * Post an answer to a GBP question.
	 */
	public static function answer_question( string $question_name, string $text ): bool|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $token;

		$url      = 'https://mybusinessqanda.googleapis.com/v1/' . $question_name . '/answers:upsert';
		$response = self::post_request( $url, $token, array( 'answer' => array( 'text' => sanitize_textarea_field( $text ) ) ) );
		if ( is_wp_error( $response ) ) return $response;
		return true;
	}

	/**
	 * Sync local performance metrics for the locked location.
	 * Called weekly by WP-Cron.
	 */
	public static function sync_performance() {
		if ( ! Ratesight_OAuth_Client::is_connected( 'gbp' ) || ! Ratesight_GBP_Client::is_locked() ) return;

		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return;

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		if ( ! $location_path ) return;

		$parts        = explode( '/', $location_path );
		$loc_resource = 'locations/' . end( $parts );

		// Also snapshot review count for velocity tracking.
		$review_count = 0;
		$avg_rating   = 0.0;
		$reviews = self::get_reviews();
		if ( ! is_wp_error( $reviews ) ) {
			$review_count = (int) ( $reviews['total']      ?? 0 );
			$avg_rating   = (float) ( $reviews['avg_rating'] ?? 0 );
		}

		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		$metrics = array(
			'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
			'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
			'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
			'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
			'CALL_CLICKS',
			'WEBSITE_CLICKS',
			'BUSINESS_DIRECTION_REQUESTS',
		);

		$metric_params = 'dailyMetrics=' . implode( '&dailyMetrics=', array_map( 'rawurlencode', $metrics ) );

		// Correct endpoint: :fetchMultiDailyMetricsTimeSeries
		$url = self::PERF_BASE . $loc_resource . ':fetchMultiDailyMetricsTimeSeries?' . $metric_params
			. '&dailyRange.startDate.year='  . gmdate( 'Y', strtotime( $start_date ) )
			. '&dailyRange.startDate.month=' . gmdate( 'n', strtotime( $start_date ) )
			. '&dailyRange.startDate.day='   . gmdate( 'j', strtotime( $start_date ) )
			. '&dailyRange.endDate.year='    . gmdate( 'Y', strtotime( $end_date ) )
			. '&dailyRange.endDate.month='   . gmdate( 'n', strtotime( $end_date ) )
			. '&dailyRange.endDate.day='     . gmdate( 'j', strtotime( $end_date ) );

		$response = self::get( $url, $token );
		if ( is_wp_error( $response ) ) {
			error_log( 'Ratesight GBP performance sync error: ' . $response->get_error_message() ); // phpcs:ignore
			return;
		}

		// Log the raw response in debug mode so mismatches can be investigated.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Ratesight GBP raw response: ' . wp_json_encode( $response ) ); // phpcs:ignore
		}

		self::store_gbp_performance( $loc_resource, $response, $review_count, $avg_rating );
		update_option( 'ratesight_gbp_performance_last_sync', current_time( 'mysql' ), false );
	}

	/**
	 * Check review velocity — returns days since last new review and recommendation.
	 */
	public static function get_review_velocity() {
		global $wpdb;
		$table         = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		$parts         = explode( '/', $location_path );
		$loc_resource  = 'locations/' . end( $parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT date, review_count, avg_rating
			 FROM `{$table}`
			 WHERE location_id = %s AND review_count > 0
			 ORDER BY date DESC LIMIT 8",
			$loc_resource
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return array( 'status' => 'no_data', 'message' => 'No review history yet — sync GBP performance first.' );
		}

		$latest_count = (int) $rows[0]['review_count'];
		$latest_date  = $rows[0]['date'];

		// Find when the count last increased.
		$days_since_new = null;
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			if ( (int) $rows[ $i ]['review_count'] < $latest_count ) {
				$days_since_new = (int) round( ( strtotime( $latest_date ) - strtotime( $rows[ $i ]['date'] ) ) / DAY_IN_SECONDS );
				break;
			}
		}

		$status = 'good';
		$message = 'Review velocity looks healthy.';

		if ( $days_since_new === null ) {
			$status  = 'warn';
			$message = 'No new reviews detected in your tracked history.';
		} elseif ( $days_since_new > 30 ) {
			$status  = 'warn';
			$message = "No new reviews in {$days_since_new} days. Consider asking recent customers.";
		}

		return array(
			'status'         => $status,
			'message'        => $message,
			'total'          => $latest_count,
			'avg_rating'     => (float) $rows[0]['avg_rating'],
			'days_since_new' => $days_since_new,
		);
	}

	/**
	 * Get GBP performance history for the trend chart.
	 */
	public static function get_performance_history( int $days = 90 ) {
		global $wpdb;
		$table         = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		$parts         = explode( '/', $location_path );
		$loc_resource  = 'locations/' . end( $parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT date, search_impressions, maps_impressions, website_clicks, call_clicks, direction_requests
			 FROM `{$table}`
			 WHERE location_id = %s
			 AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			 ORDER BY date ASC",
			$loc_resource, $days
		), ARRAY_A );
	}

	/**
	 * Get GBP overview stats (latest week vs previous week).
	 */
	/**
	 * Fetch totals for a date window directly from the API (no local storage).
	 * Used for year-over-year comparison — the GBP API supports up to 18 months back.
	 *
	 * @param string $start_date Y-m-d
	 * @param string $end_date   Y-m-d
	 * @return array  Keys: search, calls, website  (maps/directions not returned by API for SABs)
	 */
	public static function fetch_period_totals( string $start_date, string $end_date ): array {
		$empty = array( 'search' => 0, 'calls' => 0, 'website' => 0 );

		if ( ! Ratesight_OAuth_Client::is_connected( 'gbp' ) || ! Ratesight_GBP_Client::is_locked() ) {
			return $empty;
		}

		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) return $empty;

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		if ( ! $location_path ) return $empty;

		$parts        = explode( '/', $location_path );
		$loc_resource = 'locations/' . end( $parts );

		$metrics = array(
			'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
			'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
			'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
			'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
			'CALL_CLICKS',
			'WEBSITE_CLICKS',
			'BUSINESS_DIRECTION_REQUESTS',
		);

		$metric_params = 'dailyMetrics=' . implode( '&dailyMetrics=', array_map( 'rawurlencode', $metrics ) );

		// Guard: Google's Performance API supports at most ~18 months of history.
		// Cap start_date so we never request data beyond that window.
		$earliest_allowed = gmdate( 'Y-m-d', strtotime( '-548 days' ) );
		if ( $start_date < $earliest_allowed ) {
			$start_date = $earliest_allowed;
		}

		$url = self::PERF_BASE . $loc_resource . ':fetchMultiDailyMetricsTimeSeries?' . $metric_params
			. '&dailyRange.startDate.year='  . gmdate( 'Y', strtotime( $start_date ) )
			. '&dailyRange.startDate.month=' . gmdate( 'n', strtotime( $start_date ) )
			. '&dailyRange.startDate.day='   . gmdate( 'j', strtotime( $start_date ) )
			. '&dailyRange.endDate.year='    . gmdate( 'Y', strtotime( $end_date ) )
			. '&dailyRange.endDate.month='   . gmdate( 'n', strtotime( $end_date ) )
			. '&dailyRange.endDate.day='     . gmdate( 'j', strtotime( $end_date ) );

		$response = self::get( $url, $token );
		if ( is_wp_error( $response ) ) {
			error_log( '[Ratesight] GBP fetch_period_totals error (' . $start_date . ' – ' . $end_date . '): ' . $response->get_error_message() ); // phpcs:ignore
			return $empty;
		}

		$totals = array( 'search' => 0, 'calls' => 0, 'website' => 0 );

		foreach ( $response['multiDailyMetricTimeSeries'] ?? array() as $series_group ) {
			foreach ( $series_group['dailyMetricTimeSeries'] ?? array() as $series ) {
				$m = strtoupper( trim( $series['dailyMetric'] ?? '' ) );
				foreach ( $series['timeSeries']['datedValues'] ?? array() as $dv ) {
					$value = (int) ( $dv['value'] ?? 0 );
					if ( str_contains( $m, 'IMPRESSION' ) ) {
						$totals['search'] += $value;
					} elseif ( str_contains( $m, 'CALL' ) ) {
						$totals['calls'] += $value;
					} elseif ( str_contains( $m, 'WEBSITE' ) ) {
						$totals['website'] += $value;
					}
				}
			}
		}

		return $totals;
	}

	public static function get_overview_stats() {
		global $wpdb;
		$table         = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		$parts         = explode( '/', $location_path );
		$loc_resource  = 'locations/' . end( $parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				SUM(search_impressions + maps_impressions) AS total_impressions,
				SUM(website_clicks)    AS website_clicks,
				SUM(call_clicks)       AS call_clicks,
				SUM(direction_requests) AS direction_requests
			 FROM `{$table}`
			 WHERE location_id = %s
			 AND date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)",
			$loc_resource
		), ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$previous = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT SUM(search_impressions + maps_impressions) AS total_impressions
			 FROM `{$table}`
			 WHERE location_id = %s
			 AND date >= DATE_SUB(CURDATE(), INTERVAL 56 DAY)
			 AND date < DATE_SUB(CURDATE(), INTERVAL 28 DAY)",
			$loc_resource
		), ARRAY_A );

		return array(
			'total_impressions'    => (int) ( $current['total_impressions']  ?? 0 ),
			'website_clicks'       => (int) ( $current['website_clicks']     ?? 0 ),
			'call_clicks'          => (int) ( $current['call_clicks']        ?? 0 ),
			'direction_requests'   => (int) ( $current['direction_requests'] ?? 0 ),
			'prev_impressions'     => (int) ( $previous['total_impressions'] ?? 0 ),
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	public static function store_gbp_performance( string $loc_resource, array $response, int $review_count = 0, float $avg_rating = 0.0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$multidaily = $response['multiDailyMetricTimeSeries'] ?? array();
		if ( empty( $multidaily ) ) return;

		// Aggregate metrics per date.
		$by_date = array();

		foreach ( $multidaily as $series_group ) {
			foreach ( $series_group['dailyMetricTimeSeries'] ?? array() as $series ) {
				$metric = $series['dailyMetric'] ?? '';
				foreach ( $series['timeSeries']['datedValues'] ?? array() as $dv ) {
					$d = $dv['date'] ?? array();
					if ( empty( $d['year'] ) ) continue;
					$date_str = sprintf( '%04d-%02d-%02d', $d['year'], $d['month'], $d['day'] );
					$value    = (int) ( $dv['value'] ?? 0 );

					if ( ! isset( $by_date[ $date_str ] ) ) {
						$by_date[ $date_str ] = array(
							'search_impressions' => 0,
							'maps_impressions'   => 0,
							'call_clicks'        => 0,
							'website_clicks'     => 0,
							'direction_requests' => 0,
						);
					}

					// Normalise metric name — trim whitespace and uppercase.
					$m = strtoupper( trim( $metric ) );

					if ( str_contains( $m, 'SEARCH' ) && str_contains( $m, 'IMPRESSION' ) ) {
						$by_date[ $date_str ]['search_impressions'] += $value;
					} elseif ( str_contains( $m, 'MAPS' ) && str_contains( $m, 'IMPRESSION' ) ) {
						$by_date[ $date_str ]['maps_impressions'] += $value;
					} elseif ( str_contains( $m, 'CALL' ) ) {
						$by_date[ $date_str ]['call_clicks'] += $value;
					} elseif ( str_contains( $m, 'WEBSITE' ) ) {
						$by_date[ $date_str ]['website_clicks'] += $value;
					} elseif ( str_contains( $m, 'DIRECTION' ) ) {
						$by_date[ $date_str ]['direction_requests'] += $value;
					} else {
						error_log( 'Ratesight GBP: unhandled metric "' . $metric . '" value=' . $value ); // phpcs:ignore
					}
				}
			}
		}

		foreach ( $by_date as $date => $vals ) {
			$wpdb->replace( $table, array_merge(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				array(
					'location_id'  => $loc_resource,
					'date'         => $date,
					'review_count' => $review_count,
					'avg_rating'   => $avg_rating,
				),
				$vals
			), array( '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d', '%d' ) );
		}
	}

	private static function format_hours( array $periods ) {
		$days = array( 'MONDAY' => 'Mon', 'TUESDAY' => 'Tue', 'WEDNESDAY' => 'Wed', 'THURSDAY' => 'Thu', 'FRIDAY' => 'Fri', 'SATURDAY' => 'Sat', 'SUNDAY' => 'Sun' );
		$out  = array();
		foreach ( $periods as $p ) {
			$day   = $days[ $p['openDay'] ?? '' ] ?? ( $p['openDay'] ?? '' );
			$open  = $p['openTime']['hours'] ?? 0;
			$close = $p['closeTime']['hours'] ?? 0;
			$open_fmt  = sprintf( '%d:%02d %s', $open > 12 ? $open - 12 : ( $open ?: 12 ), $p['openTime']['minutes'] ?? 0, $open >= 12 ? 'PM' : 'AM' );
			$close_fmt = sprintf( '%d:%02d %s', $close > 12 ? $close - 12 : ( $close ?: 12 ), $p['closeTime']['minutes'] ?? 0, $close >= 12 ? 'PM' : 'AM' );
			$out[] = $day . ': ' . $open_fmt . ' – ' . $close_fmt;
		}
		return $out;
	}

	private static function get( string $url, string $token ): array|WP_Error {
		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		) );
		return self::parse( $response );
	}

	private static function post_request( string $url, string $token, array $body ): array|WP_Error {
		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		return self::parse( $response );
	}

	private static function parse( $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rs_gbp_insights_http', $response->get_error_message() );
		}
		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $decoded['error']['message'] ?? "HTTP {$code}";

			// Extract field-level violations when present — same as GBP client.
			$violations = array();
			foreach ( $decoded['error']['details'] ?? array() as $detail ) {
				foreach ( $detail['fieldViolations'] ?? array() as $v ) {
					$field = $v['field'] ?? '';
					$desc  = $v['description'] ?? '';
					if ( $field || $desc ) {
						$violations[] = trim( "{$field}: {$desc}", ': ' );
					}
				}
			}
			if ( ! empty( $violations ) ) {
				$msg .= ' [' . implode( '; ', $violations ) . ']';
			}

			return new \WP_Error( 'rs_gbp_insights_api', $msg );
		}
		return $decoded ?? array();
	}
}
