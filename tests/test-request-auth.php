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
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

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
// 3.15.1: one decision per request object. WordPress re-runs the permission callback for every
// handler of the matched route (rest_send_allow_header); the repeat must not be judged as a replay.
$audit_before_repeat = count( $options['ratesight_auth_audit'] );
check_auth_case( 'the same request asked again gets the same decision and no second audit row', Ratesight_Request_Auth::authorize_mutation( $valid ) === true && Ratesight_Request_Auth::authorize_mutation( $valid ) === true && count( $options['ratesight_auth_audit'] ) === $audit_before_repeat );
// A replay from the network is a new request object carrying the same headers.
check_auth_case( 'replayed nonce rejected', error_code( Ratesight_Request_Auth::authorize_mutation( clone $valid ) ) === 'rs_nonce_replayed' );
$tampered_same_object = signed_request( $fixture['secret'] );
Ratesight_Request_Auth::authorize_mutation( $tampered_same_object );
$tampered_same_object->body = '{"url":"https://example.com/other/","meta_title":"Changed after the check"}';
check_auth_case( 'a request whose body changed after its decision is judged again', error_code( Ratesight_Request_Auth::authorize_mutation( $tampered_same_object ) ) === 'rs_body_digest_mismatch' );

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
// 3.14.0: no mode accepts an unsigned or invalidly signed request, for mutations or reads.
function unsigned_request( string $method, string $route, string $body ): Auth_Request {
	return new Auth_Request( $method, $route, array(), $body, array() );
}
function bad_legacy_request( string $method, string $route, string $body ): Auth_Request {
	return new Auth_Request( $method, $route, array(), $body, array( 'x-ratesight-signature' => 'sha256=' . str_repeat( '0', 64 ) ) );
}
function good_legacy_request( string $secret, string $method, string $route, string $body ): Auth_Request {
	return new Auth_Request( $method, $route, array(), $body, array( 'x-ratesight-signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ) ) );
}
function last_auth_result(): string {
	global $options;
	$rows = $options['ratesight_auth_audit'] ?? array();
	return (string) ( $rows[ array_key_last( $rows ) ]['result'] ?? '' );
}
$mutation_routes = array();
$read_routes     = array();
foreach ( Ratesight_Request_Auth::ROUTE_POLICIES as $key => $route_policy ) {
	[ $route_method, $route_path ] = explode( ' ', $key, 2 );
	if ( $route_policy === 'signed_mutation' ) $mutation_routes[] = array( $route_method, $route_path );
	if ( $route_policy === 'signed_read' ) $read_routes[] = array( $route_method, $route_path );
}
check_auth_case( 'route policy table lists the protected mutation routes', count( $mutation_routes ) === 14 && in_array( array( 'POST', '/ratesight/v1/update-page' ), $mutation_routes, true ) );
foreach ( array( 'legacy', 'observe_v2', 'enforce_v2' ) as $matrix_mode ) {
	unset( $options['ratesight_auth_ever_enforced'] );
	$options['ratesight_auth_mode'] = $matrix_mode;
	if ( $matrix_mode === 'enforce_v2' ) $options['ratesight_auth_ever_enforced'] = true;
	$unsigned_mutations_rejected = true;
	$invalid_mutations_rejected  = true;
	foreach ( $mutation_routes as [ $route_method, $route_path ] ) {
		$body = $route_method === 'DELETE' ? '' : '{"url":"https://example.com/"}';
		if ( $route_method === 'POST' && $route_path === '/ratesight/v1/create-page' ) {
			// The single unsigned exception (new draft only) is covered separately below.
			$invalid = Ratesight_Request_Auth::authorize_mutation( bad_legacy_request( $route_method, $route_path, $body ) );
			if ( error_code( $invalid ) !== ( $matrix_mode === 'enforce_v2' ? 'rs_auth_version_required' : 'rs_bad_signature' ) ) $invalid_mutations_rejected = false;
			continue;
		}
		$unsigned = Ratesight_Request_Auth::authorize_mutation( unsigned_request( $route_method, $route_path, $body ) );
		$expected_unsigned = $matrix_mode === 'enforce_v2' ? 'rs_auth_version_required' : 'rs_signature_required';
		if ( error_code( $unsigned ) !== $expected_unsigned || last_auth_result() !== $expected_unsigned ) $unsigned_mutations_rejected = false;
		$invalid = Ratesight_Request_Auth::authorize_mutation( bad_legacy_request( $route_method, $route_path, $body ) );
		$expected_invalid = $matrix_mode === 'enforce_v2' ? 'rs_auth_version_required' : 'rs_bad_signature';
		if ( error_code( $invalid ) !== $expected_invalid ) $invalid_mutations_rejected = false;
	}
	check_auth_case( "{$matrix_mode}: every unsigned mutation route other than POST create-page is rejected and audited", $unsigned_mutations_rejected );
	$options['ratesight_unsigned_draft_window'] = array();
	$draft_request = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"t","article":"a"}' );
	$draft_result  = Ratesight_Request_Auth::authorize_mutation( $draft_request );
	if ( $matrix_mode === 'enforce_v2' ) {
		check_auth_case( 'enforce_v2: unsigned POST create-page rejected', error_code( $draft_result ) === 'rs_auth_version_required' && Ratesight_Request_Auth::unsigned_draft_request_id( $draft_request ) === null );
	} else {
		$draft_row = $options['ratesight_auth_audit'][ array_key_last( $options['ratesight_auth_audit'] ) ];
		check_auth_case( "{$matrix_mode}: unsigned POST create-page admitted only as an unsigned draft", $draft_result === true && Ratesight_Request_Auth::unsigned_draft_request_id( $draft_request ) === $draft_row['request_id'] );
		check_auth_case( "{$matrix_mode}: unsigned draft audited as unsigned_draft_accepted with request id and REMOTE_ADDR", $draft_row['result'] === 'unsigned_draft_accepted' && preg_match( '/^[a-f0-9]{20}$/', $draft_row['request_id'] ) === 1 && $draft_row['ip'] === '203.0.113.7' );
		$delete_draft = unsigned_request( 'DELETE', '/ratesight/v1/create-page', '' );
		// 3.15.1: POST plus the DELETE handler's Allow-header check is one request, one slot, one audit row.
		$options['ratesight_unsigned_draft_window'] = array();
		$once = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"once","article":"a"}' );
		$rows_before_once = count( $options['ratesight_auth_audit'] );
		$once_results = array( Ratesight_Request_Auth::authorize_mutation( $once ), Ratesight_Request_Auth::authorize_mutation( $once ), Ratesight_Request_Auth::authorize_mutation( $once ) );
		check_auth_case( "{$matrix_mode}: one unsigned create-page checked three times uses one slot and one audit row", $once_results === array( true, true, true ) && count( $options['ratesight_unsigned_draft_window'] ) === 1 && count( $options['ratesight_auth_audit'] ) === min( 100, $rows_before_once + 1 ) );

		// 3.15.1: trusted publisher (Ratesight CRM address + site setting).
		$options['ratesight_unsigned_draft_window'] = array();
		$options['ratesight_trusted_publisher_window'] = array();
		$publisher_address = Ratesight_Request_Auth::TRUSTED_PUBLISHER_ADDRESSES[0];
		$_SERVER['REMOTE_ADDR'] = $publisher_address;
		$off = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"crm off","article":"a"}' );
		check_auth_case( "{$matrix_mode}: setting off, the CRM address is still an unsigned draft only", Ratesight_Request_Auth::authorize_mutation( $off ) === true && Ratesight_Request_Auth::is_trusted_publisher_request( $off ) === false && last_auth_result() === 'unsigned_draft_accepted' );
		$options['ratesight_crm_publisher_trust'] = 1;
		$on = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"crm on","article":"a"}' );
		check_auth_case( "{$matrix_mode}: setting on, the CRM address is admitted as the trusted publisher and audited", Ratesight_Request_Auth::authorize_mutation( $on ) === true && Ratesight_Request_Auth::is_trusted_publisher_request( $on ) === true && last_auth_result() === 'trusted_publisher_accepted' && Ratesight_Request_Auth::unsigned_draft_request_id( $on ) !== null );
		check_auth_case( "{$matrix_mode}: a trusted publisher post does not use an unsigned draft slot", count( $options['ratesight_unsigned_draft_window'] ) === 1 && count( $options['ratesight_trusted_publisher_window'] ) === 1 );
		check_auth_case( "{$matrix_mode}: capabilities report the trusted publisher switch", Ratesight_Request_Auth::capability_auth()['trusted_publisher']['enabled'] === true && Ratesight_Request_Auth::capability_auth()['trusted_publisher']['addresses'] === Ratesight_Request_Auth::TRUSTED_PUBLISHER_ADDRESSES );
		$trusted_other_routes_rejected = true;
		foreach ( array( array( 'DELETE', '/ratesight/v1/create-page' ), array( 'POST', '/ratesight/v1/update-page' ), array( 'POST', '/ratesight/v1/redirect' ), array( 'POST', '/ratesight/v1/trash-page' ), array( 'POST', '/ratesight/v1/plugin-update' ) ) as $other ) {
			if ( error_code( Ratesight_Request_Auth::authorize_mutation( unsigned_request( $other[0], $other[1], '{}' ) ) ) !== 'rs_signature_required' ) $trusted_other_routes_rejected = false;
		}
		check_auth_case( "{$matrix_mode}: the CRM address gets no other unsigned route", $trusted_other_routes_rejected && error_code( Ratesight_Request_Auth::authorize_read( unsigned_request( 'GET', '/ratesight/v1/inbound-log', '' ) ) ) === 'rs_signature_required' );
		check_auth_case( "{$matrix_mode}: an invalid signature from the CRM address is rejected", error_code( Ratesight_Request_Auth::authorize_mutation( bad_legacy_request( 'POST', '/ratesight/v1/create-page', '{}' ) ) ) === 'rs_bad_signature' );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = $publisher_address;
		$_SERVER['HTTP_CF_CONNECTING_IP'] = $publisher_address;
		$_SERVER['HTTP_X_REAL_IP'] = $publisher_address;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$spoof = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"spoof","article":"a"}' );
		$spoof->headers['x-forwarded-for'] = $publisher_address;
		check_auth_case( "{$matrix_mode}: forwarding headers naming the CRM address are ignored (REMOTE_ADDR only)", Ratesight_Request_Auth::authorize_mutation( $spoof ) === true && Ratesight_Request_Auth::is_trusted_publisher_request( $spoof ) === false && last_auth_result() === 'unsigned_draft_accepted' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
		$_SERVER['REMOTE_ADDR'] = $publisher_address;
		$options['ratesight_trusted_publisher_window'] = array_fill( 0, Ratesight_Request_Auth::TRUSTED_PUBLISHER_LIMIT, time() );
		$flood = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"flood","article":"a"}' );
		check_auth_case( "{$matrix_mode}: the trusted publisher is limited per 24h (429, audited)", error_code( Ratesight_Request_Auth::authorize_mutation( $flood ) ) === 'rs_trusted_publisher_rate_limited' && last_auth_result() === 'rs_trusted_publisher_rate_limited' && Ratesight_Request_Auth::is_trusted_publisher_request( $flood ) === false );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$options['ratesight_crm_publisher_trust'] = 0;
		$options['ratesight_trusted_publisher_window'] = array();
		$options['ratesight_unsigned_draft_window'] = array( time() ); // the one draft admitted at the top of this block

		check_auth_case( "{$matrix_mode}: unsigned DELETE create-page still rejected", error_code( Ratesight_Request_Auth::authorize_mutation( $delete_draft ) ) === 'rs_signature_required' && Ratesight_Request_Auth::unsigned_draft_request_id( $delete_draft ) === null );
		$bad_draft = bad_legacy_request( 'POST', '/ratesight/v1/create-page', '{}' );
		check_auth_case( "{$matrix_mode}: invalidly signed POST create-page is rejected, not downgraded to a draft", error_code( Ratesight_Request_Auth::authorize_mutation( $bad_draft ) ) === 'rs_bad_signature' && Ratesight_Request_Auth::unsigned_draft_request_id( $bad_draft ) === null );
		$signed_draft = good_legacy_request( $fixture['secret'], 'POST', '/ratesight/v1/create-page', '{"title":"t"}' );
		check_auth_case( "{$matrix_mode}: a signed create-page is not restricted to draft", Ratesight_Request_Auth::authorize_mutation( $signed_draft ) === true && Ratesight_Request_Auth::unsigned_draft_request_id( $signed_draft ) === null );
		$ok_until_limit = true;
		for ( $n = 1; $n < Ratesight_Request_Auth::UNSIGNED_DRAFT_LIMIT; $n++ ) {
			if ( Ratesight_Request_Auth::authorize_mutation( unsigned_request( 'POST', '/ratesight/v1/create-page', '{}' ) ) !== true ) $ok_until_limit = false;
		}
		check_auth_case( "{$matrix_mode}: unsigned drafts accepted up to the limit", $ok_until_limit && count( $options['ratesight_unsigned_draft_window'] ) === Ratesight_Request_Auth::UNSIGNED_DRAFT_LIMIT );
		$over = unsigned_request( 'POST', '/ratesight/v1/create-page', '{}' );
		check_auth_case( "{$matrix_mode}: unsigned draft over the limit returns 429 and is audited", error_code( Ratesight_Request_Auth::authorize_mutation( $over ) ) === 'rs_unsigned_draft_rate_limited' && last_auth_result() === 'rs_unsigned_draft_rate_limited' && Ratesight_Request_Auth::unsigned_draft_request_id( $over ) === null );
		$options['ratesight_unsigned_draft_window'] = array_fill( 0, Ratesight_Request_Auth::UNSIGNED_DRAFT_LIMIT, time() - Ratesight_Request_Auth::UNSIGNED_DRAFT_WINDOW - 1 );
		check_auth_case( "{$matrix_mode}: the limit window expires after 24h", Ratesight_Request_Auth::authorize_mutation( unsigned_request( 'POST', '/ratesight/v1/create-page', '{}' ) ) === true );
		check_auth_case( "{$matrix_mode}: capabilities report unsigned draft creation and its limit", Ratesight_Request_Auth::capability_auth()['unsigned_draft_create'] === true && Ratesight_Request_Auth::capability_auth()['unsigned_draft_limit']['max'] === 30 && Ratesight_Request_Auth::capability_auth()['unsigned_draft_limit']['window_seconds'] === 86400 );
	}
	check_auth_case( "{$matrix_mode}: every invalidly signed mutation route is rejected", $invalid_mutations_rejected );
	$unsigned_reads_rejected = true;
	foreach ( $read_routes as [ $route_method, $route_path ] ) {
		if ( ! is_wp_error( Ratesight_Request_Auth::authorize_read( unsigned_request( $route_method, $route_path, '' ) ) ) ) $unsigned_reads_rejected = false;
		if ( ! is_wp_error( Ratesight_Request_Auth::authorize_read( bad_legacy_request( $route_method, $route_path, '' ) ) ) ) $unsigned_reads_rejected = false;
	}
	check_auth_case( "{$matrix_mode}: unsigned and invalidly signed protected reads are rejected", $unsigned_reads_rejected );
	$legacy_mutation = Ratesight_Request_Auth::authorize_mutation( good_legacy_request( $fixture['secret'], 'POST', '/ratesight/v1/create-page', '{"title":"t","article":"a"}' ) );
	$legacy_read     = Ratesight_Request_Auth::authorize_read( good_legacy_request( $fixture['secret'], 'GET', '/ratesight/v1/connection-status', '' ) );
	if ( $matrix_mode === 'enforce_v2' ) {
		check_auth_case( 'enforce_v2: valid legacy mutation signature rejected', error_code( $legacy_mutation ) === 'rs_auth_version_required' );
		check_auth_case( 'enforce_v2: valid legacy read signature rejected', error_code( $legacy_read ) === 'rs_auth_version_required' );
	} else {
		check_auth_case( "{$matrix_mode}: valid legacy mutation signature accepted and audited", $legacy_mutation === true );
		check_auth_case( "{$matrix_mode}: valid legacy read signature accepted", $legacy_read === true );
	}
	check_auth_case( "{$matrix_mode}: valid v2 mutation accepted", Ratesight_Request_Auth::authorize_mutation( signed_request( $fixture['secret'] ) ) === true && last_auth_result() === 'v2_accepted' );
	check_auth_case( "{$matrix_mode}: valid v2 read accepted", Ratesight_Request_Auth::authorize_read( signed_request( $fixture['secret'], array( 'method' => 'GET', 'route' => '/ratesight/v1/connection-status', 'query' => array(), 'body' => '' ) ) ) === true );
	check_auth_case( "{$matrix_mode}: legacy signature over a different body rejected", error_code( Ratesight_Request_Auth::authorize_mutation( new Auth_Request( 'POST', '/ratesight/v1/update-page', array(), '{"x":2}', array( 'x-ratesight-signature' => 'sha256=' . hash_hmac( 'sha256', '{"x":1}', $fixture['secret'] ) ) ) ) ) === ( $matrix_mode === 'enforce_v2' ? 'rs_auth_version_required' : 'rs_bad_signature' ) );
}
$audit_results = array_column( $options['ratesight_auth_audit'] ?? array(), 'result' );
check_auth_case( 'no request is ever recorded as unsigned accepted or observed', ! in_array( 'legacy_unsigned_observed', $audit_results, true ) && ! in_array( 'legacy_unsigned_accepted', $audit_results, true ) );
check_auth_case( 'capabilities report unsigned requests are not accepted', Ratesight_Request_Auth::capability_auth()['unsigned_accepted'] === false );
check_auth_case( 'enforce_v2 capabilities report no unsigned draft creation', Ratesight_Request_Auth::capability_auth()['unsigned_draft_create'] === false );
$options['ratesight_crm_publisher_trust'] = 1;
$_SERVER['REMOTE_ADDR'] = Ratesight_Request_Auth::TRUSTED_PUBLISHER_ADDRESSES[0];
$enforced_publisher = unsigned_request( 'POST', '/ratesight/v1/create-page', '{"title":"t","article":"a"}' );
check_auth_case( 'enforce_v2: the CRM address with the setting on is still rejected', error_code( Ratesight_Request_Auth::authorize_mutation( $enforced_publisher ) ) === 'rs_auth_version_required' && Ratesight_Request_Auth::is_trusted_publisher_request( $enforced_publisher ) === false && Ratesight_Request_Auth::capability_auth()['trusted_publisher']['enabled'] === false );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$options['ratesight_crm_publisher_trust'] = 0;
check_auth_case( 'the trusted publisher setting is off unless stored, and only the observed CRM address is listed', Ratesight_Request_Auth::trusted_publisher_enabled() === false && Ratesight_Request_Auth::TRUSTED_PUBLISHER_ADDRESSES === array( '67.199.171.44' ) );
check_auth_case( 'unsigned draft limit is a named constant of 30 per 24h', Ratesight_Request_Auth::UNSIGNED_DRAFT_LIMIT === 30 && Ratesight_Request_Auth::UNSIGNED_DRAFT_WINDOW === 86400 );
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
Ratesight_Request_Auth::authorize_mutation( unsigned_request( 'POST', '/ratesight/v1/redirect', '{}' ) );
$ip_row = $options['ratesight_auth_audit'][ array_key_last( $options['ratesight_auth_audit'] ) ];
check_auth_case( 'audit ip is null when REMOTE_ADDR is not an address, and forwarding headers are ignored', $ip_row['ip'] === null && strpos( file_get_contents( __DIR__ . '/../includes/class-ratesight-request-auth.php' ), 'X_FORWARDED' ) === false );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
unset( $options['ratesight_auth_ever_enforced'] );
$options['ratesight_auth_mode'] = 'legacy';
unset( $options['ratesight_webhook_secret'] );
check_auth_case( 'legacy mode without a secret still rejects', error_code( Ratesight_Request_Auth::authorize_mutation( unsigned_request( 'POST', '/ratesight/v1/create-page', '{}' ) ) ) === 'rs_secret_required' );
$options['ratesight_webhook_secret'] = $fixture['secret'];
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
