<?php
/**
 * 3.15.2: Ratesight CRM posts signed settings route (GET/POST /crm-publish), key handling and log.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
$options = array( 'ratesight_webhook_secret' => 'fixture-secret' );
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function rest_url( $path ) { return 'https://example.test/wp-json/' . $path; }
class WP_Error { public function __construct( public string $code = '' ) {} }
class WP_REST_Response { public function __construct( public $data, public $status ) {} }
class Route_Request {
	public array $query = array();
	public function __construct( private array $body = array(), private array $headers = array() ) {}
	public function get_json_params() { return $this->body; }
	public function get_body_params() { return array(); }
	public function get_query_params() { return $this->query; }
	public function get_header( $name ) { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}
require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-crm-publish.php';
$_SERVER['REMOTE_ADDR'] = '67.199.171.44';

$failures = 0;
$checks   = 0;
function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$get = Ratesight_CRM_Publish::handle_get( new Route_Request() );
check( 'GET: on by default, key not required, no key, limit 500, over limit draft', $get->status === 200 && $get->data['enabled'] === true && $get->data['effective'] === true && $get->data['key_required'] === false && $get->data['key_configured'] === false && $get->data['key_id'] === null && $get->data['max'] === 500 && $get->data['over_limit'] === 'draft' );
check( 'GET never returns the key or a webhook URL', ! array_key_exists( 'crm_webhook_url', $get->data ) && strpos( json_encode( $get->data ), 'rs_crm_key=' ) === false );

$plain = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => true ) ) );
check( 'POST enabled:true with no key requirement makes no key and returns no URL', $plain->status === 200 && $options['ratesight_crm_posts_hold'] === 0 && ! isset( $options[ Ratesight_CRM_Publish::KEY_OPTION ] ) && ! isset( $plain->data['crm_webhook_url'] ) && $plain->data['after']['effective'] === true );
unset( $options['ratesight_crm_posts_hold'] );

$dry = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => true, 'require_key' => true, 'dry_run' => true ) ) );
check( 'POST dry_run writes nothing', $dry->status === 200 && $dry->data['dry_run'] === true && $dry->data['would']['enabled'] === true && $dry->data['would']['require_key'] === true && $dry->data['would']['rotate_key'] === true && ! isset( $options['ratesight_crm_posts_hold'] ) && ! isset( $options['ratesight_crm_key_required'] ) && ! isset( $options[ Ratesight_CRM_Publish::KEY_OPTION ] ) );

$bad = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => 'maybe' ) ) );
check( 'POST rejects a non-boolean enabled', $bad->status === 422 && ! isset( $options['ratesight_crm_posts_hold'] ) );
$bad_key = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'require_key' => 'maybe' ) ) );
check( 'POST rejects a non-boolean require_key', $bad_key->status === 422 && ! isset( $options['ratesight_crm_key_required'] ) );

$on  = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => true, 'require_key' => true ) ) );
$key = $options[ Ratesight_CRM_Publish::KEY_OPTION ] ?? '';
check( 'POST require_key:true makes a 40-char url-safe key and requires it', $on->status === 200 && $options['ratesight_crm_posts_hold'] === 0 && $options['ratesight_crm_key_required'] === 1 && preg_match( '/^[A-Za-z0-9_-]{40}$/', $key ) === 1 && $on->data['after']['effective'] === true && $on->data['after']['key_required'] === true );
check( 'POST returns the CRM webhook URL with the key while on', $on->data['crm_webhook_url'] === 'https://example.test/wp-json/ratesight/v1/create-page?rs_crm_key=' . $key );
check( 'before/after report the key fingerprint, not the key', strpos( json_encode( array( $on->data['before'], $on->data['after'] ) ), $key ) === false && $on->data['after']['key_id'] === Ratesight_CRM_Publish::key_fingerprint( $key ) );

$again = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => true ) ) );
check( 'POST enabled:true again keeps the same key (idempotent)', $options[ Ratesight_CRM_Publish::KEY_OPTION ] === $key && $again->data['crm_webhook_url'] === $on->data['crm_webhook_url'] );

$rot = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'rotate_key' => true ) ) );
$new_key = $options[ Ratesight_CRM_Publish::KEY_OPTION ];
check( 'POST rotate_key makes a new key and the old one stops working', $new_key !== $key && strpos( $rot->data['crm_webhook_url'], $new_key ) !== false );
$old = new Route_Request(); $old->query['rs_crm_key'] = $key;
$cur = new Route_Request(); $cur->query['rs_crm_key'] = $new_key;
$hdr = new Route_Request( array(), array( 'x-ratesight-crm-key' => $new_key ) );
check( 'key_state: old key invalid, current key valid (query and header), none missing', Ratesight_CRM_Publish::key_state( $old ) === 'invalid' && Ratesight_CRM_Publish::key_state( $cur ) === 'valid' && Ratesight_CRM_Publish::key_state( $hdr ) === 'valid' && Ratesight_CRM_Publish::key_state( new Route_Request() ) === 'missing' );
$arr = new Route_Request(); $arr->query['rs_crm_key'] = array( $new_key );
check( 'key_state: an array-valued key is not accepted', Ratesight_CRM_Publish::key_state( $arr ) === 'missing' );

Ratesight_CRM_Publish::record( 'auto_publish', 'req1', 55, 'a-post', 'post', 'final_post_status' );
$get = Ratesight_CRM_Publish::handle_get( new Route_Request() );
check( 'GET lists recent auto-publishes with source and post id', $get->data['recent'][0]['post_id'] === 55 && $get->data['recent'][0]['source'] === 'ratesight_crm' && $get->data['recent'][0]['ip'] === '67.199.171.44' && $get->data['recent'][0]['key_id'] === Ratesight_CRM_Publish::key_fingerprint() );
for ( $i = 0; $i < 120; $i++ ) Ratesight_CRM_Publish::record( 'auto_publish', "r{$i}", $i, 's', 'post', 'publish' );
check( 'the auto-publish log keeps the last 100 rows', count( $options[ Ratesight_CRM_Publish::LOG_OPTION ] ) === 100 );

$off = Ratesight_CRM_Publish::handle_post( new Route_Request( array( 'enabled' => false ) ) );
check( 'POST enabled:false holds CRM posts, keeps the key, returns no URL', $options['ratesight_crm_posts_hold'] === 1 && $options[ Ratesight_CRM_Publish::KEY_OPTION ] === $new_key && ! isset( $off->data['crm_webhook_url'] ) && $off->data['after']['effective'] === false );

$options['ratesight_auth_mode'] = 'enforce_v2';
$options['ratesight_crm_posts_hold'] = 0; $options['ratesight_crm_key_required'] = 1;
check( 'enforce_v2: the switch is never effective', Ratesight_CRM_Publish::status()['effective'] === false );

$admin = file_get_contents( __DIR__ . '/../admin/partials/tab-seo-pages.php' );
check( 'the admin tab has the opt-out checkbox and never shows the key or URL', strpos( $admin, 'name="ratesight_crm_posts_hold" value="1"' ) !== false && strpos( $admin, 'ratesight_crm_publisher_trust' ) === false && strpos( $admin, 'Ratesight_CRM_Publish::key() )' ) === false && strpos( $admin, 'webhook_url(' ) === false );

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
