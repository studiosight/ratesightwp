<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'RATESIGHT_RELEASE_VERSION', '3.12.0' );

$options       = array( 'wp_ratesight_code_id' => '2' );
$scheduled     = array();
$requests      = array();
$scripted      = array();
$uuid_sequence = 0;
$paired        = false;

class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Server {
	public const CREATABLE = 'POST';
}
class WP_REST_Request {
	public function __construct( private string $body ) {}
	public function get_body(): string { return $this->body; }
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
}

function get_option( $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
function home_url() { return 'https://example.com/'; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_generate_uuid4() {
	global $uuid_sequence;
	$uuid_sequence++;
	return sprintf( '00000000-0000-4000-8000-%012d', $uuid_sequence );
}
function apply_filters( $name, $value ) { return 'ratesight_enrollment_endpoint' === $name ? 'https://dashboard.invalid/api/public/wordpress/enrollments' : $value; }
function wp_next_scheduled( $hook ) { global $scheduled; return $scheduled[ $hook ] ?? false; }
function wp_schedule_single_event( $timestamp, $hook ) { global $scheduled; $scheduled[ $hook ] = $timestamp; return true; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function register_rest_route() {}

function wp_remote_post( $url, $args ) {
	global $requests, $scripted;
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( $scripted ) {
		return array_shift( $scripted );
	}
	$payload    = json_decode( $args['body'], true );
	$challenge  = rtrim( strtr( base64_encode( str_repeat( 'c', 32 ) ), '+/', '-_' ), '=' );
	$request    = new WP_REST_Request( json_encode( array(
		'contract'       => Ratesight_Enrollment::CHALLENGE_CONTRACT,
		'requestId'      => $payload['requestId'],
		'installationId' => $payload['installationId'],
		'challenge'      => $challenge,
	), JSON_UNESCAPED_SLASHES ) );
	$proof      = Ratesight_Enrollment::handle_challenge( $request );
	$public_der = base64_decode( strtr( $payload['publicKeySpki'], '-_', '+/' ), true );
	$public_pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $public_der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	$message    = implode( "\n", array( Ratesight_Enrollment::CHALLENGE_CONTRACT, $payload['requestId'], $payload['installationId'], $challenge ) );
	$signature  = base64_decode( strtr( $proof->data['signature'], '-_', '+/' ), true );
	if ( ! ( $proof instanceof WP_REST_Response ) || 1 !== openssl_verify( $message, $signature, $public_pem, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'challenge_verification_failed' );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'ok' => true, 'code' => 'wordpress_enrollment_pending_approval' ) ),
	);
}

require __DIR__ . '/../includes/class-ratesight-enrollment.php';

$failures = 0;
function check_enrollment_case( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}

Ratesight_Enrollment::send();
$payload = json_decode( $requests[0]['args']['body'] ?? '', true );
check_enrollment_case( 'eligible OID installation announces to the public dashboard endpoint', count( $requests ) === 1 && $requests[0]['url'] === 'https://dashboard.invalid/api/public/wordpress/enrollments' );
check_enrollment_case( 'emitted payload exactly matches the enrollment v1 contract', is_array( $payload ) && array_keys( $payload ) === array( 'contract', 'requestId', 'oid', 'siteOrigin', 'pluginVersion', 'installationId', 'publicKeySpki', 'issuedAt', 'nonce', 'capabilities' ) && $payload['oid'] === '2' && $payload['siteOrigin'] === 'https://example.com' );
check_enrollment_case( 'stable P-256 installation identity answers the dashboard challenge', strlen( $payload['publicKeySpki'] ?? '' ) >= 100 && get_option( 'ratesight_enrollment_installation_id' ) === $payload['installationId'] );
$serialized = json_encode( array( $payload, Ratesight_Enrollment::status(), get_option( 'ratesight_enrollment_receipt' ) ) );
check_enrollment_case( 'status and receipt never expose the private key', strpos( $serialized, (string) get_option( 'ratesight_enrollment_private_key' ) ) === false );
check_enrollment_case( 'accepted enrollment is stored as pending operator approval', Ratesight_Enrollment::status()['accepted'] === true && Ratesight_Enrollment::status()['code'] === 'wordpress_enrollment_pending_approval' );

Ratesight_Enrollment::send();
check_enrollment_case( 'accepted matching fingerprint suppresses duplicate announcements', count( $requests ) === 1 );

$invalid = Ratesight_Enrollment::handle_challenge( new WP_REST_Request( json_encode( array(
	'contract'       => Ratesight_Enrollment::CHALLENGE_CONTRACT,
	'requestId'      => $payload['requestId'],
	'installationId' => '10000000-0000-4000-8000-000000000001',
	'challenge'      => rtrim( strtr( base64_encode( str_repeat( 'x', 32 ) ), '+/', '-_' ), '=' ),
) ) ) );
check_enrollment_case( 'challenge refuses a different installation identity', $invalid instanceof WP_Error && $invalid->get_error_code() === 'rs_enrollment_challenge_invalid' );

Ratesight_Enrollment::option_updated( 'wp_ratesight_code_id', '2', '3' );
check_enrollment_case( 'OID changes clear acceptance and enqueue a near-term retry', get_option( 'ratesight_enrollment_receipt', null ) === null && isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ) );

$paired = true;
Ratesight_Enrollment::send();
check_enrollment_case( 'already paired installations never announce again', count( $requests ) === 1 );


// --- Bounded retries, terminal blocks, and upgrade recovery for installed sites ---

function script_response( int $status, string $code ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => json_encode( array( 'ok' => $status < 400, 'code' => $code ), JSON_UNESCAPED_SLASHES ),
	);
}

$paired = false;

// A rejection the plugin cannot fix stops announcing instead of retrying forever.
delete_option( 'ratesight_enrollment_receipt' );
$scheduled = array();
$scripted[] = script_response( 403, 'wordpress_enrollment_inactive_oid' );
$before     = count( $requests );
Ratesight_Enrollment::send();
$status = Ratesight_Enrollment::status();
check_enrollment_case( 'an unactionable rejection is recorded as permanently blocked', $status['outcome'] === 'blocked' && $status['blocked'] === true && $status['accepted'] === false && count( $requests ) === $before + 1 );
check_enrollment_case( 'a blocked site is not rescheduled', $scheduled === array() );
Ratesight_Enrollment::send();
check_enrollment_case( 'a blocked site suppresses further announcements for the same identity', count( $requests ) === $before + 1 );
Ratesight_Enrollment::maybe_recover();
Ratesight_Enrollment::send();
check_enrollment_case( 'an upgrade keeps a blocked site blocked and silent', Ratesight_Enrollment::status()['blocked'] === true && count( $requests ) === $before + 1 );

// Our own outages stay in the bounded ladder and never block on the first failure.
delete_option( 'ratesight_enrollment_receipt' );
delete_option( Ratesight_Enrollment::VERSION_OPTION );
$scheduled = array();
$scripted[] = script_response( 503, 'wordpress_enrollment_store_unavailable' );
Ratesight_Enrollment::send();
$status = Ratesight_Enrollment::status();
check_enrollment_case( 'a transient outage retries with the first bounded delay', $status['outcome'] === 'retrying' && $status['attempts'] === 1 && isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ) );

// The budget is bounded: the attempt cap converts retrying into a terminal block.
$options['ratesight_enrollment_receipt'] = array_merge( (array) get_option( 'ratesight_enrollment_receipt', array() ), array( 'attempts' => 7 ) );
$scheduled = array();
$scripted[] = script_response( 503, 'wordpress_enrollment_store_unavailable' );
Ratesight_Enrollment::send();
$status = Ratesight_Enrollment::status();
check_enrollment_case( 'the bounded budget blocks a site instead of retrying forever', $status['blocked'] === true && $status['attempts'] === 8 && $scheduled === array() );

// A stored-but-unreviewed enrollment is acknowledged and does not spin.
delete_option( 'ratesight_enrollment_receipt' );
$scheduled = array();
$scripted[] = script_response( 200, 'wordpress_enrollment_review_required' );
$before     = count( $requests );
Ratesight_Enrollment::send();
$status = Ratesight_Enrollment::status();
check_enrollment_case( 'a review-required enrollment is acknowledged without retrying', $status['outcome'] === 'awaiting_review' && $status['accepted'] === true && $status['blocked'] === false && $scheduled === array() && count( $requests ) === $before + 1 );
Ratesight_Enrollment::send();
check_enrollment_case( 'a review-required enrollment suppresses duplicate announcements', count( $requests ) === $before + 1 );

// An already-installed site with no stored marker recovers exactly once per version.
delete_option( 'ratesight_enrollment_receipt' );
delete_option( Ratesight_Enrollment::VERSION_OPTION );
$scheduled = array();
$identity_before = get_option( 'ratesight_enrollment_installation_id' );
Ratesight_Enrollment::maybe_recover();
check_enrollment_case(
	'an installed site schedules one bounded recovery on upgrade',
	get_option( Ratesight_Enrollment::VERSION_OPTION ) === RATESIGHT_RELEASE_VERSION && isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] )
);
$first_delay = $scheduled[ Ratesight_Enrollment::RETRY_HOOK ];
Ratesight_Enrollment::maybe_recover();
check_enrollment_case( 'recovery is idempotent for the same plugin version', ( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ?? null ) === $first_delay );
check_enrollment_case( 'recovery preserves the installation identity and private key', get_option( 'ratesight_enrollment_installation_id' ) === $identity_before && get_option( 'ratesight_enrollment_private_key' ) !== '' );

// Only an operator changing the OID re-arms a site.
$scheduled = array();
Ratesight_Enrollment::option_updated( 'wp_ratesight_code_id', '2', '3' );
check_enrollment_case( 'an operator OID change clears the receipt and re-arms the site', get_option( 'ratesight_enrollment_receipt', null ) === null && isset( $scheduled[ Ratesight_Enrollment::RETRY_HOOK ] ) );

$paired = true;
$scheduled = array();
$paired_receipt = array( 'accepted' => true, 'outcome' => 'accepted', 'attempts' => 1 );
update_option( 'ratesight_enrollment_receipt', $paired_receipt );
delete_option( Ratesight_Enrollment::VERSION_OPTION );
Ratesight_Enrollment::maybe_recover();
check_enrollment_case( 'upgrade preserves an already paired site receipt without scheduling', get_option( 'ratesight_enrollment_receipt' ) === $paired_receipt && $scheduled === array() );

echo $failures ? "{$failures} ENROLLMENT CHECKS FAILED\n" : "ALL ENROLLMENT CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
