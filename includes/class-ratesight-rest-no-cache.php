<?php
/**
 * Keeps every ratesight/v1 REST response out of CDN, proxy, page and browser caches.
 *
 * Signed reads (connection-status, inbound-log, redirects, page, ...) and the
 * public capabilities document describe live, per-caller state. A cached copy
 * can serve stale state (an old plugin_version) or hand one caller's signed
 * response to another request. The headers are applied centrally on
 * rest_post_dispatch, which WordPress runs for every served response, including
 * errors from authentication, permission callbacks and unknown routes, so no
 * route in the namespace can be missed. rest_pre_serve_request then sends them
 * again with header() so later header() calls (WordPress core no-cache block,
 * _envelope responses, themes, caching plugins) cannot replace them.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Rest_No_Cache {
	public const ROUTE_NAMESPACE = 'ratesight/v1';

	/** Request headers that change a Ratesight response; listed in Vary. */
	public const VARY_HEADERS = array(
		'Authorization',
		'Origin',
		'X-Ratesight-Auth-Version',
		'X-Ratesight-Key-Id',
		'X-Ratesight-Timestamp',
		'X-Ratesight-Nonce',
		'X-Ratesight-Content-SHA256',
		'X-Ratesight-Signature',
		'X-Ratesight-Pairing-Signature',
	);

	/** Validators that would let a cache keep or revalidate a copy. */
	public const STRIPPED_HEADERS = array( 'ETag', 'Last-Modified' );

	public static function headers(): array {
		return array(
			'Cache-Control'                => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'Pragma'                       => 'no-cache',
			'Expires'                      => '0',
			'CDN-Cache-Control'            => 'no-store',
			'Cloudflare-CDN-Cache-Control' => 'no-store',
			'Surrogate-Control'            => 'no-store',
			'X-LiteSpeed-Cache-Control'    => 'no-cache',
			'X-Accel-Expires'              => '0',
		);
	}

	public static function register(): void {
		add_filter( 'rest_send_nocache_headers', array( self::class, 'filter_send_nocache_headers' ), PHP_INT_MAX );
		add_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'reassert_headers' ), PHP_INT_MAX, 4 );
	}

	public static function is_ratesight_route( $route ): bool {
		$route  = '/' . ltrim( strtolower( trim( (string) $route ) ), '/' );
		$prefix = '/' . self::ROUTE_NAMESPACE;
		return $route === $prefix || str_starts_with( $route, $prefix . '/' );
	}

	/** Route of the current REST request, read before dispatch (no request object yet). */
	public static function current_request_route(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return (string) $_GET['rest_route'];
		}
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path   = (string) parse_url( $uri, PHP_URL_PATH );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$marker = '/' . trim( $prefix, '/' ) . '/';
		$at     = stripos( $path, $marker );
		return $at === false ? '' : substr( $path, $at + strlen( $marker ) - 1 );
	}

	/**
	 * Makes WordPress send its own no-cache headers for Ratesight requests even
	 * when nobody is logged in. Since WordPress 6.3.2 this runs after the
	 * response headers are sent, so reassert_headers() sends ours again last.
	 */
	public static function filter_send_nocache_headers( $send ) {
		return self::is_ratesight_route( self::current_request_route() ) ? true : $send;
	}

	/**
	 * rest_post_dispatch: replaces any caching headers on a Ratesight response,
	 * success or error, with no-store headers.
	 */
	public static function filter_response( $response, $server = null, $request = null ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_headers' ) || ! method_exists( $response, 'set_headers' ) ) {
			return $response;
		}
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : self::current_request_route();
		if ( ! self::is_ratesight_route( $route ) ) {
			return $response;
		}

		$ours     = self::headers();
		$replaced = array_map( 'strtolower', array_merge( array_keys( $ours ), self::STRIPPED_HEADERS, array( 'Vary' ) ) );
		$existing = (array) $response->get_headers();
		$vary     = array();
		$kept     = array();
		foreach ( $existing as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( $lower === 'vary' ) {
				$vary = array_merge( $vary, self::split_tokens( $value ) );
				continue;
			}
			if ( in_array( $lower, $replaced, true ) ) {
				continue;
			}
			$kept[ $name ] = $value;
		}
		$ours['Vary'] = self::vary_value( $vary );
		$response->set_headers( array_merge( $kept, $ours ) );

		self::mark_request_uncacheable();
		return $response;
	}

	/**
	 * rest_pre_serve_request (last): sends the headers again with replace so a
	 * header() call made by a theme or caching plugin after rest_post_dispatch
	 * cannot win, and removes validators WordPress or others already sent.
	 */
	public static function reassert_headers( $served, $result = null, $request = null, $server = null ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : self::current_request_route();
		if ( ! self::is_ratesight_route( $route ) ) {
			return $served;
		}
		self::mark_request_uncacheable();
		if ( headers_sent() ) {
			return $served;
		}
		foreach ( self::STRIPPED_HEADERS as $name ) {
			header_remove( $name );
		}
		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
		$vary = array();
		if ( is_object( $result ) && method_exists( $result, 'get_headers' ) ) {
			foreach ( (array) $result->get_headers() as $name => $value ) {
				if ( strtolower( (string) $name ) === 'vary' ) {
					$vary = array_merge( $vary, self::split_tokens( $value ) );
				}
			}
		}
		header( 'Vary: ' . self::vary_value( $vary ), true );
		return $served;
	}

	/**
	 * Tells page caches not to store this request: DONOTCACHEPAGE (WP Super
	 * Cache, W3 Total Cache, WP Rocket, WP Fastest Cache, Cache Enabler and
	 * others), LiteSpeed Cache's no-cache control, and WP Rocket's
	 * caching-file filter.
	 */
	public static function mark_request_uncacheable(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			define( 'LSCACHE_NO_CACHE', true );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_control_set_nocache', 'Ratesight REST response' );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'do_rocket_generate_caching_files', '__return_false', PHP_INT_MAX );
		}
	}

	private static function split_tokens( $value ): array {
		$tokens = array();
		foreach ( (array) $value as $part ) {
			foreach ( explode( ',', (string) $part ) as $token ) {
				$token = trim( $token );
				if ( $token !== '' ) {
					$tokens[] = $token;
				}
			}
		}
		return $tokens;
	}

	private static function vary_value( array $existing ): string {
		$out  = array();
		$seen = array();
		foreach ( array_merge( $existing, self::VARY_HEADERS ) as $token ) {
			$key = strtolower( $token );
			if ( $key === '*' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $token;
		}
		return implode( ', ', $out );
	}
}
