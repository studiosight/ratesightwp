<?php
/**
 * 3.14.0: the single unsigned exception. In legacy and observe_v2 an unsigned
 * POST /create-page may only create a NEW DRAFT. This drives the real auth
 * layer and the real create-page handler against in-memory WordPress stubs.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'OBJECT', 'OBJECT' );

$options = array( 'ratesight_auth_mode' => 'observe_v2', 'ratesight_webhook_secret' => 'fixture-secret' );
$posts   = array( 101 => array( 'ID' => 101, 'post_name' => 'existing-service', 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Existing', 'post_content' => 'original', 'post_excerpt' => '' ) );
$next_id = 500;
$events  = array();
$writes  = array();
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';

function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $v ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' ); }
function sanitize_file_name( $v ) { return (string) $v; }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function esc_url_raw( $v ) { return (string) $v; }
function wp_kses_post( $v ) { return (string) $v; }
function post_type_exists( $t ) { return true; }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function wp_generate_password( $n ) { return str_repeat( 'x', $n ); }
function get_page_by_path( $slug, $output, $types ) {
	global $posts;
	foreach ( $posts as $p ) {
		if ( $p['post_name'] === $slug && in_array( $p['post_type'], (array) $types, true ) ) return (object) $p;
	}
	return null;
}
function get_post( $id ) { global $posts; return isset( $posts[ $id ] ) ? (object) $posts[ $id ] : null; }
function wp_update_post( $args ) { global $posts, $writes; $writes[] = array( 'update', $args ); $posts[ $args['ID'] ] = array_merge( $posts[ $args['ID'] ], $args ); return $args['ID']; }
function update_post_meta( $id, $key, $value ) { global $writes; $writes[] = array( 'meta', $id, $key ); }
function wp_set_object_terms() {}
function get_sample_permalink( $id ) { global $posts; return array( 'https://example.test/%postname%/', $posts[ $id ]['post_name'] ); }
function wp_schedule_single_event( $time, $hook, $args ) { global $events; $events[] = array( $hook, $args ); }
function spawn_cron() {}
function get_term() { return null; }

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_REST_Response { public function __construct( public $data, public $status ) {} }
class WP_REST_Request {
	public function __construct( private string $method, private string $route, private string $body, private array $headers = array() ) {}
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_body() { return $this->body; }
	public function get_json_params() { return json_decode( $this->body, true ); }
	public function get_body_params() { return array(); }
	public function get_query_params() { return array(); }
	public function get_header( $name ) { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}
class Ratesight_Options { public static function get( string $key ) { return array( 'post_status' => 'publish', 'page_status' => 'publish' )[ $key ] ?? ''; } }
class Ratesight_Logger {
	const STATUS_PENDING = 'pending'; const STATUS_MODIFIED = 'modified'; const STATUS_FAILED = 'failed';
	public static function log_pending() { return 7; }
	public static function log_update() {}
	public static function log_error() {}
}
class Ratesight_Category_Handler { public function resolve() { return 0; } }
class Ratesight_Post_Creator {
	public function create( array $args ) {
		global $posts, $next_id;
		$id = $next_id++;
		$posts[ $id ] = array( 'ID' => $id, 'post_name' => $args['slug'], 'post_type' => $args['post_type'], 'post_status' => 'draft', 'post_title' => $args['title'], 'post_content' => $args['article'], 'post_excerpt' => $args['summary'] );
		return $id;
	}
}
class Ratesight_SEO_Writer { public function write( $id ) { global $writes; $writes[] = array( 'seo', $id ); } }
class Ratesight_Layout_Writer { public function write( $id ) { global $writes; $writes[] = array( 'layout', $id ); } }
class Ratesight_Title_Writer { public function write( $id ) { global $writes; $writes[] = array( 'title', $id ); } }
class Ratesight_Link_Manager { public static function reapply_manual_links() {} }
class Ratesight_Recovery_Log { public static $calls = 0; public static function log() { self::$calls++; return 'x'; } }

require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-webhook-handler.php';

$failures = 0;
$checks   = 0;
function check_draft_case( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function unsigned_create( array $payload ) {
	$request = new WP_REST_Request( 'POST', '/ratesight/v1/create-page', json_encode( $payload ) );
	$auth    = Ratesight_Request_Auth::authorize_mutation( $request );
	if ( $auth !== true ) return array( $auth, null );
	return array( true, ( new Ratesight_Webhook_Handler() )->handle_request( $request ) );
}
function signed_create( array $payload ) {
	$body    = json_encode( $payload );
	$request = new WP_REST_Request( 'POST', '/ratesight/v1/create-page', $body, array( 'x-ratesight-signature' => 'sha256=' . hash_hmac( 'sha256', $body, 'fixture-secret' ) ) );
	$auth    = Ratesight_Request_Auth::authorize_mutation( $request );
	return array( $auth, $auth === true ? ( new Ratesight_Webhook_Handler() )->handle_request( $request ) : null );
}
function last_event_status(): string { global $events; return (string) end( $events )[1][5]; }

foreach ( array( 'legacy', 'observe_v2' ) as $mode ) {
	$options['ratesight_auth_mode'] = $mode;
	$options['ratesight_unsigned_draft_window'] = array();
	$writes = array();

	[ $auth, $response ] = unsigned_create( array( 'title' => 'New Page', 'article' => '<p>x</p>', 'status' => 'publish' ) );
	check_draft_case( "{$mode}: unsigned create with status publish is accepted, not refused", $auth === true && $response->status === 200 && $response->data['created'] === true );
	check_draft_case( "{$mode}: requested publish is downgraded to draft for the deferred publisher", last_event_status() === 'draft' && $response->data['status'] === 'draft' && $response->data['unsigned_draft'] === true );
	check_draft_case( "{$mode}: response carries the audit request id", $response->data['request_id'] === $options['ratesight_auth_audit'][ array_key_last( $options['ratesight_auth_audit'] ) ]['request_id'] );
	foreach ( array( 'private', 'pending', 'future' ) as $asked ) {
		unsigned_create( array( 'title' => "Asked {$asked}", 'article' => 'x', 'status' => $asked ) );
		check_draft_case( "{$mode}: requested {$asked} is downgraded to draft", last_event_status() === 'draft' );
	}

	$writes = array();
	[ $auth, $response ] = unsigned_create( array( 'title' => 'Existing', 'slug' => 'existing-service', 'article' => '<p>overwrite attempt</p>', 'meta_title' => 'Hijack' ) );
	$new_id = $response->data['id'];
	check_draft_case( "{$mode}: an existing slug is never updated; a new draft is created instead", $response->data['created'] === true && $response->data['updated'] === false && $new_id !== 101 && $posts[101]['post_content'] === 'original' );
	check_draft_case( "{$mode}: the new draft gets a unique slug", $posts[ $new_id ]['post_name'] !== 'existing-service' && str_starts_with( $posts[ $new_id ]['post_name'], 'existing-service-' ) );
	$touched_other = false;
	foreach ( $writes as $w ) { if ( ( $w[0] === 'update' && $w[1]['ID'] !== $new_id ) || ( in_array( $w[0], array( 'seo', 'layout', 'title', 'meta' ), true ) && $w[1] !== $new_id ) ) $touched_other = true; }
	check_draft_case( "{$mode}: SEO meta and post writes touch only the new draft", ! $touched_other );

	$posts_before = count( $posts );
	[ $auth, $response ] = unsigned_create( array( 'title' => 'Target', 'article' => 'x', 'id' => 101 ) );
	check_draft_case( "{$mode}: a payload naming an explicit post id is refused", $response->status === 403 && $response->data['code'] === 'rs_unsigned_target_refused' && count( $posts ) === $posts_before && $posts[101]['post_content'] === 'original' );
	[ $auth, $response ] = unsigned_create( array( 'title' => 'Target', 'article' => 'x', 'post_id' => '101' ) );
	check_draft_case( "{$mode}: a payload naming post_id is refused", $response->status === 403 );

	$writes = array();
	unsigned_create( array( 'title' => 'Css', 'article' => 'x', 'custom_css_url' => 'https://elsewhere.test/a.css' ) );
	$css = array_filter( $writes, static fn( $w ) => $w[0] === 'meta' && $w[2] === '_rs_custom_css_url' );
	check_draft_case( "{$mode}: unsigned drafts cannot attach an external stylesheet", count( $css ) === 0 );
	check_draft_case( "{$mode}: unsigned drafts do not write the recovery log option", Ratesight_Recovery_Log::$calls === 0 );

	$handler_src = file_get_contents( __DIR__ . '/../includes/class-ratesight-webhook-handler.php' );
	$create_src  = substr( $handler_src, strpos( $handler_src, 'private function do_handle_request' ), strpos( $handler_src, '// end do_handle_request' ) - strpos( $handler_src, 'private function do_handle_request' ) );
	check_draft_case( "{$mode}: create-page sets no redirects, noindex or options", strpos( $create_src, 'ratesight_rs_redirects' ) === false && stripos( $create_src, 'noindex' ) === false && strpos( $create_src, 'update_option' ) === false );

	$options['ratesight_unsigned_draft_window'] = array_fill( 0, Ratesight_Request_Auth::UNSIGNED_DRAFT_LIMIT, time() );
	[ $auth ] = unsigned_create( array( 'title' => 'Limit', 'article' => 'x' ) );
	check_draft_case( "{$mode}: the 31st unsigned draft in 24h gets 429", $auth instanceof WP_Error && $auth->get_error_code() === 'rs_unsigned_draft_rate_limited' && $auth->get_error_data()['status'] === 429 );

	$options['ratesight_unsigned_draft_window'] = array();
	[ $auth, $response ] = signed_create( array( 'title' => 'Existing', 'slug' => 'existing-service', 'article' => '<p>signed update</p>', 'status' => 'publish' ) );
	check_draft_case( "{$mode}: a signed create-page keeps upsert and status behavior", $auth === true && $response->data['updated'] === true && $posts[101]['post_content'] === '<p>signed update</p>' && last_event_status() === 'publish' );
	$posts[101]['post_content'] = 'original';
}

$options['ratesight_auth_mode'] = 'enforce_v2';
$options['ratesight_auth_ever_enforced'] = true;
[ $auth ] = unsigned_create( array( 'title' => 'Enforced', 'article' => 'x' ) );
check_draft_case( 'enforce_v2: unsigned create-page is rejected', $auth instanceof WP_Error && $auth->get_error_code() === 'rs_auth_version_required' );

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
