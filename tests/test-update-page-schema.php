<?php
/**
 * 3.15.0: POST /update-page `schema` writes the page's JSON-LD block (_rs_schema) and GET /update-page
 * reports it. Two parts: Ratesight_Schema's validator and printer run against WordPress stubs, and the
 * handler wiring is checked at source level (the handler needs a full WordPress request), like
 * test-update-page-field-preserving.php.
 * Run: php tests/test-update-page-schema.php (exit 0 = pass).
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

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['rs_meta'] = array();
$GLOBALS['rs_queried'] = 7;
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['rs_meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['rs_meta'][ $post_id ][ $key ] = $value; return true; }
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['rs_meta'][ $post_id ][ $key ] ); return true; }
function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function is_singular() { return true; }
function get_queried_object_id() { return $GLOBALS['rs_queried']; }
function wp_kses_post( $v ) { return str_replace( '&', '&amp;', (string) $v ); }

$root = __DIR__ . '/..';
require $root . '/includes/class-ratesight-schema.php';

$graph = array(
	'@context' => 'https://schema.org',
	'@graph'   => array(
		array( '@type' => 'LocalBusiness', 'name' => 'Smith & Sons Plumbing', 'telephone' => '+1 555 0100' ),
		array( '@type' => 'Service', 'serviceType' => 'Drain cleaning', 'description' => 'Uses </script> and <b>tags</b>' ),
	),
);

$r = Ratesight_Schema::validate_for_write( json_encode( $graph ) );
check( 'a JSON string with @context and @graph validates', isset( $r['json'], $r['data'] ) && $r['data'] === $graph );
$r = Ratesight_Schema::validate_for_write( $graph );
check( 'an already-decoded object validates', isset( $r['json'] ) );
check( 'null removes', Ratesight_Schema::validate_for_write( null ) === array( 'remove' => true ) );
check( 'empty string removes', Ratesight_Schema::validate_for_write( '' ) === array( 'remove' => true ) );
check( 'invalid JSON is refused', isset( Ratesight_Schema::validate_for_write( '{"@context":' )['error'] ) );
check( 'a list is refused', isset( Ratesight_Schema::validate_for_write( '[{"@context":"https://schema.org","@type":"Thing"}]' )['error'] ) );
check( 'a non-schema.org context is refused', isset( Ratesight_Schema::validate_for_write( array( '@context' => 'https://example.com', '@type' => 'Thing' ) )['error'] ) );
check( 'a missing @type and @graph is refused', isset( Ratesight_Schema::validate_for_write( array( '@context' => 'https://schema.org', 'name' => 'x' ) )['error'] ) );
check( 'an empty @graph is refused', isset( Ratesight_Schema::validate_for_write( array( '@context' => 'https://schema.org', '@graph' => array() ) )['error'] ) );
check( 'an oversize document is refused', isset( Ratesight_Schema::validate_for_write( array( '@context' => 'https://schema.org', '@type' => 'Thing', 'description' => str_repeat( 'a', 40000 ) ) )['error'] ) );
check( 'http://schema.org is accepted', isset( Ratesight_Schema::validate_for_write( array( '@context' => 'http://schema.org', '@type' => 'Thing' ) )['json'] ) );

// Stored value and hash.
check( 'nothing stored reads as empty', Ratesight_Schema::stored_json( 7 ) === '' && Ratesight_Schema::stored_hash( 7 ) === '' );
Ratesight_Schema::save_schema( 7, $graph );
$stored = Ratesight_Schema::stored_json( 7 );
check( 'save then read returns the canonical encoding', $stored === json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
check( 'the hash is sha256 of the stored text', Ratesight_Schema::stored_hash( 7 ) === hash( 'sha256', $stored ) );

// Printing.
ob_start();
Ratesight_Schema::inject();
$html = ob_get_clean();
check( 'the block carries the marker class', str_contains( $html, '<script type="application/ld+json" class="ratesight-schema">' ) );
preg_match( '#<script[^>]*>(.*?)</script>#s', $html, $m );
check( 'exactly one closing script tag is printed (the value cannot close it)', substr_count( $html, '</script>' ) === 1 );
check( 'the printed JSON decodes to the stored document, & and tags intact', isset( $m[1] ) && json_decode( trim( $m[1] ), true ) === $graph );
check( 'no &amp; corruption', ! str_contains( $html, '&amp;' ) );
$GLOBALS['rs_meta'][7]['_rs_schema'] = 'not json';
ob_start();
Ratesight_Schema::inject();
check( 'a stored value that is not JSON is not printed', ob_get_clean() === '' );
Ratesight_Schema::remove_schema( 7 );
check( 'remove deletes the block', Ratesight_Schema::stored_json( 7 ) === '' );

// ── Handler wiring (source level) ────────────────────────────────────────────
$handler = file_get_contents( $root . '/includes/class-ratesight-webhook-handler.php' );
$plugin  = file_get_contents( $root . '/ratesight.php' );
$start   = strpos( $handler, 'public function handle_update_by_url' );
$update  = substr( $handler, $start, strpos( $handler, 'public function', $start + 10 ) - $start );
$rstart  = strpos( $handler, 'public function handle_read_by_url' );
$read    = substr( $handler, $rstart, strpos( $handler, 'public function', $rstart + 10 ) - $rstart );

check( 'GET update-page reports the stored schema and its hash', str_contains( $read, "'schema'            => Ratesight_Schema::stored_json( \$post_id )" ) && str_contains( $read, "'schema_hash'       => Ratesight_Schema::stored_hash( \$post_id )" ) );
check( 'schema is validated before the dry run returns', strpos( $update, 'Ratesight_Schema::validate_for_write' ) !== false && strpos( $update, 'Ratesight_Schema::validate_for_write' ) < strpos( $update, 'if ( $dry_run )' ) );
check( 'dry run lists schema in would_write', (bool) preg_match( "/array\\( 'meta_title', 'meta_description', 'schema',/", $update ) );
check( 'schema needs update_seo on the page', (bool) preg_match( "/array_key_exists\\( 'schema', \\\$data \\)\\s*\\)\\s*\\{\\s*if \\( ! \\\$builder\\['update_seo'\\] \\)/", $update ) );
check( 'the pre-update snapshot keeps the previous schema', strpos( $update, "'schema'          => Ratesight_Schema::stored_json( \$post_id )" ) !== false && strpos( $update, '_rs_pre_update_snapshot' ) < strpos( $update, 'Ratesight_Schema::save_schema' ) );
check( 'the write saves or removes and reads back', str_contains( $update, 'Ratesight_Schema::remove_schema( $post_id )' ) && str_contains( $update, "Ratesight_Schema::save_schema( \$post_id, \$schema_write['data'] )" ) && str_contains( $update, '$schema_stored = Ratesight_Schema::stored_json( $post_id );' ) );
check( 'the response reports schema_stored and schema_hash', str_contains( $update, "'schema_stored'   => \$schema_stored" ) && str_contains( $update, "'schema_hash'     =>" ) );
check( 'the schema write happens before the cache purge', strpos( $update, 'Ratesight_Schema::save_schema' ) < strpos( $update, '$this->purge_cache( $post_id );' ) );
check( 'capabilities report schema_write', (bool) preg_match( "/'schema_write'\\s+=> true,/", substr( $handler, strpos( $handler, 'public function handle_capabilities' ) ) ) );
preg_match( "/define\\( 'RATESIGHT_RELEASE_VERSION', '([0-9.]+)' \\);/", $plugin, $release_match );
preg_match( '/Version:\\s+([0-9.]+)/', $plugin, $header_match );
check( 'plugin version is 3.15.0 and the header matches', ( $release_match[1] ?? '' ) === '3.15.0' && ( $header_match[1] ?? '' ) === '3.15.0' );

echo PHP_EOL . "{$checks} checks, {$failures} failure(s)" . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
