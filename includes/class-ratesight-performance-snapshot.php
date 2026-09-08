<?php
/**
 * Dashboard-owned performance snapshots.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Performance_Snapshot {
	public const CONTRACT = 'ratesight-performance-snapshot-v1';
	public const OPTION = 'ratesight_dashboard_performance_snapshot';
	private const STATES = array( 'available', 'partial', 'collecting', 'stale', 'not_connected', 'failed' );
	private const FRESHNESS = array( 'current', 'stale', 'unavailable' );
	private const DIRECTIONS = array( 'improved', 'worse', 'flat' );

	public static function register_routes(): void {
		register_rest_route( 'ratesight/v1', '/performance-snapshot', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_read' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_read' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_write' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
			),
		) );
	}

	public static function handle_read( \WP_REST_Request $request ): \WP_REST_Response {
		$snapshot = get_option( self::OPTION, null );
		return new \WP_REST_Response( array(
			'contract' => self::CONTRACT,
			'stored'   => is_array( $snapshot ),
			'snapshot' => is_array( $snapshot ) ? $snapshot : null,
		), 200 );
	}

	public static function handle_write( \WP_REST_Request $request ) {
		$expected_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
		$normalized = self::normalize( $request->get_json_params(), $expected_id );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$current = get_option( self::OPTION, null );
		if ( is_array( $current ) && strcmp( (string) ( $normalized['generatedAt'] ?? '' ), (string) ( $current['generatedAt'] ?? '' ) ) < 0 ) {
			return new \WP_Error( 'rs_performance_snapshot_stale_write', 'An older performance snapshot cannot replace the current snapshot.', array( 'status' => 409 ) );
		}
		update_option( self::OPTION, $normalized, false );
		return new \WP_REST_Response( array(
			'contract'    => self::CONTRACT,
			'stored'      => true,
			'generatedAt' => $normalized['generatedAt'],
			'digest'      => hash( 'sha256', wp_json_encode( $normalized ) ),
		), 200 );
	}

	public static function normalize( $input, string $expected_id, ?int $now = null ) {
		if ( ! is_array( $input ) || ( $input['contract'] ?? null ) !== self::CONTRACT ) {
			return self::error( 'contract', 'Unsupported performance snapshot contract.' );
		}
		$site_id = trim( (string) ( $input['siteId'] ?? '' ) );
		if ( $expected_id === '' || ! hash_equals( $expected_id, $site_id ) ) {
			return self::error( 'site', 'Performance snapshot site identity does not match this installation.', 409 );
		}
		$generated_at = self::iso_time( $input['generatedAt'] ?? null );
		$timestamp = $generated_at ? strtotime( $generated_at ) : false;
		$now = $now ?? time();
		if ( false === $timestamp || $timestamp > $now + 300 || $timestamp < $now - ( 7 * 86400 ) ) {
			return self::error( 'generated_at', 'Performance snapshot time is invalid or outside the accepted window.' );
		}
		if ( (int) ( $input['windowDays'] ?? 0 ) !== 28 || ! is_array( $input['organic'] ?? null ) ) {
			return self::error( 'shape', 'Performance snapshot shape is invalid.' );
		}

		$organic = $input['organic'];
		$state = (string) ( $organic['state'] ?? '' );
		$freshness = (string) ( $organic['freshnessState'] ?? '' );
		if ( ! in_array( $state, self::STATES, true ) || ! in_array( $freshness, self::FRESHNESS, true ) ) {
			return self::error( 'state', 'Performance snapshot state is invalid.' );
		}
		$current_range = self::date_range( $organic['currentRange'] ?? null, true );
		$comparison_range = self::date_range( $organic['comparisonRange'] ?? null, true );
		if ( is_wp_error( $current_range ) || is_wp_error( $comparison_range ) ) {
			return self::error( 'range', 'Performance snapshot date range is invalid.' );
		}

		$metrics = array();
		foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $key ) {
			$metric = self::metric( $organic['metrics'][ $key ] ?? null );
			if ( is_wp_error( $metric ) ) {
				return self::error( 'metric', 'Performance snapshot metric is invalid.' );
			}
			$metrics[ $key ] = $metric;
		}

		$daily_input = is_array( $organic['daily'] ?? null ) ? array_values( $organic['daily'] ) : array();
		$query_input = is_array( $organic['queries'] ?? null ) ? array_values( $organic['queries'] ) : array();
		if ( count( $daily_input ) > 28 || count( $query_input ) > 10 ) {
			return self::error( 'bounds', 'Performance snapshot exceeds row limits.' );
		}
		$current_dates = $organic['currentDates'] ?? null;
		$expected_dates = $organic['expectedDates'] ?? null;
		if ( ! is_int( $current_dates ) || $current_dates < 0 || $current_dates > 28 || 28 !== $expected_dates ) {
			return self::error( 'completeness', 'Performance snapshot completeness is invalid.' );
		}
		$daily = array();
		foreach ( $daily_input as $row ) {
			$date = self::date( is_array( $row ) ? ( $row['date'] ?? null ) : null );
			if ( ! $date ) return self::error( 'daily', 'Performance snapshot daily row is invalid.' );
			$daily[] = array(
				'date' => $date,
				'clicks' => self::number( $row['clicks'] ?? null, false ),
				'impressions' => self::number( $row['impressions'] ?? null, false ),
				'ctr' => self::number( $row['ctr'] ?? null ),
				'position' => self::number( $row['position'] ?? null ),
			);
			if ( in_array( null, array( $daily[count( $daily ) - 1]['clicks'], $daily[count( $daily ) - 1]['impressions'] ), true ) || $daily[count( $daily ) - 1]['clicks'] < 0 || $daily[count( $daily ) - 1]['impressions'] < 0 ) return self::error( 'daily', 'Performance snapshot daily totals are invalid.' );
		}
		$queries = array();
		foreach ( $query_input as $row ) {
			$value = is_array( $row ) ? sanitize_text_field( trim( (string) ( $row['value'] ?? '' ) ) ) : '';
			$current = is_array( $row ) && is_array( $row['current'] ?? null ) ? $row['current'] : array();
			if ( $value === '' || strlen( $value ) > 200 ) return self::error( 'query', 'Performance snapshot query row is invalid.' );
			$queries[] = array(
				'value' => $value,
				'clicks' => self::number( $current['clicks'] ?? null, false ),
				'impressions' => self::number( $current['impressions'] ?? null, false ),
				'ctr' => self::number( $current['ctr'] ?? null ),
				'position' => self::number( $current['position'] ?? null ),
			);
			if ( in_array( null, array( $queries[count( $queries ) - 1]['clicks'], $queries[count( $queries ) - 1]['impressions'] ), true ) || $queries[count( $queries ) - 1]['clicks'] < 0 || $queries[count( $queries ) - 1]['impressions'] < 0 ) return self::error( 'query', 'Performance snapshot query totals are invalid.' );
		}

		$latest_date = self::date( $organic['latestMetricDate'] ?? null );
		$normalized = array(
			'contract' => self::CONTRACT,
			'siteId' => $site_id,
			'generatedAt' => $generated_at,
			'windowDays' => 28,
			'dashboardUrl' => 'https://dash.ratesight.com/seo/' . rawurlencode( $site_id ) . '/rank',
			'organic' => array(
				'state' => $state,
				'latestMetricDate' => $latest_date,
				'freshnessState' => $freshness,
				'currentRange' => $current_range,
				'comparisonRange' => $comparison_range,
				'currentDates' => $current_dates,
				'expectedDates' => 28,
				'metrics' => $metrics,
				'daily' => $daily,
				'queries' => $queries,
			),
		);
		if ( array_key_exists( 'local', $input ) ) {
			$local = self::local( $input['local'] );
			if ( is_wp_error( $local ) ) return $local;
			$normalized['local'] = $local;
		}
		if ( array_key_exists( 'rankings', $input ) ) {
			$rankings = self::rankings( $input['rankings'] );
			if ( is_wp_error( $rankings ) ) return $rankings;
			$normalized['rankings'] = $rankings;
		}
		if ( array_key_exists( 'work', $input ) ) {
			$work = self::work( $input['work'] );
			if ( is_wp_error( $work ) ) return $work;
			$normalized['work'] = $work;
		}
		return $normalized;
	}

	private static function local( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['state'] ?? null, array( 'available', 'partial', 'not_connected', 'failed' ), true ) ) return self::error( 'local', 'Local performance snapshot is invalid.' );
		$metrics = array();
		foreach ( array( 'impressions', 'calls', 'directions', 'websiteClicks' ) as $key ) {
			$row = is_array( $input['metrics'][ $key ] ?? null ) ? $input['metrics'][ $key ] : null;
			$current = self::number( $row['current'] ?? null );
			if ( ! is_array( $row ) || ! is_bool( $row['complete'] ?? null ) || ( null !== $current && $current < 0 ) ) return self::error( 'local', 'Local performance metric is invalid.' );
			$metrics[ $key ] = array( 'current' => $current, 'complete' => $row['complete'] );
		}
		$latest = null === ( $input['latestMetricDate'] ?? null ) ? null : self::date( $input['latestMetricDate'] );
		if ( null !== ( $input['latestMetricDate'] ?? null ) && ! $latest ) return self::error( 'local', 'Local performance date is invalid.' );
		return array( 'state' => $input['state'], 'latestMetricDate' => $latest, 'metrics' => $metrics );
	}

	private static function rankings( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['state'] ?? null, array( 'available', 'partial', 'collecting', 'failed' ), true ) ) return self::error( 'rankings', 'Ranking snapshot is invalid.' );
		$counts = array();
		foreach ( array( 'tracked', 'ranking', 'notRanking', 'top3' ) as $key ) {
			$count = self::count( $input[ $key ] ?? null );
			if ( null === $count ) return self::error( 'rankings', 'Ranking totals are invalid.' );
			$counts[ $key ] = $count;
		}
		$rows = is_array( $input['keywords'] ?? null ) ? array_values( $input['keywords'] ) : array();
		if ( count( $rows ) > 10 ) return self::error( 'rankings', 'Ranking snapshot exceeds row limits.' );
		$keywords = array();
		foreach ( $rows as $row ) {
			$keyword = is_array( $row ) ? sanitize_text_field( trim( (string) ( $row['keyword'] ?? '' ) ) ) : '';
			$target = is_array( $row ) ? sanitize_text_field( trim( (string) ( $row['target'] ?? '' ) ) ) : '';
			$best_rank = self::number( $row['bestRank'] ?? null );
			$visibility = self::number( $row['visibilityPct'] ?? null, false );
			$trust = $row['trust'] ?? null;
			$row_date = null === ( $row['date'] ?? null ) ? null : self::date( $row['date'] );
			if ( '' === $keyword || strlen( $keyword ) > 200 || '' === $target || strlen( $target ) > 200 || ( null !== $best_rank && ( $best_rank < 1 || $best_rank > 100 ) ) || null === $visibility || $visibility < 0 || $visibility > 100 || ! in_array( $trust, array( 'trusted', 'degraded', 'untrusted' ), true ) || ( null !== ( $row['date'] ?? null ) && ! $row_date ) ) return self::error( 'rankings', 'Ranking row is invalid.' );
			$keywords[] = array( 'keyword' => $keyword, 'target' => $target, 'bestRank' => $best_rank, 'visibilityPct' => $visibility, 'date' => $row_date, 'trust' => $trust );
		}
		$latest = null === ( $input['latestMetricDate'] ?? null ) ? null : self::date( $input['latestMetricDate'] );
		if ( null !== ( $input['latestMetricDate'] ?? null ) && ! $latest ) return self::error( 'rankings', 'Ranking date is invalid.' );
		return array_merge( array( 'state' => $input['state'], 'latestMetricDate' => $latest ), $counts, array( 'keywords' => $keywords ) );
	}

	private static function work( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['state'] ?? null, array( 'available', 'partial', 'collecting', 'failed' ), true ) ) return self::error( 'work', 'Completed work snapshot is invalid.' );
		$normalized = array( 'state' => $input['state'] );
		foreach ( array( 'applied', 'skipped', 'measured', 'improved', 'regressed', 'maturing', 'ungradable' ) as $key ) {
			$count = self::count( $input[ $key ] ?? null );
			if ( null === $count ) return self::error( 'work', 'Completed work totals are invalid.' );
			$normalized[ $key ] = $count;
		}
		$move_rate = self::number( $input['moveRate'] ?? null );
		if ( null !== $move_rate && ( $move_rate < 0 || $move_rate > 1 ) ) return self::error( 'work', 'Completed work rate is invalid.' );
		$normalized['moveRate'] = $move_rate;
		return $normalized;
	}

	private static function metric( $input ) {
		if ( ! is_array( $input ) ) return self::error( 'metric', 'Invalid metric.' );
		$direction = $input['direction'] ?? null;
		if ( null !== $direction && ! in_array( $direction, self::DIRECTIONS, true ) ) return self::error( 'direction', 'Invalid direction.' );
		return array(
			'current' => self::number( $input['current'] ?? null ),
			'baseline' => self::number( $input['baseline'] ?? null ),
			'delta' => self::number( $input['delta'] ?? null ),
			'percentDelta' => self::number( $input['percentDelta'] ?? null ),
			'pointDelta' => self::number( $input['pointDelta'] ?? null ),
			'direction' => $direction,
			'directionEligible' => true === ( $input['directionEligible'] ?? false ),
		);
	}

	private static function number( $value, bool $nullable = true ): ?float {
		if ( null === $value && $nullable ) return null;
		if ( ! is_int( $value ) && ! is_float( $value ) ) return null;
		$value = (float) $value;
		return is_finite( $value ) && abs( $value ) <= 1000000000000 ? $value : null;
	}

	private static function count( $value ): ?int {
		return is_int( $value ) && $value >= 0 && $value <= 1000000 ? $value : null;
	}

	private static function date_range( $input, bool $nullable = false ) {
		if ( null === $input && $nullable ) return null;
		if ( ! is_array( $input ) ) return self::error( 'range', 'Invalid range.' );
		$start = self::date( $input['start'] ?? null );
		$end = self::date( $input['end'] ?? null );
		return $start && $end && $start <= $end ? array( 'start' => $start, 'end' => $end ) : self::error( 'range', 'Invalid range.' );
	}

	private static function date( $value ): ?string {
		$value = is_string( $value ) ? $value : '';
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}

	private static function iso_time( $value ): ?string {
		if ( ! is_string( $value ) || strlen( $value ) > 40 ) return null;
		try {
			return ( new \DateTimeImmutable( $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private static function error( string $suffix, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'rs_performance_snapshot_' . $suffix, $message, array( 'status' => $status ) );
	}
}
