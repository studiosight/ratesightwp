<?php
/**
 * Redirect health monitor.
 *
 * Runs daily, checks all pages that have GSC impressions, flags any that:
 *  - Return 4xx (hard 404)
 *  - 301/302 to the homepage or other catch-all targets
 *  - Chain through multiple redirects to a catch-all
 *
 * Sends an email digest only when problems are found.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Redirect_Health {

	// Paths that count as "catch-all" regardless of site.
	// The homepage is always in this list; admin can add more via the option.
	private static function catch_all_urls(): array {
		$home   = trailingslashit( home_url( '/' ) );
		$extras = array_filter( array_map(
			'trim',
			explode( "\n", get_option( 'ratesight_health_catch_all_urls', '' ) )
		) );

		$all = array_merge( [ $home ], $extras );
		// Normalise: trailing slash, lowercase.
		return array_unique( array_map(
			fn( $u ) => strtolower( trailingslashit( $u ) ),
			$all
		) );
	}

	// ── Main check ───────────────────────────────────────────────────────────

	/**
	 * Hooked to `ratesight_redirect_health` cron event.
	 * Checks all ranked pages and emails a digest if any are soft-404ing.
	 */
	public static function run(): void {
		$pages     = self::get_ranked_pages();
		if ( empty( $pages ) ) return;

		$catch_all = self::catch_all_urls();
		$flagged   = [];

		foreach ( $pages as $page ) {
			$url    = $page['url'];
			$result = self::check_url( $url, $catch_all );
			if ( $result['problem'] ) {
				$flagged[] = array_merge( $page, $result );
			}
		}

		// Store last-run result for admin UI.
		update_option( 'ratesight_redirect_health_last', [
			'checked_at' => current_time( 'mysql' ),
			'total'      => count( $pages ),
			'flagged'    => count( $flagged ),
			'issues'     => $flagged,
		], false );

		if ( empty( $flagged ) ) return;

		self::send_alert( $flagged );
	}

	// ── Check a single URL ────────────────────────────────────────────────────

	private static function check_url( string $url, array $catch_all ): array {
		$result = [ 'problem' => false, 'status' => null, 'issue' => null, 'redirect_to' => null ];

		$response = wp_remote_head( $url, [
			'timeout'     => 8,
			'redirection' => 0, // Don't auto-follow — we want the raw status.
			'user-agent'  => 'Mozilla/5.0 (compatible; Ratesight-Health/1.0)',
		] );

		if ( is_wp_error( $response ) ) {
			$result['problem'] = true;
			$result['status']  = 0;
			$result['issue']   = 'unreachable: ' . $response->get_error_message();
			return $result;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );
		$result['status'] = $code;

		if ( $code >= 400 ) {
			$result['problem'] = true;
			$result['issue']   = "hard {$code}";
			return $result;
		}

		if ( $code >= 300 && $code < 400 && $location ) {
			$dest       = strtolower( trailingslashit( $location ) );
			$is_catchall = false;
			foreach ( $catch_all as $catchall_url ) {
				if ( $dest === $catchall_url || str_ends_with( $dest, wp_parse_url( $catchall_url, PHP_URL_PATH ) ?? '' ) ) {
					$is_catchall = true;
					break;
				}
			}
			if ( $is_catchall ) {
				$result['problem']     = true;
				$result['issue']       = "soft-404: {$code} → catch-all";
				$result['redirect_to'] = $location;
				return $result;
			}

			// Follow one more hop to catch double-redirects to catch-all.
			$r2 = wp_remote_head( $location, [
				'timeout'     => 6,
				'redirection' => 0,
				'user-agent'  => 'Mozilla/5.0 (compatible; Ratesight-Health/1.0)',
			] );
			if ( ! is_wp_error( $r2 ) ) {
				$code2  = (int) wp_remote_retrieve_response_code( $r2 );
				$loc2   = wp_remote_retrieve_header( $r2, 'location' );
				if ( $code2 >= 400 ) {
					$result['problem']     = true;
					$result['issue']       = "redirect chain dead: {$code} → {$code2}";
					$result['redirect_to'] = $location;
				} elseif ( $loc2 ) {
					$dest2 = strtolower( trailingslashit( $loc2 ) );
					foreach ( $catch_all as $catchall_url ) {
						if ( $dest2 === $catchall_url ) {
							$result['problem']     = true;
							$result['issue']       = "soft-404 via chain: → catch-all";
							$result['redirect_to'] = "{$location} → {$loc2}";
							break;
						}
					}
				}
			}
		}

		return $result;
	}

	// ── Get pages with GSC impressions ────────────────────────────────────────

	private static function get_ranked_pages(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ratesight_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$since = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT url, SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(position) AS position
			 FROM `{$table}`
			 WHERE date >= %s
			 GROUP BY url
			 HAVING impressions > 0
			 ORDER BY impressions DESC
			 LIMIT 500",
			$since
		), ARRAY_A );

		return $rows ?: [];
	}

	// ── Email alert ───────────────────────────────────────────────────────────

	private static function send_alert( array $flagged ): void {
		$count   = count( $flagged );
		$subject = "[Ratesight] {$count} ranking page" . ( $count === 1 ? '' : 's' ) . ' soft-404ing on ' . wp_parse_url( home_url(), PHP_URL_HOST );

		$lines = [];
		foreach ( $flagged as $f ) {
			$impr  = number_format( (int) ( $f['impressions'] ?? 0 ) );
			$pos   = round( (float) ( $f['position'] ?? 0 ), 1 );
			$redir = $f['redirect_to'] ? " → {$f['redirect_to']}" : '';
			$lines[] = "• {$f['url']} [{$impr} impr, pos {$pos}] — {$f['issue']}{$redir}";
		}

		$body = "Ratesight found {$count} ranking page" . ( $count === 1 ? '' : 's' )
			. " on " . home_url() . " that are soft-404ing or broken:\n\n"
			. implode( "\n", $lines )
			. "\n\nThese pages have real search impressions but are no longer serving content correctly."
			. " Use the 404-recovery flow to recreate or redirect them before rankings drop further.";

		Ratesight_Notifier::alert( $subject, $body );
	}

	// ── Public: get last result for admin UI ─────────────────────────────────

	public static function get_last_result(): array {
		return get_option( 'ratesight_redirect_health_last', [
			'checked_at' => null,
			'total'      => 0,
			'flagged'    => 0,
			'issues'     => [],
		] );
	}
}
