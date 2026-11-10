<?php
/**
 * [rs_all_reviews] and [rs_leave_reviews] print Ratesight's exact embed tag
 * inline. The typ=6 / typ=5 widget scripts call
 * document.getElementById( "ratesight-reviews-widget" / "ratesight-carousel-widget" )
 * and replace that tag with their iframe in place, so the id must be exact
 * (no WordPress -js suffix) and the tag must sit where the shortcode is.
 *
 * Run: php tests/test-review-widget-inline.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$checks = 0;
$failures = 0;

function check_review_widget_inline_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

// ── Minimal WordPress stubs ────────────────────────────────────────────────
$GLOBALS['rs_test_code_id'] = '1234';
$GLOBALS['rs_test_doing']   = array();
$GLOBALS['rs_test_enqueued'] = array();

class Ratesight_Options {
	public static function get_all(): array {
		return array( 'code_id' => $GLOBALS['rs_test_code_id'], 'stars_clr' => '#1877F2', 'dark_text' => 0, 'dark_text_color' => '#666666', 'review_page' => 0 );
	}
	public static function get( string $key ) { return self::get_all()[ $key ] ?? null; }
}
function doing_action( string $hook ): bool { return in_array( $hook, $GLOBALS['rs_test_doing'], true ); }
function doing_filter( string $hook ): bool { return in_array( $hook, $GLOBALS['rs_test_doing'], true ); }
// Like WordPress: add_query_arg() does NOT encode values (callers must).
function add_query_arg( array $args, string $url ): string {
	$pairs = array();
	foreach ( $args as $k => $v ) $pairs[] = $k . '=' . $v;
	return $url . '?' . implode( '&', $pairs );
}
function esc_url( string $url ): string { return str_replace( '&', '&#038;', $url ); }
function esc_attr( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_register_script( ...$a ): void { $GLOBALS['rs_test_enqueued'][] = $a[0]; }
function wp_enqueue_script( ...$a ): void { $GLOBALS['rs_test_enqueued'][] = $a[0]; }

$root = dirname( __DIR__ );
require_once $root . '/public/class-ratesight-public.php';

function rs_new_page(): Ratesight_Public {
	$p = new ReflectionProperty( 'Ratesight_Public', 'printed_widgets' );
	$p->setAccessible( true );
	$p->setValue( null, array() );
	return new Ratesight_Public();
}

$base  = 'https://go.ratesight.com/Widgets/WidgetJS.ashx';
$attrs = ' data-no-optimize="1" data-cfasync="false" data-no-defer="1"></script>';

// ── Exact tags ─────────────────────────────────────────────────────────────
$public = rs_new_page();
$all    = $public->shortcode_all_reviews();
check_review_widget_inline_case( '[rs_all_reviews] prints the exact typ=6 tag inline', $all === '<script id="ratesight-reviews-widget" src="' . $base . '?typ=6&#038;oid=1234&#038;min=4&#038;map=true"' . $attrs );
check_review_widget_inline_case( '[rs_all_reviews] id has no -js suffix', str_contains( $all, 'id="ratesight-reviews-widget"' ) && ! str_contains( $all, '-js' ) );

$leave = $public->shortcode_leave_reviews();
check_review_widget_inline_case( '[rs_leave_reviews] prints the exact typ=5 tag inline', $leave === '<script id="ratesight-carousel-widget" src="' . $base . '?typ=5&#038;oid=1234&#038;min=4"' . $attrs );
check_review_widget_inline_case( 'nothing is enqueued for the review shortcodes', $GLOBALS['rs_test_enqueued'] === array() );

// ── Once per page ──────────────────────────────────────────────────────────
$all2   = $public->shortcode_all_reviews();
$leave2 = $public->shortcode_leave_reviews();
check_review_widget_inline_case( 'second [rs_all_reviews] prints no script, only a comment', ! str_contains( $all2, '<script' ) && str_starts_with( $all2, '<!--' ) && str_contains( $all2, '[rs_all_reviews]' ) );
check_review_widget_inline_case( 'second [rs_leave_reviews] prints no script, only a comment', ! str_contains( $leave2, '<script' ) && str_starts_with( $leave2, '<!--' ) && str_contains( $leave2, '[rs_leave_reviews]' ) );

$public = rs_new_page();
check_review_widget_inline_case( 'each widget has its own once-per-page slot', str_contains( $public->shortcode_leave_reviews(), 'id="ratesight-carousel-widget"' ) && str_contains( $public->shortcode_all_reviews(), 'id="ratesight-reviews-widget"' ) );

// Content rendered in wp_head / excerpts does not use up the slot.
$public = rs_new_page();
$GLOBALS['rs_test_doing'] = array( 'wp_head' );
$in_head = $public->shortcode_all_reviews();
$GLOBALS['rs_test_doing'] = array( 'get_the_excerpt' );
$in_excerpt = $public->shortcode_leave_reviews();
$GLOBALS['rs_test_doing'] = array();
check_review_widget_inline_case( 'nothing printed while rendering wp_head or an excerpt', $in_head === '' && $in_excerpt === '' );
check_review_widget_inline_case( 'the real content still gets the widgets afterwards', str_contains( $public->shortcode_all_reviews(), '<script id="ratesight-reviews-widget"' ) && str_contains( $public->shortcode_leave_reviews(), '<script id="ratesight-carousel-widget"' ) );

// ── Empty / odd code id ────────────────────────────────────────────────────
$GLOBALS['rs_test_code_id'] = '   ';
$public = rs_new_page();
check_review_widget_inline_case( 'empty code id prints nothing', $public->shortcode_all_reviews() === '' && $public->shortcode_leave_reviews() === '' );
$GLOBALS['rs_test_code_id'] = '';
$public = rs_new_page();
check_review_widget_inline_case( 'blank code id prints nothing', $public->shortcode_all_reviews() === '' && $public->shortcode_leave_reviews() === '' );

$GLOBALS['rs_test_code_id'] = '12"><x';
$public = rs_new_page();
$odd = $public->shortcode_all_reviews();
check_review_widget_inline_case( 'code id is url-encoded in src', str_contains( $odd, 'oid=12%22%3E%3Cx' ) && ! str_contains( $odd, '"><x' ) );

$GLOBALS['rs_test_code_id'] = '12 34&typ=1';
$public = rs_new_page();
$amp = $public->shortcode_all_reviews();
check_review_widget_inline_case( 'space and ampersand in code id are encoded and cannot add params', str_contains( $amp, 'oid=12%2034%26typ%3D1&#038;min=4' ) && ! str_contains( $amp, '12 34' ) && substr_count( $amp, 'typ=' ) === 1 );
$amp = $public->shortcode_leave_reviews();
check_review_widget_inline_case( 'carousel encodes space and ampersand too', str_contains( $amp, 'oid=12%2034%26typ%3D1&#038;min=4' ) && substr_count( $amp, 'typ=' ) === 1 );
$GLOBALS['rs_test_code_id'] = '1234';

// ── Source checks ──────────────────────────────────────────────────────────
$src = file_get_contents( $root . '/public/class-ratesight-public.php' );
check_review_widget_inline_case( 'review widgets are no longer enqueued (enqueue prints id="<handle>-js")', ! str_contains( $src, "wp_register_script( 'ratesight-reviews-widget'" ) && ! str_contains( $src, "wp_register_script( 'ratesight-carousel-widget'" ) );
check_review_widget_inline_case( 'sidebar widget unchanged', str_contains( $src, "wp_register_script( 'ratesight-sidebar-widget', esc_url_raw( \$url ), array(), null, true );" ) );
check_review_widget_inline_case( 'no inline-script fallback', ! preg_match( '/wp_add_inline_script|wp_print_inline_script_tag/', $src ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} inline review widget checks\n";
