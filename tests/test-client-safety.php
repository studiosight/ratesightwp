<?php

$checks = 0;
$failures = 0;

function check_client_safety_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$root = dirname( __DIR__ );
$wizard = file_get_contents( $root . '/admin/partials/inc-setup-wizard.php' );
$admin = file_get_contents( $root . '/admin/class-ratesight-admin.php' );
$loader = file_get_contents( $root . '/includes/class-ratesight.php' );
$notifier = file_get_contents( $root . '/includes/class-ratesight-notifier.php' );
$settings = file_get_contents( $root . '/admin/partials/tab-seo-pages.php' );
$connections = file_get_contents( $root . '/admin/partials/tab-connections-dashboard.php' );
$performance = file_get_contents( $root . '/admin/partials/tab-performance-dashboard.php' );
$plugin = file_get_contents( $root . '/ratesight.php' );

check_client_safety_case( 'setup wizard contains only WordPress-local client steps', ! str_contains( $wizard, "'id'      => 'gsc'" ) && ! str_contains( $wizard, "'id'      => 'gbp'" ) && str_contains( $wizard, 'No action is needed here.' ) );
check_client_safety_case( 'legacy provider revocation notice is not registered or rendered', ! str_contains( $loader, "'revocation_notice'" ) && ! str_contains( $admin, 'public function revocation_notice' ) );
check_client_safety_case( 'operational email settings are absent from the client UI', ! str_contains( $settings, 'ratesight_notify_enabled' ) && ! str_contains( $settings, 'ratesight_notify_email' ) && ! str_contains( $admin, "register_setting( 'ratesight_options_seo_pages', 'ratesight_notify" ) );
check_client_safety_case( 'retired notifier cannot schedule or send client email', str_contains( $notifier, 'self::unschedule();' ) && str_contains( $notifier, 'return false;' ) && ! str_contains( $notifier, 'wp_mail(' ) && ! str_contains( $loader, "add_action( 'ratesight_daily_digest'" ) );
check_client_safety_case( 'connection surface hides provider and authentication internals', str_contains( $connections, 'There is nothing you need to connect in WordPress.') && ! str_contains( $connections, 'Ratesight ID</th>') && ! str_contains( $connections, 'Signed API' ) && ! str_contains( $connections, 'Legacy Provider State' ) && ! str_contains( $connections, 'Open Dashboard Connections' ) );
check_client_safety_case( 'settings connection status does not expose employee controls', str_contains( $settings, 'There is nothing you need to connect here.' ) && ! str_contains( $settings, 'Manage Connection in Dashboard' ) && ! str_contains( $settings, "['mode']" ) && ! str_contains( $settings, 'signed readiness current' ) );
check_client_safety_case( 'missing performance setup gives calm honest guidance', str_contains( $performance, 'Your results are being prepared' ) && str_contains( $performance, 'No action is needed.' ) && ! str_contains( $performance, 'Add the Ratesight ID on the Connections tab' ) );
check_client_safety_case( 'license notices avoid implementation failure details', substr_count( $plugin . $admin, 'Ratesight setup needs attention.') === 2 && ! str_contains( $plugin, 'Plugin disabled —' ) && ! str_contains( $admin, 'RS Pages are hidden and the webhook is disabled' ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} client-safety checks\n";
