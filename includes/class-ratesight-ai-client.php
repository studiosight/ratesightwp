<?php
/**
 * Ratesight AI Client.
 *
 * Thin wrapper around the Ratesight Cloudflare Worker AI endpoints.
 * The worker holds the DeepSeek API key (DEEPSEEK_API secret) and
 * proxies all requests to api.deepseek.com using deepseek-v4-flash.
 *
 * All AI calls in the plugin go through this class so there is a single
 * place to change model, endpoint, or auth if needed in future.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_AI_Client {

	const WORKER_BASE = 'https://oauth.ratesight.com';

	// ─── Core ──────────────────────────────────────────────────────────────────

	/**
	 * Send a prompt to the worker's /ai-chat endpoint.
	 *
	 * @param string $prompt       The user message.
	 * @param array  $context_data Optional context array (type, stats, etc.).
	 * @param int    $timeout      Seconds.
	 * @return array { ok: bool, reply: string } | { ok: false, error: string }
	 */
	public static function prompt( string $prompt, array|string $context_data = array(), int $timeout = 45 ): array {
		if ( ! is_array( $context_data ) || empty( $context_data ) ) {
			$context_data = array( 'type' => 'general' );
		}

		$payload = wp_json_encode( array(
			'prompt'  => $prompt,
			'context' => $context_data,
		) );
		$auth = Ratesight_OAuth_Client::sign_request( $payload );

		$response = wp_remote_post( self::WORKER_BASE . '/ai-chat', array(
			'timeout' => $timeout,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'payload' => $payload ) + $auth ),
		) );

		return self::parse( $response );
	}

	/**
	 * Send data to the worker's /insights endpoint.
	 * Worker runs the full insights prompt against the performance data
	 * and returns structured { wins, opportunities, actions, trends }.
	 */
	public static function get_insights( array $performance_data, int $timeout = 120 ): array {
		$posts_json = json_encode( $performance_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$auth       = Ratesight_OAuth_Client::sign_request( $posts_json );

		$response = wp_remote_post( self::WORKER_BASE . '/insights', array(
			'timeout' => $timeout,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'posts' => $performance_data ) + $auth ),
		) );

		return self::parse( $response );
	}

	/**
	 * Send keyword data to the worker's /recommend endpoint.
	 * Returns { ok: true, recommendations: [...] }.
	 */
	public static function get_recommendations( array $keywords, array $existing_titles, int $timeout = 30 ): array {
		$auth = Ratesight_OAuth_Client::sign_request( wp_json_encode( $keywords ) . '|recommend' );

		$response = wp_remote_post( self::WORKER_BASE . '/recommend', array(
			'timeout' => $timeout,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'keywords'        => $keywords,
				'existing_titles' => $existing_titles,
			) + $auth ),
		) );

		return self::parse( $response );
	}

	/**
	 * Convenience alias matching the original ajax_ai_chat() call pattern.
	 */
	public static function contextual_chat( string $prompt, array $context_data, int $timeout = 45 ): array {
		return self::prompt( $prompt, $context_data, $timeout );
	}

	// ─── Internal ─────────────────────────────────────────────────────────────

	private static function parse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => 'Request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['ok'] ) ) {
			$error = $body['error'] ?? ( 'HTTP ' . $code );
			return array( 'ok' => false, 'error' => $error );
		}

		return $body; // Pass through the full worker response (ok, reply, recommendations, etc.)
	}
}
