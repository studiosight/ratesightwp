<?php
/**
 * Ratesight AI Client.
 *
 * AI helpers moved to the Ratesight dashboard (stored recommendations). This class no longer
 * makes any network call; every method reports the helper as unavailable so callers use their
 * existing fallbacks. No AI provider key ever lives on, or is reachable from, a site.
 *
 * All AI calls in the plugin go through this class so there is a single
 * place to change model, endpoint, or auth if needed in future.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_AI_Client {

	private static function unavailable(): array {
		return array( 'ok' => false, 'error' => 'AI suggestions now appear in your Ratesight dashboard.' );
	}

	public static function prompt( string $prompt, array|string $context_data = array(), int $timeout = 45 ): array {
		unset( $prompt, $context_data, $timeout );
		return self::unavailable();
	}

	public static function get_insights( array $performance_data, int $timeout = 120 ): array {
		unset( $performance_data, $timeout );
		return self::unavailable();
	}

	public static function get_recommendations( array $keywords, array $existing_titles, int $timeout = 30 ): array {
		unset( $keywords, $existing_titles, $timeout );
		return self::unavailable();
	}

	public static function contextual_chat( string $prompt, array $context_data, int $timeout = 45 ): array {
		return self::prompt( $prompt, $context_data, $timeout );
	}
}
