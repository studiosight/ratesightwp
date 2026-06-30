<?php
/**
 * IndexNow integration.
 *
 * IndexNow notifies multiple search engines (Bing, Yandex, etc.) instantly
 * when a URL is published or updated.
 *
 * Key serving strategy: rather than writing a file to the web root (which
 * fails on many hosts), we intercept requests via WordPress's own routing
 * and serve the key as plain text. This works on every host and every
 * permalink structure.
 *
 * Key URL format: https://example.com/?rs_indexnow={key}
 * This is passed as keyLocation in all API requests.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_IndexNow {

	private const API_URL = 'https://api.indexnow.org/IndexNow';

	// -------------------------------------------------------------------------
	// Key management
	// -------------------------------------------------------------------------

	/**
	 * Get the IndexNow key, generating one if it doesn't exist yet.
	 */
	public static function get_key() {
		$key = get_option( 'ratesight_indexnow_key', '' );
		if ( ! $key ) {
			$key = wp_generate_password( 32, false, false );
			update_option( 'ratesight_indexnow_key', $key, false );
		}
		return $key;
	}

	/**
	 * URL where the key is served. Passed to IndexNow as keyLocation.
	 */
	public static function key_url() {
		return add_query_arg( 'rs_indexnow', self::get_key(), home_url( '/' ) );
	}

	/**
	 * Serve the key file when WordPress intercepts the request.
	 * Hooked to template_redirect.
	 */
	public static function maybe_serve_key() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = sanitize_text_field( wp_unslash( $_GET['rs_indexnow'] ?? '' ) );
		if ( ! $requested ) return;

		$key = get_option( 'ratesight_indexnow_key', '' );
		if ( ! $key || ! hash_equals( $key, $requested ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo esc_html( $key );
		exit;
	}

	// -------------------------------------------------------------------------
	// Submission
	// -------------------------------------------------------------------------

	/**
	 * Submit a single URL to IndexNow.
	 * Called silently after a post publishes.
	 *
	 * @param  string $url  The full URL to submit.
	 * @return true|\WP_Error
	 */
	public static function submit( string $url ): bool|\WP_Error {
		$key  = self::get_key();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => self::key_url(),
				'urlList'     => array( $url ),
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			self::log_entry( array( $url ), false, $response->get_error_message() );
			return new \WP_Error( 'rs_indexnow_http', $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 200 || $code === 202 ) {
			self::log_entry( array( $url ), true, 'HTTP ' . $code );
			return true;
		}
		$msg = wp_remote_retrieve_response_message( $response );
		self::log_entry( array( $url ), false, "HTTP {$code}: {$msg}" );
		return new \WP_Error( 'rs_indexnow_api', "IndexNow returned HTTP {$code}: {$msg}" );
	}

	public static function submit_bulk( array $urls ): bool|\WP_Error {
		if ( empty( $urls ) ) return true;
		$key  = self::get_key();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$response = wp_remote_post( self::API_URL, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => self::key_url(),
				'urlList'     => array_values( array_unique( $urls ) ),
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			self::log_entry( $urls, false, $response->get_error_message() );
			return new \WP_Error( 'rs_indexnow_http', $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 200 || $code === 202 ) {
			self::log_entry( $urls, true, 'HTTP ' . $code );
			return true;
		}
		self::log_entry( $urls, false, "HTTP {$code}" );
		return new \WP_Error( 'rs_indexnow_api', "IndexNow HTTP {$code}" );
	}

	// ── Submission log ────────────────────────────────────────────────────────

	private static function log_entry( array $urls, bool $success, string $note ): void {
		$log = get_option( 'ratesight_indexnow_log', array() );
		array_unshift( $log, array(
			'time'    => current_time( 'mysql' ),
			'urls'    => $urls,
			'success' => $success,
			'note'    => $note,
		) );
		update_option( 'ratesight_indexnow_log', array_slice( $log, 0, 50 ), false );
	}

	public static function get_log(): array {
		return get_option( 'ratesight_indexnow_log', array() );
	}

	public static function clear_log(): void {
		delete_option( 'ratesight_indexnow_log' );
	}

	// Status check
	// -------------------------------------------------------------------------

	/**
	 * Verify the key URL is reachable and returns the correct key.
	 * Used in the Connections tab status check.
	 */
	public static function verify_key() {
		$key      = self::get_key();
		$response = wp_remote_get( self::key_url(), array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) ) return false;

		$body = trim( wp_remote_retrieve_body( $response ) );
		return hash_equals( $key, $body );
	}
}
