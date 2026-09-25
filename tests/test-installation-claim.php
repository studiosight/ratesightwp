<?php
/**
 * 3.13.0: unclaimed installation report, daily schedule, and the signed /claim route.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'RATESIGHT_RELEASE_VERSION', '3.13.0' );
define( 'HOUR_IN_SECONDS', 3600 );

$options        = array();
$scheduled      = array();
$recurring      = array();
$requests       = array();
$routes         = array();
$uuid_sequence  = 0;
$paired         = false;
$dashboard_code = 'wordpress_installation_recorded';
$control_key    = '';

class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Server {
	public const CREATABLE = 'POST';
}
class WP_REST_Request {
	public function __construct( private string $body, private array $headers = array() ) {}
	public function get_body(): string { return $this->body; }
	public function get_header( string $name ): string { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}
class WP_REST_Response {
	public function __construct( public array $data, public int $status ) {}
}
class Ratesight_Options {
	public static function get( string $key ) { return 'code_id' === $key ? get_option( 'wp_ratesight_code_id', '' ) : ''; }
	public static function option_name( string $key ): string { return 'code_id' === $key ? 'wp_ratesight_code_id' : ''; }
}
class Ratesight_Pairing {
	public static function is_connected(): bool { global $paired; return $paired; }
	public static function verify_control_plane_signature( string $body, string $signature ): bool {
		global $control_key;
		$decoded = base64_decode( $signature, true );
		return false !== $decoded && 1 === openssl_verify( $body, $decoded, $control_key, OPENSSL_ALGO_SHA256 );
	}
}

function get_option( $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function add_option( $name, $value ) { global $options; if ( array_key_exists( $name, $options ) ) return false; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
function home_url() { return 'https://example.com/'; }
function get_bloginfo( $what ) { return 'name' === $what ? "The Healing Corner &amp; Spa\n" : ''; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_generate_uuid4() {
	global $uuid_sequence;
	$uuid_sequence++;
	return sprintf( '00000000-0000-4000-8000-%012d', $uuid_sequence );
}
function apply_filters( $name, $value ) { return 'ratesight_enrollment_endpoint' === $name ? 'https://dashboard.invalid/api/public/wordpress/enrollments' : $value; }
function wp_next_scheduled( $hook ) { global $scheduled, $recurring; return $scheduled[ $hook ] ?? ( $recurring[ $hook ]['at'] ?? false ); }
function wp_schedule_single_event( $timestamp, $hook ) { global $scheduled; $scheduled[ $hook ] = $timestamp; return true; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { global $recurring; $recurring[ $hook ] = array( 'at' => $timestamp, 'recurrence' => $recurrence ); return true; }
function wp_unschedule_event( $timestamp, $hook ) { global $scheduled, $recurring; unset( $scheduled[ $hook ], $recurring[ $hook ] ); return true; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function register_rest_route( $namespace, $path, $args ) { global $routes; $routes[ $path ] = $args; }
function add_action() {}

function wp_remote_post( $url, $args ) {
	global $requests, $dashboard_code;
	$requests[] = array( 'url' => $url, 'args' => $args );
	$payload = json_decode( $args['body'], true );
	if ( 'ratesight-wordpress-enrollment-v1' === ( $payload['contract'] ?? '' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'code' => 'wordpress_enrollment_pending_approval' ) ) );
	}
	return array( 'response' => array( 'code' => 'fail' === $dashboard_code ? 503 : 200 ), 'body' => json_encode( array( 'code' => $dashboard_code ) ) );
}

require __DIR__ . '/../includes/class-ratesight-enrollment.php';

$failures = 0;
function check_claim_case( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}

// ---------------------------------------------------------------------------
// Unclaimed installation report
// ---------------------------------------------------------------------------
Ratesight_Enrollment::send();
$hello = json_decode( $requests[0]['args']['body'] ?? '', true );
check_claim_case( 'site without a client id reports the installation to the same public endpoint', count( $requests ) === 1 && $requests[0]['url'] === 'https://dashboard.invalid/api/public/wordpress/enrollments' );
check_claim_case( 'installation report matches the installation v1 contract exactly', is_array( $hello ) && array_keys( $hello ) === array( 'contract', 'requestId', 'siteOrigin', 'siteName', 'pluginVersion', 'installationId', 'publicKeySpki', 'pairingState', 'issuedAt', 'nonce' ) && $hello['contract'] === Ratesight_Enrollment::INSTALLATION_CONTRACT );
check_claim_case( 'installation report carries origin, clean site name, version and unpaired state', ( $hello['siteOrigin'] ?? '' ) === 'https://example.com' && ( $hello['siteName'] ?? '' ) === 'The Healing Corner & Spa' && ( $hello['pluginVersion'] ?? '' ) === '3.13.0' && ( $hello['pairingState'] ?? '' ) === 'unpaired' );
check_claim_case( 'installation report requestId is accepted by the challenge route grammar', (bool) preg_match( '/^wpenr:v1:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $hello['requestId'] ?? '' ) );
check_claim_case( 'installation report has no oid and no secret material', ! array_key_exists( 'oid', $hello ) && strpos( (string) $requests[0]['args']['body'], 'PRIVATE KEY' ) === false && strpos( (string) $requests[0]['args']['body'], (string) get_option( 'ratesight_enrollment_private_key' ) ) === false );
check_claim_case( 'accepted installation report is recorded and schedules no retry', Ratesight_Enrollment::status()['installationReport']['accepted'] === true && ! isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ) );

$dashboard_code = 'fail';
Ratesight_Enrollment::send();
check_claim_case( 'rejected installation report schedules a single hourly retry', Ratesight_Enrollment::status()['installationReport']['accepted'] === false && ( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ?? 0 ) >= time() + 3500 );
$dashboard_code = 'wordpress_installation_recorded';
unset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] );

$options['wp_ratesight_code_id'] = 'not-a-number';
$before = count( $requests );
Ratesight_Enrollment::send();
check_claim_case( 'a non-numeric client id is treated as unclaimed', count( $requests ) === $before + 1 && json_decode( end( $requests )['args']['body'], true )['contract'] === Ratesight_Enrollment::INSTALLATION_CONTRACT );
unset( $options['wp_ratesight_code_id'] );

$paired = true;
$before = count( $requests );
Ratesight_Enrollment::send();
check_claim_case( 'paired installations send nothing', count( $requests ) === $before );
$paired = false;

// ---------------------------------------------------------------------------
// Daily schedule
// ---------------------------------------------------------------------------
Ratesight_Enrollment::ensure_schedule();
check_claim_case( 'daily installation report is scheduled with the daily recurrence', ( $recurring[ Ratesight_Enrollment::DAILY_HOOK ]['recurrence'] ?? '' ) === 'daily' );
$first = $recurring[ Ratesight_Enrollment::DAILY_HOOK ]['at'];
$recurring[ Ratesight_Enrollment::DAILY_HOOK ]['at'] = $first - 5;
Ratesight_Enrollment::ensure_schedule();
check_claim_case( 'ensure_schedule is idempotent', $recurring[ Ratesight_Enrollment::DAILY_HOOK ]['at'] === $first - 5 );
Ratesight_Enrollment::unschedule();
check_claim_case( 'deactivation clears the daily report', ! isset( $recurring[ Ratesight_Enrollment::DAILY_HOOK ] ) );
Ratesight_Enrollment::register_route();
check_claim_case( 'claim route is registered as a public signed bootstrap route', isset( $routes['/claim'] ) && $routes['/claim']['permission_callback'] === '__return_true' && $routes['/claim']['callback'] === array( 'Ratesight_Enrollment', 'handle_claim' ) );

// ---------------------------------------------------------------------------
// Signed /claim route
// ---------------------------------------------------------------------------
$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
openssl_pkey_export( $key, $control_private );
$control_key = openssl_pkey_get_details( $key )['key'];
$rogue = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
openssl_pkey_export( $rogue, $rogue_private );
$installation_id = (string) get_option( 'ratesight_enrollment_installation_id' );

function claim_request( array $overrides = array(), ?string $signing_key = null, ?string $body_override = null ): WP_REST_Request {
	global $control_private, $installation_id;
	$now     = time();
	$payload = array_merge( array(
		'contract'       => Ratesight_Enrollment::CLAIM_CONTRACT,
		'installationId' => $installation_id,
		'site'           => 'https://example.com',
		'oid'            => '42',
		'issuedAt'       => $now,
		'expiresAt'      => $now + 120,
		'nonce'          => rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ),
		'force'          => false,
	), $overrides );
	$body = $body_override ?? json_encode( $payload, JSON_UNESCAPED_SLASHES );
	openssl_sign( $body, $signature, $signing_key ?? $control_private, OPENSSL_ALGO_SHA256 );
	return new WP_REST_Request( $body, array( 'x-ratesight-pairing-signature' => base64_encode( $signature ) ) );
}
function claim_code( $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() : (string) ( $result->data['code'] ?? '' );
}

$unsigned = Ratesight_Enrollment::handle_claim( new WP_REST_Request( json_encode( array( 'contract' => Ratesight_Enrollment::CLAIM_CONTRACT ) ) ) );
check_claim_case( 'unsigned claim is refused', claim_code( $unsigned ) === 'rs_claim_signature_invalid' );
check_claim_case( 'claim signed by an unpinned key is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array(), $rogue_private ) ) ) === 'rs_claim_signature_invalid' );
$tampered_source = claim_request();
$tampered = new WP_REST_Request( str_replace( '"42"', '"43"', $tampered_source->get_body() ), array( 'x-ratesight-pairing-signature' => $tampered_source->get_header( 'x_ratesight_pairing_signature' ) ) );
check_claim_case( 'claim body changed after signing is refused', claim_code( Ratesight_Enrollment::handle_claim( $tampered ) ) === 'rs_claim_signature_invalid' );
check_claim_case( 'claim with a different contract is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'contract' => 'ratesight-wordpress-pairing-v1' ) ) ) ) === 'rs_claim_contract_invalid' );
check_claim_case( 'claim for a different installation is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'installationId' => '10000000-0000-4000-8000-000000000009' ) ) ) ) === 'rs_claim_installation_mismatch' );
check_claim_case( 'claim for a different site is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'site' => 'https://other.example' ) ) ) ) === 'rs_claim_site_mismatch' );
check_claim_case( 'expired claim is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'issuedAt' => time() - 900, 'expiresAt' => time() - 600 ) ) ) ) === 'rs_claim_expired' );
check_claim_case( 'claim with a non-numeric client id is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'oid' => '4x' ) ) ) ) === 'rs_claim_payload_invalid' );
check_claim_case( 'claim with a non-boolean force flag is refused', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'force' => 'yes' ) ) ) ) === 'rs_claim_payload_invalid' );
check_claim_case( 'refused claims never set a client id', get_option( 'wp_ratesight_code_id', '' ) === '' );

$good   = claim_request();
$result = Ratesight_Enrollment::handle_claim( $good );
check_claim_case( 'valid claim sets the client id', claim_code( $result ) === 'wordpress_claim_recorded' && get_option( 'wp_ratesight_code_id' ) === '42' );
check_claim_case( 'valid claim queues the enrollment immediately', isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ) && $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] <= time() );
check_claim_case( 'replayed claim is refused', claim_code( Ratesight_Enrollment::handle_claim( $good ) ) === 'rs_claim_replayed' );
check_claim_case( 'repeating the same client id is idempotent', claim_code( Ratesight_Enrollment::handle_claim( claim_request() ) ) === 'wordpress_claim_unchanged' );
check_claim_case( 'a different client id is refused without the signed force flag', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'oid' => '77' ) ) ) ) === 'rs_claim_oid_conflict' && get_option( 'wp_ratesight_code_id' ) === '42' );
$paired = true;
check_claim_case( 'force never re-points an already paired site', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'oid' => '77', 'force' => true ) ) ) ) === 'rs_claim_site_paired' && get_option( 'wp_ratesight_code_id' ) === '42' );
$paired = false;
check_claim_case( 'signed force flag replaces a different client id', claim_code( Ratesight_Enrollment::handle_claim( claim_request( array( 'oid' => '77', 'force' => true ) ) ) ) === 'wordpress_claim_replaced' && get_option( 'wp_ratesight_code_id' ) === '77' );

$before = count( $requests );
Ratesight_Enrollment::send();
$enrollment = json_decode( end( $requests )['args']['body'], true );
check_claim_case( 'after a claim the existing enrollment contract fires with the claimed id', count( $requests ) === $before + 1 && $enrollment['contract'] === Ratesight_Enrollment::CONTRACT && $enrollment['oid'] === '77' && $enrollment['installationId'] === $installation_id );
check_claim_case( 'claim receipt stores no nonce or signature material', ! str_contains( json_encode( get_option( 'ratesight_claim_receipt' ) ), $good->get_header( 'x_ratesight_pairing_signature' ) ) );

echo $failures ? "{$failures} INSTALLATION/CLAIM CHECKS FAILED\n" : "ALL INSTALLATION/CLAIM CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
