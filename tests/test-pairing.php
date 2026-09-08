<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$options = array(
	'ratesight_webhook_secret' => 'previous-secret-value',
	'ratesight_auth_v2_readiness' => array( 'key_id' => 'stale', 'completed_at' => time() ),
);
$public_key = '';

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
	public static function get( string $key ) { return $key === 'code_id' ? '2' : ''; }
}
class Ratesight_Request_Auth {
	public static function mode(): string { return (string) get_option( 'ratesight_auth_mode', 'legacy' ); }
	public static function set_mode( string $mode ) { update_option( 'ratesight_auth_mode', $mode ); return true; }
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

echo $failures ? "{$failures} PAIRING CHECKS FAILED\n" : "ALL PAIRING CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
