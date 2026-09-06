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
	// REST route
	// -------------------------------------------------------------------------

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/indexnow';

	/** Bounded so one call cannot be turned into a bulk submission run against IndexNow. */
	const MAX_URLS_PER_REQUEST = 10;

	/**
	 * Since 3.4.0. submit() has worked for a long time but was reachable ONLY from the admin
	 * bulk-action UI (class-ratesight-bulk-operations.php) — there was no REST route, so nothing
	 * off-site could ask this site to notify search engines about a URL it had just changed. The
	 * dashboard's caller has been sitting behind a capability gate waiting for this.
	 */
	public static function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_submit' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );
	}

	/**
	 * POST /wp-json/ratesight/v1/indexnow  { urls: string[], dry_run? }
	 *
	 * Submits each URL individually (submit() takes one URL) and reports a PER-URL result, so a
	 * partial failure is visible instead of collapsing into one boolean.
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_submit( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params() ?: $request->get_body_params();
		$urls = ( is_array( $data ) && isset( $data['urls'] ) && is_array( $data['urls'] ) ) ? $data['urls'] : null;

		if ( $urls === null ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'Required field "urls" is missing or is not an array.',
			), 422 );
		}
		if ( count( $urls ) > self::MAX_URLS_PER_REQUEST ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'Too many urls: ' . count( $urls ) . ' sent, maximum is ' . self::MAX_URLS_PER_REQUEST . '.',
			), 422 );
		}

		// Only this site's own URLs. IndexNow rejects a host mismatch anyway, but submitting someone
		// else's URL under this site's key is not a request this plugin should relay.
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$dry_run = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
		$results = array();

		foreach ( $urls as $raw ) {
			$url = esc_url_raw( (string) $raw );
			if ( $url === '' ) {
				$results[] = array( 'url' => (string) $raw, 'submitted' => false, 'reason' => 'not a valid url' );
				continue;
			}
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				$results[] = array( 'url' => $url, 'submitted' => false, 'reason' => 'host does not match this site' );
				continue;
			}
			if ( $dry_run ) {
				$results[] = array( 'url' => $url, 'submitted' => false, 'reason' => 'dry run' );
				continue;
			}
			$outcome = self::submit( $url );
			$results[] = is_wp_error( $outcome )
				? array( 'url' => $url, 'submitted' => false, 'reason' => $outcome->get_error_message() )
				: array( 'url' => $url, 'submitted' => true );
		}

		return new \WP_REST_Response( array(
			'success'   => true,
			'dry_run'   => $dry_run,
			'submitted' => count( array_filter( $results, static fn( $r ) => ! empty( $r['submitted'] ) ) ),
			'results'   => $results,
		), 200 );
	}

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

	/**
	 * Return the allowlisted, value-free admin status contract.
	 */
	public static function status(): array {
		$verified = self::verify_key();

		return array(
			'configured' => trim( (string) get_option( 'ratesight_indexnow_key', '' ) ) !== '',
			'verified'   => $verified,
			'errorCode'  => $verified ? null : 'key_unreachable',
		);
	}
}
