<?php
/**
 * Image compression phase 1 (unreleased): capability report, on-upload filters gated by the
 * ratesight_image_compression option, quality floors, skips, and the signed dry-run inventory.
 * Runs with mocks; no WordPress, GD or Imagick install is needed.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
const ARRAY_A = 'ARRAY_A';
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_Error { public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {} }
class WP_REST_Response { public function __construct( public $data, public $status ) {} }
class Fake_Request {
	public function __construct( private array $json = array(), private array $query = array() ) {}
	public function get_json_params() { return $this->json; }
	public function get_body_params() { return array(); }
	public function get_query_params() { return $this->query; }
}

// ── WordPress mocks ──────────────────────────────────────────────────────────
$options = array();
$writes  = array();
$filters = array();
$wp_version = '6.5.2';
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $options, $writes; $options[ $name ] = $value; $writes[] = $name; return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { global $filters; $filters[ $hook ][ $priority ][] = $callback; return true; }
function remove_filter( $hook, $callback, $priority = 10 ) {
	global $filters;
	foreach ( $filters[ $hook ][ $priority ] ?? array() as $i => $cb ) if ( $cb === $callback ) unset( $filters[ $hook ][ $priority ][ $i ] );
	if ( empty( $filters[ $hook ][ $priority ] ) ) unset( $filters[ $hook ][ $priority ] );
	if ( empty( $filters[ $hook ] ) ) unset( $filters[ $hook ] );
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	global $filters;
	$by_priority = $filters[ $hook ] ?? array();
	ksort( $by_priority );
	foreach ( $by_priority as $callbacks ) foreach ( $callbacks as $cb ) $value = $cb( $value, ...$args );
	return $value;
}
$supports = array( 'image/webp' => true, 'image/avif' => true );
function wp_image_editor_supports( $args ) { global $supports; return $supports[ $args['mime_type'] ] ?? false; }
$uploads_dir = sys_get_temp_dir() . '/rs-image-compression-test-' . getmypid();
@mkdir( $uploads_dir . '/2026/11', 0777, true );
function wp_get_upload_dir() { global $uploads_dir; return array( 'basedir' => $uploads_dir ); }
$registered = array();
function register_rest_route( $namespace, $path, $definitions ) {
	global $registered;
	foreach ( $definitions as $definition ) $registered[ $definition['methods'] . ' /' . $namespace . $path ] = $definition['permission_callback'];
}

// Editor and library mocks. Static switches select the scenario.
class WP_Image_Editor_Imagick { public static bool $ok = true; public static function test( $a = array() ) { return self::$ok; } public static function supports_mime_type( $m ) { return true; } }
class WP_Image_Editor_GD { public static bool $ok = true; public static function test( $a = array() ) { return self::$ok; } public static function supports_mime_type( $m ) { return true; } }
class Imagick { public static array $formats = array( 'WEBP', 'AVIF' ); public static function queryFormats( $p = '*' ) { return in_array( $p, self::$formats, true ) ? array( $p ) : array(); } }
if ( ! function_exists( 'imagetypes' ) ) {
	define( 'RS_TEST_GD_MOCK', true );
	if ( ! defined( 'IMG_WEBP' ) ) define( 'IMG_WEBP', 32 );
	if ( ! defined( 'IMG_AVIF' ) ) define( 'IMG_AVIF', 256 );
	$gd_types = IMG_WEBP | IMG_AVIF;
	function imagetypes() { global $gd_types; return $gd_types; }
}

class Fake_WPDB {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public array $queries = array();
	public $counts = array();
	public array $files = array();
	public $hold = null;
	public function prepare( $sql, ...$args ) { foreach ( $args as $a ) $sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 ); return $sql; }
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function get_results( $sql, $mode ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'GROUP BY post_mime_type' ) ) return $this->counts;
		preg_match( '/p\.ID > (\d+) ORDER BY p\.ID ASC LIMIT (\d+)/', $sql, $m );
		$rows = array_values( array_filter( $this->files, fn( $r ) => $r['id'] > (int) $m[1] ) );
		return array_slice( $rows, 0, (int) $m[2] );
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return $this->hold; }
	public function __call( $name, $args ) { $this->queries[] = 'WRITE:' . $name; return false; }
}
$wpdb = new Fake_WPDB();

require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-image-compression.php';

$failures = 0;
$checks   = 0;
function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

/** A JPEG header getimagesize() can read: SOI + SOF0 with the given size and channel count. */
function jpeg_file( string $name, int $w, int $h, int $channels = 3, int $pad_to = 0 ): string {
	global $uploads_dir;
	$sof = "\xFF\xC0" . pack( 'n', 8 + 3 * $channels ) . "\x08" . pack( 'n', $h ) . pack( 'n', $w ) . chr( $channels );
	for ( $i = 1; $i <= $channels; $i++ ) $sof .= chr( $i ) . "\x11\x00";
	$path = $uploads_dir . '/2026/11/' . $name;
	file_put_contents( $path, "\xFF\xD8" . $sof . str_repeat( "\x00", 1000 ) . "\xFF\xD9" );
	if ( $pad_to > 0 ) { $fh = fopen( $path, 'r+' ); ftruncate( $fh, $pad_to ); fclose( $fh ); } // Sparse: no disk use.
	return $path;
}
function hooked( string $hook ): bool { global $filters; return ! empty( $filters[ $hook ] ); }

// ── Option off: nothing registered ───────────────────────────────────────────
check( 'option off by default: register() adds no filter', Ratesight_Image_Compression::register() === false && $filters === array() );
$options['ratesight_image_compression'] = 0;
check( 'option explicitly 0: still no filter', Ratesight_Image_Compression::register() === false && $filters === array() );

// ── Routes ───────────────────────────────────────────────────────────────────
Ratesight_Image_Compression::register_routes();
check( 'GET is a signed read and POST a signed mutation', $registered === array(
	'GET /ratesight/v1/image-compression'  => array( 'Ratesight_Request_Auth', 'authorize_read' ),
	'POST /ratesight/v1/image-compression' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
) );
check( 'route policy table lists both', ( Ratesight_Request_Auth::ROUTE_POLICIES['GET /ratesight/v1/image-compression'] ?? '' ) === 'signed_read' && ( Ratesight_Request_Auth::ROUTE_POLICIES['POST /ratesight/v1/image-compression'] ?? '' ) === 'signed_mutation' );

// ── Capability matrix ────────────────────────────────────────────────────────
$cap = Ratesight_Image_Compression::capability();
check( 'imagick with WebP and AVIF on WP 6.5: editor imagick, webp and avif usable, AVIF still off', $cap['editor'] === 'imagick' && $cap['webp'] === true && $cap['avif'] === true && $cap['avif_enabled'] === false && $cap['imagick'] === array( 'available' => true, 'webp' => true, 'avif' => true ) && $cap['wp_version'] === '6.5.2' && $cap['enabled'] === false );
$wp_version = '6.4.3';
$cap = Ratesight_Image_Compression::capability();
check( 'AVIF is not reported usable below WordPress 6.5', $cap['avif'] === false && $cap['avif_reason'] === 'wordpress_below_6_5' && $cap['webp'] === true );
WP_Image_Editor_Imagick::$ok = false;
Imagick::$formats = array();
$supports = array( 'image/webp' => true, 'image/avif' => false );
if ( defined( 'RS_TEST_GD_MOCK' ) ) $gd_types = IMG_WEBP;
$cap = Ratesight_Image_Compression::capability();
check( 'GD only with WebP: editor gd, webp true, avif false with a reason', $cap['editor'] === 'gd' && $cap['webp'] === true && $cap['avif'] === false && $cap['avif_reason'] === 'editor_cannot_write_avif' && $cap['gd']['available'] === true && ( defined( 'RS_TEST_GD_MOCK' ) ? $cap['gd']['webp'] === true && $cap['gd']['avif'] === false : true ) );
check( 'imagick queried formats reported as unavailable, not assumed', $cap['imagick']['webp'] === false && $cap['imagick']['avif'] === false );
WP_Image_Editor_GD::$ok = false;
check( 'no usable editor: editor null', Ratesight_Image_Compression::capability()['editor'] === null );
WP_Image_Editor_GD::$ok = true;
$wp_version = '6.5.2';

// ── Option on: filters registered ────────────────────────────────────────────
$options['ratesight_image_compression'] = 1;
check( 'option on: register() adds the upload filters', Ratesight_Image_Compression::register() === true && hooked( 'image_editor_output_format' ) && hooked( 'wp_editor_set_quality' ) && hooked( 'big_image_size_threshold' ) && hooked( 'wp_handle_upload' ) && hooked( 'jpeg_quality' ) );
check( 'no attachment-metadata or original_image filter is added', ! hooked( 'wp_generate_attachment_metadata' ) && ! hooked( 'wp_update_attachment_metadata' ) && ! hooked( 'wp_get_original_image_path' ) );
check( 'big_image_size_threshold is 2560', apply_filters( 'big_image_size_threshold', 4000, array(), '', 0 ) === 2560 );

// ── Quality targets and floors ───────────────────────────────────────────────
check( 'quality targets: JPEG 82, WebP 80, AVIF 60', apply_filters( 'wp_editor_set_quality', 90, 'image/jpeg' ) === 82 && apply_filters( 'wp_editor_set_quality', 86, 'image/webp' ) === 80 && apply_filters( 'wp_editor_set_quality', 90, 'image/avif' ) === 60 );
check( 'PNG quality is left to core', apply_filters( 'wp_editor_set_quality', 90, 'image/png' ) === 90 );
add_filter( 'wp_editor_set_quality', fn( $q ) => 10, 10, 2 );
add_filter( 'jpeg_quality', fn( $q ) => 5, 10, 1 );
check( 'a filter asking for 10 cannot go below the floors (JPEG 75, WebP 75, AVIF 50)', apply_filters( 'wp_editor_set_quality', 90, 'image/jpeg' ) === 75 && apply_filters( 'wp_editor_set_quality', 90, 'image/webp' ) === 75 && apply_filters( 'wp_editor_set_quality', 90, 'image/avif' ) === 50 );
check( 'jpeg_quality cannot go below 75 either', apply_filters( 'jpeg_quality', 82, 'image_resize' ) === 75 );
unset( $filters['wp_editor_set_quality'][10], $filters['jpeg_quality'][10] );
add_filter( 'wp_editor_set_quality', fn( $q ) => 95, 10, 2 );
check( 'a site asking for higher quality keeps it', apply_filters( 'wp_editor_set_quality', 90, 'image/webp' ) === 95 );
unset( $filters['wp_editor_set_quality'][10] );
check( 'floor clamps a value above 100 to 100', Ratesight_Image_Compression::filter_quality_floor( 140, 'image/jpeg' ) === 100 );

// ── Output format decisions ──────────────────────────────────────────────────
WP_Image_Editor_Imagick::$ok = true;
$photo = jpeg_file( 'team-photo.jpg', 3000, 2000 );
apply_filters( 'wp_handle_upload', array( 'file' => $photo, 'url' => 'u', 'type' => 'image/jpeg' ), 'upload' );
check( 'an ordinary JPEG upload converts to WebP (main and sub-size calls)', apply_filters( 'image_editor_output_format', array(), $photo, 'image/jpeg' ) === array( 'image/jpeg' => 'image/webp' ) && apply_filters( 'image_editor_output_format', array(), null, 'image/jpeg' ) === array( 'image/jpeg' => 'image/webp' ) );
check( 'the scaled file save also converts (core passes -scaled.jpg): see PR question', apply_filters( 'image_editor_output_format', array(), str_replace( '.jpg', '-scaled.jpg', $photo ), 'image/jpeg' ) === array( 'image/jpeg' => 'image/webp' ) );
check( 'PNG (transparency possible) keeps its format', apply_filters( 'image_editor_output_format', array(), 'logo.png', 'image/png' ) === array() );
check( 'GIF (animation possible) keeps its format', apply_filters( 'image_editor_output_format', array(), 'spinner.gif', 'image/gif' ) === array() );
check( 'SVG keeps its format', apply_filters( 'image_editor_output_format', array(), 'mark.svg', 'image/svg+xml' ) === array() );
check( 'other filters\' mappings are preserved', apply_filters( 'image_editor_output_format', array( 'image/png' => 'image/png' ), null, 'image/jpeg' ) === array( 'image/png' => 'image/png', 'image/jpeg' => 'image/webp' ) );

$png_upload = apply_filters( 'wp_handle_upload', array( 'file' => '/x/a.png', 'url' => 'u', 'type' => 'image/png' ), 'upload' );
check( 'a PNG upload resets the JPEG decision and is never converted', apply_filters( 'image_editor_output_format', array(), null, 'image/jpeg' ) === array() && $png_upload['type'] === 'image/png' );
check( 'no upload context (media editor, later sub-size pass): JPEG not converted', apply_filters( 'image_editor_output_format', array(), $photo, 'image/jpeg' ) === array() );

$logo = jpeg_file( 'acme-logo.jpg', 800, 400 );
apply_filters( 'wp_handle_upload', array( 'file' => $logo, 'type' => 'image/jpeg' ), 'upload' );
check( 'a JPEG named as a logo or line art keeps JPEG', apply_filters( 'image_editor_output_format', array(), null, 'image/jpeg' ) === array() );
check( 'logo heuristic does not trip on words containing "icon"', Ratesight_Image_Compression::decide( jpeg_file( 'siliconvalley.jpg', 100, 100 ), 'imagick' ) === 'convert' );

$big = jpeg_file( 'huge-scan.jpg', 2000, 1000, 3, 21 * 1024 * 1024 );
apply_filters( 'wp_handle_upload', array( 'file' => $big, 'type' => 'image/jpeg' ), 'upload' );
check( 'over 20 MB: too_large, keeps JPEG', apply_filters( 'image_editor_output_format', array(), null, 'image/jpeg' ) === array() && end( $options['ratesight_image_compression_log'] )['event'] === 'too_large' );
$mp = jpeg_file( 'panorama.jpg', 8000, 6000 );
check( 'over 40 megapixels: too_large', Ratesight_Image_Compression::decide( $mp, 'imagick' ) === 'too_large' );

$cmyk = jpeg_file( 'print-cmyk.jpg', 1200, 800, 4 );
check( 'CMYK JPEG under GD keeps JPEG; under Imagick it converts', Ratesight_Image_Compression::decide( $cmyk, 'gd' ) === 'cmyk_gd' && Ratesight_Image_Compression::decide( $cmyk, 'imagick' ) === 'convert' );

$supports['image/webp'] = false;
apply_filters( 'wp_handle_upload', array( 'file' => $photo, 'type' => 'image/jpeg' ), 'upload' );
$last = end( $options['ratesight_image_compression_log'] );
check( 'WebP unsupported: original format kept and format_unsupported recorded (no fake success)', apply_filters( 'image_editor_output_format', array(), null, 'image/jpeg' ) === array() && $last['event'] === 'format_unsupported' && $last['file'] === 'team-photo.jpg' );
$supports['image/webp'] = true;
check( 'the log keeps file basenames only, never server paths', ! str_contains( json_encode( $options['ratesight_image_compression_log'] ), $uploads_dir ) );

// ── Turning it off unregisters ───────────────────────────────────────────────
Ratesight_Image_Compression::unregister();
check( 'unregister() removes every filter it added', ! hooked( 'image_editor_output_format' ) && ! hooked( 'wp_editor_set_quality' ) && ! hooked( 'big_image_size_threshold' ) && ! hooked( 'wp_handle_upload' ) && ! hooked( 'jpeg_quality' ) );

// ── POST /image-compression ──────────────────────────────────────────────────
$options['ratesight_image_compression'] = 0;
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array() ) );
check( 'POST without enabled: 422', $r->status === 422 && $r->data['code'] === 'rs_image_compression_enabled_required' );
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => 'maybe' ) ) );
check( 'POST with a non-boolean enabled: 422', $r->status === 422 && $r->data['code'] === 'rs_image_compression_enabled_invalid' );
$writes = array();
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => true, 'dry_run' => true ) ) );
check( 'dry run writes nothing', $r->status === 200 && $r->data['dry_run'] === true && $r->data['would']['enabled'] === true && $writes === array() && (int) $options['ratesight_image_compression'] === 0 );
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => true ) ) );
check( 'enable: option on, read back, WebP on new uploads, existing media unchanged', $r->status === 200 && $r->data['after']['enabled'] === true && Ratesight_Image_Compression::enabled() && $r->data['webp_conversion'] === 'webp_on_new_uploads' && $r->data['existing_media'] === 'unchanged' );
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => false ) ) );
check( 'disable: option off and filters gone', $r->status === 200 && $r->data['after']['enabled'] === false && ! Ratesight_Image_Compression::enabled() && ! hooked( 'image_editor_output_format' ) );
$supports['image/webp'] = false;
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => true ) ) );
check( 'enable without WebP support says format_unsupported', $r->status === 200 && $r->data['webp_conversion'] === 'format_unsupported' );
$supports['image/webp'] = true;
$options['ratesight_image_compression'] = 0;

check( 'without the page-lifecycle hold marker the answer says marker_unavailable', Ratesight_Image_Compression::hosting_only() === array( 'hosting_only' => false, 'basis' => 'marker_unavailable' ) );
// A build that has the hosting-only visibility hold (PR #56) declares this constant.
if ( true ) { class Ratesight_Page_Lifecycle { const VISIBILITY_HOLD_META = '_ratesight_visibility_hold'; } }
$wpdb->hold = '42';
$writes = array();
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => true ) ) );
check( 'hosting-only site: enable refused 409, nothing written', $r->status === 409 && $r->data['code'] === 'rs_image_compression_hosting_only' && $writes === array() && ! Ratesight_Image_Compression::enabled() );
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => false ) ) );
check( 'hosting-only site: turning off is still allowed', $r->status === 200 );
$wpdb->hold = null;
$wpdb->last_error = 'table missing';
$r = Ratesight_Image_Compression::handle_post( new Fake_Request( array( 'enabled' => true ) ) );
check( 'unreadable marker: enable refused, not assumed safe', $r->status === 409 && $r->data['code'] === 'rs_image_compression_hosting_state_unknown' );
$wpdb->last_error = '';

// ── GET /image-compression: dry-run inventory ────────────────────────────────
$wpdb->counts = array(
	array( 'mime' => 'image/jpeg', 'n' => '3' ),
	array( 'mime' => 'image/png', 'n' => '2' ),
	array( 'mime' => 'image/gif', 'n' => '1' ),
);
$a = jpeg_file( 'a.jpg', 100, 100, 3, 400000 );
$b = jpeg_file( 'b.jpg', 100, 100, 3, 600000 );
$wpdb->files = array(
	array( 'id' => 10, 'file' => '2026/11/a.jpg' ),
	array( 'id' => 11, 'file' => '2026/11/b.jpg' ),
	array( 'id' => 12, 'file' => '2026/11/gone.jpg' ),
);
$writes  = array();
$wpdb->queries = array();
$r    = Ratesight_Image_Compression::handle_get( new Fake_Request() );
$inv  = $r->data['inventory'];
check( 'GET answers 200 with capability, settings, hosting state and inventory', $r->status === 200 && $r->data['ok'] && isset( $r->data['capability']['editor'], $r->data['settings']['quality'] ) && $r->data['hosting_only']['basis'] === 'visibility_hold_marker' );
check( 'counts by mime type; only JPEG eligible, others say why', $inv['by_mime'][0] === array( 'mime' => 'image/jpeg', 'count' => 3, 'eligible' => true, 'reason' => null ) && $inv['by_mime'][1]['eligible'] === false && $inv['by_mime'][1]['reason'] === 'not_converted_in_phase_1' );
check( 'measured bytes from the files that exist; a missing file is counted, not zero bytes', $inv['jpeg_sample']['measured'] === 2 && $inv['jpeg_sample']['measured_bytes'] === 1000000 && $inv['jpeg_sample']['missing_files'] === 1 );
check( 'estimated savings are labelled an assumption', $inv['estimated_savings']['sample_bytes'] === 250000 && $inv['estimated_savings']['basis'] === 'assumed_ratio_not_measured' && $inv['estimated_savings']['all_jpeg_bytes'] === 375000 && $inv['estimated_savings']['extrapolated'] === true );
check( 'megapixel limit is unknown in the dry run, with a reason (never 0)', $inv['jpeg_sample']['too_large_pixels'] === null && $inv['jpeg_sample']['too_large_pixels_reason'] === 'not_measured_in_dry_run' );
check( 'dry run: no writes, SELECT only', $writes === array() && count( array_filter( $wpdb->queries, fn( $q ) => ! str_starts_with( ltrim( $q ), 'SELECT' ) ) ) === 0 && $inv['writes'] === 'none' );
$r2 = Ratesight_Image_Compression::handle_get( new Fake_Request( array(), array( 'limit' => '1' ) ) );
check( 'batched: limit 1 returns a next_after_id cursor', $r2->data['inventory']['jpeg_sample']['files'] === 1 && $r2->data['inventory']['jpeg_sample']['next_after_id'] === 10 );
$r3 = Ratesight_Image_Compression::handle_get( new Fake_Request( array(), array( 'limit' => '1', 'after_id' => '10' ) ) );
check( 'cursor continues after the last id', $r3->data['inventory']['jpeg_sample']['after_id'] === 10 && $r3->data['inventory']['jpeg_sample']['measured_bytes'] === 600000 );
$r4 = Ratesight_Image_Compression::handle_get( new Fake_Request( array(), array( 'limit' => '5000' ) ) );
check( 'limit is capped at 200', $r4->data['inventory']['jpeg_sample']['limit'] === 200 );
$wpdb->files = array( array( 'id' => 20, 'file' => '../../etc/passwd' ) );
$r5 = Ratesight_Image_Compression::handle_get( new Fake_Request() );
check( 'a path outside uploads is never read; no files measured gives null savings with a reason', $r5->data['inventory']['jpeg_sample']['missing_files'] === 1 && $r5->data['inventory']['estimated_savings']['sample_bytes'] === null && $r5->data['inventory']['estimated_savings']['reason'] === 'no_files_measured' );
$supports['image/webp'] = false;
$r6 = Ratesight_Image_Compression::handle_get( new Fake_Request() );
check( 'WebP unsupported: savings null with format_unsupported, JPEG not eligible', $r6->data['inventory']['estimated_savings']['reason'] === 'format_unsupported' && $r6->data['inventory']['estimated_savings']['all_jpeg_bytes'] === null && $r6->data['inventory']['by_mime'][0]['eligible'] === false );
$supports['image/webp'] = true;
$wpdb->counts = null;
$r7 = Ratesight_Image_Compression::handle_get( new Fake_Request() );
check( 'unreadable media library: 503 error, not zeros', $r7 instanceof WP_Error && $r7->code === 'rs_media_inventory_unavailable' && $r7->data['status'] === 503 );

// Cleanup the temp uploads dir.
foreach ( glob( $uploads_dir . '/2026/11/*' ) as $f ) unlink( $f );
@rmdir( $uploads_dir . '/2026/11' ); @rmdir( $uploads_dir . '/2026' ); @rmdir( $uploads_dir );

echo $failures ? "{$failures} OF {$checks} CHECKS FAILED\n" : "ALL {$checks} CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
