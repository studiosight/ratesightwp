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
 *   to         string  Destination URL. For type fuzzy-refused this is the BLOCKED
 *              cross-city candidate (nothing was actually served — no redirect).
 *   type       string  'explicit' | 'fuzzy' | 'fuzzy-refused'
 *   confidence float   1.0 for explicit, 0–1 for fuzzy. NOTE: context.mode 'hub'
 *              rows record the best SAME-CITY similarity that failed the threshold
 *              (may be < 0.70 or 0.0) — the hub serve is mode-based, not
 *              similarity-based; segment audits by context.mode, not confidence.
 *   ts         string  ISO 8601 UTC timestamp
 *   context    object  OPTIONAL (fuzzy rows, v3.2.18+): mode ('legacy'|'same-city'|
 *              'hub'|'refused'), source_city, target_city, fallback_reason?,
 *              refused_city? — proves WHICH constraint path served/blocked the row.
 *              Older rows lack this key; readers must treat it as optional.
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
	 * @param string $type       'explicit' | 'fuzzy' | 'fuzzy-refused'
	 * @param float  $confidence 1.0 for explicit redirects.
	 * @param array  $context    Small optional decision context (fuzzy mode rows):
	 *                           mode/source_city/target_city/fallback_reason. Kept
	 *                           tiny — every row lives in a 1000-entry option ring.
	 */
	public static function log( string $from, string $to, string $type = 'explicit', float $confidence = 1.0, array $context = array() ): void {
		$log   = get_option( self::OPTION_KEY, array() );

		$entry = array(
			'from'       => $from,
			'to'         => $to,
			'type'       => $type,
			'confidence' => round( $confidence, 4 ),
			'ts'         => gmdate( 'c' ), // ISO 8601 UTC
		);
		if ( ! empty( $context ) ) {
			// Whitelist + truncate: the log is a ring buffer in wp_options, keep rows small.
			$allowed = array( 'mode', 'source_city', 'target_city', 'fallback_reason', 'refused_city' );
			$ctx     = array();
			foreach ( $allowed as $k ) {
				if ( isset( $context[ $k ] ) && $context[ $k ] !== '' ) {
					$ctx[ $k ] = substr( (string) $context[ $k ], 0, 120 );
				}
			}
			if ( ! empty( $ctx ) ) $entry['context'] = $ctx;
		}
		$log[] = $entry;

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
