<?php
/**
 * Standalone tests for Ratesight_Related_Links::render_block() (v3.3.2).
 *
 * No WordPress required: the WP functions render_block() touches are stubbed here
 * from a small mutable $WP fixture, so this runs with plain
 * `php tests/test-related-links-render.php`. Exit code 0 = all pass, 1 = failures.
 *
 * The regression under test: on a Divi/Elementor Theme Builder POST TEMPLATE the
 * body is rendered from inside the layout, so `in_the_loop()` and `is_main_query()`
 * are FALSE while `the_content` runs. The old guard bailed there and the stored
 * link lists never reached the HTML (measured live 2026-08-26: a post with 6
 * stored links served 0 `data-rs-block` sections). Both stubs below therefore
 * return FALSE for every case — a render that depends on them cannot pass.
 *
 * @package Ratesight
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' ); // satisfies the include guard only

// ── Mutable request fixture the stubs read ───────────────────────────────────
$WP = array(
	'is_admin'    => false,
	'is_feed'     => false,
	'is_singular' => true,
	'queried_id'  => 0,
	'current_id'  => 0,
	'post_types'  => array(), // id => post_type
	'meta'        => array(), // id => links array
);

// ── Minimal WP stubs ─────────────────────────────────────────────────────────
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
}
if ( ! class_exists( 'WP_REST_Request' ) )  { class WP_REST_Request {} }
if ( ! class_exists( 'WP_REST_Response' ) ) { class WP_REST_Response {} }
if ( ! class_exists( 'WP_Error' ) )         { class WP_Error {} }

function is_admin(): bool            { global $WP; return (bool) $WP['is_admin']; }
function is_feed(): bool             { global $WP; return (bool) $WP['is_feed']; }
function is_singular( $t = '' ): bool { global $WP; return (bool) $WP['is_singular']; }
function get_queried_object_id(): int { global $WP; return (int) $WP['queried_id']; }
function get_the_ID()                { global $WP; return $WP['current_id'] ? (int) $WP['current_id'] : false; }
function get_post_type( $id = null )  { global $WP; return $WP['post_types'][ (int) $id ] ?? 'post'; }
function get_post_meta( $id, $key, $single = false ) { global $WP; return $WP['meta'][ (int) $id ] ?? ''; }

// The loop-state helpers the fixed guard must NOT depend on. Always false: this is
// exactly the theme-builder situation that caused the bug.
function in_the_loop(): bool   { return false; }
function is_main_query(): bool { return false; }

function esc_url( $v )  { return $v; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function get_permalink( $id = 0 ) { return 'https://example.com/?p=' . (int) $id; }
function rest_ensure_response( $v ) { return $v; }
function url_to_postid( $u ) { return 0; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function delete_post_meta( $id, $key ) {}
function update_post_meta( $id, $key, $value ) {}
function get_the_title( $id = 0 ) { return ''; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function register_rest_route( ...$args ) {}

require_once __DIR__ . '/../includes/class-ratesight-related-links.php';

$failures = 0;
$checks   = 0;

function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

/** How many related-services blocks are in a rendered string. */
function blocks_in( string $html ): int {
	return substr_count( $html, 'data-rs-block="related-services"' );
}

/** Reset the per-request once-flag between cases (private static, no prod setter). */
function reset_rendered(): void {
	$p = new ReflectionProperty( 'Ratesight_Related_Links', 'rendered' );
	$p->setAccessible( true );
	$p->setValue( null, array() );
}

/**
 * Configure the fixture for one case.
 *
 * @param array $over Overrides merged over the singular-post baseline.
 */
function scenario( array $over = array() ): void {
	global $WP;
	$WP = array_merge( array(
		'is_admin'    => false,
		'is_feed'     => false,
		'is_singular' => true,
		'queried_id'  => 109502,
		'current_id'  => 109502,
		'post_types'  => array( 109502 => 'post' ),
		'meta'        => array( 109502 => array(
			array( 'url' => 'https://example.com/coolsculpting-chula-vista/', 'anchor' => 'CoolSculpting in Chula Vista' ),
			array( 'url' => 'https://example.com/weight-loss/', 'anchor' => 'Medical weight loss' ),
		) ),
	), $over );
	reset_rendered();
}

const BODY = '<p>post body</p>';

// ── 1. The regression: a singular post view renders the block exactly once ────
scenario();
$out = Ratesight_Related_Links::render_block( BODY );
check( 'singular post: block renders (theme-builder loop state is false)', blocks_in( $out ) === 1 );
check( 'singular post: original content is preserved and comes first', str_starts_with( $out, BODY ) );
check( 'singular post: both stored links are in the block', substr_count( $out, '<li class="rs-related-services__item">' ) === 2 );

// ── 2. Idempotence: the_content running twice still yields one block ──────────
scenario();
$twice = Ratesight_Related_Links::render_block( Ratesight_Related_Links::render_block( BODY ) );
check( 'the_content twice: at most one block', blocks_in( $twice ) === 1 );

// Divi re-runs the filter on a fresh copy of the body rather than on the output.
scenario();
Ratesight_Related_Links::render_block( BODY );
$second = Ratesight_Related_Links::render_block( BODY );
check( 'the_content twice (separate passes): second pass appends nothing', blocks_in( $second ) === 0 && $second === BODY );

// ── 3. A Divi Theme Builder layout is the "current post": still renders ───────
// This is the exact production shape: the queried object is the post, the post in
// scope is the layout that renders it, and in_the_loop() is false.
scenario( array(
	'current_id' => 991,
	'post_types' => array( 109502 => 'post', 991 => 'et_template' ),
) );
check( 'divi theme-builder layout in scope: block still renders once',
	blocks_in( Ratesight_Related_Links::render_block( BODY ) ) === 1 );

scenario( array(
	'current_id' => 992,
	'post_types' => array( 109502 => 'post', 992 => 'elementor_library' ),
) );
check( 'elementor template in scope: block still renders once',
	blocks_in( Ratesight_Related_Links::render_block( BODY ) ) === 1 );

// ── 4. Never on archives / feeds / admin ─────────────────────────────────────
scenario( array( 'is_singular' => false ) );
$arch = Ratesight_Related_Links::render_block( BODY );
check( 'archive view: no block', blocks_in( $arch ) === 0 && $arch === BODY );

scenario( array( 'is_feed' => true ) );
$feed = Ratesight_Related_Links::render_block( BODY );
check( 'feed: no block', blocks_in( $feed ) === 0 && $feed === BODY );

// A feed request is also singular for a single-post feed — still refused.
scenario( array( 'is_feed' => true, 'is_singular' => true ) );
check( 'singular feed: no block', blocks_in( Ratesight_Related_Links::render_block( BODY ) ) === 0 );

scenario( array( 'is_admin' => true ) );
$admin = Ratesight_Related_Links::render_block( BODY );
check( 'admin: no block', blocks_in( $admin ) === 0 && $admin === BODY );

// ── 5. Another post's content inside a singular view is left alone ────────────
// (related-post module, blog-feed module inside a theme-builder layout, …)
scenario( array(
	'current_id' => 108153,
	'post_types' => array( 109502 => 'post', 108153 => 'post' ),
) );
$foreign = Ratesight_Related_Links::render_block( '<p>a different post</p>' );
check( 'foreign post content: no block', blocks_in( $foreign ) === 0 && $foreign === '<p>a different post</p>' );

// …and the real post still gets its block afterwards in the same request.
global $WP;
$WP['current_id'] = 109502;
check( 'foreign post skip does not consume the once-flag',
	blocks_in( Ratesight_Related_Links::render_block( BODY ) ) === 1 );

// ── 6. Nothing stored -> nothing appended ────────────────────────────────────
scenario( array( 'meta' => array() ) );
$empty = Ratesight_Related_Links::render_block( BODY );
check( 'no stored links: content untouched', $empty === BODY );

// ── 7. No queried object (a 404 that still reports singular) ─────────────────
scenario( array( 'queried_id' => 0, 'current_id' => 0 ) );
check( 'no queried post: content untouched', Ratesight_Related_Links::render_block( BODY ) === BODY );

echo PHP_EOL . ( $failures === 0
	? "PASS — {$checks} checks" . PHP_EOL
	: "FAIL — {$failures} of {$checks} checks failed" . PHP_EOL );

exit( $failures === 0 ? 0 : 1 );
