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
check_dashboard_performance_case( 'canonical Ratesight casing is used', str_contains( $dashboard, 'Ratesight Dashboard' ) );
check_dashboard_performance_case( 'dashboard CTA is removed', ! str_contains( $dashboard, 'Open Performance in Ratesight' ) && ! str_contains( $dashboard, 'button button-primary' ) );
check_dashboard_performance_case( 'duplicate provider connections are explicitly unnecessary', str_contains( $dashboard, 'do not need to connect those providers again in WordPress' ) );
check_dashboard_performance_case( 'provider credential copying is explicitly denied', str_contains( $dashboard, 'No Google, Bing, or Business Profile credentials are copied' ) );
check_dashboard_performance_case( 'stored dashboard metrics render locally', str_contains( $dashboard, 'Ratesight_Performance_Snapshot::OPTION' ) && str_contains( $dashboard, 'Ranking wins' ) );
check_dashboard_performance_case( 'expanded performance sections render locally', str_contains( $dashboard, 'Business Profile' ) && str_contains( $dashboard, 'Ranking highlights' ) && str_contains( $dashboard, 'Completed SEO work' ) );
check_dashboard_performance_case( 'single ranking target is summarized once', str_contains( $dashboard, 'Tracking area:' ) && str_contains( $dashboard, 'count( $ranking_targets ) > 1' ) );
check_dashboard_performance_case( 'client surface is positive-only', str_contains( $dashboard, 'Close to page one' ) && str_contains( $dashboard, 'Biggest improvements' ) && str_contains( $dashboard, "'improved' ===" ) && ! str_contains( $dashboard, 'Not ranking' ) && ! str_contains( $dashboard, 'scan unavailable' ) && ! str_contains( $dashboard, "'notRanking' =>" ) );

echo "All dashboard-owned performance tests passed.\n";
