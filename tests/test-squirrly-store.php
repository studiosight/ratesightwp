<?php
/**
 * Standalone tests for the v3.2.20 Squirrly storage adapter. No WordPress and
 * no Squirrly install required: the WP functions and the four Squirrly symbols
 * the adapter touches are stubbed here, so this runs with plain
 * `php tests/test-squirrly-store.php`.
 * Exit code 0 = all pass, 1 = failures.
 *
 * Squirrly presence is decided by `defined()`/`class_exists()`, neither of
 * which can be undone inside one process, so each environment (squirrly
 * absent / native / degraded / throwing) runs in its own child process and
 * this file aggregates the results.
 *
 * @package Ratesight
 */

$scenario = $argv[1] ?? '';

// ── Parent: run every scenario in its own process ────────────────────────────
if ( $scenario === '' ) {
	$scenarios = array( 'absent', 'native', 'degraded', 'throwing' );
	$failed    = 0;
	foreach ( $scenarios as $s ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $s );
		passthru( $cmd, $code );
		if ( $code !== 0 ) $failed++;
	}
	echo PHP_EOL . ( $failed ? "FAILED: {$failed} scenario(s)" : 'ALL SCENARIOS PASS' ) . PHP_EOL;
	exit( $failed ? 1 : 0 );
}

define( 'ABSPATH', sys_get_temp_dir() . '/' ); // satisfies the include guards only

// ── Minimal WordPress stubs ──────────────────────────────────────────────────

$GLOBALS['rs_meta']  = array(); // post_id => [ key => value ]
$GLOBALS['rs_posts'] = array( 42 => (object) array( 'ID' => 42, 'post_type' => 'page', 'post_title' => 'CoolSculpting Chula Vista' ) );

function get_post_meta( $post_id, $key, $single = false ) {
	return $GLOBALS['rs_meta'][ $post_id ][ $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['rs_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function get_post( $post_id ) {
	return $GLOBALS['rs_posts'][ $post_id ] ?? null;
}
function sanitize_text_field( $v )     { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function maybe_unserialize( $v )       { return is_string( $v ) ? ( @unserialize( $v ) ?: $v ) : $v; }

// ── Squirrly stubs, per scenario ─────────────────────────────────────────────

/** The stored SEO domain, standing in for one serialised `qss.seo` column. */
class RS_Test_Sq {
	public $title       = '';
	public $description = '';
	public $noindex     = '';   // an unrelated field that must survive our write
}

/** Stand-in for SQ_Models_Qss: one in-memory table keyed by url_hash. */
class RS_Test_Qss {
	public static array $rows = array();
	public static int $writes = 0;
	public function getSqSeo( $hash = null ) {
		return isset( self::$rows[ $hash ] ) ? clone self::$rows[ $hash ] : new RS_Test_Sq();
	}
	public function updateSqSeo( $post, $sq = false ) {
		self::$writes++;
		self::$rows[ $post->hash ] = clone $sq;
		return 1;
	}
}

class RS_Test_Frontend {
	public function getPostDetails( $post ) {
		// Mirrors Squirrly's own rule for post/page: hash = md5( ID ).
		return (object) array(
			'ID' => $post->ID, 'post_type' => $post->post_type, 'term_id' => 0,
			'taxonomy' => '', 'hash' => md5( (string) $post->ID ), 'url' => 'https://example.test/x/',
		);
	}
}

class RS_Test_ThrowingQss {
	public function getSqSeo( $hash = null ) { throw new RuntimeException( 'squirrly internals moved' ); }
	public function updateSqSeo( $post, $sq = false ) { throw new RuntimeException( 'squirrly internals moved' ); }
}

if ( $scenario !== 'absent' ) {
	define( 'SQ_VERSION', '14.2.3' );
}

if ( $scenario === 'native' || $scenario === 'throwing' ) {
	class_alias( 'RS_Test_Frontend', 'SQ_Models_Frontend' );
	class_alias( $scenario === 'throwing' ? 'RS_Test_ThrowingQss' : 'RS_Test_Qss', 'SQ_Models_Qss' );

	class SQ_Classes_ObjController {
		public static function getClass( $name ) {
			return $name === 'SQ_Models_Qss' ? new SQ_Models_Qss() : new SQ_Models_Frontend();
		}
	}
}

// Yoast is "installed" in the native scenario too — Squirrly must still win.
if ( $scenario === 'native' ) {
	define( 'WPSEO_VERSION', '28.3' );
}

require_once __DIR__ . '/../includes/class-ratesight-squirrly.php';
require_once __DIR__ . '/../includes/class-ratesight-seo-writer.php';

$failures = 0;
$checks   = 0;

function check( string $name, bool $ok ): void {
	global $failures, $checks, $scenario;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . "[{$scenario}] " . $name . PHP_EOL;
}

const T = 'CoolSculpting in Chula Vista | Silva MD';
const D = 'Non-surgical fat reduction in Chula Vista, CA.';

switch ( $scenario ) {

	// ── Squirrly not installed: the adapter must be completely inert ──────────
	case 'absent':
		check( 'is_active() is false', Ratesight_Squirrly::is_active() === false );

		$r = Ratesight_Squirrly::write( 42, T, D );
		check( 'write() refuses to write anything', $r['qss'] === false && $r['postmeta'] === false );
		check( 'write() touched no post meta', empty( $GLOBALS['rs_meta'] ) );

		$read = Ratesight_Squirrly::read( 42 );
		check( 'read() returns empty', $read['meta_title'] === '' && $read['store'] === 'none' );

		// The generic (no-SEO-plugin) path in the writer must be unchanged.
		check( 'active_plugin() is none', Ratesight_SEO_Writer::active_plugin() === 'none' );
		( new Ratesight_SEO_Writer() )->write( 42, T, D );
		check( 'SEO writer used the generic fallback keys',
			get_post_meta( 42, '_ratesight_meta_title', true ) === T
			&& get_post_meta( 42, '_ratesight_meta_description', true ) === D );
		check( 'SEO writer wrote no squirrly keys',
			get_post_meta( 42, '_sq_title', true ) === ''
			&& get_post_meta( 42, '_squirrly_seo', true ) === '' );
		break;

	// ── Squirrly with its models reachable: the real fix ──────────────────────
	case 'native':
		check( 'is_active() is true', Ratesight_Squirrly::is_active() === true );
		check( 'has_native_store() is true', Ratesight_Squirrly::has_native_store() === true );

		// A row already exists carrying an unrelated field.
		$pre          = new RS_Test_Sq();
		$pre->title   = 'old title';
		$pre->noindex = '1';
		RS_Test_Qss::$rows[ md5( '42' ) ] = $pre;

		$r = Ratesight_Squirrly::write( 42, T, D );
		check( 'write() reports the served store was written', $r['qss'] === true && $r['native'] === true );
		check( 'write() also wrote the post-meta fallback', $r['postmeta'] === true );

		$row = RS_Test_Qss::$rows[ md5( '42' ) ];
		check( 'qss row now holds our title',       $row->title === T );
		check( 'qss row now holds our description', $row->description === D );
		check( 'qss row kept its unrelated fields', $row->noindex === '1' );

		check( 'post meta uses squirrly\'s documented keys',
			get_post_meta( 42, '_sq_title', true ) === T
			&& get_post_meta( 42, '_sq_description', true ) === D );

		$read = Ratesight_Squirrly::read( 42 );
		check( 'read() returns the served store', $read['meta_title'] === T && $read['store'] === 'qss' );

		// Post-meta fallback is used only when the served store has nothing.
		RS_Test_Qss::$rows = array();
		$GLOBALS['rs_meta'][42]['_sq_title'] = 'meta-only title';
		$read = Ratesight_Squirrly::read( 42 );
		check( 'read() falls back to post meta when the row is empty',
			$read['meta_title'] === 'meta-only title' && $read['store'] === 'postmeta' );

		// ── Ratesight_SEO_Writer wiring, with Yoast ALSO installed ────────────
		$GLOBALS['rs_meta']  = array();
		RS_Test_Qss::$rows   = array();
		RS_Test_Qss::$writes = 0;

		check( 'active_plugin() reports the plugin that RENDERS (squirrly, not yoast)',
			Ratesight_SEO_Writer::active_plugin() === 'squirrly' );

		$stored = ( new Ratesight_SEO_Writer() )->write( 42, T, D );
		check( 'SEO writer reached the squirrly served store', RS_Test_Qss::$writes === 1
			&& ( RS_Test_Qss::$rows[ md5( '42' ) ]->title ?? '' ) === T );
		check( 'SEO writer read-back returns the served value', $stored['meta_title'] === T && $stored['meta_description'] === D );
		check( 'SEO writer reports the per-layer squirrly result',
			isset( $stored['squirrly']['qss'] ) && $stored['squirrly']['qss'] === true );
		check( 'SEO writer still keeps yoast in sync',
			get_post_meta( 42, '_yoast_wpseo_title', true ) === T );
		check( 'SEO writer no longer writes the inert _squirrly_seo key',
			get_post_meta( 42, '_squirrly_seo', true ) === '' );
		check( 'SEO writer did NOT use the generic fallback',
			get_post_meta( 42, '_ratesight_meta_title', true ) === '' );
		break;

	// ── Squirrly present but its models are not: degrade, never fatal ─────────
	case 'degraded':
		check( 'is_active() is true', Ratesight_Squirrly::is_active() === true );
		check( 'has_native_store() is false', Ratesight_Squirrly::has_native_store() === false );

		$r = Ratesight_Squirrly::write( 42, T, D );
		check( 'write() reports the served store was NOT written', $r['qss'] === false );
		check( 'write() still stored the post-meta fallback', $r['postmeta'] === true
			&& get_post_meta( 42, '_sq_title', true ) === T );
		check( 'write() says so in the note', str_contains( $r['note'], 'only _sq_title' ) );

		$read = Ratesight_Squirrly::read( 42 );
		check( 'read() falls back to post meta', $read['meta_title'] === T && $read['store'] === 'postmeta' );
		break;

	// ── Squirrly internals throw: catch, report, never take the site down ─────
	case 'throwing':
		$r = Ratesight_Squirrly::write( 42, T, D );
		check( 'write() survives a throwing squirrly', $r['qss'] === false && $r['postmeta'] === true );
		check( 'write() names the failure', str_contains( $r['note'], 'squirrly internals moved' ) );

		$read = Ratesight_Squirrly::read( 42 );
		check( 'read() survives and falls back to post meta', $read['meta_title'] === T && $read['store'] === 'postmeta' );
		break;
}

echo PHP_EOL . "[{$scenario}] {$checks} checks, {$failures} failure(s)" . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
