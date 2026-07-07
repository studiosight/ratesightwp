<?php
/**
 * Standalone tests for the runtime 404 router's PURE decision core (v3.2.18
 * fuzzy-constraint). No WordPress required: route_decision() and its helpers
 * make no WP calls, so this runs with plain `php tests/test-runtime-404-router.php`.
 * Exit code 0 = all pass, 1 = failures (usable in CI later).
 *
 * The cross-city fixtures are the REAL offenders from the Quantum serve log
 * (2026-07-07 audit: 97 cross-city rows of 1,325 fuzzy decisions).
 *
 * @package Ratesight
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' ); // satisfies the include guard only

/** Stub of the real options registry so current_mode() is testable without WP. */
class Ratesight_Options {
	public static $fuzzy_mode = 'legacy';
	public static function get( string $key ) { return $key === 'fuzzy_mode' ? self::$fuzzy_mode : null; }
}

require __DIR__ . '/../includes/class-ratesight-runtime-404-router.php';

$failures = 0;
$checks   = 0;

function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

/** Build an index row the way get_post_index() does (slug + slug/title tokens + url). */
function row( string $slug ): array {
	$tokens = array();
	foreach ( preg_split( '/[-_\s]+/', strtolower( $slug ), -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
		if ( strlen( $t ) >= 3 ) $tokens[] = $t;
	}
	return array( 'slug' => $slug, 'tokens' => array_unique( $tokens ), 'url' => 'https://example.com/' . $slug . '/' );
}

$T = Ratesight_Runtime_404_Router::THRESHOLD;

// ── City parsing ─────────────────────────────────────────────────────────────
check( 'city: san-francisco', Ratesight_Runtime_404_Router::city_of_slug( 'moving-and-storage-services-san-francisco-ca' ) === 'francisco' );
check( 'city: san-ramon != san-rafael', Ratesight_Runtime_404_Router::cities_differ( 'office-moving-company-san-rafael-ca', 'office-moving-company-san-ramon-ca' ) );
check( 'city: geo-suffix union-city != foster-city', Ratesight_Runtime_404_Router::cities_differ( 'moving-companies-union-city-ca', 'moving-companies-foster-city-ca' ) );
check( 'city: geo-suffix walnut-creek == walnut-creek (slug variant)', ! Ratesight_Runtime_404_Router::cities_differ( 'commercial-movers-walnut-creek-ca', 'commercial-mover-walnut-creek-ca' ) );
check( 'city: same city, service variant (companies vs company) not blocked', ! Ratesight_Runtime_404_Router::cities_differ( 'moving-companies-hercules-ca', 'moving-company-hercules-ca' ) );
check( 'city: hub has no city', Ratesight_Runtime_404_Router::city_of_slug( 'commercial-movers' ) === '' );
check( 'city: bay-point != bay-area', Ratesight_Runtime_404_Router::cities_differ( 'full-service-moving-bay-point-ca', 'full-service-moving-bay-area-ca' ) );

// ── Hub mapping ──────────────────────────────────────────────────────────────
check( 'hub: commercial-mover -> commercial-movers', Ratesight_Runtime_404_Router::service_hub_slug( 'commercial-mover-fairfield-ca' ) === 'commercial-movers' );
check( 'hub: business-relocations -> commercial-movers', Ratesight_Runtime_404_Router::service_hub_slug( 'business-relocations-palo-alto-ca' ) === 'commercial-movers' );
check( 'hub: office-mover -> office-movers', Ratesight_Runtime_404_Router::service_hub_slug( 'office-mover-benicia-ca' ) === 'office-movers' );
check( 'hub: unrelated slug -> none', Ratesight_Runtime_404_Router::service_hub_slug( 'household-movers-belmont-ca' ) === '' );

// ── Required offender cases (constrained mode blocks cross-city) ─────────────
// Fixture: only the wrong-city sibling exists — legacy would 301 to it.
$cases = array(
	array( 'business-moving-company-san-bruno-ca', 'business-moving-company-san-ramon-ca', 'San Bruno must not redirect to San Ramon' ),
	array( 'office-moving-company-san-rafael-ca', 'office-moving-company-san-ramon-ca', 'San Rafael must not redirect to San Ramon' ),
	array( 'full-service-moving-bay-point-ca', 'full-service-moving-bay-area-ca', 'Bay Point must not redirect to Bay Area' ),
	array( 'best-local-moving-companies-hercules-ca', 'best-local-moving-companies-berkeley-ca', 'Hercules must not redirect to Berkeley' ),
);
foreach ( $cases as [ $from, $wrong, $name ] ) {
	$index = array( row( $wrong ) );
	// Sanity: legacy DOES cross-city (that is the bug we constrain).
	$legacy = Ratesight_Runtime_404_Router::route_decision( $from, $index, 'legacy', $T );
	check( "legacy precondition: {$from} would legacy-match", $legacy['action'] === 'redirect' );
	// Constrained mode blocks it (and logs the refusal since nothing safe exists).
	$d = Ratesight_Runtime_404_Router::route_decision( $from, $index, 'same-city-or-hub', $T );
	check( $name, $d['action'] !== 'redirect' );
	check( "{$name} (refusal recorded)", $d['action'] === 'refuse' && $d['refused_url'] !== '' && $d['context']['mode'] === 'refused' );
}

// ── Same-city match still works ──────────────────────────────────────────────
$index = array( row( 'local-moving-companies-hercules-ca' ), row( 'local-moving-companies-berkeley-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'moving-companies-hercules-ca', $index, 'same-city-or-hub', $T );
check( 'same-city match still redirects', $d['action'] === 'redirect' && strpos( $d['url'], 'hercules' ) !== false );
check( 'same-city mode recorded', $d['context']['mode'] === 'same-city' );

// ── Base-hub fallback for a commercial/office city with no same-city page ────
$index = array( row( 'commercial-movers' ), row( 'commercial-movers-san-ramon-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'commercial-movers-fairfield-ca', $index, 'same-city-or-hub', $T );
check( 'hub fallback: fairfield-commercial -> /commercial-movers/', $d['action'] === 'redirect' && $d['url'] === 'https://example.com/commercial-movers/' );
check( 'hub mode + reason recorded', $d['context']['mode'] === 'hub' && $d['context']['fallback_reason'] !== '' );

$index = array( row( 'office-movers' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'office-mover-benicia-ca', $index, 'same-city-or-hub', $T );
check( 'hub fallback: benicia-office -> /office-movers/', $d['action'] === 'redirect' && $d['url'] === 'https://example.com/office-movers/' );

// Hub must actually EXIST on the site — no guessed URL.
$index = array( row( 'moving-companies-berkeley-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'commercial-movers-fairfield-ca', $index, 'same-city-or-hub', $T );
check( 'hub fallback requires the hub page to exist', $d['action'] !== 'redirect' );

// ── No catch-all when no safe match exists ───────────────────────────────────
$index = array( row( 'totally-unrelated-blog-post-about-boxes' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'household-movers-belmont-ca', $index, 'same-city-or-hub', $T );
check( 'no catch-all: unrelated corpus -> none (plain 404)', $d['action'] === 'none' );
// Non-commercial family never hub-falls-back either.
$index = array( row( 'commercial-movers' ), row( 'office-movers' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'household-movers-belmont-ca', $index, 'same-city-or-hub', $T );
check( 'no catch-all: household slug never falls back to a mover hub', $d['action'] === 'none' );

// ── Legacy mode preserves old behavior ───────────────────────────────────────
$index = array( row( 'business-moving-company-san-ramon-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'business-moving-company-san-bruno-ca', $index, 'legacy', $T );
check( 'legacy mode: cross-city still matches (unchanged pre-3.2.18 behavior)', $d['action'] === 'redirect' && strpos( $d['url'], 'san-ramon' ) !== false );
check( 'legacy mode recorded', $d['context']['mode'] === 'legacy' );
// Exact slug still short-circuits in both modes.
$index = array( row( 'commercial-movers-fairfield-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'commercial-movers-fairfield-ca', $index, 'same-city-or-hub', $T );
check( 'exact slug match still wins in constrained mode', $d['action'] === 'redirect' && $d['score'] === 1.0 );

// ── Off mode: maybe_route() returns before scoring; the decision core is never
//    called. current_mode() validation is the testable seam without WP:
check( 'mode whitelist: bogus option value falls back to legacy', in_array( 'weird-value', Ratesight_Runtime_404_Router::MODES, true ) === false );
check( "mode whitelist: 'off' is a valid mode", in_array( 'off', Ratesight_Runtime_404_Router::MODES, true ) );

// ── Review-panel regressions (2026-07-07 adversarial review) ─────────────────
// Directional-prefix cities are DIFFERENT cities (EPA != PA, SSF != SF).
check( 'review: east-palo-alto != palo-alto', Ratesight_Runtime_404_Router::cities_differ( 'movers-east-palo-alto-ca', 'movers-palo-alto-ca' ) );
check( 'review: south-san-francisco != san-francisco', Ratesight_Runtime_404_Router::cities_differ( 'moving-company-south-san-francisco-ca', 'moving-company-san-francisco-ca' ) );
$index = array( row( 'movers-palo-alto-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'movers-east-palo-alto-ca', $index, 'same-city-or-hub', $T );
check( 'review: EPA must not redirect to PA (was score 0.76 pre-fix)', $d['action'] !== 'redirect' );
$index = array( row( 'moving-company-san-francisco-ca' ) );
$d     = Ratesight_Runtime_404_Router::route_decision( 'moving-company-south-san-francisco-ca', $index, 'same-city-or-hub', $T );
check( 'review: SSF must not redirect to SF (was score 0.81 pre-fix)', $d['action'] !== 'redirect' );
// ...while same-city slug variants (service-word divergence) stay eligible.
check( 'review: service-word divergence still same city', ! Ratesight_Runtime_404_Router::cities_differ( 'local-moving-companies-hercules-ca', 'local-moving-company-hercules-ca' ) );
// WP slug-collision suffix must not launder a city slug into "city-less".
check( 'review: dedup suffix -2 keeps the city', Ratesight_Runtime_404_Router::city_of_slug( 'movers-san-ramon-ca-2' ) === 'ramon' );
check( 'review: dedup suffix cross-city still blocked', Ratesight_Runtime_404_Router::cities_differ( 'movers-san-bruno-ca', 'movers-san-ramon-ca-2' ) );

// current_mode(): whitelist enforced through the REAL read path (stubbed options).
Ratesight_Options::$fuzzy_mode = 'weird-value';
check( 'review: current_mode() falls back to legacy on bogus stored value', Ratesight_Runtime_404_Router::current_mode() === 'legacy' );
Ratesight_Options::$fuzzy_mode = 'off';
check( "review: current_mode() honours 'off'", Ratesight_Runtime_404_Router::current_mode() === 'off' );
Ratesight_Options::$fuzzy_mode = 'same-city-or-hub';
check( "review: current_mode() honours 'same-city-or-hub'", Ratesight_Runtime_404_Router::current_mode() === 'same-city-or-hub' );
Ratesight_Options::$fuzzy_mode = 'legacy';

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
