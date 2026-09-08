<?php

$checks = 0;
$failures = 0;

function check_client_trust_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$root = dirname( __DIR__ );
$pairing = file_get_contents( $root . '/includes/class-ratesight-pairing.php' );
$wizard = file_get_contents( $root . '/admin/partials/inc-setup-wizard.php' );
$support = file_get_contents( $root . '/admin/partials/tab-support.php' );
$connections = file_get_contents( $root . '/admin/partials/tab-connections-dashboard.php' );
$performance = file_get_contents( $root . '/admin/partials/tab-performance-dashboard.php' );
$cpt = file_get_contents( $root . '/includes/class-ratesight-cpt.php' );
$bulk = file_get_contents( $root . '/includes/class-ratesight-bulk-operations.php' );

check_client_trust_case( 'connection helper requires dashboard pairing and configured signed auth', str_contains( $pairing, 'function is_connected' ) && str_contains( $pairing, "'dashboard' ===" ) && str_contains( $pairing, "true === ( \$auth['configured']" ) && str_contains( $pairing, "array( 'observe_v2', 'enforce_v2' )" ) );
check_client_trust_case( 'signed service surfaces require proven pairing while setup accepts a manual account ID', str_contains( $wizard, "Ratesight_Options::get( 'code_id' )" ) && str_contains( $support, 'Ratesight_Pairing::is_connected()' ) && str_contains( $connections, 'Ratesight_Pairing::is_connected()' ) && ! str_contains( $support, "Ratesight_Options::get( 'code_id' )" ) );
check_client_trust_case( 'stale snapshots receive calm historical labeling', str_contains( $performance, "'current' !== ( \$organic['freshnessState']" ) && str_contains( $performance, 'time() - ( 2 * 86400 )' ) && str_contains( $performance, 'Most recent verified results' ) && str_contains( $performance, 'We are refreshing this report.' ) );
check_client_trust_case( 'client sees a verified-through date instead of hidden freshness mechanics', str_contains( $performance, 'Results verified through ' ) && ! str_contains( $performance, 'freshnessState</' ) );
check_client_trust_case( 'dashboard-managed SEO Content blocks WordPress create edit and delete', str_contains( $cpt, "'create_posts' => 'do_not_allow'" ) && str_contains( $cpt, "'edit_post'    => 'do_not_allow'" ) && str_contains( $cpt, "'delete_post'  => 'do_not_allow'" ) );
check_client_trust_case( 'technical bulk actions are absent from SEO Content', str_contains( $bulk, "'bulk_actions-edit-ratesight_page' === current_filter()" ) && str_contains( $bulk, 'return array();' ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} client trust checks\n";
