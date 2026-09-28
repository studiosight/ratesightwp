<?php
/**
 * 3.14.1: POST /update-page changes only the SEO fields it is sent, and GET /update-page reports
 * every active SEO plugin plus Squirrly's per-field store. Source-level, like
 * test-plugin-hardening-340.php, because the handler needs a full WordPress request.
 * Run: php tests/test-update-page-field-preserving.php (exit 0 = pass).
 *
 * @package Ratesight
 */

$failures = 0;
$checks   = 0;
function check( string $label, bool $ok ) {
	global $failures, $checks;
	$checks++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$root    = __DIR__ . '/..';
$handler = file_get_contents( $root . '/includes/class-ratesight-webhook-handler.php' );
$writer  = file_get_contents( $root . '/includes/class-ratesight-seo-writer.php' );
$pageapi = file_get_contents( $root . '/includes/class-ratesight-page-api.php' );
$plugin  = file_get_contents( $root . '/ratesight.php' );

$start  = strpos( $handler, 'public function handle_update_by_url' );
$end    = strpos( $handler, 'public function', $start + 10 );
$update = substr( $handler, $start, $end - $start );

check( 'update-page passes the sent fields straight to the writer', str_contains( $update, '$writer->write( $post_id, $meta_title, $meta_description )' ) );
check( 'update-page no longer rewrites an omitted title from the Yoast/Rank Math chain', ! preg_match( '/\$meta_title\s*\?\?\s*\$seo_title_before/', $update ) );
check( 'update-page no longer rewrites an omitted description from the Yoast/Rank Math chain', ! preg_match( '/\$meta_description\s*\?\?\s*\$seo_desc_before/', $update ) );
check( 'the rollback snapshot is still written before the update', strpos( $update, '_rs_pre_update_snapshot' ) !== false && strpos( $update, '_rs_pre_update_snapshot' ) < strpos( $update, '$writer->write(' ) );

$rstart = strpos( $handler, 'public function handle_read_by_url' );
$read   = substr( $handler, $rstart, strpos( $handler, 'public function', $rstart + 10 ) - $rstart );
check( 'GET update-page reports every active SEO plugin', str_contains( $read, "'seo_plugins'       => Ratesight_SEO_Writer::detected_plugin_ids()" ) );
check( 'GET update-page reports the squirrly block', str_contains( $read, 'Ratesight_Squirrly::describe( $post_id )' ) );
check( 'GET update-page states the write mode', str_contains( $read, "'seo_write_mode'    => 'field_preserving'" ) );

check( 'SEO writer accepts null for an omitted field', (bool) preg_match( '/public function write\( int \$post_id, \?string \$meta_title, \?string \$meta_description \)/', $writer ) );
check( 'page API single-field squirrly write passes null for the other field', str_contains( $pageapi, "\$title   = \$field === 'seo_title' ? \$value : null;" ) && str_contains( $pageapi, "\$desc    = \$field === 'seo_title' ? null : \$value;" ) );
$cstart = strpos( $handler, 'private function do_handle_request' );
$create = substr( $handler, $cstart, strpos( $handler, '// end do_handle_request' ) - $cstart );
check( 'create-page update branch writes only the SEO fields that were sent', str_contains( $create, '( new Ratesight_SEO_Writer() )->write( $post_id, $meta_title_sent, $meta_description_sent );' ) );
check( 'create-page new-post path keeps the title/summary defaults', str_contains( $create, '( new Ratesight_SEO_Writer() )->write( $post_id, $meta_title, $meta_description );' ) );
check( 'plugin version is 3.14.1', str_contains( $plugin, "define( 'RATESIGHT_RELEASE_VERSION', '3.14.1' );" ) && str_contains( $plugin, 'Version:           3.14.1' ) );

echo PHP_EOL . "{$checks} checks, {$failures} failure(s)" . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
