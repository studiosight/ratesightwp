<?php
/**
 * Activity logger.
 *
 * Log lifecycle for a deferred-publish webhook request:
 *
 *   1. Webhook arrives         → log_pending()   → status: 'pending',  returns log_id
 *   2. Cron publishes post     → log_update()    → status: 'success' or 'success_with_warnings'
 *   3. Any fatal error         → log_error()     → status: 'failed'
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Logger {

	// ── Status constants ──────────────────────────────────────────────────────
	const STATUS_PENDING          = 'pending';
	const STATUS_SUCCESS          = 'success';
	const STATUS_SUCCESS_WARNINGS = 'success_with_warnings';
	const STATUS_FAILED           = 'failed';
	const STATUS_MODIFIED         = 'modified';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Create a pending log row at the start of a webhook request.
	 * Returns the log row ID so it can be updated later by the cron job.
	 *
	 * @return int  Log row ID (0 on DB failure).
	 */
	public static function log_pending( string $title, string $child_category = '', string $raw_payload = '' ) {
		return self::insert( array(
			'title'          => $title,
			'child_category' => $child_category,
			'status'         => self::STATUS_PENDING,
			'raw_payload'    => self::maybe_payload( $raw_payload ),
		) );
	}

	/**
	 * Update an existing log row once the cron job completes.
	 *
	 * @param int    $log_id   Row ID returned by log_pending().
	 * @param int    $post_id  The post that was created.
	 * @param string $status   One of the STATUS_* constants.
	 * @param string $notes    Any non-fatal warnings accumulated during processing.
	 */
	public static function log_update( int $log_id, int $post_id, string $status, string $notes = '' ) {
		global $wpdb;
		$wpdb->update(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prefix . RATESIGHT_LOG_TABLE,
			array_filter( array(
				'post_id' => $post_id,
				'status'  => $status,
				'notes'   => $notes !== '' ? $notes : null,
			), static fn( $v ) => $v !== null ),
			array( 'id' => $log_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Log a fatal failure (used when the webhook pipeline itself errors out
	 * before a post is created, so there is no pending row to update).
	 */
	public static function log_error( string $error_message, ?int $post_id = null, string $title = '', string $raw_payload = '' ) {
		self::insert( array(
			'post_id'       => $post_id,
			'title'         => $title,
			'status'        => self::STATUS_FAILED,
			'error_message' => $error_message,
			'raw_payload'   => self::maybe_payload( $raw_payload ),
		) );

		// Optionally mirror failures to the PHP / WP_DEBUG_LOG error log.
		if ( Ratesight_Options::get( 'log_errors_to_wp' ) ) {
			$context = $title ? " | title: {$title}" : '';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[Ratesight] Webhook error{$context}: {$error_message}" );
		}
	}

	/**
	 * Retrieve recent log rows for the admin log viewer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_logs( int $limit = 100, string $search = '', string $status = '' ) {
		global $wpdb;
		$table  = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$where  = array( '1=1' );
		$params = array();

		if ( $search !== '' ) {
			$where[]  = '( title LIKE %s OR child_category LIKE %s OR error_message LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $status !== '' ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$sql = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY received_at DESC LIMIT %d';  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete rows older than the configured retention period.
	 * Hooked to ratesight_prune_logs WP-Cron event.
	 */
	public static function prune_logs() {
		global $wpdb;

		// ── Activity log ──────────────────────────────────────────────────────
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$log_days  = max( 1, (int) Ratesight_Options::get( 'log_retention_days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"DELETE FROM `{$log_table}` WHERE received_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$log_days
		) );

		// ── Performance history (GSC, keywords, GBP) ─────────────────────────
		// 0 = keep forever. Minimum 90 days so trend data is always meaningful.
		$perf_retention = (int) Ratesight_Options::get( 'performance_retention_days' );
		if ( $perf_retention > 0 ) :
			$perf_days  = max( 90, $perf_retention );

			$perf_table = $wpdb->prefix . 'ratesight_performance';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"DELETE FROM `{$perf_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$perf_days
			) );

			$kw_table = $wpdb->prefix . 'ratesight_keywords';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"DELETE FROM `{$kw_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$perf_days
			) );

			$gbp_table = $wpdb->prefix . 'ratesight_gbp_performance';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"DELETE FROM `{$gbp_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$perf_days
			) );
		endif;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Insert a row and return its ID (0 on failure).
	 */
	private static function insert( array $data ) {
		global $wpdb;

		$row = array_merge( array(
			'received_at'    => current_time( 'mysql' ),
			'post_id'        => null,
			'title'          => '',
			'child_category' => '',
			'status'         => self::STATUS_PENDING,
			'error_message'  => null,
			'notes'          => null,
			'raw_payload'    => null,
		), $data );

		// NULL post_id must bind as %s so it inserts as SQL NULL, not 0.
		$formats = array( '%s', is_null( $row['post_id'] ) ? '%s' : '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		$result = $wpdb->insert( $wpdb->prefix . RATESIGHT_LOG_TABLE, $row, $formats );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Return the payload string only when storage is enabled in settings,
	 * otherwise return null so the column stays empty.
	 */
	private static function maybe_payload( string $payload ): ?string {
		return Ratesight_Options::get( 'store_raw_payload' ) ? $payload : null;
	}
}
