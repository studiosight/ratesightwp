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
check_dashboard_performance_case( 'configured site opens dashboard Results workspace', str_contains( $dashboard, "'https://dash.ratesight.com/seo/' . rawurlencode( \$ratesight_id ) . '/rank'" ) );
check_dashboard_performance_case( 'missing site ID has safe dashboard fallback', str_contains( $dashboard, "'https://dash.ratesight.com/seo'" ) );
check_dashboard_performance_case( 'canonical Ratesight casing is used', str_contains( $dashboard, 'Ratesight Dashboard' ) && str_contains( $dashboard, 'Open Performance in Ratesight' ) );
check_dashboard_performance_case( 'duplicate provider connections are explicitly unnecessary', str_contains( $dashboard, 'do not need to connect those providers again in WordPress' ) );
check_dashboard_performance_case( 'credential and metric copying is explicitly denied', str_contains( $dashboard, 'No Google, Bing, or Business Profile credentials or performance records are copied' ) );
check_dashboard_performance_case( 'dashboard URL is escaped at render boundary', str_contains( $dashboard, 'esc_url( $dashboard_url )' ) );

echo "All dashboard-owned performance tests passed.\n";
