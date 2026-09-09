<?php

$checks = 0;
$failures = 0;

function check_client_navigation_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ratesight-admin.php' );
$wrapper = file_get_contents( $root . '/admin/partials/page-wrapper.php' );
$support = file_get_contents( $root . '/admin/partials/tab-support.php' );

$client_menu_start = strpos( $admin, '$client_tabs = array(' );
$client_menu_end = strpos( $admin, ');', $client_menu_start );
$client_menu = substr( $admin, $client_menu_start, $client_menu_end - $client_menu_start );

check_client_navigation_case( 'Overview is the default Ratesight destination', str_contains( $admin, ": 'performance';" ) && str_contains( $admin, '$entry[0] = \'Overview\';' ) );
check_client_navigation_case( 'primary client menu contains Publishing, Activity Log, Reviews, and Support', str_contains( $client_menu, "'seo-pages' => 'Publishing'" ) && str_contains( $client_menu, "'logs'      => 'Activity Log'" ) && str_contains( $client_menu, "'widgets'   => 'Reviews & Widgets'" ) && str_contains( $client_menu, "'support'   => 'Support'" ) );
check_client_navigation_case( 'credential and technical reference pages remain absent from the primary client menu', ! preg_match( '/connections|links|help|Reference|Settings/', $client_menu ) );
check_client_navigation_case( 'SEO Content replaces the internal Pages label', substr_count( $admin, "__( 'SEO Content', 'ratesight' )") === 2 && ! str_contains( $admin, "__( 'Pages', 'ratesight' )" ) );
check_client_navigation_case( 'duplicate in-page tab navigation is removed', ! str_contains( $wrapper, 'id="rs-tabs"' ) && ! str_contains( $wrapper, 'nav-tab-wrapper' ) );
check_client_navigation_case( 'technical header chrome is removed', ! str_contains( $wrapper, 'Agency' ) && ! str_contains( $wrapper, 'rs-header-ver' ) && ! str_contains( $wrapper, 'rs-chips' ) && ! str_contains( $wrapper, 'detected_plugins()' ) );
check_client_navigation_case( 'technical support routes remain available without primary links', str_contains( $wrapper, "'connections' => 'tab-connections.php'") && str_contains( $wrapper, "'seo-pages'   => 'tab-seo-pages.php'") && str_contains( $wrapper, "'links'       => 'tab-links.php'") && str_contains( $wrapper, "'logs'        => 'tab-logs.php'") && str_contains( $wrapper, "'help'        => 'tab-help.php'") && str_contains( $wrapper, "'widget-settings' => 'tab-widget-settings.php'") && ! str_contains( $client_menu, 'connections' ) && ! str_contains( $client_menu, 'links' ) && ! str_contains( $client_menu, 'help' ) && ! str_contains( $client_menu, 'widget-settings' ) );
check_client_navigation_case( 'Support explains value without dashboard or auth internals', str_contains( $support, 'How Ratesight Helps' ) && str_contains( $support, 'support@ratesight.com' ) && ! preg_match( '/dashboard|webhook|auth mode|secret|credential/i', $support ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} client-navigation checks\n";
