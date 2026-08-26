<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );

class WP_Error {
	private string $code;
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}

$options = array();
$nonce_dir = sys_get_temp_dir() . '/ratesight-auth-test-' . getmypid();
mkdir( $nonce_dir );
function get_option( $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_option( $name, $value ) {
	global $nonce_dir;
	$path = $nonce_dir . '/' . preg_replace( '/[^a-z0-9_-]/i', '_', $name );
	$handle = @fopen( $path, 'x' );
	if ( ! $handle ) return false;
	fwrite( $handle, (string) $value );
	fclose( $handle );
	return true;
}

class Auth_Request {
	public function __construct( public string $method, public string $route, public array $query, public string $body, public array $headers ) {}
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_query_params() { return $this->query; }
	public function get_body() { return $this->body; }
	public function get_header( $name ) { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}

require __DIR__ . '/../includes/class-ratesight-request-auth.php';

$failures = 0;
$checks = 0;
function check_auth_case( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function error_code( $result ): string { return $result instanceof WP_Error ? $result->get_error_code() : ''; }
function signed_request( string $secret, array $overrides = array() ): Auth_Request {
	$method = $overrides['method'] ?? 'POST';
	$route = $overrides['route'] ?? '/ratesight/v1/update-page';
	$query = $overrides['query'] ?? array( 'tag' => array( 'red', 'blue' ), 'space' => 'hello world', 'a' => 'first' );
	$body = $overrides['body'] ?? '{"url":"https://example.com/services/","meta_title":"Fixture Title"}';
	$timestamp = (string) ( $overrides['timestamp'] ?? time() );
	$nonce = $overrides['nonce'] ?? rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	$digest = hash( 'sha256', $body );
	$key_id = $overrides['key_id'] ?? Ratesight_Request_Auth::key_id( $secret );
	$canonical = Ratesight_Request_Auth::canonical_request( $method, $route, $query, $timestamp, $nonce, $digest );
	$signature = $overrides['signature'] ?? Ratesight_Request_Auth::signature( $secret, $canonical );
	return new Auth_Request( $method, $route, $query, $body, array(
		'x-ratesight-auth-version' => Ratesight_Request_Auth::VERSION,
		'x-ratesight-key-id' => $key_id,
		'x-ratesight-timestamp' => $timestamp,
		'x-ratesight-nonce' => $nonce,
		'x-ratesight-content-sha256' => $overrides['digest'] ?? $digest,
		'x-ratesight-signature' => $signature,
	) );
}
function legacy_request( string $secret, string $body = '{}', bool $signed = true ): Auth_Request {
	return new Auth_Request( 'POST', '/ratesight/v1/update-page', array(), $body, $signed
		? array( 'x-ratesight-signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ) )
		: array()
	);
}

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/rs-hmac-v2.json' ), true );
$canonical = Ratesight_Request_Auth::canonical_request( $fixture['method'], $fixture['route'], $fixture['query'], $fixture['timestamp'], $fixture['nonce'], $fixture['bodyDigest'] );
check_auth_case( 'golden canonical query', Ratesight_Request_Auth::canonical_query( $fixture['query'] ) === $fixture['canonicalQuery'] );
check_auth_case( 'golden body digest', hash( 'sha256', $fixture['body'] ) === $fixture['bodyDigest'] );
check_auth_case( 'golden key id', Ratesight_Request_Auth::key_id( $fixture['secret'] ) === $fixture['keyId'] );
check_auth_case( 'golden canonical bytes', $canonical === $fixture['canonical'] );
check_auth_case( 'golden signature', Ratesight_Request_Auth::signature( $fixture['secret'], $canonical ) === $fixture['signature'] );

$options['ratesight_auth_mode'] = 'enforce_v2';
$options['ratesight_webhook_secret'] = $fixture['secret'];
$valid = signed_request( $fixture['secret'] );
check_auth_case( 'valid v2 accepted', Ratesight_Request_Auth::authorize_mutation( $valid ) === true );
check_auth_case( 'replayed nonce rejected', error_code( Ratesight_Request_Auth::authorize_mutation( $valid ) ) === 'rs_nonce_replayed' );

foreach ( array( 'method', 'route', 'query', 'body' ) as $field ) {
	$request = signed_request( $fixture['secret'] );
	if ( $field === 'method' ) $request->method = 'DELETE';
	if ( $field === 'route' ) $request->route = '/ratesight/v1/create-page';
	if ( $field === 'query' ) $request->query['a'] = 'changed';
	if ( $field === 'body' ) $request->body .= ' ';
	$expected = $field === 'body' ? 'rs_body_digest_mismatch' : 'rs_bad_signature';
	check_auth_case( "altered {$field} rejected", error_code( Ratesight_Request_Auth::authorize_mutation( $request ) ) === $expected );
}
check_auth_case( 'expired timestamp rejected', error_code( Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['secret'], array( 'timestamp' => time() - 301 ) ) ) ) === 'rs_timestamp_expired' );
check_auth_case( 'malformed nonce rejected', error_code( Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['secret'], array( 'nonce' => 'short' ) ) ) ) === 'rs_auth_headers_invalid' );
check_auth_case( 'unknown key rejected', error_code( Ratesight_Request_Auth::authorize_mutation( signed_request( 'wrong-secret' ) ) ) === 'rs_key_unknown' );
check_auth_case( 'bad signature rejected', error_code( Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['secret'], array( 'signature' => 'sha256=' . str_repeat( '0', 64 ) ) ) ) ) === 'rs_bad_signature' );
check_auth_case( 'enforce mode rejects valid legacy signature', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'] ) ) ) === 'rs_auth_version_required' );
check_auth_case( 'enforce mode rejects unsigned mutation', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'], '{}', false ) ) ) === 'rs_auth_version_required' );
$options['ratesight_auth_mode'] = 'observe_v2';
check_auth_case( 'observe mode accepts valid legacy signature', Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'] ) ) === true );
check_auth_case( 'observe mode rejects unsigned mutation', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'], '{}', false ) ) ) === 'rs_signature_required' );
$options['ratesight_auth_mode'] = 'legacy';
check_auth_case( 'legacy mode remains compatible with unsigned mutation', Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'], '{}', false ) ) === true );
$options['ratesight_auth_mode'] = 'enforce_v2';

$options['ratesight_webhook_secret_previous'] = $fixture['previousSecret'];
$options['ratesight_webhook_secret_previous_expires'] = time() + 60;
check_auth_case( 'previous key accepted during grace', Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['previousSecret'] ) ) === true );
$options['ratesight_webhook_secret_previous_expires'] = time() - 1;
check_auth_case( 'previous key rejected after grace', error_code( Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['previousSecret'] ) ) ) === 'rs_key_unknown' );

if ( function_exists( 'pcntl_fork' ) ) {
	$nonce = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	$children = array();
	for ( $i = 0; $i < 2; $i++ ) {
		$pid = pcntl_fork();
		if ( $pid === 0 ) exit( Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['secret'], array( 'nonce' => $nonce ) ) ) === true ? 0 : 1 );
		$children[] = $pid;
	}
	$accepted = 0;
	foreach ( $children as $pid ) { pcntl_waitpid( $pid, $status ); if ( pcntl_wexitstatus( $status ) === 0 ) $accepted++; }
	check_auth_case( 'parallel nonce claim accepts exactly one request', $accepted === 1 );
}

foreach ( glob( $nonce_dir . '/*' ) ?: array() as $file ) unlink( $file );
rmdir( $nonce_dir );
echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
