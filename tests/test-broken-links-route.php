<?php
/**
 * GET /broken-links: signed read of the stored outbound broken-link results (ratesight_link_cache).
 * Read only, bounded, no post content and no outbound link map.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
const ARRAY_A = 'ARRAY_A';
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_Error { public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {} }
class WP_REST_Response { public function __construct( public $data, public $status ) {} }
$options = array( 'ratesight_link_last_scan' => '2026-11-04 09:30:00' );
$writes  = array();
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value ) { global $writes; $writes[] = $name; return true; }
function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function get_gmt_from_date( $value, $format ) { return gmdate( $format, strtotime( $value . ' UTC' ) + 6 * 3600 ); }
$registered = array();
function register_rest_route( $namespace, $path, $definitions ) {
	global $registered;
	foreach ( $definitions as $definition ) $registered[ $definition['methods'] . ' /' . $namespace . $path ] = $definition['permission_callback'];
}

class Fake_WPDB {
	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';
	public array $queries = array();
	public $counts = array( 'scanned' => '12', 'checked' => '10', 'pending' => '2' );
	public array $rows = array();
	public function get_row( $sql, $mode ) { $this->queries[] = $sql; return $this->counts; }
	public function get_results( $sql, $mode ) { $this->queries[] = $sql; return $this->rows; }
	public function __call( $name, $args ) { $this->queries[] = 'WRITE:' . $name; return false; }
}
$wpdb = new Fake_WPDB();
require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-link-manager.php';

$failures = 0;
$checks   = 0;
function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

Ratesight_Link_Manager::register_routes();
check( 'registers GET /broken-links as a signed read only', $registered === array( 'GET /ratesight/v1/broken-links' => array( 'Ratesight_Request_Auth', 'authorize_read' ) ) );
check( 'route policy table lists it as signed_read', ( Ratesight_Request_Auth::ROUTE_POLICIES['GET /ratesight/v1/broken-links'] ?? '' ) === 'signed_read' );

$wpdb->rows = array(
	array( 'post_id' => '7', 'post_title' => 'Moving tips', 'post_type' => 'post', 'broken_urls' => json_encode( array(
		array( 'url' => 'https://gone.example/a', 'anchor' => 'guide', 'code' => 404, 'status' => 'broken', 'checked_at' => '2026-11-04 10:00:00' ),
		array( 'url' => 'https://shield.example/b', 'anchor' => 'rates', 'code' => 403, 'status' => 'possibly_blocked', 'checked_at' => '2026-11-04 11:00:00' ),
		array( 'url' => 'https://ok.example/c', 'code' => 404, 'status' => 'ignored' ),
		array( 'url' => 'https://old.example/d', 'code' => 404, 'status' => 'replaced' ),
		array( 'url' => 'mailto:a@example.test', 'code' => 0, 'status' => 'broken' ),
	) ) ),
	array( 'post_id' => '9', 'post_title' => 'Only ignored', 'post_type' => 'page', 'broken_urls' => json_encode( array( array( 'url' => 'https://x.example', 'status' => 'ignored' ) ) ) ),
	array( 'post_id' => '11', 'post_title' => 'Bad json', 'post_type' => 'page', 'broken_urls' => '{oops' ),
);
$response = Ratesight_Link_Manager::handle_broken_links_get( null );
$data     = $response->data;
check( 'answers 200 ok', $response->status === 200 && $data['ok'] === true );
check( 'totals: scanned, checked, pending from the cache; broken, possibly blocked and ignored from the rows', $data['totals'] === array( 'pages_scanned' => 12, 'pages_checked' => 10, 'pages_pending' => 2, 'pages_with_issues' => 1, 'broken' => 1, 'possibly_blocked' => 1, 'ignored' => 2 ) );
check( 'lists only pages with a broken or possibly blocked target', count( $data['pages'] ) === 1 && $data['pages'][0]['post_id'] === 7 && $data['pages'][0]['title'] === 'Moving tips' );
check( 'each target carries url, code and status; replaced, ignored and mailto are left out', array_column( $data['pages'][0]['links'], 'status' ) === array( 'broken', 'possibly_blocked' ) && $data['pages'][0]['links'][0]['code'] === 404 && $data['pages'][0]['links'][1]['code'] === 403 );
check( 'page url and WordPress edit link', $data['pages'][0]['url'] === 'https://example.test/?p=7' && $data['pages'][0]['edit_url'] === 'https://example.test/wp-admin/post.php?post=7&action=edit' );
check( 'times are UTC ISO 8601 (site time converted)', $data['last_scan'] === '2026-11-04T15:30:00Z' && $data['last_checked'] === '2026-11-04T17:00:00Z' );
check( 'links to the WordPress Links tab where ignore, replace and unlink live', $data['manage_url'] === 'https://example.test/wp-admin/admin.php?page=ratesight&tab=links&lf=broken' );
check( 'not truncated under the caps', $data['truncated'] === false && $data['max_pages'] === 200 );
check( 'no post content or outbound link map in the answer', ! preg_match( '/post_content|outbound_urls|"outbound"/', json_encode( $data ) ) );
check( 'read only: only SELECT queries, no option writes', $writes === array() && count( array_filter( $wpdb->queries, fn( $q ) => ! str_starts_with( ltrim( $q ), 'SELECT' ) ) ) === 0 );
check( 'only published posts are read', count( array_filter( $wpdb->queries, fn( $q ) => str_contains( $q, "p.post_status = 'publish'" ) ) ) === 2 );

// Caps: 250 pages with issues, one page with 60 broken targets.
$many = array();
for ( $i = 1; $i <= 250; $i++ ) {
	$targets = array();
	for ( $j = 0; $j < ( $i === 1 ? 60 : 1 ); $j++ ) $targets[] = array( 'url' => "https://gone.example/{$i}/{$j}", 'code' => 0, 'status' => 'broken' );
	$many[] = array( 'post_id' => (string) $i, 'post_title' => "Page {$i}", 'post_type' => 'post', 'broken_urls' => json_encode( $targets ) );
}
$capped = Ratesight_Link_Manager::broken_links_summary( array( 'scanned' => 300, 'checked' => 300, 'pending' => 0 ), $many, '' );
check( 'caps the page list at 200 and says truncated', count( $capped['pages'] ) === 200 && $capped['truncated'] === true );
check( 'caps targets per page at 50 but totals count every target', count( $capped['pages'][0]['links'] ) === 50 && $capped['totals']['broken'] === 309 && $capped['totals']['pages_with_issues'] === 250 );
check( 'never scanned: last_scan null, not a made-up time', $capped['last_scan'] === null && $capped['last_checked'] === null );

$wpdb->counts = null;
$missing = Ratesight_Link_Manager::handle_broken_links_get( null );
check( 'unreadable link cache answers an error, not zeros', $missing instanceof WP_Error && $missing->code === 'rs_link_cache_unavailable' && $missing->data['status'] === 503 );

echo $failures ? "{$failures} OF {$checks} CHECKS FAILED\n" : "ALL {$checks} CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
