<?php
/**
 * Versioned request authentication for the Ratesight REST API.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Request_Auth {
	public const VERSION        = 'rs-hmac-v2';
	public const MAX_CLOCK_SKEW = 300;
	public const MAX_BODY_BYTES = 1048576;
	public const READINESS_TTL  = 86400;
	public const MODES          = array( 'legacy', 'observe_v2', 'enforce_v2' );
	public const ROUTE_POLICIES = array(
		'GET /ratesight/v1/capabilities' => 'public_bootstrap',
		'POST /ratesight/v1/create-page' => 'signed_mutation',
		'DELETE /ratesight/v1/create-page' => 'signed_mutation',
		'GET /ratesight/v1/update-page' => 'signed_read',
		'POST /ratesight/v1/update-page' => 'signed_mutation',
		'POST /ratesight/v1/redirect' => 'signed_mutation',
		'DELETE /ratesight/v1/redirect' => 'signed_mutation',
		'GET /ratesight/v1/redirects' => 'signed_read',
		'GET /ratesight/v1/redirects-log' => 'signed_read',
		'GET /ratesight/v1/inbound-log' => 'signed_read',
		'GET /ratesight/v1/related-links' => 'signed_read',
		'POST /ratesight/v1/related-links' => 'signed_mutation',
		'DELETE /ratesight/v1/related-links' => 'signed_mutation',
		'GET /ratesight/v1/page' => 'signed_read',
		'POST /ratesight/v1/page' => 'signed_mutation',
	);

	public static function mode(): string {
		$stored = get_option( 'ratesight_auth_mode', null );
		$ever_enforced = (bool) get_option( 'ratesight_auth_ever_enforced', false );
		if ( $stored === null || $stored === false ) {
			return $ever_enforced ? 'enforce_v2' : 'legacy';
		}
		$mode = (string) $stored;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return 'enforce_v2';
		}
		if ( $ever_enforced && $mode === 'legacy' ) {
			return 'enforce_v2';
		}
		if ( $mode === 'enforce_v2' && ! $ever_enforced ) {
			update_option( 'ratesight_auth_ever_enforced', true, false );
		}
		return $mode;
	}

	public static function set_mode( string $mode ) {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'rs_auth_mode_invalid', 'Unknown request authentication mode.', array( 'status' => 400 ) );
		}
		if ( $mode === 'legacy' && (bool) get_option( 'ratesight_auth_ever_enforced', false ) ) {
			return new WP_Error( 'rs_auth_mode_downgrade_blocked', 'An enforced installation cannot return to legacy mode.', array( 'status' => 409 ) );
		}
		if ( $mode === 'enforce_v2' ) {
			if ( self::mode() !== 'enforce_v2' && self::mode() !== 'observe_v2' ) {
				return new WP_Error( 'rs_auth_enforce_observe_required', 'Observe mode is required before enforcement.', array( 'status' => 409 ) );
			}
			$secret = (string) get_option( 'ratesight_webhook_secret', '' );
			if ( $secret === '' ) {
				return new WP_Error( 'rs_auth_enforce_secret_required', 'A primary webhook secret is required before enforcement.', array( 'status' => 409 ) );
			}
			$proof = get_option( 'ratesight_auth_v2_readiness', array() );
			$accepted_at = is_array( $proof ) ? (int) ( $proof['accepted_at'] ?? 0 ) : 0;
			$proof_key   = is_array( $proof ) ? (string) ( $proof['key_id'] ?? '' ) : '';
			if ( ! hash_equals( self::key_id( $secret ), $proof_key ) || $accepted_at < time() - self::READINESS_TTL || $accepted_at > time() + self::MAX_CLOCK_SKEW ) {
				return new WP_Error( 'rs_auth_enforce_readiness_required', 'A recent successful rs-hmac-v2 request using the current key is required before enforcement.', array( 'status' => 409 ) );
			}
			update_option( 'ratesight_auth_ever_enforced', true, false );
		}
		update_option( 'ratesight_auth_mode', $mode, false );
		return true;
	}

	public static function key_id( string $secret ): string {
		return substr( hash( 'sha256', $secret ), 0, 16 );
	}

	public static function normalize_route( string $route ): string {
		$route = '/' . ltrim( preg_replace( '#/+#', '/', $route ), '/' );
		return strlen( $route ) > 1 ? rtrim( $route, '/' ) : $route;
	}

	public static function canonical_query( array $query ): string {
		$pairs = array();
		foreach ( $query as $key => $value ) {
			$values = is_array( $value ) ? $value : array( $value );
			sort( $values, SORT_STRING );
			foreach ( $values as $item ) {
				$pairs[] = array( (string) $key, (string) $item );
			}
		}
		usort( $pairs, static function ( array $a, array $b ): int {
			return $a[0] === $b[0] ? strcmp( $a[1], $b[1] ) : strcmp( $a[0], $b[0] );
		} );
		return implode( '&', array_map( static function ( array $pair ): string {
			return rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
		}, $pairs ) );
	}

	public static function canonical_query_from_raw( string $raw_query ) {
		$pairs = array();
		foreach ( explode( '&', $raw_query ) as $part ) {
			if ( $part === '' ) {
				continue;
			}
			$pieces = explode( '=', $part, 2 );
			$key    = urldecode( $pieces[0] );
			$value  = urldecode( $pieces[1] ?? '' );
			if ( strpos( $key, '[' ) !== false || strpos( $key, ']' ) !== false ) {
				return new WP_Error( 'rs_query_grammar_unsupported', 'Bracketed query keys are unsupported.', array( 'status' => 403 ) );
			}
			$pairs[] = array( $key, $value );
		}
		usort( $pairs, static function ( array $a, array $b ): int {
			return $a[0] === $b[0] ? strcmp( $a[1], $b[1] ) : strcmp( $a[0], $b[0] );
		} );
		return implode( '&', array_map( static function ( array $pair ): string {
			return rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
		}, $pairs ) );
	}

	public static function canonical_request( string $method, string $route, array $query, string $timestamp, string $nonce, string $body_digest ): string {
		return implode( "\n", array(
			self::VERSION,
			strtoupper( $method ),
			self::normalize_route( $route ),
			self::canonical_query( $query ),
			$timestamp,
			$nonce,
			strtolower( $body_digest ),
		) );
	}

	public static function signature( string $secret, string $canonical ): string {
		return 'sha256=' . hash_hmac( 'sha256', $canonical, $secret );
	}

	public static function authorize_public( $request ): bool {
		return true;
	}

	public static function authorize_read( $request ) {
		return self::authorize( $request, 'signed_read' );
	}

	public static function authorize_mutation( $request ) {
		return self::authorize( $request, 'signed_mutation' );
	}

	public static function authorize( $request, string $policy ) {
		$mode    = self::mode();
		$secret  = (string) get_option( 'ratesight_webhook_secret', '' );
		$version = (string) $request->get_header( 'x_ratesight_auth_version' );

		if ( $secret === '' ) {
			return self::failure( 'rs_secret_required', 403, $request, $policy );
		}
		if ( $version === self::VERSION ) {
			return self::verify_v2( $request, $policy );
		}

		$legacy = self::verify_legacy( $request, $secret );
		if ( true === $legacy && $mode !== 'enforce_v2' ) {
			self::record_audit( $request, $policy, 'legacy_signature_accepted' );
			return true;
		}
		if ( $mode === 'legacy' ) {
			self::record_audit( $request, $policy, 'legacy_unsigned_accepted' );
			return true;
		}
		if ( $mode === 'observe_v2' && is_wp_error( $legacy ) ) {
			return $legacy;
		}
		return self::failure( 'rs_auth_version_required', 403, $request, $policy );
	}

	private static function verify_legacy( $request, string $secret ) {
		$provided = (string) $request->get_header( 'x_ratesight_signature' );
		if ( $provided === '' ) {
			return new WP_Error( 'rs_signature_required', 'Request signature is required.', array( 'status' => 403 ) );
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret );
		return hash_equals( $expected, $provided )
			? true
			: new WP_Error( 'rs_bad_signature', 'Request signature is invalid.', array( 'status' => 403 ) );
	}

	private static function verify_v2( $request, string $policy ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::failure( 'rs_body_too_large', 413, $request, $policy );
		}

		$key_id    = (string) $request->get_header( 'x_ratesight_key_id' );
		$timestamp = (string) $request->get_header( 'x_ratesight_timestamp' );
		$nonce     = (string) $request->get_header( 'x_ratesight_nonce' );
		$digest    = strtolower( (string) $request->get_header( 'x_ratesight_content_sha256' ) );
		$signature = strtolower( (string) $request->get_header( 'x_ratesight_signature' ) );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $key_id ) || ! preg_match( '/^[0-9]{10}$/', $timestamp ) || ! preg_match( '/^[A-Za-z0-9_-]{22}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $digest ) || ! preg_match( '/^sha256=[a-f0-9]{64}$/', $signature ) ) {
			return self::failure( 'rs_auth_headers_invalid', 403, $request, $policy, $key_id );
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return self::failure( 'rs_timestamp_expired', 403, $request, $policy, $key_id );
		}
		if ( ! hash_equals( hash( 'sha256', $body ), $digest ) ) {
			return self::failure( 'rs_body_digest_mismatch', 403, $request, $policy, $key_id );
		}

		$secret = self::secret_for_key_id( $key_id );
		if ( $secret === null ) {
			return self::failure( 'rs_key_unknown', 403, $request, $policy, $key_id );
		}
		$raw_query = method_exists( $request, 'get_query_string' ) ? (string) $request->get_query_string() : (string) ( $_SERVER['QUERY_STRING'] ?? '' );
		$query = $raw_query !== '' ? self::canonical_query_from_raw( $raw_query ) : self::canonical_query( $request->get_query_params() );
		if ( is_wp_error( $query ) ) {
			return self::failure( $query->get_error_code(), 403, $request, $policy, $key_id );
		}
		$canonical = implode( "\n", array( self::VERSION, strtoupper( $request->get_method() ), self::normalize_route( $request->get_route() ), $query, $timestamp, $nonce, $digest ) );
		if ( ! hash_equals( self::signature( $secret, $canonical ), $signature ) ) {
			return self::failure( 'rs_bad_signature', 403, $request, $policy, $key_id );
		}
		if ( ! self::claim_nonce( $key_id, $nonce, (int) $timestamp ) ) {
			return self::failure( 'rs_nonce_replayed', 409, $request, $policy, $key_id );
		}
		$primary = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( $primary !== '' && hash_equals( self::key_id( $primary ), $key_id ) ) {
			update_option( 'ratesight_auth_v2_readiness', array( 'key_id' => $key_id, 'accepted_at' => time() ), false );
		}
		self::record_audit( $request, $policy, 'v2_accepted', $key_id );
		return true;
	}

	private static function secret_for_key_id( string $key_id ): ?string {
		$primary = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( $primary !== '' && hash_equals( self::key_id( $primary ), $key_id ) ) {
			return $primary;
		}
		$previous = (string) get_option( 'ratesight_webhook_secret_previous', '' );
		$expires  = (int) get_option( 'ratesight_webhook_secret_previous_expires', 0 );
		if ( $previous !== '' && $expires >= time() && hash_equals( self::key_id( $previous ), $key_id ) ) {
			return $previous;
		}
		return null;
	}

	private static function claim_nonce( string $key_id, string $nonce, int $timestamp ): bool {
		$name = 'ratesight_auth_nonce_' . hash( 'sha256', $key_id . ':' . $nonce );
		return add_option( $name, $timestamp + self::MAX_CLOCK_SKEW, '', false );
	}

	public static function prune_nonces(): void {
		global $wpdb;
		$like = $wpdb->esc_like( 'ratesight_auth_nonce_' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
			$like
		), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['option_value'] < time() ) {
				delete_option( $row['option_name'] );
			}
		}
	}

	public static function capability_auth(): array {
		$primary  = (string) get_option( 'ratesight_webhook_secret', '' );
		$previous = (string) get_option( 'ratesight_webhook_secret_previous', '' );
		$expires  = (int) get_option( 'ratesight_webhook_secret_previous_expires', 0 );
		$proof    = get_option( 'ratesight_auth_v2_readiness', array() );
		$accepted_at = is_array( $proof ) ? (int) ( $proof['accepted_at'] ?? 0 ) : 0;
		$proof_key   = is_array( $proof ) ? (string) ( $proof['key_id'] ?? '' ) : '';
		$readiness_current = $primary !== '' && hash_equals( self::key_id( $primary ), $proof_key ) && $accepted_at >= time() - self::READINESS_TTL && $accepted_at <= time() + self::MAX_CLOCK_SKEW;
		return array(
			'supported'              => array( self::VERSION, 'legacy-body-hmac' ),
			'mode'                   => self::mode(),
			'configured'             => $primary !== '',
			'current_key_id'         => $primary !== '' ? self::key_id( $primary ) : null,
			'previous_key_id'        => $previous !== '' && $expires >= time() ? self::key_id( $previous ) : null,
			'previous_grace_expires' => $previous !== '' && $expires >= time() ? gmdate( 'c', $expires ) : null,
			'readiness_current'       => $readiness_current,
			'readiness_expires'       => $readiness_current ? gmdate( 'c', $accepted_at + self::READINESS_TTL ) : null,
		);
	}

	private static function failure( string $code, int $status, $request, string $policy, string $key_id = '' ) {
		self::record_audit( $request, $policy, $code, $key_id );
		return new WP_Error( $code, 'Request authentication failed.', array( 'status' => $status ) );
	}

	private static function record_audit( $request, string $policy, string $result, string $key_id = '' ): void {
		$rows = get_option( 'ratesight_auth_audit', array() );
		$rows = is_array( $rows ) ? $rows : array();
		$rows[] = array(
			'time'       => gmdate( 'c' ),
			'request_id' => substr( hash( 'sha256', (string) $request->get_header( 'x_ratesight_nonce' ) . microtime( true ) ), 0, 20 ),
			'method'     => strtoupper( (string) $request->get_method() ),
			'route'      => self::normalize_route( (string) $request->get_route() ),
			'policy'     => $policy,
			'key_id'     => preg_match( '/^[a-f0-9]{16}$/', $key_id ) ? $key_id : null,
			'result'     => $result,
		);
		update_option( 'ratesight_auth_audit', array_slice( $rows, -100 ), false );
	}
}
