<?php
/**
 * The four 3.4.0 defect fixes, locked so none of them can silently regress.
 *
 * 1. Ratesight_Logger::log() does not exist. Two call sites invoked it, so a PHP fatal killed the
 *    request AFTER the write had landed: the caller saw a 500 for work that had been done.
 * 2. POST /update-page accepted dry_run and wrote anyway — a caller that believed it was previewing
 *    was editing a live page.
 * 3. Alt text was settable only implicitly at upload time; there was no way to correct it.
 * 4. IndexNow submission had no REST route, and /capabilities reported the flag only under
 *    provider_ownership while callers gate on a top-level field.
 *
 * Tests 1, 2 and 4's capability wiring are source-level, matching this repo's existing approach for
 * behaviour that lives inside a WP-dependent request handler. Tests 3 and 4's route behaviour are
 * executed against stubbed WordPress functions, which is what test-page-lifecycle.php does.
 *
 * @package Ratesight
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$failures = 0;
$checks   = 0;
function check( string $label, bool $ok ) {
	global $failures, $checks;
	$checks++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$root = __DIR__ . '/..';

// -----------------------------------------------------------------------------
// 1. No caller invokes the method that does not exist.
// -----------------------------------------------------------------------------
$logger_src = file_get_contents( $root . '/includes/class-ratesight-logger.php' );
preg_match_all( '/public static function (\w+)/', $logger_src, $m );
$logger_methods = $m[1];
check( 'Ratesight_Logger still does not define log()', ! in_array( 'log', $logger_methods, true ) );

$fatal_callers = array();
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	foreach ( file( $file ) as $i => $line ) {
		// Skip comments: the fix notes name the old call deliberately.
		$trimmed = ltrim( $line );
		if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) continue;
		if ( preg_match( '/Ratesight_Logger::log\s*\(/', $line ) ) {
			$fatal_callers[] = basename( $file ) . ':' . ( $i + 1 );
		}
	}
}
check( 'no code path calls the undefined Ratesight_Logger::log() — found: ' . ( implode( ', ', $fatal_callers ) ?: 'none' ), $fatal_callers === array() );

foreach ( array( 'class-ratesight-related-links.php', 'class-ratesight-page-api.php' ) as $fixed ) {
	$src = file_get_contents( $root . '/includes/' . $fixed );
	check( "{$fixed} logs through the log_pending + log_update pair", (bool) preg_match( '/Ratesight_Logger::log_update\(\s*\n\s*Ratesight_Logger::log_pending\(/', $src ) );
}

// -----------------------------------------------------------------------------
// 2. update-page honours dry_run, and refuses BEFORE the first write.
// -----------------------------------------------------------------------------
$handler_src = file_get_contents( $root . '/includes/class-ratesight-webhook-handler.php' );
$update_start = strpos( $handler_src, 'public function handle_update_by_url' );
check( 'handle_update_by_url exists', $update_start !== false );
$update_body = substr( $handler_src, $update_start, strpos( $handler_src, 'POST /redirect', $update_start ) - $update_start );

check( 'handle_update_by_url reads dry_run with the same filter_var idiom as the other verbs', (bool) preg_match( "/\\\$dry_run\s*=\s*filter_var\(\s*\\\$data\['dry_run'\]/", $update_body ) );

$dry_run_return = strpos( $update_body, "'message'          => 'Dry run. Nothing was written.'" );
check( 'the dry-run branch returns a response saying nothing was written', $dry_run_return !== false );

// The snapshot (update_post_meta of _rs_pre_update_snapshot) is itself a write, so the dry-run
// branch has to come first or a "preview" still mutates the post.
$snapshot_write = strpos( $update_body, "update_post_meta( \$post_id, '_rs_pre_update_snapshot'" );
check( 'the pre-update snapshot write exists', $snapshot_write !== false );
check( 'the dry-run branch returns BEFORE the pre-update snapshot write', $dry_run_return < $snapshot_write );

$post_update = strpos( $update_body, 'wp_update_post( $post_data )' );
check( 'the dry-run branch returns BEFORE wp_update_post', $post_update !== false && $dry_run_return < $post_update );

// The capability/conflict refusals are pure reads and must still run first, so a dry run reports the
// same 409/422 a real write would hit rather than a false clean preview.
check( 'the expected_hash conflict check still runs before the dry-run branch', strpos( $update_body, "'expected_hash'" ) < $dry_run_return );
check( 'the builder capability refusal still runs before the dry-run branch', strpos( $update_body, 'Content updates are not supported for' ) < $dry_run_return );

// -----------------------------------------------------------------------------
// 3 + 4. The new routes are declared, policy-mapped and capability-reported.
// -----------------------------------------------------------------------------
$auth_src = file_get_contents( $root . '/includes/class-ratesight-request-auth.php' );
foreach ( array( 'media-alt', 'indexnow' ) as $route ) {
	check( "POST /ratesight/v1/{$route} is a signed_mutation in ROUTE_POLICIES", str_contains( $auth_src, "'POST /ratesight/v1/{$route}' => 'signed_mutation'" ) );
}

$bootstrap = file_get_contents( $root . '/includes/class-ratesight.php' );
check( 'Ratesight_Media_Alt::register_routes is hooked to rest_api_init', str_contains( $bootstrap, "add_action( 'rest_api_init', array( 'Ratesight_Media_Alt', 'register_routes' ) )" ) );
check( 'Ratesight_IndexNow::register_routes is hooked to rest_api_init', str_contains( $bootstrap, "add_action( 'rest_api_init', array( 'Ratesight_IndexNow', 'register_routes' ) )" ) );
check( 'class-ratesight-media-alt.php is loaded by the plugin bootstrap', str_contains( file_get_contents( $root . '/ratesight.php' ), 'includes/class-ratesight-media-alt.php' ) );

// The capability flag callers actually gate on. The nested provider_ownership.indexnow was already
// true and kept the feature switched off for every caller that read the top level.
check( 'capabilities reports indexnow at the TOP level', (bool) preg_match( "/'indexnow'\s*=> true,/", $handler_src ) );
check( 'capabilities still reports the nested provider_ownership.indexnow', (bool) preg_match( "/'indexnow'\s*=> true,\s*\n\s*\),/", $handler_src ) );
check( 'capabilities reports media_alt', (bool) preg_match( "/'media_alt'\s*=> true,/", $handler_src ) );
check( 'capabilities reports update_page_dry_run', (bool) preg_match( "/'update_page_dry_run'\s*=> true,/", $handler_src ) );

// update-page's post_status behaviour is BY DESIGN, not one of the defects. Locked so a future
// reader does not "fix" it back into the incident it was written to prevent.
check( 'update-page still preserves post_status by design', str_contains( $handler_src, 'Ratesight_Publisher::STATUS_PRESERVE' ) && (bool) preg_match( "/'update_page_preserves_status'\s*=> true,/", $handler_src ) );

// -----------------------------------------------------------------------------
// media-alt behaviour, against stubbed WordPress.
// -----------------------------------------------------------------------------
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request {
	private $body;
	public function __construct( $body ) { $this->body = $body; }
	public function get_json_params() { return $this->body; }
	public function get_body_params() { return $this->body; }
}
$GLOBALS['meta']  = array();
$GLOBALS['types'] = array( 7 => 'attachment', 9 => 'post' );
function register_rest_route( $ns, $path, $args ) {}
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_url_raw( $v ) { return (string) $v; }
function get_post_type( $id ) { return $GLOBALS['types'][ $id ] ?? false; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function wp_get_attachment_url( $id ) { return "https://example.test/wp-content/uploads/img-{$id}.jpg"; }
function attachment_url_to_postid( $url ) { return str_contains( $url, 'img-7.jpg' ) ? 7 : 0; }
function get_the_title( $id ) { return "Attachment {$id}"; }
function wp_json_encode( $v ) { return json_encode( $v ); }

require $root . '/includes/class-ratesight-media-alt.php';

$call = fn( array $body ) => Ratesight_Media_Alt::handle_set_alt( new WP_REST_Request( $body ) );

$r = $call( array( 'attachment_id' => 7, 'alt_text' => 'Blue moving truck' ) );
check( 'media-alt writes the alt text and reports before/after', $r->status === 200 && $r->data['success'] === true && $r->data['alt_before'] === '' && $r->data['alt_after'] === 'Blue moving truck' && $r->data['changed'] === true );

$r = $call( array( 'attachment_id' => 7, 'alt_text' => 'Blue moving truck' ) );
check( 'an identical re-write is reported as changed:false, not as a failure', $r->data['success'] === true && $r->data['changed'] === false );

$r = $call( array( 'url' => 'https://example.test/wp-content/uploads/img-7-300x200.jpg', 'alt_text' => 'From a resized src' ) );
check( 'a resized image src still resolves to its attachment', $r->status === 200 && $r->data['attachment_id'] === 7 );

$r = $call( array( 'attachment_id' => 7, 'alt_text' => '', 'dry_run' => true ) );
check( 'a dry run writes nothing and says so', $r->data['dry_run'] === true && $r->data['alt_after'] === 'From a resized src' && get_post_meta( 7, '_wp_attachment_image_alt', true ) === 'From a resized src' );

$r = $call( array( 'attachment_id' => 7, 'alt_text' => '' ) );
check( 'an empty alt_text clears the meta deliberately', $r->data['alt_after'] === '' && ! isset( $GLOBALS['meta'][7]['_wp_attachment_image_alt'] ) );

$r = $call( array( 'attachment_id' => 7 ) );
check( 'a missing alt_text is refused rather than defaulted to empty', $r->status === 422 );

$r = $call( array( 'attachment_id' => 9, 'alt_text' => 'x' ) );
check( 'a post that is not an attachment is refused', $r->status === 422 && ! isset( $GLOBALS['meta'][9] ) );

$r = $call( array( 'url' => 'https://example.test/nope.jpg', 'alt_text' => 'x' ) );
check( 'an unresolvable url is a 404, not a silent no-op', $r->status === 404 );

$r = $call( array( 'attachment_id' => 7, 'alt_text' => str_repeat( 'a', 301 ) ) );
check( 'an over-long alt_text is refused', $r->status === 422 );

echo $failures
	? "FAIL — {$checks} checks, {$failures} failure(s)\n"
	: "PASS — {$checks} checks, 0 failures\n";
exit( $failures ? 1 : 0 );
