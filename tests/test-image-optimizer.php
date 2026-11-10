<?php
/**
 * Ratesight_Image_Optimizer wiring against in-memory WordPress stubs: default OFF, settings
 * sanitising, the upload path on a server with NO image library (strip still works, no resize or
 * conversion, no crash), format fallback, idempotency marker, rollback refusal for converted
 * uploads, and static security checks (nonce + manage_options on every AJAX action, no shell or
 * remote calls). Real pixel work needs WordPress's image editors and GD or Imagick; that part is
 * reported as skipped here.
 *
 * Run: php tests/test-image-optimizer.php
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$options  = array();
$postmeta = array();
$attached = array();
$editor_mimes = array(); // mime => supported by the (stub) image editor.
$uploads_base = sys_get_temp_dir() . '/rs-img-test-' . getmypid();
@mkdir( $uploads_base . '/2026/11', 0777, true );

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_hex_color( $v ) { return $v; }
function add_settings_error( ...$a ) { global $settings_errors; $settings_errors[] = $a; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function wp_basename( $p ) { return basename( (string) $p ); }
function trailingslashit( $p ) { return rtrim( (string) $p, '/' ) . '/'; }
function wp_get_upload_dir() { global $uploads_base; return array( 'basedir' => $uploads_base ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_unique_filename( $dir, $name ) { return $name; }
function get_attached_file( $id ) { global $attached; return $attached[ $id ] ?? ''; }
function get_post_meta( $id, $key, $single = false ) { global $postmeta; return $postmeta[ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $postmeta; $postmeta[ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { global $postmeta; unset( $postmeta[ $id ][ $key ] ); return true; }
function wp_image_editor_supports( $args ) { global $editor_mimes; return ! empty( $editor_mimes[ $args['mime_type'] ] ); }
function get_bloginfo( $what ) { return '6.7'; }

require __DIR__ . '/../includes/class-ratesight-options.php';
require __DIR__ . '/../includes/class-ratesight-image-metadata.php';
require __DIR__ . '/../includes/class-ratesight-image-optimizer.php';

$failures = 0;
$checks   = 0;
$skipped  = 0;
function check_opt( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function reset_editor_cache(): void {
	$p = new ReflectionProperty( 'Ratesight_Image_Optimizer', 'supports' );
	$p->setAccessible( true );
	$p->setValue( null, array() );
}
function set_opt( string $key, $value ): void { global $options; $options[ Ratesight_Options::option_name( $key ) ] = $value; }

// ── Defaults: nothing changes on an existing install ─────────────────────────
$schema = Ratesight_Options::schema();
$images = array_filter( $schema, static fn( $d ) => $d['group'] === 'images' );
check_opt( 'image settings live in their own group', count( $images ) === 9 );
check_opt( 'optimization is OFF by default', $schema['img_opt_enabled']['default'] === 0 );
check_opt( 'defaults: 2000 px long edge, WebP, quality 82, originals not kept, no location', $schema['img_opt_max_edge']['default'] === 2000 && $schema['img_opt_format']['default'] === 'webp' && $schema['img_opt_quality']['default'] === 82 && $schema['img_opt_keep_original']['default'] === 0 && $schema['img_geo_lat']['default'] === '' && $schema['img_geo_lng']['default'] === '' );
$s = Ratesight_Image_Optimizer::settings();
check_opt( 'settings() with nothing stored: disabled, 2000, 82, webp, no location', $s['enabled'] === false && $s['max_edge'] === 2000 && $s['quality'] === 82 && $s['format'] === 'webp' && $s['location'] === null && $s['keep_original'] === false );

$upload = array( 'file' => '/nowhere/photo.jpg', 'url' => 'https://example.test/wp-content/uploads/2026/11/photo.jpg', 'type' => 'image/jpeg' );
check_opt( 'while OFF, an upload is returned untouched', Ratesight_Image_Optimizer::filter_handle_upload( $upload, 'upload' ) === $upload );
check_opt( 'while OFF, the quality filter changes nothing', Ratesight_Image_Optimizer::filter_quality( 90, 'image/webp' ) === 90 );

// ── Sanitising ───────────────────────────────────────────────────────────────
check_opt( 'max edge: 0 allowed (no resize), clamped to 320..12000, junk = default', Ratesight_Options::sanitise( '0', 'img_edge' ) === 0 && Ratesight_Options::sanitise( '50', 'img_edge' ) === 320 && Ratesight_Options::sanitise( '99999', 'img_edge' ) === 12000 && Ratesight_Options::sanitise( 'abc', 'img_edge' ) === 2000 && Ratesight_Options::sanitise( '1600', 'img_edge' ) === 1600 );
check_opt( 'quality clamped to 40..100, empty = 82', Ratesight_Options::sanitise( '10', 'img_quality' ) === 40 && Ratesight_Options::sanitise( '101', 'img_quality' ) === 100 && Ratesight_Options::sanitise( '', 'img_quality' ) === 82 );
check_opt( 'format allowlist', Ratesight_Options::sanitise( 'avif', 'img_format' ) === 'avif' && Ratesight_Options::sanitise( 'gif', 'img_format' ) === 'webp' );
$settings_errors = array();
check_opt( 'latitude normalised, invalid rejected with a settings error', Ratesight_Options::sanitise( ' 40.44062480 ', 'latitude' ) === '40.4406248' && Ratesight_Options::sanitise( '95', 'latitude' ) === '' && count( $settings_errors ) === 1 );
check_opt( 'longitude range is +/-180', Ratesight_Options::sanitise( '-179.5', 'longitude' ) === '-179.5' && Ratesight_Options::sanitise( '-181', 'longitude' ) === '' );
check_opt( 'labels: tags stripped, 100 characters max', Ratesight_Options::sanitise( '<b>Pittsburgh</b>', 'label' ) === 'Pittsburgh' && strlen( Ratesight_Options::sanitise( str_repeat( 'a', 300 ), 'label' ) ) === 100 );

// ── Format choice and fallback ───────────────────────────────────────────────
$editor_mimes = array(); reset_editor_cache();
check_opt( 'no image library: the upload keeps its type', Ratesight_Image_Optimizer::target_mime( 'image/jpeg', 'webp' ) === 'image/jpeg' && Ratesight_Image_Optimizer::target_mime( 'image/png', 'avif' ) === 'image/png' );
$editor_mimes = array( 'image/jpeg' => true, 'image/webp' => true ); reset_editor_cache();
check_opt( 'AVIF asked, server writes only WebP: WebP', Ratesight_Image_Optimizer::target_mime( 'image/jpeg', 'avif' ) === 'image/webp' );
$editor_mimes['image/avif'] = true; reset_editor_cache();
check_opt( 'AVIF asked and writable (WordPress 6.5+): AVIF', Ratesight_Image_Optimizer::target_mime( 'image/jpeg', 'avif' ) === 'image/avif' );
check_opt( '"original" keeps the type', Ratesight_Image_Optimizer::target_mime( 'image/png', 'original' ) === 'image/png' );

// ── Upload on a server with no GD / Imagick: strip + location, nothing else ──
$editor_mimes = array(); reset_editor_cache();
set_opt( 'img_opt_enabled', 1 );
set_opt( 'img_geo_lat', '40.4406248' );
set_opt( 'img_geo_lng', '-79.9958864' );
$tiff  = "II*\0" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvV', 0x013B, 2, 11 ) . pack( 'V', 26 ) . pack( 'V', 0 ) . "Jane Owner\0";
$jpeg  = "\xFF\xD8" . "\xFF\xE1" . pack( 'n', strlen( $tiff ) + 8 ) . "Exif\0\0" . $tiff;
$jpeg .= "\xFF\xDB" . pack( 'n', 3 ) . "\x00" . "\xFF\xDA" . pack( 'n', 3 ) . "\x00" . "\x11\x22" . "\xFF\xD9";
$file  = $uploads_base . '/2026/11/photo.jpg';
file_put_contents( $file, $jpeg );
$upload = array( 'file' => $file, 'url' => 'https://example.test/wp-content/uploads/2026/11/photo.jpg', 'type' => 'image/jpeg' );
$after  = Ratesight_Image_Optimizer::filter_handle_upload( $upload, 'upload' );
$bytes  = file_get_contents( $file );
check_opt( 'no-library upload: same file, URL and type (nothing converted)', $after === $upload );
check_opt( 'no-library upload: owner name stripped, business GPS written', ! str_contains( $bytes, 'Jane Owner' ) && str_contains( $bytes, 'WGS-84' ) );
check_opt( 'WordPress is told not to add a -scaled copy of a processed file', Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, $file ) === false && Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, '/other.jpg' ) === 2560 );
$attached[ 7 ] = $file;
$meta = Ratesight_Image_Optimizer::filter_generate_metadata( array( 'file' => '2026/11/photo.jpg', 'sizes' => array(), 'image_meta' => array( 'camera' => 'PhoneCo X', 'credit' => 'Jane Owner', 'caption' => 'Kept' ) ), 7 );
$rec  = $postmeta[7]['_rs_img_opt'] ?? null;
check_opt( 'record stored on the attachment (idempotency marker)', is_array( $rec ) && $rec['mode'] === 'upload' && $rec['stripped'] === true && $rec['gps'] === true && $rec['converted'] === false && in_array( 'no_image_editor', $rec['notes'], true ) );
check_opt( 'camera and credit cleared from attachment metadata, caption kept', $meta['image_meta']['camera'] === '' && $meta['image_meta']['credit'] === '' && $meta['image_meta']['caption'] === 'Kept' );
check_opt( 'an already optimized attachment is skipped by the bulk tool', Ratesight_Image_Optimizer::optimize_existing( 7, Ratesight_Image_Optimizer::settings() )['status'] === 'skipped' );
check_opt( 'an upload-mode record is never rolled back (posts may link the new file)', Ratesight_Image_Optimizer::rollback( 7 )['status'] === 'skipped' && file_exists( $file ) );
check_opt( 'GIF uploads are never touched', Ratesight_Image_Optimizer::filter_handle_upload( array( 'file' => $file, 'url' => 'u', 'type' => 'image/gif' ) ) === array( 'file' => $file, 'url' => 'u', 'type' => 'image/gif' ) );

// No coordinates: strip only.
set_opt( 'img_geo_lng', '' );
file_put_contents( $file, $jpeg );
Ratesight_Image_Optimizer::filter_handle_upload( $upload, 'sideload' );
$bytes = file_get_contents( $file );
check_opt( 'no coordinates configured: stripped, no GPS invented', ! str_contains( $bytes, 'Jane Owner' ) && ! str_contains( $bytes, 'WGS-84' ) );

// ── Static checks ────────────────────────────────────────────────────────────
$src = file_get_contents( __DIR__ . '/../includes/class-ratesight-image-optimizer.php' );
$meta_src = file_get_contents( __DIR__ . '/../includes/class-ratesight-image-metadata.php' );
$tab = file_get_contents( __DIR__ . '/../admin/partials/tab-images.php' );
preg_match( '/private static function guard\(\): void \{(.*?)\n\t\}/s', $src, $g );
check_opt( 'AJAX guard verifies the nonce and manage_options', isset( $g[1] ) && str_contains( $g[1], "check_ajax_referer( self::NONCE, 'nonce' )" ) && str_contains( $g[1], "current_user_can( 'manage_options' )" ) );
preg_match_all( '/public static function (ajax_\w+)\(\): void \{\n\t\tself::guard\(\);/', $src, $guarded );
preg_match_all( '/public static function (ajax_\w+)\(/', $src, $all_ajax );
check_opt( 'every AJAX action calls the guard first: ' . json_encode( array_values( array_diff( $all_ajax[1], $guarded[1] ) ) ), count( $all_ajax[1] ) === 3 && $guarded[1] === $all_ajax[1] );
check_opt( 'no nopriv AJAX action is registered', ! str_contains( $src, 'wp_ajax_nopriv' ) );
$forbidden = '/\b(exec|shell_exec|system|passthru|proc_open|popen|curl_init|curl_exec|wp_remote_\w+|wp_safe_remote_\w+|fsockopen)\s*\(|`/';
check_opt( 'no shell or remote calls in the new classes', ! preg_match( $forbidden, preg_replace( '#^\s*(//|\*).*$#m', '', $src . $meta_src ) ) );
check_opt( 'apply and rollback need an explicit confirm word', str_contains( $src, "\$confirm !== 'apply'" ) && str_contains( $src, "\$confirm !== 'rollback'" ) );
check_opt( 'apply refuses while the setting is off', (bool) preg_match( "/public static function ajax_apply.*?if \( ! \\\$settings\['enabled'\] \)/s", $src ) );
$missing = array();
foreach ( $images as $def ) if ( ! str_contains( $tab, 'name="' . $def['name'] . '"' ) ) $missing[] = $def['name'];
check_opt( 'the Image optimization form carries every option of its group: missing ' . json_encode( $missing ), $missing === array() );
check_opt( 'exactly one form saves the images group', substr_count( $tab, "settings_fields( 'ratesight_options_images' )" ) === 1 );
$wrapper = file_get_contents( __DIR__ . '/../admin/partials/page-wrapper.php' );
check_opt( 'images tab is routed', str_contains( $wrapper, "'images'      => 'tab-images.php'" ) && str_contains( $wrapper, "'images' );" ) );
check_opt( 'class files are loaded by the plugin', str_contains( file_get_contents( __DIR__ . '/../ratesight.php' ), "'includes/class-ratesight-image-optimizer.php'" ) && str_contains( file_get_contents( __DIR__ . '/../includes/class-ratesight.php' ), 'Ratesight_Image_Optimizer::register();' ) );

// ── Pixel work ───────────────────────────────────────────────────────────────
if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
	$skipped++;
	echo 'skip   resize / WebP / AVIF encoding (no GD or Imagick on this PHP; it runs through WordPress image editors, verify on a WordPress test site)' . PHP_EOL;
} else {
	$skipped++;
	echo 'skip   resize / WebP / AVIF encoding (needs WordPress core image editors; verify on a WordPress test site)' . PHP_EOL;
}

// Cleanup.
array_map( 'unlink', glob( $uploads_base . '/2026/11/*' ) ?: array() );
@rmdir( $uploads_base . '/2026/11' ); @rmdir( $uploads_base . '/2026' ); @rmdir( $uploads_base );

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . ( $skipped ? " ({$skipped} skipped)" : '' ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
