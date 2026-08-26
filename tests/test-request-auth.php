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
	public function __construct( public string $method, public string $route, public array $query, public string $body, public array $headers, public ?string $raw_query = null ) {}
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_query_params() { return $this->query; }
	public function get_query_string() {
		if ( $this->raw_query !== null ) return $this->raw_query;
		$pairs = array();
		foreach ( $this->query as $key => $value ) foreach ( is_array( $value ) ? $value : array( $value ) as $item ) $pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $item );
		return implode( '&', $pairs );
	}
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
	), $overrides['raw_query'] ?? null );
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
check_auth_case( 'raw repeated query keys preserve every value', Ratesight_Request_Auth::canonical_query_from_raw( 'tag=red&tag=blue&a=first' ) === 'a=first&tag=blue&tag=red' );
parse_str( 'tag=red&tag=blue&a=first', $collapsed_query );
check_auth_case( 'raw canonicalization avoids PHP parser value collapse', $collapsed_query['tag'] === 'blue' && Ratesight_Request_Auth::canonical_query_from_raw( 'tag=red&tag=blue&a=first' ) !== Ratesight_Request_Auth::canonical_query( $collapsed_query ) );
check_auth_case( 'bracket query grammar rejected', error_code( Ratesight_Request_Auth::canonical_query_from_raw( 'tag%5B%5D=red' ) ) === 'rs_query_grammar_unsupported' );
check_auth_case( 'plain-permalink transport route is excluded after matched route binding', Ratesight_Request_Auth::canonical_query_from_raw( 'rest_route=%2Fratesight%2Fv1%2Fauth-self-test', '/ratesight/v1/auth-self-test' ) === '' );
check_auth_case( 'arbitrary and repeated query values remain bound beside plain-permalink transport', Ratesight_Request_Auth::canonical_query_from_raw( 'rest_route=%2Fratesight%2Fv1%2Fupdate-page&tag=red&tag=blue', '/ratesight/v1/update-page' ) === 'tag=blue&tag=red' );
check_auth_case( 'only one matching transport pair is excluded and mismatched rest_route remains bound', Ratesight_Request_Auth::canonical_query_from_raw( 'rest_route=%2Fratesight%2Fv1%2Fupdate-page&rest_route=%2Fother', '/ratesight/v1/update-page' ) === 'rest_route=%2Fother' );

unset( $options['ratesight_auth_mode'], $options['ratesight_auth_ever_enforced'] );
check_auth_case( 'upgrade with no mode remains legacy compatible', Ratesight_Request_Auth::mode() === 'legacy' );
$options['ratesight_auth_mode'] = 'typo_v2';
check_auth_case( 'invalid mode fails safe to enforce', Ratesight_Request_Auth::mode() === 'enforce_v2' );
unset( $options['ratesight_auth_mode'] );
$options['ratesight_auth_ever_enforced'] = true;
check_auth_case( 'missing mode after enforcement cannot silently downgrade', Ratesight_Request_Auth::mode() === 'enforce_v2' );
$options['ratesight_auth_mode'] = 'legacy';
check_auth_case( 'stored legacy after enforcement cannot silently downgrade', Ratesight_Request_Auth::mode() === 'enforce_v2' );
check_auth_case( 'explicit rollback to observe remains available', Ratesight_Request_Auth::set_mode( 'observe_v2' ) === true && Ratesight_Request_Auth::mode() === 'observe_v2' );
check_auth_case( 'explicit legacy downgrade remains blocked', error_code( Ratesight_Request_Auth::set_mode( 'legacy' ) ) === 'rs_auth_mode_downgrade_blocked' );
unset( $options['ratesight_auth_ever_enforced'] );
check_auth_case( 'new install can enter observe mode', Ratesight_Request_Auth::set_mode( 'observe_v2' ) === true );
unset( $options['ratesight_webhook_secret'], $options['ratesight_auth_v2_readiness'] );
check_auth_case( 'enforcement refuses without a primary secret', error_code( Ratesight_Request_Auth::set_mode( 'enforce_v2' ) ) === 'rs_auth_enforce_secret_required' );
$options['ratesight_webhook_secret'] = $fixture['secret'];
$options['ratesight_auth_v2_readiness'] = array();
check_auth_case( 'enforcement refuses missing readiness proof', error_code( Ratesight_Request_Auth::set_mode( 'enforce_v2' ) ) === 'rs_auth_enforce_readiness_required' );
$options['ratesight_auth_v2_readiness'] = array( 'key_id' => Ratesight_Request_Auth::key_id( 'wrong-secret' ), 'completed_at' => time() );
check_auth_case( 'enforcement refuses readiness for the wrong key', error_code( Ratesight_Request_Auth::set_mode( 'enforce_v2' ) ) === 'rs_auth_enforce_readiness_required' );
$options['ratesight_auth_v2_readiness'] = array( 'key_id' => $fixture['keyId'], 'completed_at' => time() - Ratesight_Request_Auth::READINESS_TTL - 1 );
check_auth_case( 'enforcement refuses stale readiness proof', error_code( Ratesight_Request_Auth::set_mode( 'enforce_v2' ) ) === 'rs_auth_enforce_readiness_required' );
unset( $options['ratesight_auth_v2_readiness'] );
$options['ratesight_auth_mode'] = 'legacy';
$legacy_self_test = signed_request( $fixture['secret'], array( 'method' => 'GET', 'route' => '/ratesight/v1/auth-self-test', 'query' => array(), 'body' => '' ) );
check_auth_case( 'signed self-test cannot establish readiness outside observe or enforce', Ratesight_Request_Auth::authorize_read( $legacy_self_test ) === true && error_code( Ratesight_Request_Auth::handle_self_test( $legacy_self_test ) ) === 'rs_auth_readiness_mode_required' && empty( $options['ratesight_auth_v2_readiness'] ) );
$options['ratesight_auth_mode'] = 'observe_v2';
$options['ratesight_webhook_secret_previous'] = $fixture['previousSecret'];
$options['ratesight_webhook_secret_previous_expires'] = time() + 60;
$previous_self_test = signed_request( $fixture['previousSecret'], array( 'method' => 'GET', 'route' => '/ratesight/v1/auth-self-test', 'query' => array(), 'body' => '' ) );
check_auth_case( 'previous-key self-test cannot establish current readiness', Ratesight_Request_Auth::authorize_read( $previous_self_test ) === true && error_code( Ratesight_Request_Auth::handle_self_test( $previous_self_test ) ) === 'rs_auth_readiness_not_verified' && empty( $options['ratesight_auth_v2_readiness'] ) );
unset( $options['ratesight_webhook_secret_previous'], $options['ratesight_webhook_secret_previous_expires'] );
$ordinary_request = signed_request( $fixture['secret'] );
check_auth_case( 'ordinary verifier acceptance does not record operational readiness', Ratesight_Request_Auth::authorize_read( $ordinary_request ) === true && empty( $options['ratesight_auth_v2_readiness'] ) );
$forged_self_test = signed_request( $fixture['secret'], array( 'method' => 'GET', 'route' => '/ratesight/v1/auth-self-test', 'query' => array(), 'body' => '' ) );
check_auth_case( 'self-test handler cannot be called without prior verifier acceptance', error_code( Ratesight_Request_Auth::handle_self_test( $forged_self_test ) ) === 'rs_auth_readiness_not_verified' );
$readiness_headers = array_change_key_case( Ratesight_Request_Auth::signed_headers( $fixture['secret'], 'GET', '/ratesight/v1/auth-self-test' ), CASE_LOWER );
$readiness_request = new Auth_Request( 'GET', '/ratesight/v1/auth-self-test', array(), '', $readiness_headers, 'rest_route=%2Fratesight%2Fv1%2Fauth-self-test' );
check_auth_case( 'plain-permalink admin self-test passes verifier without recording readiness', Ratesight_Request_Auth::authorize_read( $readiness_request ) === true && empty( $options['ratesight_auth_v2_readiness'] ) );
check_auth_case( 'successful signed self-test handler records readiness', Ratesight_Request_Auth::handle_self_test( $readiness_request )['readiness'] === true && ( $options['ratesight_auth_v2_readiness']['key_id'] ?? '' ) === $fixture['keyId'] );
check_auth_case( 'self-test candidate is single use', error_code( Ratesight_Request_Auth::handle_self_test( $readiness_request ) ) === 'rs_auth_readiness_not_verified' );
check_auth_case( 'capabilities expose sanitized current readiness', Ratesight_Request_Auth::capability_auth()['readiness_current'] === true && Ratesight_Request_Auth::capability_auth()['readiness_expires'] !== null );
check_auth_case( 'current readiness permits enforce and latches', Ratesight_Request_Auth::set_mode( 'enforce_v2' ) === true && ! empty( $options['ratesight_auth_ever_enforced'] ) );
check_auth_case( 'enforced mode can roll back only to observe', Ratesight_Request_Auth::set_mode( 'observe_v2' ) === true && error_code( Ratesight_Request_Auth::set_mode( 'legacy' ) ) === 'rs_auth_mode_downgrade_blocked' );
check_auth_case( 'fresh proof permits re-enforcement after observe rollback', Ratesight_Request_Auth::set_mode( 'enforce_v2' ) === true );
check_auth_case( 'unknown transition is rejected', error_code( Ratesight_Request_Auth::set_mode( 'corrupt' ) ) === 'rs_auth_mode_invalid' );
$admin_source = file_get_contents( __DIR__ . '/../admin/class-ratesight-admin.php' );
check_auth_case( 'admin transition input cannot submit readiness proof', strpos( $admin_source, "_POST['readiness" ) === false && strpos( $admin_source, 'ratesight_auth_v2_readiness' ) === false );
check_auth_case( 'admin self-test signs server-side GET without creating content', strpos( $admin_source, "signed_headers( \$secret, 'GET', \$route )" ) !== false && strpos( $admin_source, 'wp_remote_get( $endpoint' ) !== false && strpos( $admin_source, "'[Ratesight Test] '" ) === false );
check_auth_case( 'admin self-test response never exposes the secret', strpos( $admin_source, "'secret' => \$secret" ) === false );

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
$plain_query_tamper = signed_request( $fixture['secret'], array( 'method' => 'GET', 'route' => '/ratesight/v1/update-page', 'query' => array( 'url' => 'https://example.com/' ), 'body' => '', 'raw_query' => 'rest_route=%2Fratesight%2Fv1%2Fupdate-page&url=https%3A%2F%2Fattacker.example%2F' ) );
check_auth_case( 'plain-permalink arbitrary query tampering remains signature-bound', error_code( Ratesight_Request_Auth::authorize_read( $plain_query_tamper ) ) === 'rs_bad_signature' );
check_auth_case( 'enforce mode rejects valid legacy signature', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'] ) ) ) === 'rs_auth_version_required' );
check_auth_case( 'enforce mode rejects unsigned mutation', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'], '{}', false ) ) ) === 'rs_auth_version_required' );
$options['ratesight_auth_mode'] = 'observe_v2';
check_auth_case( 'observe mode accepts valid legacy signature', Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'] ) ) === true );
check_auth_case( 'observe mode rejects unsigned mutation', error_code( Ratesight_Request_Auth::authorize_mutation( legacy_request( $fixture['secret'], '{}', false ) ) ) === 'rs_signature_required' );
$options['ratesight_auth_mode'] = 'legacy';
unset( $options['ratesight_auth_ever_enforced'] );
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
