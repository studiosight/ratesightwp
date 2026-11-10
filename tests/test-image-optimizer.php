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
function apply_filters( $hook, $value, ...$args ) { global $throw_in; if ( $throw_in === $hook ) throw new RuntimeException( 'boom' ); return $value; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function wp_basename( $p ) { return basename( (string) $p ); }
function trailingslashit( $p ) { return rtrim( (string) $p, '/' ) . '/'; }
function wp_get_upload_dir() { global $uploads_base; return array( 'basedir' => $uploads_base ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_unique_filename( $dir, $name ) { return $name; }
function get_attached_file( $id ) { global $attached, $throw_in; if ( $throw_in === 'get_attached_file' ) throw new RuntimeException( 'boom' ); return $attached[ $id ] ?? ''; }
function get_post_meta( $id, $key, $single = false ) { global $postmeta; return $postmeta[ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) {
	global $postmeta, $journal_log, $attached;
	if ( $key === '_rs_img_opt_journal' ) $journal_log[] = array( 'backups' => ! empty( $value['backups'] ), 'current' => $value['current'], 'done' => count( $value['done'] ), 'bytes' => (string) @file_get_contents( $attached[ $id ] ?? '' ) );
	$postmeta[ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) { global $postmeta; unset( $postmeta[ $id ][ $key ] ); return true; }
function wp_image_editor_supports( $args ) { global $editor_mimes; return ! empty( $editor_mimes[ $args['mime_type'] ] ); }
function get_bloginfo( $what ) { return '6.7'; }
function get_post_mime_type( $id ) { global $mimes; return $mimes[ $id ] ?? ''; }
function wp_get_attachment_metadata( $id ) { global $att_meta; return $att_meta[ $id ] ?? array(); }
function wp_update_attachment_metadata( $id, $m ) { global $att_meta; $att_meta[ $id ] = $m; return true; }
$mimes = array(); $att_meta = array(); $journal_log = array(); $throw_in = '';
ini_set( 'error_log', '/dev/null' );

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
check_opt( 'big-image threshold is kept when nothing was resized', Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, $file ) === 2560 && Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, '/other.jpg' ) === 2560 );
$pend = new ReflectionProperty( 'Ratesight_Image_Optimizer', 'pending' );
$pend->setAccessible( true );
$saved_pending = $pend->getValue();
$pend->setValue( null, array( '/resized.jpg' => array( 'resized' => true ), '/same.jpg' => array( 'resized' => false, 'max_edge' => 2000 ) ) );
check_opt( 'big-image threshold is turned off only for a file our resize already shrank', Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, '/resized.jpg' ) === false && Ratesight_Image_Optimizer::filter_big_image_threshold( 2560, null, '/same.jpg' ) === 2560 );
$pend->setValue( null, $saved_pending );
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

// ── Fail open on any exception ───────────────────────────────────────────────
set_opt( 'img_geo_lng', '-79.9958864' );
file_put_contents( $file, $jpeg );
$throw_in = 'ratesight_image_optimize_upload';
check_opt( 'an exception inside the upload filter returns the upload unchanged', Ratesight_Image_Optimizer::filter_handle_upload( $upload, 'upload' ) === $upload && file_get_contents( $file ) === $jpeg );
$throw_in = 'get_attached_file';
$in_meta  = array( 'file' => 'x', 'sizes' => array( 'thumb' => array( 'file' => 'x-150x150.jpg' ) ) );
check_opt( 'an exception inside the metadata filter returns the metadata unchanged', Ratesight_Image_Optimizer::filter_generate_metadata( $in_meta, 7 ) === $in_meta );
check_opt( 'an exception inside the bulk step is reported as failed, never thrown', Ratesight_Image_Optimizer::optimize_existing( 8, Ratesight_Image_Optimizer::settings() )['status'] === 'failed' );
$throw_in = '';

// ── Encoder output verification (pure: getimagesize is core PHP) ─────────────
$png_ok = "\x89PNG\r\n\x1A\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', 32, 16 ) . "\x08\x02\x00\x00\x00" . pack( 'N', crc32( 'IHDR' . pack( 'NN', 32, 16 ) . "\x08\x02\x00\x00\x00" ) );
$probe  = $uploads_base . '/2026/11/probe.png';
file_put_contents( $probe, '' );
check_opt( 'a zero-byte encode never counts as a valid (smaller) image', ! Ratesight_Image_Optimizer::is_valid_image_file( $probe, 'image/png' ) );
file_put_contents( $probe, 'garbage bytes' );
check_opt( 'a non-image encode is rejected', ! Ratesight_Image_Optimizer::is_valid_image_file( $probe, 'image/png' ) );
file_put_contents( $probe, $png_ok );
check_opt( 'a real image header of the expected type is accepted', Ratesight_Image_Optimizer::is_valid_image_file( $probe, 'image/png' ) );
check_opt( 'an image of a different type than expected is rejected', ! Ratesight_Image_Optimizer::is_valid_image_file( $probe, 'image/webp' ) );
file_put_contents( $probe, "\x89PNG\r\n\x1A\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', 0, 0 ) . "\x08\x02\x00\x00\x00" . pack( 'N', 0 ) );
check_opt( 'zero dimensions are rejected', ! Ratesight_Image_Optimizer::is_valid_image_file( $probe, 'image/png' ) );
unlink( $probe );
check_opt( 'reencode verifies before returning (no replace on an unverified file)', (bool) preg_match( '/saved_path === \'\' \|\| ! self::is_valid_image_file\( \$saved_path, \$saved_mime \)/', file_get_contents( __DIR__ . '/../includes/class-ratesight-image-optimizer.php' ) ) );

// ── Formats the plugin cannot clean ──────────────────────────────────────────
$p = Ratesight_Image_Optimizer::plan( 'image/tiff', 'image/tiff', true );
check_opt( 'TIFF is only processed when converted to a cleanable type (WebP)', ! $p['refuse'] && $p['must_convert'] && $p['target'] === 'image/webp' );
$p = Ratesight_Image_Optimizer::plan( 'image/tiff', 'image/avif', false );
check_opt( 'TIFF with no cleanable output on this server is refused', $p['refuse'] && $p['reason'] === 'tiff_metadata_not_cleanable' );
$p = Ratesight_Image_Optimizer::plan( 'image/jpeg', 'image/avif', true );
check_opt( 'JPEG to AVIF is allowed (the source is cleaned before the encoder sees it)', ! $p['refuse'] && $p['target'] === 'image/avif' && ! $p['must_convert'] );
check_opt( 'GIF / unknown types are refused', Ratesight_Image_Optimizer::plan( 'image/gif', 'image/webp', true )['refuse'] );
$src_now = file_get_contents( __DIR__ . '/../includes/class-ratesight-image-optimizer.php' );
check_opt( 'the source is cleaned before reencode() in the upload path', strpos( $src_now, '$pre = Ratesight_Image_Metadata::rewrite_file( $path, null, true );' ) !== false && strpos( $src_now, '$pre = Ratesight_Image_Metadata::rewrite_file' ) < strpos( $src_now, '$encoded = self::reencode( $path, $try' ) );
set_opt( 'img_opt_format', 'original' );
$tif = $uploads_base . '/2026/11/scan.tif';
file_put_contents( $tif, "II*\0" . 'Jane Owner GPS' );
$tu = array( 'file' => $tif, 'url' => 'https://example.test/wp-content/uploads/2026/11/scan.tif', 'type' => 'image/tiff' );
$postmeta = array();
check_opt( 'TIFF on a server that cannot convert it: left exactly as uploaded and not marked clean', Ratesight_Image_Optimizer::filter_handle_upload( $tu ) === $tu && file_get_contents( $tif ) === "II*\0Jane Owner GPS" );
$attached[ 9 ] = $tif;
Ratesight_Image_Optimizer::filter_generate_metadata( array( 'sizes' => array() ), 9 );
check_opt( '... and no optimization record claims it was cleaned', ! isset( $postmeta[9]['_rs_img_opt'] ) );
unlink( $tif );
set_opt( 'img_opt_format', 'webp' );

// ── Path confinement ─────────────────────────────────────────────────────────
$outside = sys_get_temp_dir() . '/rs-outside-' . getmypid() . '.jpg';
file_put_contents( $outside, $jpeg );
$link = $uploads_base . '/2026/11/link.jpg';
@symlink( $outside, $link );
check_opt( 'a file inside uploads passes the path check', Ratesight_Image_Optimizer::is_inside_uploads( $file ) );
check_opt( 'a file outside uploads fails the path check', ! Ratesight_Image_Optimizer::is_inside_uploads( $outside ) );
check_opt( 'a symlink inside uploads that points outside fails', ! is_link( $link ) || ! Ratesight_Image_Optimizer::is_inside_uploads( $link ) );
check_opt( '"../" escapes and missing files fail', ! Ratesight_Image_Optimizer::is_inside_uploads( $uploads_base . '/2026/11/../../../' . basename( $outside ) ) && ! Ratesight_Image_Optimizer::is_inside_uploads( $uploads_base . '/nope.jpg' ) );
@mkdir( $uploads_base . '/ratesight-originals', 0777, true );
file_put_contents( $uploads_base . '/ratesight-originals/b.jpg', $jpeg );
check_opt( 'files in the backup directory are never processed as uploads', ! Ratesight_Image_Optimizer::is_inside_uploads( $uploads_base . '/ratesight-originals/b.jpg' ) );
$attached[ 20 ] = $outside; $mimes[ 20 ] = 'image/jpeg';
$r = Ratesight_Image_Optimizer::optimize_existing( 20, Ratesight_Image_Optimizer::settings() );
check_opt( 'bulk: an attachment whose file is outside uploads is skipped and not written', $r['status'] === 'failed' && $postmeta[20]['_rs_img_opt_error']['code'] === 'outside_uploads' && file_get_contents( $outside ) === $jpeg );
@unlink( $link ); unlink( $outside ); unlink( $uploads_base . '/ratesight-originals/b.jpg' );

// ── Journal before replacement, resume, rollback ─────────────────────────────
set_opt( 'img_opt_keep_original', 1 );
$old = $uploads_base . '/2026/11/old.jpg';
file_put_contents( $old, $jpeg );
$attached[ 30 ] = $old; $mimes[ 30 ] = 'image/jpeg'; $att_meta[ 30 ] = array( 'width' => 16, 'height' => 16, 'file' => '2026/11/old.jpg', 'sizes' => array(), 'image_meta' => array( 'credit' => 'Jane Owner' ) );
$journal_log = array();
$r = Ratesight_Image_Optimizer::optimize_existing( 30, Ratesight_Image_Optimizer::settings() );
check_opt( 'bulk in place: optimized', $r['status'] === 'optimized' && ! str_contains( file_get_contents( $old ), 'Jane Owner' ) );
check_opt( 'journal with backups is written BEFORE the file is changed', $journal_log !== array() && $journal_log[0]['backups'] && $journal_log[0]['bytes'] === $jpeg );
check_opt( 'journal notes the file before and after it is replaced', count( $journal_log ) === 3 && $journal_log[1]['current'] === '2026/11/old.jpg' && $journal_log[1]['bytes'] === $jpeg && $journal_log[2]['done'] === 1 );
check_opt( 'journal removed and record written on success; rollback flag set before', ! isset( $postmeta[30]['_rs_img_opt_journal'] ) && $postmeta[30]['_rs_img_opt']['mode'] === 'in_place' && $postmeta[30]['_rs_img_opt_rollback'] === 1 );
$backup_rel = $postmeta[30]['_rs_img_opt']['backups']['2026/11/old.jpg'];
$backup_abs = $uploads_base . '/' . $backup_rel;
check_opt( 'backup copy is in the backup directory and cleaned', str_starts_with( $backup_rel, 'ratesight-originals/' ) && is_file( $backup_abs ) && ! str_contains( file_get_contents( $backup_abs ), 'Jane Owner' ) && ! str_contains( file_get_contents( $backup_abs ), 'WGS-84' ) );
$r = Ratesight_Image_Optimizer::rollback( 30 );
check_opt( 'rollback restores the (cleaned) original and clears the markers', $r['status'] === 'restored' && ! isset( $postmeta[30]['_rs_img_opt'] ) && ! isset( $postmeta[30]['_rs_img_opt_rollback'] ) && ! is_file( $backup_abs ) && ! str_contains( file_get_contents( $old ), 'WGS-84' ) && ! str_contains( file_get_contents( $old ), 'Jane Owner' ) );

// Interrupted run: journal present, file half-done. A re-run restores the backup first.
@mkdir( dirname( $backup_abs ), 0777, true );
$clean_original = Ratesight_Image_Metadata::rewrite_bytes( $jpeg, null );
file_put_contents( $backup_abs, $clean_original );
file_put_contents( $old, 'half written' );
$postmeta[30] = array( '_rs_img_opt_journal' => array( 'attempts' => 1, 'backups' => array( '2026/11/old.jpg' => $backup_rel ), 'prev_meta' => array( 'width' => 16, 'height' => 16 ), 'done' => array(), 'current' => '2026/11/old.jpg' ), '_rs_img_opt_rollback' => 1 );
$journal_log = array();
$r = Ratesight_Image_Optimizer::optimize_existing( 30, Ratesight_Image_Optimizer::settings() );
check_opt( 'resume: the original is restored from the journal backup before processing', $r['status'] === 'optimized' && $journal_log[0]['bytes'] === $clean_original && str_contains( file_get_contents( $old ), 'WGS-84' ) );
check_opt( 'resume: the original backup is reused (no backup of a half-done file)', $postmeta[30]['_rs_img_opt']['backups'] === array( '2026/11/old.jpg' => $backup_rel ) && $att_meta[30]['width'] === 16 );
Ratesight_Image_Optimizer::rollback( 30 );
$postmeta[30] = array( '_rs_img_opt_journal' => array( 'attempts' => 2, 'backups' => array(), 'done' => array(), 'current' => '' ) );
$r = Ratesight_Image_Optimizer::optimize_existing( 30, Ratesight_Image_Optimizer::settings() );
check_opt( 'a twice-interrupted attachment is skipped with an error (no endless retry)', $r['status'] === 'failed' && $postmeta[30]['_rs_img_opt_error']['code'] === 'interrupted' );
set_opt( 'img_opt_keep_original', 0 );
@unlink( $old );
foreach ( array( '/ratesight-originals/2026/11', '/ratesight-originals/2026', '/ratesight-originals' ) as $d ) {
	foreach ( array_merge( glob( $uploads_base . $d . '/*' ) ?: array(), array( $uploads_base . $d . '/.htaccess' ) ) as $f ) if ( is_file( $f ) ) unlink( $f );
	@rmdir( $uploads_base . $d );
}

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
