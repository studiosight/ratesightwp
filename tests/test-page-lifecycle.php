<?php
/**
 * Standalone tests for the v3.2.19 page-lifecycle decision cores. No WordPress
 * required: Ratesight_Page_Lifecycle::parse_intent() and
 * Ratesight_Publisher::resolve_final_status() make no WP calls, so this runs
 * with plain `php tests/test-page-lifecycle.php`.
 * Exit code 0 = all pass, 1 = failures.
 *
 * @package Ratesight
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' ); // satisfies the include guards only

/**
 * Minimal stubs. parse_intent() and resolve_final_status() never call these,
 * but the class files reference them at parse time in other methods, so the
 * symbols must exist for the include to be safe to load.
 */
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { const CREATABLE = 'POST'; }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

require_once __DIR__ . '/../includes/class-ratesight-page-lifecycle.php';
require_once __DIR__ . '/../includes/class-ratesight-publisher.php';

$failures = 0;
$checks   = 0;

function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

// ── trash/restore intent: confirm is mandatory for a real write ──────────────
$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421 ) );
check( 'confirm: missing confirm is refused', $r['ok'] === false && $r['status'] === 428 );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421, 'confirm' => false ) );
check( 'confirm: confirm:false is refused', $r['ok'] === false && $r['status'] === 428 );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421, 'confirm' => 'yes-please' ) );
check( 'confirm: non-boolean confirm is refused', $r['ok'] === false && $r['status'] === 428 );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421, 'confirm' => true ) );
check( 'confirm: confirm:true proceeds as a write', $r['ok'] === true && $r['dry_run'] === false );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421, 'confirm' => 'true' ) );
check( 'confirm: string "true" proceeds', $r['ok'] === true );

// ── dry_run is honoured honestly and needs no confirmation ───────────────────
$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 111421, 'dry_run' => true ) );
check( 'dry_run: preview without confirm is allowed', $r['ok'] === true && $r['dry_run'] === true );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'slug' => 'coolsculpting-chula-vista', 'confirm' => true, 'dry_run' => 'true' ) );
check( 'dry_run: string "true" parses as a dry run', $r['ok'] === true && $r['dry_run'] === true );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'slug' => 'x', 'confirm' => true, 'dry_run' => false ) );
check( 'dry_run: explicit false is a real write', $r['ok'] === true && $r['dry_run'] === false );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'url' => 'https://example.com/dead-page/', 'confirm' => true ) );
check( 'dry_run: default is false when omitted', $r['ok'] === true && $r['dry_run'] === false );

// ── selector resolution order: id > url > slug ───────────────────────────────
$r = Ratesight_Page_Lifecycle::parse_intent( array( 'confirm' => true ) );
check( 'selector: no id/url/slug is a 422', $r['ok'] === false && $r['status'] === 422 );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => 0, 'slug' => 'fallback', 'confirm' => true ) );
check( 'selector: id 0 falls through to slug', $r['ok'] === true && $r['by'] === 'slug' && $r['value'] === 'fallback' );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'id' => '111421', 'confirm' => true ) );
check( 'selector: numeric-string id casts to int', $r['by'] === 'id' && $r['value'] === 111421 );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'url' => ' https://example.com/p/ ', 'slug' => 'p', 'confirm' => true ) );
check( 'selector: url wins over slug and is trimmed', $r['by'] === 'url' && $r['value'] === 'https://example.com/p/' );

$r = Ratesight_Page_Lifecycle::parse_intent( array( 'url' => '', 'slug' => 'only-slug', 'confirm' => true ) );
check( 'selector: empty url falls through to slug', $r['by'] === 'slug' );

// ── publisher status resolution — the draft-leak fix ─────────────────────────
$P = 'Ratesight_Publisher';

check(
	'status: preserve sentinel means write nothing',
	$P::resolve_final_status( Ratesight_Publisher::STATUS_PRESERVE, 'publish' ) === null
);
check(
	'status: preserve wins even when the site default is publish',
	$P::resolve_final_status( Ratesight_Publisher::STATUS_PRESERVE, 'publish' ) === null
);
check(
	'status: create-page with no status still uses the site default',
	$P::resolve_final_status( '', 'publish' ) === 'publish'
);
check(
	'status: create-page with no status honours a draft default',
	$P::resolve_final_status( '', 'draft' ) === 'draft'
);
check(
	'status: an explicit request status wins over the site default',
	$P::resolve_final_status( 'draft', 'publish' ) === 'draft'
);
check(
	'status: an unknown site default falls back to publish (unchanged)',
	$P::resolve_final_status( '', 'nonsense' ) === 'publish'
);
check(
	'status: the sentinel is not a valid WordPress status a caller could send',
	! in_array( Ratesight_Publisher::STATUS_PRESERVE, array( 'publish', 'draft', 'pending', 'private' ), true )
);

// ── summary ─────────────────────────────────────────────────────────────────
echo PHP_EOL . ( $failures === 0 ? "PASS" : "FAIL" ) . " — {$checks} checks, {$failures} failures" . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
