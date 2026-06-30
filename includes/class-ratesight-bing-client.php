<?php
/**
 * Bing Webmaster Tools API client.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Bing_Client {

	const API_BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

	public static function is_connected(): bool {
		return trim( (string) Ratesight_Options::get( 'bing_api_key' ) ) !== '';
	}

	public static function is_locked(): bool {
		return trim( (string) Ratesight_Options::get( 'bing_site_url' ) ) !== '';
	}

	public static function get_api_key(): string {
		return trim( (string) Ratesight_Options::get( 'bing_api_key' ) );
	}

	public static function get_site_url(): string {
		return trim( (string) Ratesight_Options::get( 'bing_site_url' ) );
	}

	private static function api_get( string $method, array $params = array() ): array|\WP_Error {
		$api_key = self::get_api_key();
		if ( $api_key === '' ) {
			return new \WP_Error( 'bing_no_key', 'Bing API key not configured.' );
		}

		$params['apikey'] = $api_key;
		$url              = self::API_BASE . $method . '?' . http_build_query( $params );

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) return $response;

		$code    = wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		if ( $code === 429 ) {
			$retry_after = $headers['retry-after'] ?? 60;
			return new \WP_Error( 'bing_rate_limit', "Bing API rate limited — retry after {$retry_after}s. Sync will resume on next scheduled run." );
		}
		if ( $code === 401 ) return new \WP_Error( 'bing_auth', 'Bing API key is invalid or expired.' );
		if ( $code !== 200 ) return new \WP_Error( 'bing_http', 'Bing API returned HTTP ' . $code );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) return new \WP_Error( 'bing_parse', 'Could not parse Bing API response.' );

		return $body;
	}

	public static function get_sites(): array|\WP_Error {
		$response = self::api_get( 'GetUserSites' );
		if ( is_wp_error( $response ) ) return $response;
		return $response['d'] ?? array();
	}

	public static function sync_performance(): array|\WP_Error {
		if ( ! self::is_connected() || ! self::is_locked() ) {
			return new \WP_Error( 'not_ready', 'Not connected or no site locked.' );
		}

		$site_url = self::get_site_url();
		$response = self::api_get( 'GetPageStats', array( 'siteUrl' => $site_url ) );
		if ( is_wp_error( $response ) ) return $response;

		$raw_rows   = $response['d'] ?? array();
		$today      = current_time( 'Y-m-d' );
		$url_map    = self::build_url_map();
		$stored     = 0;
		$skipped    = 0;
		$sample     = array_slice( $raw_rows, 0, 3 );

		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_bing_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $raw_rows as $row ) {
			// GetPageStats returns page URLs in the Query field.
			$url = $row['Query'] ?? ( $row['PageUrl'] ?? ( $row['Url'] ?? '' ) );
			if ( ! $url ) { $skipped++; continue; }

			// Bing dates come as /Date(ms)/ — convert to Y-m-d.
			$raw_date = $row['Date'] ?? '';
			if ( preg_match( '/Date\((\d+)\)/', $raw_date, $m ) ) {
				$date = gmdate( 'Y-m-d', (int) ( $m[1] / 1000 ) );
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw_date ) ) {
				$date = substr( $raw_date, 0, 10 );
			} else {
				$date = $today;
			}

			$post_id = $url_map[ rtrim( $url, '/' ) ]
				?? $url_map[ rtrim( $url, '/' ) . '/' ]
				?? 0;

			$wpdb->replace( $perf_table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				'post_id'     => $post_id,
				'url'         => $url,
				'date'        => $date,
				'impressions' => (int)   ( $row['Impressions']          ?? $row['AvgImpressions']   ?? 0 ),
				'clicks'      => (int)   ( $row['Clicks']               ?? $row['AvgClicksPerDay']  ?? 0 ),
				'position'    => (float) ( $row['AvgImpressionPosition'] ?? $row['AvgClickPosition'] ?? 0 ),
				'ctr'         => (float) ( $row['AvgCTR'] ?? 0 ),
			), array( '%d', '%s', '%s', '%d', '%d', '%f', '%f' ) );

			$post_id ? $stored++ : $skipped++;
		}

		update_option( 'ratesight_bing_last_sync', current_time( 'mysql' ) );

		$keep_days = (int) Ratesight_Options::get( 'performance_retention_days' );
		if ( $keep_days > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$perf_table}` WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)", $keep_days ) ); // phpcs:ignore
		}

		return array(
			'total_rows' => count( $raw_rows ),
			'stored'     => $stored,
			'skipped'    => $skipped,
			'kw_stored'  => 0,
			'sample'     => $sample,
		);
	}

	private static function build_url_map(): array {
		$posts = get_posts( array(
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$map = array();
		foreach ( $posts as $id ) {
			$permalink = get_permalink( $id );
			if ( $permalink ) {
				$map[ rtrim( $permalink, '/' ) ] = $id;
			}
		}
		return $map;
	}
}
