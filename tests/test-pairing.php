<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$options = array(
	'wp_ratesight_code_id' => '2',
	'ratesight_webhook_secret' => 'previous-secret-value',
	'ratesight_auth_v2_readiness' => array( 'key_id' => 'stale', 'completed_at' => time() ),
);
$public_key = '';
$fail_mode_update = false;

class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Request {
	public function __construct( private string $body, private array $headers ) {}
	public function get_body(): string { return $this->body; }
	public function get_header( string $name ): string { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}
class WP_REST_Response {
	public function __construct( public array $data, public int $status ) {}
}
class Ratesight_Options {
	public static function get( string $key ) { return $key === 'code_id' ? get_option( 'wp_ratesight_code_id', '' ) : ''; }
	public static function option_name( string $key ): string { return $key === 'code_id' ? 'wp_ratesight_code_id' : ''; }
}
class Ratesight_Request_Auth {
	public static function mode(): string { return (string) get_option( 'ratesight_auth_mode', 'legacy' ); }
	public static function set_mode( string $mode ) { global $fail_mode_update; if ( $fail_mode_update ) return new WP_Error( 'rs_auth_mode_failed' ); update_option( 'ratesight_auth_mode', $mode ); return true; }
	public static function key_id( string $secret ): string { return substr( hash( 'sha256', $secret ), 0, 16 ); }
}
function get_option( $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
function add_option( $name, $value ) { global $options; if ( array_key_exists( $name, $options ) ) return false; $options[ $name ] = $value; return true; }
function home_url() { return 'https://example.com/'; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function apply_filters( $name, $value ) { global $public_key; return $name === 'ratesight_pairing_public_key' ? $public_key : $value; }

require __DIR__ . '/../includes/class-ratesight-pairing.php';

$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
openssl_pkey_export( $key, $private_key );
$public_key = openssl_pkey_get_details( $key )['key'];

function pairing_request( array $overrides = array(), ?string $signing_key = null ): WP_REST_Request {
	global $private_key;
	$now = time();
	$payload = array_merge( array(
		'contract' => Ratesight_Pairing::CONTRACT,
		'oid' => '2',
		'site' => 'https://example.com',
		'issuedAt' => $now,
		'expiresAt' => $now + 300,
		'nonce' => rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ),
		'secret' => bin2hex( random_bytes( 32 ) ),
	), $overrides );
	$body = json_encode( $payload, JSON_UNESCAPED_SLASHES );
	openssl_sign( $body, $signature, $signing_key ?? $private_key, OPENSSL_ALGO_SHA256 );
	return new WP_REST_Request( $body, array( 'x-ratesight-pairing-signature' => base64_encode( $signature ) ) );
}

$failures = 0;
function check_pairing_case( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$request = pairing_request();
$result = Ratesight_Pairing::handle( $request );
check_pairing_case( 'valid control-plane signature pairs the site', $result instanceof WP_REST_Response && $result->status === 200 && $result->data['ok'] === true );
check_pairing_case( 'pairing enters observe mode and clears stale readiness', get_option( 'ratesight_auth_mode' ) === 'observe_v2' && get_option( 'ratesight_auth_v2_readiness', null ) === null );
check_pairing_case( 'previous credential receives bounded rollback grace', get_option( 'ratesight_webhook_secret_previous' ) === 'previous-secret-value' && get_option( 'ratesight_webhook_secret_previous_expires' ) > time() );
check_pairing_case( 'response and receipt contain proof but no secret', strpos( json_encode( array( $result->data, get_option( 'ratesight_pairing_receipt' ) ) ), get_option( 'ratesight_webhook_secret' ) ) === false );
$replay = Ratesight_Pairing::handle( $request );
check_pairing_case( 'signed nonce replay is refused', $replay instanceof WP_Error && $replay->get_error_code() === 'rs_pairing_replayed' );

$before = get_option( 'ratesight_webhook_secret' );
$wrong_site = Ratesight_Pairing::handle( pairing_request( array( 'site' => 'https://attacker.invalid' ) ) );
check_pairing_case( 'signed payload is bound to the local site origin', $wrong_site instanceof WP_Error && $wrong_site->get_error_code() === 'rs_pairing_site_mismatch' && get_option( 'ratesight_webhook_secret' ) === $before );
$tampered = pairing_request();
$tampered_request = new WP_REST_Request( $tampered->get_body() . ' ', array( 'x-ratesight-pairing-signature' => $tampered->get_header( 'x-ratesight-pairing-signature' ) ) );
$invalid = Ratesight_Pairing::handle( $tampered_request );
check_pairing_case( 'body tampering invalidates the signature before mutation', $invalid instanceof WP_Error && $invalid->get_error_code() === 'rs_pairing_signature_invalid' && get_option( 'ratesight_webhook_secret' ) === $before );

delete_option( 'wp_ratesight_code_id' );
$bootstrap = Ratesight_Pairing::handle( pairing_request( array( 'oid' => '170652' ) ) );
check_pairing_case( 'signed dashboard pairing bootstraps a blank local Ratesight ID', $bootstrap instanceof WP_REST_Response && $bootstrap->status === 200 && get_option( 'wp_ratesight_code_id' ) === '170652' );

$before = get_option( 'ratesight_webhook_secret' );
$mismatch = Ratesight_Pairing::handle( pairing_request( array( 'oid' => '2' ) ) );
check_pairing_case( 'dashboard pairing cannot silently rebind an existing Ratesight ID', $mismatch instanceof WP_Error && $mismatch->get_error_code() === 'rs_pairing_oid_mismatch' && get_option( 'wp_ratesight_code_id' ) === '170652' && get_option( 'ratesight_webhook_secret' ) === $before );

delete_option( 'wp_ratesight_code_id' );
$before = get_option( 'ratesight_webhook_secret' );
$wrong_site_bootstrap = Ratesight_Pairing::handle( pairing_request( array( 'oid' => '2', 'site' => 'https://attacker.invalid' ) ) );
check_pairing_case( 'site validation completes before a blank identity is stored', $wrong_site_bootstrap instanceof WP_Error && get_option( 'wp_ratesight_code_id', '' ) === '' && get_option( 'ratesight_webhook_secret' ) === $before );

update_option( 'ratesight_auth_mode', 'legacy' );
$fail_mode_update = true;
$mode_failure = Ratesight_Pairing::handle( pairing_request( array( 'oid' => '2' ) ) );
$fail_mode_update = false;
check_pairing_case( 'authentication setup completes before a blank identity is stored', $mode_failure instanceof WP_Error && $mode_failure->get_error_code() === 'rs_auth_mode_failed' && get_option( 'wp_ratesight_code_id', '' ) === '' && get_option( 'ratesight_webhook_secret' ) === $before );

// --- Control-plane key pinning -------------------------------------------------

function pairing_signature( string $body, $signing_key ): string {
	openssl_sign( $body, $signature, $signing_key, OPENSSL_ALGO_SHA256 );
	return base64_encode( $signature );
}

function new_pairing_key(): array {
	$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
	openssl_pkey_export( $key, $private );
	return array( $key, $private, openssl_pkey_get_details( $key )['key'] );
}

$pin_body = '{"contract":"ratesight-wordpress-pairing-v1"}';
list( $primary_key, $primary_private, $primary_public ) = new_pairing_key();
list( $backup_key, $backup_private, $backup_public ) = new_pairing_key();
list( $stranger_key, $stranger_private, $stranger_public ) = new_pairing_key();

$constants = ( new ReflectionClass( Ratesight_Pairing::class ) )->getConstants();
$shipped = $constants['PUBLIC_KEYS'] ?? null;
check_pairing_case( 'the shipped pins are a list of PEM public keys', is_array( $shipped ) && count( $shipped ) >= 1 && str_contains( (string) $shipped[0], '-----BEGIN PUBLIC KEY-----' ) );
$shipped_details = is_array( $shipped ) ? openssl_pkey_get_details( openssl_pkey_get_public( $shipped[0] ) ) : false;
check_pairing_case( 'the shipped primary pin is EC P-256', is_array( $shipped_details ) && 'prime256v1' === ( $shipped_details['ec']['curve_name'] ?? null ) );

$public_key = array( $primary_public, $backup_public );
check_pairing_case( 'a signature from the primary pin verifies', Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $primary_key ) ) );
check_pairing_case( 'a signature from the backup pin verifies', Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $backup_key ) ) );
check_pairing_case( 'a signature from an unpinned key is refused', ! Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $stranger_key ) ) );
check_pairing_case( 'a signature over different bytes is refused', ! Ratesight_Pairing::verify_control_plane_signature( $pin_body . ' ', pairing_signature( $pin_body, $primary_key ) ) );
check_pairing_case( 'a malformed base64 signature is refused', ! Ratesight_Pairing::verify_control_plane_signature( $pin_body, '!!!not-base64!!!' ) );

$public_key = $primary_public;
check_pairing_case( 'a single-string pin still verifies', Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $primary_key ) ) );
check_pairing_case( 'a single-string pin still refuses an unpinned key', ! Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $backup_key ) ) );

$public_key = array( 'not-a-key', $backup_public );
check_pairing_case( 'an unparsable pin is skipped, not fatal', Ratesight_Pairing::verify_control_plane_signature( $pin_body, pairing_signature( $pin_body, $backup_key ) ) );

echo $failures ? "{$failures} PAIRING CHECKS FAILED\n" : "ALL PAIRING CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
