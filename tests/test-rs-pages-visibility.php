<?php
/**
 * 3.15.7: standalone tests for the rs_pages visibility hold decision cores and
 * the restore-page prior-status fix. No WordPress required:
 *   php tests/test-rs-pages-visibility.php
 * The end-to-end proof (real WordPress, real REST stack, signed requests) is
 * the disposable WordPress harness on gp (agent-scratch/claude-takeover-20261012/wp-harness-20261013:
 * bootstrap.sh, then run.sh <plugin-worktree>); see the PR for its results.
 * Exit code 0 = all pass, 1 = failures.
 *
 * @package Ratesight
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'OBJECT', 'OBJECT' );

class WP_REST_Server { const CREATABLE = 'POST'; }
class WP_REST_Request {
	public function __construct( private array $json = array() ) {}
	public function get_json_params() { return $this->json; }
	public function get_body_params() { return array(); }
}
class WP_REST_Response {
	public function __construct( public $data = null, public $status = 200 ) {}
}
class WP_Error {}
class WP_Post {
	public $ID; public $post_type; public $post_status; public $post_name = ''; public $post_title = ''; public $post_content = ''; public $post_excerpt = ''; public $post_content_filtered = ''; public $post_parent = 0;
	public function __construct( array $f ) { foreach ( $f as $k => $v ) $this->$k = $v; }
}
class Ratesight_Logger {
	const STATUS_MODIFIED = 'modified';
	public static function log_pending( ...$a ) { return 1; }
	public static function log_update( ...$a ) {}
}

// Minimal filter API and the core behaviour of wp_untrash_post() since WP 5.6.
$filters = array();
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { global $filters; $filters[ $hook ][] = array( $cb, $prio ); return true; }
function remove_filter( $hook, $cb, $prio = 10 ) {
	global $filters;
	foreach ( $filters[ $hook ] ?? array() as $i => $f ) if ( $f[0] === $cb ) unset( $filters[ $hook ][ $i ] );
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	global $filters;
	foreach ( $filters[ $hook ] ?? array() as $f ) $value = ( $f[0] )( $value, ...$args );
	return $value;
}
$posts = array();
$meta  = array();
function get_post( $id ) { global $posts; return $posts[ (int) $id ] ?? null; }
function get_the_title( $id ) { global $posts; return $posts[ (int) $id ]->post_title ?? ''; }
function get_post_meta( $id, $key, $single = false ) { global $meta; return $meta[ (int) $id ][ $key ] ?? ''; }
function get_post_status( $id ) { global $posts; return $posts[ (int) $id ]->post_status ?? false; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_untrash_post( $id ) {
	global $posts, $meta;
	$previous = $meta[ $id ]['_wp_trash_meta_status'] ?? '';
	$new      = apply_filters( 'wp_untrash_post_status', 'draft', $id, $previous ); // core default since 5.6
	$posts[ $id ]->post_status = $new;
	return $posts[ $id ];
}

function metadata_exists( $type, $id, $key ) { global $meta; return isset( $meta[ (int) $id ][ $key ] ); }
function delete_post_meta( $id, $key ) { global $meta; unset( $meta[ (int) $id ][ $key ] ); return true; }
$opts = array();
function get_option( $k, $d = false ) { global $opts; return $opts[ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { global $opts; $opts[ $k ] = $v; return true; }
require_once __DIR__ . '/../includes/class-ratesight-page-lifecycle.php';

$failures = 0;
$checks   = 0;
function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
$L = 'Ratesight_Page_Lifecycle';

// ── intent: exactly one of dry_run / confirm ─────────────────────────────────
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only' ), true );
check( 'intent: neither dry_run nor confirm is a 428', $r['ok'] === false && $r['status'] === 428 );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'dry_run' => true, 'confirm' => true ), true );
check( 'intent: both dry_run and confirm is a 422', $r['ok'] === false && $r['status'] === 422 );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'dry_run' => true ), true );
check( 'intent: dry_run alone is a preview', $r['ok'] === true && $r['dry_run'] === true && $r['ids'] === null );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true ), true );
check( 'intent: confirm alone is a write over every eligible page', $r['ok'] === true && $r['dry_run'] === false && $r['ids'] === null );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => 'yes-please' ), true );
check( 'intent: non-boolean confirm is refused', $r['ok'] === false && $r['status'] === 428 );

// ── intent: reason ───────────────────────────────────────────────────────────
$r = $L::parse_visibility_intent( array( 'confirm' => true ), true );
check( 'reason: unpublish without reason is a 422', $r['ok'] === false && $r['status'] === 422 );
$r = $L::parse_visibility_intent( array( 'confirm' => true, 'reason' => 'departed' ), true );
check( 'reason: unpublish with another reason is a 422', $r['ok'] === false && $r['status'] === 422 );
$r = $L::parse_visibility_intent( array( 'confirm' => true ), false );
check( 'reason: republish needs no reason', $r['ok'] === true && $r['reason'] === null );

// ── intent: ids ──────────────────────────────────────────────────────────────
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true, 'ids' => array( 3, '4', 3 ) ), true );
check( 'ids: ints and digit strings, de-duplicated', $r['ok'] === true && $r['ids'] === array( 3, 4 ) );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true, 'ids' => array() ), true );
check( 'ids: an empty list never means "all"', $r['ok'] === false && $r['status'] === 422 );
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true, 'ids' => '5' ), true );
check( 'ids: a scalar is refused', $r['ok'] === false && $r['status'] === 422 );
foreach ( array( 0, -2, 'abc', 1.5, null, array( 1 ) ) as $bad ) {
	$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true, 'ids' => array( 7, $bad ) ), true );
	check( 'ids: invalid entry ' . json_encode( $bad ) . ' refuses the whole request', $r['ok'] === false && $r['status'] === 422 );
}
$r = $L::parse_visibility_intent( array( 'reason' => 'hosting_only', 'confirm' => true, 'ids' => range( 1, 1001 ) ), true );
check( 'ids: more than 200 is refused', $r['ok'] === false && $r['status'] === 422 );

// ── per-item decisions ───────────────────────────────────────────────────────
$d = $L::decide_visibility( 'unpublish', 'ratesight_page', 'publish', null );
check( 'unpublish: published ratesight_page goes to draft', $d === array( 'change' => true, 'result' => 'unpublished', 'to' => 'draft' ) );
foreach ( array( 'draft', 'private', 'pending', 'future', 'trash' ) as $status ) {
	$d = $L::decide_visibility( 'unpublish', 'ratesight_page', $status, null );
	check( "unpublish: {$status} ratesight_page is skipped", $d['change'] === false && $d['result'] === 'skipped' && $d['to'] === $status );
}
foreach ( array( 'page', 'post', 'attachment', null ) as $type ) {
	$d = $L::decide_visibility( 'unpublish', $type, 'publish', null );
	check( 'unpublish: ' . json_encode( $type ) . ' is refused as not_ratesight_page', $d['change'] === false && $d['result'] === 'not_ratesight_page' && $d['to'] === null );
}
$hold = array( 'prior_status' => 'publish', 'reason' => 'hosting_only', 'at' => '2026-10-13T00:00:00Z' );
$d = $L::decide_visibility( 'republish', 'ratesight_page', 'draft', $hold );
check( 'republish: held draft returns to its prior status', $d === array( 'change' => true, 'result' => 'republished', 'to' => 'publish' ) );
$d = $L::decide_visibility( 'republish', 'ratesight_page', 'draft', null );
check( 'republish: a draft without a hold is skipped (never publishes an ordinary draft)', $d['change'] === false && $d['result'] === 'skipped' );
$d = $L::decide_visibility( 'republish', 'ratesight_page', 'publish', $hold );
check( 'republish: an already published page is skipped', $d['change'] === false && $d['result'] === 'skipped' );
$d = $L::decide_visibility( 'republish', 'ratesight_page', 'draft', array( 'prior_status' => 'future' ) );
check( 'republish: a hold with an unexpected prior status is skipped', $d['change'] === false && $d['result'] === 'skipped' );
$d = $L::decide_visibility( 'republish', 'page', 'draft', $hold );
check( 'republish: a normal page is refused as not_ratesight_page', $d['result'] === 'not_ratesight_page' );

// ── history log: append-only, capped ────────────────────────────────────────
$log = $L::append_visibility_log( 'garbage', array( array( 'n' => 1 ) ) );
check( 'log: non-array stored value starts a fresh log', $log === array( array( 'n' => 1 ) ) );
$log = array();
for ( $i = 0; $i < 1003; $i++ ) $log = $L::append_visibility_log( $log, array( array( 'n' => $i ) ) );
check( 'log: capped at the newest 1000 entries', count( $log ) === 1000 && $log[0]['n'] === 3 && $log[999]['n'] === 1002 );

// ── restore-page returns the post to its pre-trash status ───────────────────
$posts[ 10 ] = new WP_Post( array( 'ID' => 10, 'post_type' => 'ratesight_page', 'post_status' => 'trash', 'post_title' => 'A' ) );
$meta[ 10 ]  = array( '_wp_trash_meta_status' => 'publish' );
$res = $L::handle_restore( new WP_REST_Request( array( 'id' => 10, 'confirm' => true ) ) );
check( 'restore: a published post comes back published, not draft', $res->status === 200 && $res->data['status_after'] === 'publish' && $posts[10]->post_status === 'publish' );
check( 'restore: the untrash and field-pin filters are removed after the request', empty( $filters['wp_untrash_post_status'] ) && empty( $filters['wp_insert_post_data'] ) );

$posts[ 11 ] = new WP_Post( array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'trash', 'post_title' => 'B' ) );
$meta[ 11 ]  = array( '_wp_trash_meta_status' => 'private' );
$res = $L::handle_restore( new WP_REST_Request( array( 'id' => 11, 'confirm' => true ) ) );
check( 'restore: a private post comes back private', $res->data['status_after'] === 'private' );

$posts[ 12 ] = new WP_Post( array( 'ID' => 12, 'post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'C' ) );
$meta[ 12 ]  = array();
$res = $L::handle_restore( new WP_REST_Request( array( 'id' => 12, 'confirm' => true ) ) );
check( 'restore: no recorded prior status falls back to the core default (draft)', $res->data['status_after'] === 'draft' );

$posts[ 13 ] = new WP_Post( array( 'ID' => 13, 'post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'D' ) );
$meta[ 13 ]  = array( '_wp_trash_meta_status' => 'publish' );
wp_untrash_post( 13 );
check( 'restore: the filter does not leak into other untrash calls', $posts[13]->post_status === 'draft' );


// ── stale holds: a status change made elsewhere clears the hold ──────────────
$hold_json = '{"prior_status":"publish","reason":"hosting_only","at":"2026-10-13T00:00:00Z"}';
$posts[ 20 ] = new WP_Post( array( 'ID' => 20, 'post_type' => 'ratesight_page', 'post_status' => 'publish', 'post_name' => 'held' ) );
$meta[ 20 ]  = array( '_ratesight_visibility_hold' => $hold_json );
$L::on_transition_post_status( 'draft', 'draft', $posts[20] );
check( 'transition: draft -> draft keeps the hold', isset( $meta[20]['_ratesight_visibility_hold'] ) );
$L::on_transition_post_status( 'publish', 'draft', $posts[20] );
$last = end( $opts['ratesight_rs_pages_visibility_log'] );
check( 'transition: an outside publish clears the hold and logs hold_cleared', ! isset( $meta[20]['_ratesight_visibility_hold'] ) && $last['action'] === 'hold_cleared' && $last['post_id'] === 20 && $last['from'] === 'draft' && $last['to'] === 'publish' && $last['reason'] === 'hosting_only' );
$meta[ 21 ] = array( '_ratesight_visibility_hold' => $hold_json );
$L::on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 21, 'post_type' => 'page', 'post_status' => 'publish' ) ) );
check( 'transition: other post types are ignored', isset( $meta[21]['_ratesight_visibility_hold'] ) );
$meta[ 22 ] = array( '_ratesight_visibility_hold' => $hold_json );
$L::on_transition_post_status( 'trash', 'draft', new WP_Post( array( 'ID' => 22, 'post_type' => 'ratesight_page', 'post_status' => 'trash' ) ) );
check( 'transition: trashing a held page keeps the hold (restore returns it to a held draft)', isset( $meta[22]['_ratesight_visibility_hold'] ) );
$L::on_transition_post_status( 'pending', 'draft', new WP_Post( array( 'ID' => 22, 'post_type' => 'ratesight_page', 'post_status' => 'pending' ) ) );
check( 'transition: an outside move to pending clears the hold', ! isset( $meta[22]['_ratesight_visibility_hold'] ) );

// ── the other publish paths and the 404 router check the hold first ─────────
$src = static fn( string $f ): string => (string) file_get_contents( __DIR__ . '/../includes/' . $f );
$core = $src( 'class-ratesight.php' );
$retry = substr( $core, strpos( $core, 'public function retry_pending_posts' ) );
check( 'retry_pending_posts skips a held post before any status write', strpos( $retry, 'Ratesight_Page_Lifecycle::has_hold( $post_id )' ) !== false && strpos( $retry, 'Ratesight_Page_Lifecycle::has_hold( $post_id )' ) < strpos( $retry, 'wp_update_post(' ) );
check( 'the transition hook is registered', str_contains( $core, "array( 'Ratesight_Page_Lifecycle',      'on_transition_post_status'       ), 5, 3 )" ) );
$pub = $src( 'class-ratesight-publisher.php' );
$run = substr( $pub, strpos( $pub, 'public function run(' ) );
check( 'deferred publisher returns for a held post before image attach and status write', strpos( $run, 'Ratesight_Page_Lifecycle::has_hold( $post_id )' ) !== false && strpos( $run, 'Ratesight_Page_Lifecycle::has_hold( $post_id )' ) < strpos( $run, 'Ratesight_Image_Uploader' ) );
$router = $src( 'class-ratesight-runtime-404-router.php' );
$route = substr( $router, strpos( $router, 'public static function maybe_route' ) );
check( '404 router returns for a held slug before any routing decision', strpos( $route, 'Ratesight_Page_Lifecycle::slug_is_held(' ) !== false && strpos( $route, 'Ratesight_Page_Lifecycle::slug_is_held(' ) < strpos( $route, 'self::route_decision(' ) );


// ── run lock: atomic INSERT IGNORE, token-checked release, conditional takeover ─
class Fake_Lock_Wpdb {
	public $options = 'wp_options';
	public $rows_affected = 0;
	public array $rows = array();
	public $after_select = null;
	public function prepare( $sql, ...$args ) { return json_encode( array( $sql, $args ) ); }
	public function query( $q ) {
		[ $sql, $a ] = json_decode( $q, true );
		$this->rows_affected = 0;
		if ( str_starts_with( $sql, 'INSERT IGNORE INTO wp_options' ) ) {
			if ( ! array_key_exists( $a[0], $this->rows ) ) { $this->rows[ $a[0] ] = $a[1]; $this->rows_affected = 1; }
		} elseif ( str_starts_with( $sql, 'UPDATE wp_options SET option_value = %s WHERE option_name = %s AND option_value = %s' ) ) {
			if ( ( $this->rows[ $a[1] ] ?? null ) === $a[2] ) { $this->rows[ $a[1] ] = $a[0]; $this->rows_affected = 1; }
		} elseif ( str_starts_with( $sql, 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s' ) ) {
			if ( ( $this->rows[ $a[0] ] ?? null ) === $a[1] ) { unset( $this->rows[ $a[0] ] ); $this->rows_affected = 1; }
		} else {
			throw new RuntimeException( 'unexpected SQL ' . $sql );
		}
		return $this->rows_affected;
	}
	public function get_var( $q ) {
		[ $sql, $a ] = json_decode( $q, true );
		$v = $this->rows[ $a[0] ] ?? null;
		if ( $this->after_select ) { ( $this->after_select )( $this ); $this->after_select = null; }
		return $v;
	}
}
$wpdb = new Fake_Lock_Wpdb();
$K = 'ratesight_rs_pages_visibility_lock';
$t1 = $L::acquire_visibility_lock();
check( 'lock: first caller acquires (INSERT IGNORE inserted the row)', is_string( $t1 ) && $wpdb->rows[ $K ] === $t1 && preg_match( '/^\d{10}\|[a-f0-9]{32}$/', $t1 ) );
check( 'lock: a second caller is busy while it is held', $L::acquire_visibility_lock() === null && $wpdb->rows[ $K ] === $t1 );
check( 'lock: release with a different token removes nothing', $L::release_visibility_lock( '1|not-mine' ) === false && $wpdb->rows[ $K ] === $t1 );
check( 'lock: release with its own token removes it', $L::release_visibility_lock( $t1 ) === true && ! isset( $wpdb->rows[ $K ] ) );
$wpdb->rows[ $K ] = ( time() - 5 ) . '|oldtoken';
$t2 = $L::acquire_visibility_lock();
check( 'lock: an expired lock is taken over with a conditional UPDATE', is_string( $t2 ) && $wpdb->rows[ $K ] === $t2 );
check( 'lock: the run whose lock expired cannot release the new owner', $L::release_visibility_lock( ( time() - 5 ) . '|oldtoken' ) === false && $wpdb->rows[ $K ] === $t2 );
$wpdb->rows[ $K ] = ( time() - 5 ) . '|stale';
$wpdb->after_select = static function ( $db ) use ( $K ) { $db->rows[ $K ] = ( time() + 300 ) . '|racer'; };
check( 'lock: of two callers that saw the same expired lock, the second loses', $L::acquire_visibility_lock() === null && str_ends_with( $wpdb->rows[ $K ], '|racer' ) );
$wpdb->rows[ $K ] = (string) ( time() - 1 );
check( 'lock: a legacy integer value that expired is taken over too', is_string( $L::acquire_visibility_lock() ) );
unset( $GLOBALS['wpdb'] );

// ── cache purge: per post inside the loop, global once per run ──────────────
$life = $src( 'class-ratesight-page-lifecycle.php' );
$runv = substr( $life, strpos( $life, 'private static function run_visibility' ), strpos( $life, '/** Decoded hold meta' ) - strpos( $life, 'private static function run_visibility' ) );
check( 'purge: per-post purge inside the loop, global purge once after it', substr_count( $runv, 'self::purge_post_cache( $post_id );' ) === 1 && substr_count( $runv, 'self::purge_global_cache();' ) === 1 && strpos( $runv, 'self::purge_global_cache();' ) > strrpos( $runv, '$count++;' ) );
$wh = $src( 'class-ratesight-webhook-handler.php' );
check( 'purge: Elementor clear_cache only in purge_global_cache; the routes call purge_post_cache( $id, false )', substr_count( $wh, 'files_manager->clear_cache()' ) === 1 && strpos( $wh, 'files_manager->clear_cache()' ) > strpos( $wh, 'public static function purge_global_cache' ) && str_contains( $life, 'Ratesight_Webhook_Handler::purge_post_cache( $post_id, false );' ) );
check( 'core 404 guess filter is registered', str_contains( $core, "'do_redirect_guess_404_permalink', array( 'Ratesight_Page_Lifecycle',     'filter_redirect_guess_404'" ) );


// ── page_location: real public path for hierarchical pages, front-page flag ─
$posts[ 30 ] = new WP_Post( array( 'ID' => 30, 'post_type' => 'ratesight_page', 'post_status' => 'publish', 'post_name' => 'parent' ) );
$posts[ 31 ] = new WP_Post( array( 'ID' => 31, 'post_type' => 'ratesight_page', 'post_status' => 'draft', 'post_name' => 'child', 'post_parent' => 30 ) );
function get_page_uri( $p ) { global $posts; $parts = array( $p->post_name ); $par = (int) ( $p->post_parent ?? 0 ); while ( $par && isset( $posts[ $par ] ) ) { array_unshift( $parts, $posts[ $par ]->post_name ); $par = (int) ( $posts[ $par ]->post_parent ?? 0 ); } return implode( '/', $parts ); }
function get_permalink( $p ) { return $p->post_status === 'publish' ? 'https://example.test/' . get_page_uri( $p ) . '/' : 'https://example.test/?post_type=' . $p->post_type . '&p=' . $p->ID; }
$loc = $L::page_location( $posts[31] );
check( 'location: a child page reports uri parent/child', $loc['uri'] === 'parent/child' );
check( 'location: a draft gets its would-be public link, not the ?p= form', $loc['link'] === 'https://example.test/parent/child/' && $posts[31]->post_status === 'draft' );
check( 'location: not the front page by default', $loc['front_page'] === false );
$opts['show_on_front'] = 'page'; $opts['page_on_front'] = '31';
check( 'location: front_page true when show_on_front=page and page_on_front is this id', $L::page_location( $posts[31] )['front_page'] === true && $L::page_location( $posts[30] )['front_page'] === false );
$opts['show_on_front'] = 'posts';
check( 'location: front_page false when show_on_front is posts', $L::page_location( $posts[31] )['front_page'] === false );

echo ( $failures ? "FAIL" : "PASS" ) . ": {$checks} checks, {$failures} failures" . PHP_EOL;
exit( $failures ? 1 : 0 );
