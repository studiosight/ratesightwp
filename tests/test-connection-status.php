<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'RATESIGHT_RELEASE_VERSION', '3.4.0-test' );

$options = array(
	'ratesight_auth_mode' => 'observe_v2',
	'ratesight_webhook_secret' => 'fixture-secret-that-must-never-appear',
	'ratesight_auth_v2_readiness' => array(
		'key_id' => substr( hash( 'sha256', 'fixture-secret-that-must-never-appear' ), 0, 16 ),
		'completed_at' => time(),
	),
	'ratesight_indexnow_key' => 'fixture-indexnow-key-that-must-never-appear',
);
$network_calls = 0;

function get_option( $key, $default = false ) {
	global $options;
	return $options[ $key ] ?? $default;
}
function update_option() { return true; }
function wp_remote_get() { global $network_calls; $network_calls++; return array(); }
function wp_remote_post() { global $network_calls; $network_calls++; return array(); }

class WP_REST_Server { const READABLE = 'GET'; }
class WP_REST_Request {}
class WP_REST_Response {
	public function __construct( public $data, public $status ) {}
}
class WP_Error {}
class Ratesight_Options {
	public static function get( string $key ) { return $key === 'code_id' ? 'fixture-oid' : ''; }
	public static function secret_setting_status( string $key ): array { return array( 'configured' => $key === 'deepseek_api_key' ); }
}
class Ratesight_OAuth_Client {
	public static function is_connected( string $service ) { return $service === 'gsc'; }
}
class Ratesight_GSC_Client { public static function is_locked() { return true; } }
class Ratesight_GBP_Client { public static function is_locked() { return false; } }
class Ratesight_Bing_Client {
	public static function is_connected(): bool { return true; }
	public static function is_locked(): bool { return false; }
}

require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-webhook-handler.php';

$response = ( new Ratesight_Webhook_Handler() )->handle_connection_status( new WP_REST_Request() );
$encoded = json_encode( $response->data );
$failures = 0;

function check_connection_case( string $label, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
}

check_connection_case( 'uses the versioned connection status contract', $response->status === 200 && $response->data['contract'] === 'ratesight-connection-status-v1' );
check_connection_case( 'reports request-auth readiness without key identifiers', $response->data['requestAuth']['mode'] === 'observe_v2' && $response->data['requestAuth']['configured'] === true && $response->data['requestAuth']['readinessCurrent'] === true );
check_connection_case( 'reports only boolean local provider residue', $response->data['legacyProviderResidue']['gsc'] === array( 'credentialConfigured' => true, 'selectionConfigured' => true ) && $response->data['legacyProviderResidue']['gbp']['credentialConfigured'] === false );
check_connection_case( 'reports retained IndexNow without returning its key', $response->data['retainedLocal']['indexNowConfigured'] === true && strpos( $encoded, 'fixture-indexnow-key' ) === false );
check_connection_case( 'does not expose stored auth or provider material', strpos( $encoded, 'fixture-secret' ) === false && ! preg_match( '/(?:current|previous)_key_id|access_token|refresh_token|email|property|location|siteUrl/i', $encoded ) );
check_connection_case( 'does not call WordPress or provider networks', $network_calls === 0 );

echo $failures ? "{$failures} CONNECTION STATUS CHECKS FAILED\n" : "ALL CONNECTION STATUS CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
