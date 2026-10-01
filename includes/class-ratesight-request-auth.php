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
	/** Unsigned draft creations accepted per site per window (legacy and observe_v2 only). */
	public const UNSIGNED_DRAFT_LIMIT  = 30;
	public const UNSIGNED_DRAFT_WINDOW = 86400;
	public const UNSIGNED_DRAFT_ROUTE  = '/ratesight/v1/create-page';
	private const UNSIGNED_DRAFT_OPTION = 'ratesight_unsigned_draft_window';
	/**
	 * Since 3.15.1: connecting addresses of the Ratesight CRM publisher. Compared with
	 * REMOTE_ADDR only (forwarding headers are caller-controlled and never read). Used
	 * only when the site turned on "Ratesight CRM posts" (TRUSTED_PUBLISHER_SETTING).
	 * Since 3.15.2 the address alone is not enough: the request must also carry the
	 * site's CRM key (Ratesight_CRM_Publish), and the key is never accepted from any
	 * other address.
	 */
	public const TRUSTED_PUBLISHER_ADDRESSES = array( '67.199.171.44' );
	public const TRUSTED_PUBLISHER_SETTING   = 'ratesight_crm_publisher_trust';
	/**
	 * New posts published for the trusted publisher per site per window. Since 3.15.2
	 * (was 500): past the limit a CRM post is still created, as a draft.
	 */
	public const TRUSTED_PUBLISHER_LIMIT     = 10;
	public const TRUSTED_PUBLISHER_WINDOW    = 86400;
	private const TRUSTED_PUBLISHER_OPTION   = 'ratesight_trusted_publisher_window';
	private static $operational_candidates = array();
	/** @var WeakMap|null Request object => audit request id for admitted unsigned drafts. */
	private static $unsigned_drafts = null;
	/** @var WeakMap|null Request object => true when admitted as the trusted publisher. */
	private static $trusted_publishers = null;
	/** @var WeakMap|null Request object => array( fingerprint => decision ) for this PHP request. */
	private static $decisions = null;
	public const ROUTE_POLICIES = array(
		'GET /ratesight/v1/capabilities' => 'public_bootstrap',
		'POST /ratesight/v1/pair' => 'public_signed_bootstrap',
		'POST /ratesight/v1/enrollment-challenge' => 'public_proof_bootstrap',
		'POST /ratesight/v1/claim' => 'public_signed_bootstrap',
		'POST /ratesight/v1/plugin-update' => 'signed_mutation',
		'GET /ratesight/v1/auth-self-test' => 'signed_read',
		'GET /ratesight/v1/connection-status' => 'signed_read',
		'GET /ratesight/v1/performance-snapshot' => 'signed_read',
		'POST /ratesight/v1/performance-snapshot' => 'signed_mutation',
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
		'POST /ratesight/v1/trash-page' => 'signed_mutation',
		'POST /ratesight/v1/restore-page' => 'signed_mutation',
		'POST /ratesight/v1/media-alt' => 'signed_mutation',
		'POST /ratesight/v1/indexnow' => 'signed_mutation',
		'GET /ratesight/v1/crm-publish' => 'signed_read',
		'POST /ratesight/v1/crm-publish' => 'signed_mutation',
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
			$completed_at = is_array( $proof ) ? (int) ( $proof['completed_at'] ?? 0 ) : 0;
			$proof_key   = is_array( $proof ) ? (string) ( $proof['key_id'] ?? '' ) : '';
			if ( ! hash_equals( self::key_id( $secret ), $proof_key ) || $completed_at < time() - self::READINESS_TTL || $completed_at > time() + self::MAX_CLOCK_SKEW ) {
				return new WP_Error( 'rs_auth_enforce_readiness_required', 'A recent successful current-key rs-hmac-v2 self-test is required before enforcement.', array( 'status' => 409 ) );
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

	public static function canonical_query_from_raw( string $raw_query, string $matched_route = '' ) {
		$pairs              = array();
		$transport_excluded = false;
		$normalized_route   = $matched_route !== '' ? self::normalize_route( $matched_route ) : '';
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
			if ( ! $transport_excluded && $key === 'rest_route' && $normalized_route !== '' && self::normalize_route( $value ) === $normalized_route ) {
				$transport_excluded = true;
				continue;
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

	public static function signed_headers( string $secret, string $method, string $route, array $query = array(), string $body = '' ): array {
		$timestamp = (string) time();
		$nonce     = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
		$digest    = hash( 'sha256', $body );
		$canonical = self::canonical_request( $method, $route, $query, $timestamp, $nonce, $digest );
		return array(
			'X-Ratesight-Auth-Version'   => self::VERSION,
			'X-Ratesight-Key-Id'         => self::key_id( $secret ),
			'X-Ratesight-Timestamp'      => $timestamp,
			'X-Ratesight-Nonce'          => $nonce,
			'X-Ratesight-Content-SHA256' => $digest,
			'X-Ratesight-Signature'      => self::signature( $secret, $canonical ),
		);
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

	/**
	 * Since 3.15.1 the decision is made once per request. WordPress calls a route's
	 * permission callback again for every handler on the matched route when it builds
	 * the Allow header (rest_send_allow_header), with the same request object. Before
	 * this, each repeat was judged as a new request: a valid rs-hmac-v2 request was
	 * audited a second time as rs_nonce_replayed, and one unsigned create-page used
	 * three slots of the unsigned draft limit (POST plus the DELETE handler), so a
	 * site was limited to 10 drafts per 24 hours instead of 30. A replay from the
	 * network is a different request object and is still judged on its own.
	 */
	public static function authorize( $request, string $policy ) {
		if ( ! is_object( $request ) ) {
			return self::decide( $request, $policy );
		}
		$fingerprint = hash( 'sha256', implode( "\n", array(
			$policy,
			strtoupper( (string) $request->get_method() ),
			(string) $request->get_route(),
			(string) $request->get_header( 'x_ratesight_auth_version' ),
			(string) $request->get_header( 'x_ratesight_key_id' ),
			(string) $request->get_header( 'x_ratesight_timestamp' ),
			(string) $request->get_header( 'x_ratesight_nonce' ),
			(string) $request->get_header( 'x_ratesight_signature' ),
			(string) $request->get_header( 'x_ratesight_content_sha256' ),
			method_exists( $request, 'get_query_string' ) ? (string) $request->get_query_string() : (string) ( $_SERVER['QUERY_STRING'] ?? '' ),
			(string) json_encode( $request->get_query_params() ),
			hash( 'sha256', (string) $request->get_body() ),
			(string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			self::mode(),
			self::trusted_publisher_enabled() ? '1' : '0',
			class_exists( 'Ratesight_CRM_Publish' ) ? hash( 'sha256', 'k:' . Ratesight_CRM_Publish::provided_key( $request ) ) : '',
			(string) $request->get_header( 'x_ratesight_crm_key' ),
		) ) );
		self::$decisions ??= new WeakMap();
		$known = self::$decisions[ $request ] ?? array();
		if ( array_key_exists( $fingerprint, $known ) ) {
			return $known[ $fingerprint ];
		}
		$decision              = self::decide( $request, $policy );
		$known[ $fingerprint ] = $decision;
		self::$decisions[ $request ] = $known;
		return $decision;
	}

	private static function decide( $request, string $policy ) {
		$mode    = self::mode();
		$secret  = (string) get_option( 'ratesight_webhook_secret', '' );
		$version = (string) $request->get_header( 'x_ratesight_auth_version' );

		if ( $secret === '' ) {
			return self::failure( 'rs_secret_required', 403, $request, $policy );
		}
		if ( $version === self::VERSION ) {
			return self::verify_v2( $request, $policy );
		}

		if ( $mode === 'enforce_v2' ) {
			return self::failure( 'rs_auth_version_required', 403, $request, $policy );
		}

		// legacy and observe_v2 accept a VALID legacy body HMAC. Since 3.14.0 a request
		// with an invalid signature is rejected in every mode, and a request with no
		// signature is rejected everywhere except one narrow case: POST /create-page may
		// create a NEW DRAFT (never publish, never update an existing post), rate
		// limited and audited. The handler enforces the draft-only restrictions.
		$legacy = self::verify_legacy( $request, $secret );
		if ( true === $legacy ) {
			self::record_audit( $request, $policy, 'legacy_signature_accepted' );
			return true;
		}
		$legacy_error = is_wp_error( $legacy ) ? $legacy->get_error_code() : 'rs_signature_required';
		if ( $legacy_error === 'rs_signature_required' && self::is_unsigned_draft_candidate( $request, $policy ) ) {
			// Since 3.15.1: the Ratesight CRM publisher does not sign. When the site
			// turned on "Ratesight CRM posts", the new post follows the site's Final
			// Post Status instead of being held as a draft. Since 3.15.2 that needs
			// BOTH the publisher's address AND the site's CRM key; with either missing
			// it is a new draft. Every other restriction of an unsigned creation still
			// applies (new post only, never an update).
			$trust = self::trusted_publisher_check( $request );
			if ( $trust === 'trusted' ) {
				return self::accept_trusted_publisher( $request, $policy );
			}
			return self::accept_unsigned_draft( $request, $policy, $trust );
		}
		return self::failure( $legacy_error, 403, $request, $policy );
	}

	private static function is_unsigned_draft_candidate( $request, string $policy ): bool {
		return $policy === 'signed_mutation'
			&& strtoupper( (string) $request->get_method() ) === 'POST'
			&& self::normalize_route( (string) $request->get_route() ) === self::UNSIGNED_DRAFT_ROUTE
			&& (string) $request->get_header( 'x_ratesight_auth_version' ) === ''
			&& (string) $request->get_header( 'x_ratesight_signature' ) === '';
	}

	private static function accept_unsigned_draft( $request, string $policy, string $note = '' ) {
		$now    = time();
		$window = get_option( self::UNSIGNED_DRAFT_OPTION, array() );
		$window = array_values( array_filter( is_array( $window ) ? $window : array(), static function ( $stamp ) use ( $now ): bool {
			return is_int( $stamp ) && $stamp > $now - self::UNSIGNED_DRAFT_WINDOW && $stamp <= $now + self::MAX_CLOCK_SKEW;
		} ) );
		if ( count( $window ) >= self::UNSIGNED_DRAFT_LIMIT ) {
			update_option( self::UNSIGNED_DRAFT_OPTION, $window, false );
			return self::failure( 'rs_unsigned_draft_rate_limited', 429, $request, $policy );
		}
		$window[] = $now;
		update_option( self::UNSIGNED_DRAFT_OPTION, $window, false );
		$request_id = self::record_audit( $request, $policy, 'unsigned_draft_accepted', '', $note );
		self::$unsigned_drafts ??= new WeakMap();
		self::$unsigned_drafts[ $request ] = $request_id;
		return true;
	}

	public static function trusted_publisher_enabled(): bool {
		return (bool) get_option( self::TRUSTED_PUBLISHER_SETTING, 0 );
	}

	private static function is_trusted_publisher_source(): bool {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return $remote !== '' && in_array( $remote, self::TRUSTED_PUBLISHER_ADDRESSES, true );
	}

	/**
	 * Since 3.15.2. 'trusted' only when the switch is on, the request connects from the
	 * CRM publisher's address AND carries this site's CRM key. Otherwise the reason the
	 * creation stays a draft ('' when the request made no CRM claim at all).
	 */
	private static function trusted_publisher_check( $request ): string {
		$has_key    = class_exists( 'Ratesight_CRM_Publish' ) && Ratesight_CRM_Publish::provided_key( $request ) !== '';
		$from_crm   = self::is_trusted_publisher_source();
		if ( ! self::trusted_publisher_enabled() ) {
			return $has_key ? 'crm_publish_off' : '';
		}
		if ( ! $from_crm ) {
			// The key is never accepted from any other address.
			return $has_key ? 'crm_key_wrong_source' : '';
		}
		if ( ! class_exists( 'Ratesight_CRM_Publish' ) ) {
			return 'crm_key_missing';
		}
		$state = Ratesight_CRM_Publish::key_state( $request );
		if ( $state === 'valid' ) {
			return 'trusted';
		}
		return $state === 'missing' ? 'crm_key_missing' : 'crm_key_invalid';
	}

	private static function accept_trusted_publisher( $request, string $policy ) {
		$now    = time();
		$window = get_option( self::TRUSTED_PUBLISHER_OPTION, array() );
		$window = array_values( array_filter( is_array( $window ) ? $window : array(), static function ( $stamp ) use ( $now ): bool {
			return is_int( $stamp ) && $stamp > $now - self::TRUSTED_PUBLISHER_WINDOW && $stamp <= $now + self::MAX_CLOCK_SKEW;
		} ) );
		if ( count( $window ) >= self::TRUSTED_PUBLISHER_LIMIT ) {
			// Since 3.15.2: past the daily publish limit the post is still created, as a
			// draft (the unsigned draft limit applies), so nothing the CRM sends is lost.
			update_option( self::TRUSTED_PUBLISHER_OPTION, $window, false );
			return self::accept_unsigned_draft( $request, $policy, 'crm_publish_limit' );
		}
		$window[] = $now;
		update_option( self::TRUSTED_PUBLISHER_OPTION, $window, false );
		$request_id = self::record_audit( $request, $policy, 'trusted_publisher_accepted' );
		// Also an unsigned creation: the handler keeps the new-post-only restrictions.
		self::$unsigned_drafts ??= new WeakMap();
		self::$unsigned_drafts[ $request ] = $request_id;
		self::$trusted_publishers ??= new WeakMap();
		self::$trusted_publishers[ $request ] = true;
		return true;
	}

	/**
	 * True when this unsigned creation was admitted from the Ratesight CRM publisher's
	 * address on a site that turned the setting on. The create-page handler then lets
	 * the new post follow the requested status or the site's Final Post Status.
	 */
	public static function is_trusted_publisher_request( $request ): bool {
		return self::$trusted_publishers !== null && is_object( $request ) && isset( self::$trusted_publishers[ $request ] );
	}

	/**
	 * The audit request id when this request was admitted as an unsigned draft
	 * creation, else null. The create-page handler MUST apply the draft-only
	 * restrictions whenever this is non-null.
	 */
	public static function unsigned_draft_request_id( $request ): ?string {
		if ( self::$unsigned_drafts === null || ! is_object( $request ) || ! isset( self::$unsigned_drafts[ $request ] ) ) {
			return null;
		}
		return self::$unsigned_drafts[ $request ];
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
		$query = $raw_query !== '' ? self::canonical_query_from_raw( $raw_query, (string) $request->get_route() ) : self::canonical_query( $request->get_query_params() );
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
		if ( self::normalize_route( (string) $request->get_route() ) === '/ratesight/v1/auth-self-test' ) {
			self::$operational_candidates[ spl_object_id( $request ) ] = $key_id;
		}
		self::record_audit( $request, $policy, 'v2_accepted', $key_id );
		return true;
	}

	public static function complete_operational_readiness( $request ) {
		$request_id = spl_object_id( $request );
		$key_id     = (string) ( self::$operational_candidates[ $request_id ] ?? '' );
		unset( self::$operational_candidates[ $request_id ] );
		if ( ! in_array( self::mode(), array( 'observe_v2', 'enforce_v2' ), true ) ) {
			return new WP_Error( 'rs_auth_readiness_mode_required', 'Operational readiness must be established in Observe or Enforce mode.', array( 'status' => 409 ) );
		}
		$primary = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( $key_id === '' || $primary === '' || ! hash_equals( self::key_id( $primary ), $key_id ) ) {
			return new WP_Error( 'rs_auth_readiness_not_verified', 'Operational readiness requires a verified current-key self-test.', array( 'status' => 403 ) );
		}
		update_option( 'ratesight_auth_v2_readiness', array( 'key_id' => $key_id, 'completed_at' => time() ), false );
		self::record_audit( $request, 'signed_read', 'v2_operational_readiness', $key_id );
		return true;
	}

	public static function handle_self_test( $request ) {
		$result = self::complete_operational_readiness( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'success' => true, 'readiness' => true );
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
		foreach ( array( 'ratesight_auth_nonce_', 'ratesight_pairing_nonce_' ) as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
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
	}

	public static function capability_auth(): array {
		$primary  = (string) get_option( 'ratesight_webhook_secret', '' );
		$previous = (string) get_option( 'ratesight_webhook_secret_previous', '' );
		$expires  = (int) get_option( 'ratesight_webhook_secret_previous_expires', 0 );
		$proof    = get_option( 'ratesight_auth_v2_readiness', array() );
		$completed_at = is_array( $proof ) ? (int) ( $proof['completed_at'] ?? 0 ) : 0;
		$proof_key   = is_array( $proof ) ? (string) ( $proof['key_id'] ?? '' ) : '';
		$readiness_current = $primary !== '' && hash_equals( self::key_id( $primary ), $proof_key ) && $completed_at >= time() - self::READINESS_TTL && $completed_at <= time() + self::MAX_CLOCK_SKEW;
		return array(
			'supported'              => array( self::VERSION, 'legacy-body-hmac' ),
			'mode'                   => self::mode(),
			// Since 3.14.0: unsigned (or invalidly signed) requests to protected routes are
			// rejected in every mode; legacy and observe_v2 differ only in accepting a valid
			// legacy body HMAC.
			'unsigned_accepted'      => false,
			// Since 3.14.0: the single unsigned exception. legacy/observe_v2 only.
			'unsigned_draft_create'  => self::mode() !== 'enforce_v2',
			'unsigned_draft_limit'   => array( 'max' => self::UNSIGNED_DRAFT_LIMIT, 'window_seconds' => self::UNSIGNED_DRAFT_WINDOW, 'route' => 'POST ' . self::UNSIGNED_DRAFT_ROUTE, 'status' => 'draft', 'updates_existing' => false ),
			// Since 3.15.1: per-site switch. When enabled, an unsigned POST /create-page
			// from a listed address creates a new post with the site's Final Post Status.
			// Since 3.15.2: also requires the site's CRM key (requires_key), and past the
			// limit a CRM post is created as a draft (over_limit).
			'trusted_publisher'      => array( 'enabled' => self::mode() !== 'enforce_v2' && self::trusted_publisher_enabled(), 'addresses' => self::TRUSTED_PUBLISHER_ADDRESSES, 'requires_key' => true, 'key_configured' => class_exists( 'Ratesight_CRM_Publish' ) && Ratesight_CRM_Publish::key() !== '', 'max' => self::TRUSTED_PUBLISHER_LIMIT, 'window_seconds' => self::TRUSTED_PUBLISHER_WINDOW, 'over_limit' => 'draft', 'route' => 'POST ' . self::UNSIGNED_DRAFT_ROUTE, 'updates_existing' => false ),
			'configured'             => $primary !== '',
			'current_key_id'         => $primary !== '' ? self::key_id( $primary ) : null,
			'previous_key_id'        => $previous !== '' && $expires >= time() ? self::key_id( $previous ) : null,
			'previous_grace_expires' => $previous !== '' && $expires >= time() ? gmdate( 'c', $expires ) : null,
			'readiness_current'       => $readiness_current,
			'readiness_expires'       => $readiness_current ? gmdate( 'c', $completed_at + self::READINESS_TTL ) : null,
		);
	}

	private static function failure( string $code, int $status, $request, string $policy, string $key_id = '' ) {
		self::record_audit( $request, $policy, $code, $key_id );
		return new WP_Error( $code, 'Request authentication failed.', array( 'status' => $status ) );
	}

	private static function record_audit( $request, string $policy, string $result, string $key_id = '', string $note = '' ): string {
		$rows = get_option( 'ratesight_auth_audit', array() );
		$rows = is_array( $rows ) ? $rows : array();
		$request_id = substr( hash( 'sha256', (string) $request->get_header( 'x_ratesight_nonce' ) . microtime( true ) . random_int( 0, PHP_INT_MAX ) ), 0, 20 );
		// Since 3.14.0: the connecting address. REMOTE_ADDR only; forwarding headers are
		// caller-controlled and never trusted here.
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$rows[] = array(
			'time'       => gmdate( 'c' ),
			'request_id' => $request_id,
			'ip'         => filter_var( $remote, FILTER_VALIDATE_IP ) !== false ? $remote : null,
			'method'     => strtoupper( (string) $request->get_method() ),
			'route'      => self::normalize_route( (string) $request->get_route() ),
			'policy'     => $policy,
			'key_id'     => preg_match( '/^[a-f0-9]{16}$/', $key_id ) ? $key_id : null,
			'result'     => $result,
		);
		if ( $note !== '' ) {
			$rows[ array_key_last( $rows ) ]['note'] = $note;
		}
		update_option( 'ratesight_auth_audit', array_slice( $rows, -100 ), false );
		return $request_id;
	}
}
