<?php
/**
 * 3.14.2: every ratesight/v1 REST response (success and error) carries no-store
 * headers; other namespaces are untouched.
 */
if ( isset( $_SERVER['RS_NO_CACHE_SERVE'] ) || getenv( 'RS_NO_CACHE_SERVE' ) ) {
	// Built-in server mode: emulate a theme/caching plugin that already sent
	// cacheable headers, then run the rest_pre_serve_request hook.
	// add_filter / do_action below are declared at file scope, so they exist here too.
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	require __DIR__ . '/../includes/class-ratesight-rest-no-cache.php';
	$route = (string) ( $_GET['route'] ?? '' );
	header( 'Cache-Control: public, max-age=86400' );
	header( 'Expires: Thu, 01 Jan 2099 00:00:00 GMT' );
	header( 'ETag: "abc"' );
	header( 'Last-Modified: Mon, 01 Jan 2024 00:00:00 GMT' );
	header( 'Vary: Origin', false );
	$request = new class( $route ) { public function __construct( private string $r ) {} public function get_route() { return $this->r; } };
	Ratesight_Rest_No_Cache::reassert_headers( false, null, $request, null );
	header( 'Content-Type: application/json' );
	echo '{}';
	return;
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$filters = array();
$actions = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	global $filters;
	$filters[ $hook ][] = array( $callback, $priority, $args );
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return add_filter( $hook, $callback, $priority, $args ); }
function do_action( $hook, ...$args ) { global $actions; $actions[] = array( $hook, $args ); }
function rest_get_url_prefix() { return 'wp-json'; }

class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_HTTP_Response {
	public $data; public $status; public $headers = array();
	public function __construct( $data = null, $status = 200, $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; }
	public function get_headers() { return $this->headers; }
	public function set_headers( $headers ) { $this->headers = $headers; }
	public function header( $key, $value, $replace = true ) { $this->headers[ $key ] = $value; }
	public function get_status() { return $this->status; }
}
class WP_REST_Response extends WP_HTTP_Response {}
class WP_REST_Request {
	public function __construct( private string $method, private string $route ) {}
	public function get_route() { return $this->route; }
	public function get_method() { return $this->method; }
}

$registered = array();
function register_rest_route( $namespace, $path, $definitions ) {
	global $registered;
	$items = isset( $definitions['methods'] ) ? array( $definitions ) : $definitions;
	foreach ( $items as $definition ) {
		foreach ( (array) explode( ',', (string) $definition['methods'] ) as $method ) {
			$registered[] = array( trim( $method ), '/' . $namespace . $path );
		}
	}
}

require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-performance-snapshot.php';
require __DIR__ . '/../includes/class-ratesight-pairing.php';
require __DIR__ . '/../includes/class-ratesight-enrollment.php';
require __DIR__ . '/../includes/class-ratesight-release-update.php';
require __DIR__ . '/../includes/class-ratesight-webhook-handler.php';
require __DIR__ . '/../includes/class-ratesight-related-links.php';
require __DIR__ . '/../includes/class-ratesight-page-api.php';
require __DIR__ . '/../includes/class-ratesight-page-lifecycle.php';
require __DIR__ . '/../includes/class-ratesight-media-alt.php';
require __DIR__ . '/../includes/class-ratesight-crm-publish.php';
require __DIR__ . '/../includes/class-ratesight-indexnow.php';
require __DIR__ . '/../includes/class-ratesight-rest-no-cache.php';
( new Ratesight_Webhook_Handler() )->register_route();
Ratesight_Related_Links::register_routes();
( new Ratesight_Page_API() )->register_routes();
Ratesight_Page_Lifecycle::register_routes();
Ratesight_Media_Alt::register_routes();
Ratesight_CRM_Publish::register_routes();
Ratesight_IndexNow::register_routes();
Ratesight_Performance_Snapshot::register_routes();
Ratesight_Pairing::register_route();
Ratesight_Enrollment::register_route();
Ratesight_Release_Update::register_route();

$checks = 0;
$failures = 0;
function check( string $label, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$expected = array(
	'Cache-Control'                => 'no-store, no-cache, must-revalidate, max-age=0, private',
	'Pragma'                       => 'no-cache',
	'Expires'                      => '0',
	'CDN-Cache-Control'            => 'no-store',
	'Cloudflare-CDN-Cache-Control' => 'no-store',
	'Surrogate-Control'            => 'no-store',
);
$vary_required = array( 'authorization', 'x-ratesight-auth-version', 'x-ratesight-key-id', 'x-ratesight-timestamp', 'x-ratesight-nonce', 'x-ratesight-content-sha256', 'x-ratesight-signature', 'x-ratesight-pairing-signature' );

function cacheable_headers(): array {
	return array(
		'cache-control' => 'public, max-age=86400',
		'Expires'       => 'Thu, 01 Jan 2099 00:00:00 GMT',
		'ETag'          => '"abc"',
		'Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT',
		'Vary'          => 'Accept-Encoding',
		'X-WP-Total'    => '3',
	);
}

function headers_problems( array $headers ): array {
	global $expected, $vary_required;
	$problems = array();
	$lower = array();
	foreach ( $headers as $name => $value ) {
		$key = strtolower( $name );
		if ( isset( $lower[ $key ] ) ) $problems[] = "duplicate {$name}";
		$lower[ $key ] = $value;
	}
	foreach ( $expected as $name => $value ) {
		if ( ( $lower[ strtolower( $name ) ] ?? null ) !== $value ) $problems[] = "{$name}=" . var_export( $lower[ strtolower( $name ) ] ?? null, true );
	}
	foreach ( array( 'etag', 'last-modified' ) as $gone ) {
		if ( isset( $lower[ $gone ] ) ) $problems[] = "{$gone} still present";
	}
	$vary = array_map( 'trim', explode( ',', strtolower( (string) ( $lower['vary'] ?? '' ) ) ) );
	foreach ( array_merge( $vary_required, array( 'accept-encoding' ) ) as $token ) {
		if ( ! in_array( $token, $vary, true ) ) $problems[] = "Vary missing {$token}";
	}
	if ( ( $lower['x-wp-total'] ?? null ) !== '3' ) $problems[] = 'unrelated header lost';
	return $problems;
}

// Registration: all three hooks, last priority.
Ratesight_Rest_No_Cache::register();
foreach ( array( 'rest_send_nocache_headers', 'rest_post_dispatch', 'rest_pre_serve_request' ) as $hook ) {
	$entry = $filters[ $hook ][0] ?? null;
	check( "registers {$hook} at PHP_INT_MAX", $entry !== null && $entry[1] === PHP_INT_MAX && $entry[0][0] === 'Ratesight_Rest_No_Cache' );
}
$plugin = file_get_contents( __DIR__ . '/../ratesight.php' );
$reg_at = strpos( $plugin, 'Ratesight_Rest_No_Cache::register();' );
check( 'ratesight.php loads the class and registers it before the license gate', str_contains( $plugin, "'includes/class-ratesight-rest-no-cache.php'," ) && $reg_at !== false && $reg_at < strpos( $plugin, 'if ( ! Ratesight_License::is_valid() )' ) );

// Non-ratesight routes first, so the page-cache markers can be checked as untouched.
foreach ( array( '/wp/v2/posts', '/ratesightx/v1/capabilities', '/ratesight/v2/capabilities', '/ratesight', '/', '/oembed/1.0/embed', '/wp/v2/ratesight/v1' ) as $route ) {
	foreach ( array( 200, 401 ) as $status ) {
		$response = new WP_REST_Response( array(), $status, cacheable_headers() );
		$out = Ratesight_Rest_No_Cache::filter_response( $response, null, new WP_REST_Request( 'GET', $route ) );
		check( "untouched: {$route} ({$status})", $out === $response && $out->get_headers() === cacheable_headers() );
	}
}
check( 'no page-cache marker set for other namespaces', ! defined( 'DONOTCACHEPAGE' ) && ! defined( 'LSCACHE_NO_CACHE' ) && $actions === array() && ! isset( $filters['do_rocket_generate_caching_files'] ) );
check( 'rest_pre_serve_request leaves other routes alone', Ratesight_Rest_No_Cache::reassert_headers( false, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) ) === false );

// Every registered ratesight/v1 route, success and error statuses.
$routes = array();
foreach ( $registered as $pair ) {
	$routes[ $pair[0] . ' ' . $pair[1] ] = $pair;
}
check( 'every route in the auth policy table is exercised', ! array_diff( array_keys( Ratesight_Request_Auth::ROUTE_POLICIES ), array_keys( $routes ) ) );
$statuses = array( 200 => 'success', 201 => 'created', 400 => 'bad request', 401 => 'signature / permission failure', 403 => 'forbidden', 404 => 'not found', 409 => 'conflict', 429 => 'rate limited', 500 => 'server error' );
foreach ( $routes as $key => list( $method, $route ) ) {
	$bad = array();
	foreach ( $statuses as $status => $label ) {
		$data = $status >= 400 ? array( 'code' => 'rest_forbidden', 'message' => 'x', 'data' => array( 'status' => $status ) ) : array( 'ok' => true );
		$out = Ratesight_Rest_No_Cache::filter_response( new WP_REST_Response( $data, $status, cacheable_headers() ), null, new WP_REST_Request( $method, $route ) );
		foreach ( headers_problems( $out->get_headers() ) as $p ) $bad[] = "{$status}: {$p}";
		if ( $out->get_status() !== $status || $out->data !== $data ) $bad[] = "{$status}: body or status changed";
	}
	check( "no-store on {$key} (" . count( $statuses ) . ' statuses)' . ( $bad ? ' ' . implode( '; ', $bad ) : '' ), ! $bad );
}
check( 'at least 27 ratesight routes were exercised', count( $routes ) >= 27 );

// Unknown route in the namespace (rest_no_route) and the namespace index.
foreach ( array( '/ratesight/v1/does-not-exist', '/ratesight/v1', '/RateSight/V1/Capabilities' ) as $route ) {
	$out = Ratesight_Rest_No_Cache::filter_response( new WP_REST_Response( array( 'code' => 'rest_no_route' ), 404, cacheable_headers() ), null, new WP_REST_Request( 'GET', $route ) );
	check( "no-store on {$route}", ! headers_problems( $out->get_headers() ) );
}
// A response with no prior headers.
$out = Ratesight_Rest_No_Cache::filter_response( new WP_REST_Response( array(), 200, array() ), null, new WP_REST_Request( 'GET', '/ratesight/v1/capabilities' ) );
$h = $out->get_headers();
check( 'headers added to a bare response', ( $h['Cache-Control'] ?? '' ) === $expected['Cache-Control'] && str_contains( $h['Vary'] ?? '', 'Authorization' ) && str_contains( $h['Vary'] ?? '', 'X-Ratesight-Signature' ) );
check( 'non-response values pass through', Ratesight_Rest_No_Cache::filter_response( 'x', null, new WP_REST_Request( 'GET', '/ratesight/v1/capabilities' ) ) === 'x' );

check( 'page caches told to skip: DONOTCACHEPAGE and LSCACHE_NO_CACHE', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE === true && defined( 'LSCACHE_NO_CACHE' ) );
check( 'LiteSpeed no-cache action fired once', count( array_filter( $actions, fn( $a ) => $a[0] === 'litespeed_control_set_nocache' ) ) === 1 );
check( 'WP Rocket caching files disabled', ( $filters['do_rocket_generate_caching_files'][0][0] ?? null ) === '__return_false' );

// rest_send_nocache_headers (before dispatch, route read from the request).
$_SERVER['REQUEST_URI'] = '/wp-json/ratesight/v1/capabilities?x=1';
check( 'send_nocache true for /wp-json/ratesight/v1/*', Ratesight_Rest_No_Cache::filter_send_nocache_headers( false ) === true );
$_SERVER['REQUEST_URI'] = '/blog/wp-json/ratesight/v1/connection-status';
check( 'send_nocache true in a subdirectory install', Ratesight_Rest_No_Cache::filter_send_nocache_headers( false ) === true );
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
check( 'send_nocache unchanged for other namespaces', Ratesight_Rest_No_Cache::filter_send_nocache_headers( false ) === false && Ratesight_Rest_No_Cache::filter_send_nocache_headers( true ) === true );
$_GET['rest_route'] = '/ratesight/v1/inbound-log';
$_SERVER['REQUEST_URI'] = '/?rest_route=/ratesight/v1/inbound-log';
check( 'send_nocache true for ?rest_route=/ratesight/v1/*', Ratesight_Rest_No_Cache::filter_send_nocache_headers( false ) === true );
unset( $_GET['rest_route'] );

// Real HTTP: headers already sent by other code are replaced on the wire.
$port = 20000 + random_int( 0, 20000 );
$proc = proc_open( array( PHP_BINARY, '-S', "127.0.0.1:{$port}", __FILE__ ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes, null, array( 'RS_NO_CACHE_SERVE' => '1' ) );
$wire = null;
for ( $i = 0; $i < 50 && $wire === null; $i++ ) {
	usleep( 100000 );
	$ctx = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 2 ) ) );
	$wire_ok = @file_get_contents( "http://127.0.0.1:{$port}/?route=/ratesight/v1/capabilities", false, $ctx );
	if ( $wire_ok !== false ) {
		$wire = array();
		foreach ( array_slice( $http_response_header, 1 ) as $line ) {
			list( $n, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$wire[ strtolower( $n ) ][] = $v;
		}
		$other = @file_get_contents( "http://127.0.0.1:{$port}/?route=/wp/v2/posts", false, $ctx );
		$other_headers = $http_response_header;
	}
}
proc_terminate( $proc );
proc_close( $proc );
check( 'built-in server reachable', $wire !== null );
if ( $wire !== null ) {
	$wire_bad = array();
	foreach ( $expected as $name => $value ) {
		if ( ( $wire[ strtolower( $name ) ] ?? array() ) !== array( $value ) ) $wire_bad[] = $name . '=' . json_encode( $wire[ strtolower( $name ) ] ?? null );
	}
	if ( isset( $wire['etag'] ) || isset( $wire['last-modified'] ) ) $wire_bad[] = 'validators still sent';
	$wire_vary = strtolower( implode( ',', $wire['vary'] ?? array() ) );
	foreach ( $vary_required as $t ) if ( ! str_contains( $wire_vary, $t ) ) $wire_bad[] = "Vary missing {$t}";
	if ( ! str_contains( $wire_vary, 'origin' ) ) $wire_bad[] = 'Vary lost Origin';
	check( 'on the wire: cacheable headers sent earlier are replaced' . ( $wire_bad ? ' ' . implode( '; ', $wire_bad ) : '' ), ! $wire_bad );
	check( 'on the wire: other namespace keeps its headers', in_array( 'Cache-Control: public, max-age=86400', $other_headers, true ) );
}

echo PHP_EOL . "{$checks} checks, {$failures} failure(s)" . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
