<?php

$performance_file = __DIR__ . '/../admin/partials/tab-performance.php';
$dashboard_file = __DIR__ . '/../admin/partials/tab-performance-dashboard.php';
$performance = file_get_contents( $performance_file );
$dashboard = file_get_contents( $dashboard_file );

function check_dashboard_performance_case( string $label, bool $condition ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
	echo "PASS: {$label}\n";
}

$include_position = strpos( $performance, "require __DIR__ . '/tab-performance-dashboard.php';" );
$return_position = strpos( $performance, "return;", $include_position === false ? 0 : $include_position );
$legacy_position = strpos( $performance, 'Ratesight_GSC_Client::is_locked()' );

check_dashboard_performance_case( 'dashboard performance partial exists', is_string( $dashboard ) );
check_dashboard_performance_case( 'dashboard partial loads before legacy provider work', false !== $include_position && false !== $return_position && false !== $legacy_position && $include_position < $return_position && $return_position < $legacy_position );
check_dashboard_performance_case( 'canonical Ratesight casing is used', str_contains( $dashboard, 'Ratesight' ) );
check_dashboard_performance_case( 'dashboard CTA is removed', ! str_contains( $dashboard, 'Open Performance in Ratesight' ) && ! str_contains( $dashboard, 'button button-primary' ) );
check_dashboard_performance_case( 'stored dashboard metrics render locally', str_contains( $dashboard, 'Ratesight_Performance_Snapshot::OPTION' ) && str_contains( $dashboard, 'How Customers Found You' ) );
check_dashboard_performance_case( 'outcome-led performance sections render locally', str_contains( $dashboard, 'Website visits from Google' ) && str_contains( $dashboard, 'Top 3 searches' ) && str_contains( $dashboard, 'SEO Improvements' ) );
check_dashboard_performance_case( 'single ranking target is summarized without repeated area columns', str_contains( $dashboard, '1 === count( $ranking_targets )' ) && str_contains( $dashboard, 'count( $ranking_targets ) > 1' ) );
check_dashboard_performance_case( 'client surface is positive-only', str_contains( $dashboard, 'Close to page one' ) && str_contains( $dashboard, 'Biggest movers' ) && str_contains( $dashboard, "'improved' ===" ) && ! str_contains( $dashboard, 'Not ranking' ) && ! str_contains( $dashboard, 'scan unavailable' ) && ! str_contains( $dashboard, "'notRanking' =>" ) );
check_dashboard_performance_case( 'technical and diagnostic presentation is absent', ! str_contains( $dashboard, 'Last dashboard snapshot' ) && ! str_contains( $dashboard, 'source through' ) && ! str_contains( $dashboard, 'Connected site ID' ) && ! str_contains( $dashboard, 'Partial data' ) && ! str_contains( $dashboard, 'Visibility</th>' ) );
check_dashboard_performance_case( 'implementation ratios are absent', ! str_contains( $dashboard, 'Changes applied' ) && ! str_contains( $dashboard, '>Measured<' ) && ! str_contains( $dashboard, 'moveRate' ) );

echo "All dashboard-owned performance tests passed.\n";
