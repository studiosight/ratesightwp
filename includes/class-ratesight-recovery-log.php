<?php
/**
 * Recovery action log + re-measure loop.
 *
 * When a redirect or page-recreate is applied via the API, this class:
 *  1. Snapshots current GSC metrics (impressions, clicks, position) for the URL.
 *  2. Stores the action in `ratesight_recovery_actions` (wp_options).
 *  3. After every GSC sync, reads current metrics for each pending action and
 *     computes the before/after delta — closing the loop automatically.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Recovery_Log {

	const OPTION_KEY = 'ratesight_recovery_actions';

	// ── Log a recovery action ─────────────────────────────────────────────────

	/**
	 * Log a redirect or recreate action with current GSC baseline.
	 *
	 * @param string $type     'redirect' | 'recreate'
	 * @param string $from_url Affected URL path (e.g. /office-movers-san-carlos-ca/)
	 * @param string $to_url   Destination (redirect target or new page URL)
	 * @param array  $meta     Extra info (code, slug, title, etc.)
	 */
	public static function log( string $type, string $from_url, string $to_url, array $meta = [] ): string {
		$id      = uniqid( 'rs_', true );
		$before  = self::fetch_gsc_metrics( $from_url );
		$actions = get_option( self::OPTION_KEY, [] );

		$actions[ $id ] = [
			'id'         => $id,
			'type'       => $type,
			'from_url'   => $from_url,
			'to_url'     => $to_url,
			'meta'       => $meta,
			'applied_at' => current_time( 'mysql' ),
			'before'     => $before,
			'after'      => null,
			'delta'      => null,
			'measured_at'=> null,
		];

		update_option( self::OPTION_KEY, $actions, false );
		return $id;
	}

	// ── Re-measure after GSC sync ─────────────────────────────────────────────

	/**
	 * Hooked to `ratesight_sync_gsc` (fires after each sync).
	 * Fills in `after` metrics for any pending actions and computes delta.
	 */
	public static function remeasure(): void {
		$actions = get_option( self::OPTION_KEY, [] );
		if ( empty( $actions ) ) return;

		$updated = false;
		foreach ( $actions as $id => &$action ) {
			if ( $action['after'] !== null ) continue; // Already measured.

			// Wait at least 24h before re-measuring (one sync cycle minimum).
			$applied = strtotime( $action['applied_at'] ?? '1970-01-01' );
			if ( time() - $applied < DAY_IN_SECONDS ) continue;

			$after = self::fetch_gsc_metrics( $action['from_url'] );
			if ( $after === null ) continue; // No GSC data yet.

			$before = $action['before'] ?? [];
			$action['after']       = $after;
			$action['measured_at'] = current_time( 'mysql' );
			$action['delta']       = [
				'impressions' => ( $after['impressions'] ?? 0 ) - ( $before['impressions'] ?? 0 ),
				'clicks'      => ( $after['clicks']      ?? 0 ) - ( $before['clicks']      ?? 0 ),
				'position'    => round( ( $after['position'] ?? 0 ) - ( $before['position'] ?? 0 ), 2 ),
			];
			$updated = true;
		}
		unset( $action );

		if ( $updated ) {
			update_option( self::OPTION_KEY, $actions, false );
		}
	}

	// ── Query GSC data ────────────────────────────────────────────────────────

	/**
	 * Fetch the most recent 28-day aggregate for a URL from the performance table.
	 * Returns null if no data exists yet.
	 */
	private static function fetch_gsc_metrics( string $url ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$since = gmdate( 'Y-m-d', strtotime( '-28 days' ) );

		// Match by URL or post path.
		$url_like = '%' . $wpdb->esc_like( ltrim( $url, '/' ) ) . '%';

		$row = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT
				SUM(impressions) AS impressions,
				SUM(clicks)      AS clicks,
				AVG(position)    AS position
			 FROM `{$table}`
			 WHERE date >= %s
			   AND url LIKE %s",
			$since, $url_like
		) );

		if ( ! $row || $row->impressions === null ) return null;

		return [
			'impressions' => (int) $row->impressions,
			'clicks'      => (int) $row->clicks,
			'position'    => round( (float) $row->position, 2 ),
			'window_days' => 28,
			'fetched_at'  => current_time( 'mysql' ),
		];
	}

	// ── Read actions ──────────────────────────────────────────────────────────

	public static function get_all(): array {
		$actions = get_option( self::OPTION_KEY, [] );
		// Newest first.
		usort( $actions, fn( $a, $b ) => strcmp( $b['applied_at'] ?? '', $a['applied_at'] ?? '' ) );
		return $actions;
	}

	public static function get_pending(): array {
		return array_filter( self::get_all(), fn( $a ) => $a['after'] === null );
	}

	public static function get_measured(): array {
		return array_filter( self::get_all(), fn( $a ) => $a['after'] !== null );
	}
}
