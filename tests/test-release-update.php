<?php

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'RATESIGHT_RELEASE_VERSION', '3.6.0' );

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Response {
	public function __construct( public array $data, public int $status ) {}
}
class WP_REST_Request {
	public function __construct( private array $params ) {}
	public function get_json_params(): array { return $this->params; }
}
class Ratesight_Options { public static function get() { return '2'; } }
class Ratesight_Request_Auth { public static function authorize_mutation() { return true; } }
function apply_filters( $name, $value ) { global $release_public_key; return $name === 'ratesight_pairing_public_key' ? $release_public_key : $value; }
function get_bloginfo() { return '6.6.0'; }

require __DIR__ . '/../includes/class-ratesight-pairing.php';
require __DIR__ . '/../includes/class-ratesight-release-update.php';

$release_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
openssl_pkey_export( $release_key, $release_private_key );
$release_public_key = openssl_pkey_get_details( $release_key )['key'];

function release_request( array $overrides = array(), $key = null ): WP_REST_Request {
	global $release_private_key;
	$manifest = array_merge( array(
		'contract' => Ratesight_Release_Update::CONTRACT,
		'version' => '3.7.0',
		'assetUrl' => 'https://github.com/studiosight/ratesightwp/releases/download/v3.7.0/ratesight-3.7.0.zip',
		'sha256' => str_repeat( 'a', 64 ),
		'minPhp' => '8.0',
		'minWordPress' => '6.3',
	), $overrides );
	$json = json_encode( $manifest, JSON_UNESCAPED_SLASHES );
	openssl_sign( $json, $signature, $key ?? $release_private_key, OPENSSL_ALGO_SHA256 );
	return new WP_REST_Request( array( 'manifestJson' => $json, 'signature' => base64_encode( $signature ) ) );
}

$failures = 0;
function check_release_case( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$valid = Ratesight_Release_Update::preflight( release_request() );
check_release_case( 'signed fixed-namespace release is eligible', $valid instanceof WP_REST_Response && $valid->status === 200 && $valid->data['eligible'] === true );
check_release_case( 'preflight cannot apply an update', $valid->data['applySupported'] === false );
$wrong_host = Ratesight_Release_Update::preflight( release_request( array( 'assetUrl' => 'https://attacker.invalid/ratesight.zip' ) ) );
check_release_case( 'non-Ratesight release host is refused', $wrong_host instanceof WP_Error && $wrong_host->get_error_code() === 'rs_release_manifest_invalid' );
$old = Ratesight_Release_Update::preflight( release_request( array( 'version' => '3.5.9', 'assetUrl' => 'https://github.com/studiosight/ratesightwp/releases/download/v3.5.9/ratesight-3.5.9.zip' ) ) );
check_release_case( 'non-newer target is reported ineligible', $old instanceof WP_REST_Response && $old->data['eligible'] === false && in_array( 'target_not_newer', $old->data['blockedBy'], true ) );
$other_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
$invalid = Ratesight_Release_Update::preflight( release_request( array(), $other_key ) );
check_release_case( 'unknown release signer is refused', $invalid instanceof WP_Error && $invalid->get_error_code() === 'rs_release_signature_invalid' );

echo $failures ? "{$failures} RELEASE CHECKS FAILED\n" : "ALL RELEASE CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
