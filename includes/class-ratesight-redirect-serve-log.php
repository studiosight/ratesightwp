<?php
/**
 * Redirect serve log.
 *
 * Logs every redirect actually served — both explicit (set via the API or admin)
 * and fuzzy (matched at runtime by the 404 router). Stored as a ring buffer in
 * a WordPress option (max MAX_ENTRIES entries, oldest dropped first).
 *
 * Shape of each entry:
 *   from       string  Requested path (e.g. /coolsculpting/)
 *   to         string  Destination URL
 *   type       string  'explicit' | 'fuzzy'
 *   confidence float   1.0 for explicit, 0–1 for fuzzy
 *   ts         string  ISO 8601 UTC timestamp
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Redirect_Serve_Log {

	const OPTION_KEY  = 'ratesight_redirect_serve_log';
	const MAX_ENTRIES = 1000;

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Append a served redirect to the log.
	 *
	 * @param string $from       Requested path or URL.
	 * @param string $to         Destination URL.
	 * @param string $type       'explicit' | 'fuzzy'
	 * @param float  $confidence 1.0 for explicit redirects.
	 */
	public static function log( string $from, string $to, string $type = 'explicit', float $confidence = 1.0 ): void {
		$log   = get_option( self::OPTION_KEY, array() );

		$log[] = array(
			'from'       => $from,
			'to'         => $to,
			'type'       => $type,
			'confidence' => round( $confidence, 4 ),
			'ts'         => gmdate( 'c' ), // ISO 8601 UTC
		);

		// Ring buffer — trim oldest entries if over cap.
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Return all log entries at or after $since (ISO 8601 or Unix timestamp string).
	 * If $since is empty, returns the last $limit entries.
	 *
	 * @param string $since ISO 8601 timestamp or empty string.
	 * @param int    $limit Maximum entries to return when $since is empty.
	 * @return array
	 */
	public static function since( string $since = '', int $limit = 100 ): array {
		$log = get_option( self::OPTION_KEY, array() );
		if ( empty( $log ) ) return array();

		if ( $since === '' ) {
			return array_values( array_slice( $log, -$limit ) );
		}

		$cutoff  = strtotime( $since );
		if ( $cutoff === false ) {
			return array_values( array_slice( $log, -$limit ) );
		}

		$filtered = array_filter( $log, static function ( $entry ) use ( $cutoff ) {
			return strtotime( $entry['ts'] ?? '1970-01-01' ) >= $cutoff;
		} );

		return array_values( $filtered );
	}
}
