<?php

define( 'ABSPATH', sys_get_temp_dir() . '/' );
$options = array();
$writes = array();

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}
class WP_REST_Request {
	public function __construct( private array $payload = array() ) {}
	public function get_json_params(): array { return $this->payload; }
}
class WP_REST_Response {
	public function __construct( public $data, public int $status ) {}
}
class Ratesight_Options {
	public static function get( string $key ) { return 'code_id' === $key ? '170652' : ''; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
function esc_html( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ): string { return esc_html( $value ); }
function esc_url( $value ): string { return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : ''; }
function number_format_i18n( $value, $decimals = 0 ): string { return number_format( (float) $value, (int) $decimals ); }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ): bool { global $options, $writes; $options[ $key ] = $value; $writes[] = array( $key, $autoload ); return true; }

require __DIR__ . '/../includes/class-ratesight-performance-snapshot.php';

$now = strtotime( '2026-09-07T20:00:00Z' );
$payload = array(
	'contract' => Ratesight_Performance_Snapshot::CONTRACT,
	'siteId' => '170652',
	'generatedAt' => '2026-09-07T19:59:00Z',
	'windowDays' => 28,
	'dashboardUrl' => 'https://attacker.invalid/',
	'organic' => array(
		'state' => 'available',
		'latestMetricDate' => '2026-09-06',
		'freshnessState' => 'current',
		'currentRange' => array( 'start' => '2026-08-10', 'end' => '2026-09-06' ),
		'comparisonRange' => array( 'start' => '2026-07-13', 'end' => '2026-08-09' ),
		'currentDates' => 28,
		'expectedDates' => 28,
		'metrics' => array(
			'clicks' => array( 'current' => 12, 'baseline' => 10, 'delta' => 2, 'percentDelta' => 0.2, 'pointDelta' => null, 'direction' => 'improved', 'directionEligible' => true ),
			'impressions' => array( 'current' => 1200, 'baseline' => 1000, 'delta' => 200, 'percentDelta' => 0.2, 'pointDelta' => null, 'direction' => 'improved', 'directionEligible' => true ),
			'ctr' => array( 'current' => 0.01, 'baseline' => 0.01, 'delta' => 0, 'percentDelta' => 0, 'pointDelta' => 0, 'direction' => 'flat', 'directionEligible' => true ),
			'position' => array( 'current' => 8.5, 'baseline' => 9.2, 'delta' => -0.7, 'percentDelta' => -0.076, 'pointDelta' => null, 'direction' => 'improved', 'directionEligible' => true ),
		),
		'daily' => array( array( 'date' => '2026-09-06', 'clicks' => 2, 'impressions' => 100, 'ctr' => 0.02, 'position' => 8.5 ) ),
		'queries' => array( array( 'value' => '<b>window cleaning</b>', 'current' => array( 'clicks' => 3, 'impressions' => 90, 'ctr' => 0.033, 'position' => 4.2 ) ) ),
	),
	'local' => array(
		'state' => 'available',
		'latestMetricDate' => '2026-09-06',
		'metrics' => array(
			'impressions' => array( 'current' => 900, 'complete' => true ),
			'calls' => array( 'current' => 14, 'complete' => true ),
			'directions' => array( 'current' => 8, 'complete' => true ),
			'websiteClicks' => array( 'current' => 31, 'complete' => false ),
		),
	),
	'rankings' => array(
		'state' => 'partial', 'latestMetricDate' => '2026-09-06', 'tracked' => 2, 'ranking' => 1, 'notRanking' => 1, 'top3' => 1,
		'keywords' => array(
			array( 'keyword' => '<b>window cleaning</b>', 'target' => 'Brentwood', 'bestRank' => 2, 'visibilityPct' => 80, 'date' => '2026-09-06', 'trust' => 'trusted' ),
			array( 'keyword' => 'gutter cleaning', 'target' => 'Brentwood', 'bestRank' => null, 'visibilityPct' => 0, 'date' => '2026-09-06', 'trust' => 'untrusted' ),
		),
	),
	'work' => array( 'state' => 'available', 'applied' => 12, 'skipped' => 2, 'measured' => 4, 'improved' => 2, 'regressed' => 1, 'maturing' => 3, 'ungradable' => 1, 'moveRate' => 0.5 ),
);

$failures = 0;
function check_snapshot_case( string $label, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
}

ob_start();
require __DIR__ . '/../admin/partials/tab-performance-dashboard.php';
$empty_rendered = ob_get_clean();
check_snapshot_case( 'WordPress renders the waiting state before the first snapshot', str_contains( $empty_rendered, 'Waiting for the first dashboard snapshot.' ) );

$normalized = Ratesight_Performance_Snapshot::normalize( $payload, '170652', $now );
check_snapshot_case( 'valid snapshot normalizes', is_array( $normalized ) );
check_snapshot_case( 'dashboard URL is canonical and not caller controlled', $normalized['dashboardUrl'] === 'https://dash.ratesight.com/seo/170652/rank' );
check_snapshot_case( 'query text is sanitized', $normalized['organic']['queries'][0]['value'] === 'window cleaning' );
check_snapshot_case( 'metrics remain numeric and bounded', $normalized['organic']['metrics']['clicks']['current'] === 12.0 && count( $normalized['organic']['daily'] ) === 1 );
check_snapshot_case( 'Business Profile totals normalize', $normalized['local']['metrics']['calls']['current'] === 14.0 && false === $normalized['local']['metrics']['websiteClicks']['complete'] );
check_snapshot_case( 'ranking rows are sanitized and bounded', $normalized['rankings']['keywords'][0]['keyword'] === 'window cleaning' && $normalized['rankings']['top3'] === 1 );
check_snapshot_case( 'completed work remains aggregate only', $normalized['work']['applied'] === 12 && $normalized['work']['moveRate'] === 0.5 );
$organic_only = $payload;
unset( $organic_only['local'], $organic_only['rankings'], $organic_only['work'] );
$organic_only_normalized = Ratesight_Performance_Snapshot::normalize( $organic_only, '170652', $now );
check_snapshot_case( 'organic-only v1 snapshots remain compatible', is_array( $organic_only_normalized ) && ! isset( $organic_only_normalized['local'] ) && ! isset( $organic_only_normalized['rankings'] ) && ! isset( $organic_only_normalized['work'] ) );

$wrong_site = $payload;
$wrong_site['siteId'] = '2';
check_snapshot_case( 'cross-site snapshot is refused', Ratesight_Performance_Snapshot::normalize( $wrong_site, '170652', $now ) instanceof WP_Error );
$too_many = $payload;
$too_many['organic']['daily'] = array_fill( 0, 29, $payload['organic']['daily'][0] );
check_snapshot_case( 'unbounded daily rows are refused', Ratesight_Performance_Snapshot::normalize( $too_many, '170652', $now ) instanceof WP_Error );
$future = $payload;
$future['generatedAt'] = '2026-09-08T20:00:00Z';
check_snapshot_case( 'future snapshot is refused', Ratesight_Performance_Snapshot::normalize( $future, '170652', $now ) instanceof WP_Error );
$negative = $payload;
$negative['organic']['daily'][0]['clicks'] = -1;
check_snapshot_case( 'negative daily totals are refused', Ratesight_Performance_Snapshot::normalize( $negative, '170652', $now ) instanceof WP_Error );
$too_many_rankings = $payload;
$too_many_rankings['rankings']['keywords'] = array_fill( 0, 11, $payload['rankings']['keywords'][0] );
check_snapshot_case( 'unbounded ranking rows are refused', Ratesight_Performance_Snapshot::normalize( $too_many_rankings, '170652', $now ) instanceof WP_Error );
$bad_work = $payload;
$bad_work['work']['applied'] = -1;
check_snapshot_case( 'negative completed-work totals are refused', Ratesight_Performance_Snapshot::normalize( $bad_work, '170652', $now ) instanceof WP_Error );

$options[ Ratesight_Performance_Snapshot::OPTION ] = $normalized;
$older = $payload;
$older['generatedAt'] = '2026-09-07T19:58:00Z';
$result = Ratesight_Performance_Snapshot::handle_write( new WP_REST_Request( $older ) );
check_snapshot_case( 'older snapshot cannot replace last good state', $result instanceof WP_Error && $options[ Ratesight_Performance_Snapshot::OPTION ]['generatedAt'] === '2026-09-07T19:59:00Z' );

$newer = $payload;
$newer['generatedAt'] = gmdate( 'Y-m-d\TH:i:s\Z' );
$result = Ratesight_Performance_Snapshot::handle_write( new WP_REST_Request( $newer ) );
check_snapshot_case( 'newer snapshot stores successfully', $result instanceof WP_REST_Response && $result->status === 200 && $result->data['stored'] === true );
check_snapshot_case( 'snapshot option disables autoload', end( $writes ) === array( Ratesight_Performance_Snapshot::OPTION, false ) );
check_snapshot_case( 'write receipt does not echo metric rows', ! isset( $result->data['snapshot'] ) && isset( $result->data['digest'] ) );

$read = Ratesight_Performance_Snapshot::handle_read( new WP_REST_Request() );
check_snapshot_case( 'signed read returns stored sanitized snapshot', $read->status === 200 && $read->data['stored'] === true && $read->data['snapshot']['siteId'] === '170652' );

ob_start();
require __DIR__ . '/../admin/partials/tab-performance-dashboard.php';
$rendered = ob_get_clean();
check_snapshot_case( 'WordPress renders dashboard metric values', str_contains( $rendered, '>12<' ) && str_contains( $rendered, '>1,200<' ) );
check_snapshot_case( 'WordPress renders sanitized query evidence', str_contains( $rendered, 'window cleaning' ) && ! str_contains( $rendered, '<b>window cleaning</b>' ) );
check_snapshot_case( 'WordPress renders Business Profile performance', str_contains( $rendered, 'Business Profile' ) && str_contains( $rendered, '>14<' ) );
check_snapshot_case( 'WordPress renders tracked rankings', str_contains( $rendered, 'Tracked rankings' ) && str_contains( $rendered, 'Brentwood' ) );
check_snapshot_case( 'WordPress renders completed work and outcomes', str_contains( $rendered, 'Completed SEO work' ) && str_contains( $rendered, '50%' ) );
check_snapshot_case( 'WordPress does not render a dashboard CTA', ! str_contains( $rendered, 'Open Performance in Ratesight' ) );

echo $failures ? "{$failures} PERFORMANCE SNAPSHOT CHECKS FAILED\n" : "ALL PERFORMANCE SNAPSHOT CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
