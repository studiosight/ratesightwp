<?php
/**
 * 3.15.1: the hourly retry of a stuck deferred publish (Ratesight::retry_pending_posts)
 * applies the status the request asked for (post meta _rs_request_status) before the
 * site setting, so an unsigned draft is never published by the retry. Drives the real
 * method and the real Ratesight_Publisher::resolve_final_status against in-memory stubs.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'RATESIGHT_LOG_TABLE', 'ratesight_logs' );
define( 'ARRAY_A', 'ARRAY_A' );

$posts   = array();
$meta    = array();
$updates = array();
$logs    = array();
$site    = array( 'post_status' => 'publish', 'page_status' => 'publish' );

class WP_Error { public function get_error_message() { return 'x'; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_post( $id ) { global $posts; return isset( $posts[ $id ] ) ? (object) $posts[ $id ] : null; }
function get_post_type( $id ) { global $posts; return $posts[ $id ]['post_type'] ?? false; }
function get_post_meta( $id, $key, $single = false ) { global $meta; return $meta[ $id ][ $key ] ?? ''; }
function wp_update_post( $args, $wp_error = false ) { global $posts, $updates; $updates[] = $args; $posts[ $args['ID'] ]['post_status'] = $args['post_status']; return $args['ID']; }
class Ratesight_Options { public static function get( string $key ) { global $site; return $site[ $key ] ?? ''; } }
class Ratesight_Logger {
	const STATUS_SUCCESS = 'success'; const STATUS_FAILED = 'failed';
	public static function log_update( $log_id, $post_id, $status, $note ) { global $logs; $logs[ $post_id ] = array( $status, $note ); }
}
class Ratesight_Loader {}
class Stub_Wpdb {
	public string $prefix = 'wp_';
	public array $rows = array();
	public function get_results( $sql, $output ) { return $this->rows; }
}
$wpdb = new Stub_Wpdb();

require __DIR__ . '/../includes/class-ratesight-publisher.php';
require __DIR__ . '/../includes/class-ratesight.php';

$failures = 0;
$checks   = 0;
function check_retry_case( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function run_retry( array $cases ): void {
	global $posts, $meta, $updates, $logs, $wpdb;
	$posts = array(); $meta = array(); $updates = array(); $logs = array(); $wpdb->rows = array();
	foreach ( $cases as $id => $case ) {
		$posts[ $id ] = array( 'ID' => $id, 'post_status' => $case['status'] ?? 'draft', 'post_type' => $case['type'] ?? 'post' );
		if ( isset( $case['requested'] ) ) $meta[ $id ]['_rs_request_status'] = $case['requested'];
		$wpdb->rows[] = array( 'id' => 1000 + $id, 'post_id' => $id );
	}
	( new ReflectionClass( 'Ratesight' ) )->newInstanceWithoutConstructor()->retry_pending_posts();
}
function updated_ids(): array { global $updates; return array_map( static fn( $u ) => $u['ID'], $updates ); }

// Site setting: Published.
run_retry( array(
	1 => array( 'requested' => 'draft' ),                       // unsigned draft, deferred publish never ran
	2 => array(),                                              // signed request, no status asked
	3 => array( 'requested' => 'publish' ),
	4 => array( 'requested' => 'private' ),
	5 => array( 'requested' => 'not-a-status' ),                // unknown meta is ignored, not trusted
	6 => array( 'requested' => 'draft', 'type' => 'ratesight_page' ),
	7 => array( 'status' => 'publish' ),                        // already live
) );
check_retry_case( 'a stuck post that asked for draft (every unsigned draft) is not published by the retry', $posts[1]['post_status'] === 'draft' && ! in_array( 1, updated_ids(), true ) );
check_retry_case( 'its log row is resolved, with a note that it was kept as draft', $logs[1][0] === 'success' && str_contains( $logs[1][1], 'kept as draft' ) );
check_retry_case( 'a stuck post with no requested status gets the site Final Post Status', $posts[2]['post_status'] === 'publish' && $logs[2][0] === 'success' );
check_retry_case( 'a stuck post that asked for publish is published', $posts[3]['post_status'] === 'publish' );
check_retry_case( 'a stuck post that asked for private becomes private', $posts[4]['post_status'] === 'private' );
check_retry_case( 'an unknown stored status falls back to the site setting', $posts[5]['post_status'] === 'publish' );
check_retry_case( 'a stuck reference page that asked for draft stays a draft', $posts[6]['post_status'] === 'draft' && ! in_array( 6, updated_ids(), true ) );
check_retry_case( 'a post that is already live is only marked resolved', ! in_array( 7, updated_ids(), true ) && $logs[7][0] === 'success' );

// Site setting: Draft for posts, Private for reference pages.
$site = array( 'post_status' => 'draft', 'page_status' => 'private' );
run_retry( array(
	1 => array(),
	2 => array( 'requested' => 'publish' ),
	3 => array( 'type' => 'ratesight_page' ),
) );
check_retry_case( 'site setting Draft: a stuck post with no requested status stays a draft', $posts[1]['post_status'] === 'draft' && ! in_array( 1, updated_ids(), true ) && $logs[1][0] === 'success' );
check_retry_case( 'site setting Draft: a request that asked for publish is published', $posts[2]['post_status'] === 'publish' );
check_retry_case( 'reference pages use the Reference Page Status', $posts[3]['post_status'] === 'private' );

// The create-page handler stores the status the retry reads.
$handler = file_get_contents( __DIR__ . '/../includes/class-ratesight-webhook-handler.php' );
check_retry_case( 'create-page stores the per-request status on the post', strpos( $handler, "update_post_meta( \$post_id, '_rs_request_status', \$request_status );" ) !== false );
check_retry_case( 'the stored status is listed in the installation inventory', strpos( file_get_contents( __DIR__ . '/../includes/class-ratesight-installation.php' ), "'_rs_request_status'" ) !== false );

// Link Domain Rules no longer share a settings group with the AI SEO Pages tab.
$options_source = file_get_contents( __DIR__ . '/../includes/class-ratesight-options.php' );
preg_match_all( "/'name'\s*=>\s*'([a-z_]+)',[^\n]*'group'\s*=>\s*'([a-z_]+)'/", $options_source, $rows, PREG_SET_ORDER );
$groups = array();
foreach ( $rows as $row ) $groups[ $row[2] ][] = $row[1];
$links_form = file_get_contents( __DIR__ . '/../admin/partials/tab-links.php' );
$seo_form   = file_get_contents( __DIR__ . '/../admin/partials/tab-seo-pages.php' );
check_retry_case( 'the links settings group holds exactly the two domain lists', ( $groups['links'] ?? array() ) === array( 'ratesight_link_approved_domains', 'ratesight_link_excluded_domains' ) );
check_retry_case( 'the Link Domain Rules form saves the links group, not the AI SEO Pages group', str_contains( $links_form, "settings_fields( 'ratesight_options_links' )" ) && ! str_contains( $links_form, "settings_fields( 'ratesight_options_seo_pages' )" ) );
$links_complete = true;
foreach ( $groups['links'] as $name ) { if ( ! str_contains( $links_form, 'name="' . $name . '"' ) ) $links_complete = false; }
check_retry_case( 'the Link Domain Rules form carries every option of its group', $links_complete );
$seo_missing = array();
foreach ( $groups['seo_pages'] as $name ) {
	// A plain input/select, or a wp_dropdown_* call that names the field.
	if ( ! str_contains( $seo_form, 'name="' . $name . '"' ) && ! preg_match( "/'name'\s*=>\s*'" . $name . "'/", $seo_form ) ) $seo_missing[] = $name;
}
check_retry_case( 'the AI SEO Pages form carries every option of its group (none is reset on save): missing ' . json_encode( $seo_missing ), $seo_missing === array() );
check_retry_case( 'exactly one form saves the AI SEO Pages group', substr_count( $seo_form . $links_form, "settings_fields( 'ratesight_options_seo_pages' )" ) === 1 );

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
