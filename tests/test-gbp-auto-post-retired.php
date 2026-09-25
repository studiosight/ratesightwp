<?php
// Guards the 3.13.0 retirement of plugin-side Google Business Profile
// auto-posting. The dashboard owns blog to GBP posts; the plugin must never
// call the GBP localPosts API when a Ratesight post is published.

$root     = dirname( __DIR__ );
$checks   = 0;
$failures = 0;
function check_retired( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$publisher = file_get_contents( $root . '/includes/class-ratesight-publisher.php' );
$options   = file_get_contents( $root . '/includes/class-ratesight-options.php' );
$webhook   = file_get_contents( $root . '/includes/class-ratesight-webhook-handler.php' );
$admin_tab = file_get_contents( $root . '/admin/partials/tab-connections.php' );

check_retired( 'publisher never calls post_to_gbp from the publish flow', ! preg_match( '/self::post_to_gbp\s*\(/', $publisher ) );
check_retired( 'publisher never reads the legacy gbp_post_enabled option', strpos( $publisher, "'gbp_post_enabled'" ) === false );
check_retired( 'publisher never calls the GBP client create_post', strpos( $publisher, 'Ratesight_GBP_Client::create_post' ) === false );
check_retired( 'post_to_gbp stub returns the retired error', strpos( $publisher, 'rs_gbp_post_retired' ) !== false );
check_retired( 'legacy option defaults off', (bool) preg_match( "/'ratesight_gbp_post_enabled',\s*'default'\s*=>\s*0/", $options ) );
check_retired( 'capabilities report gbp_auto_post false', (bool) preg_match( "/'gbp_auto_post'\s*=>\s*false/", $webhook ) );
check_retired( 'admin checkbox is disabled', (bool) preg_match( '/name="ratesight_gbp_post_enabled"[^>]*disabled/', $admin_tab ) );

$callers = array();
foreach ( array( 'includes', 'admin', 'public' ) as $directory ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->getExtension() !== 'php' ) continue;
		$source = file_get_contents( $file->getRealPath() );
		if ( preg_match( '/Ratesight_GBP_Client::create_post\s*\(/', $source ) ) $callers[] = $file->getFilename();
	}
}
check_retired( 'no plugin code path creates GBP local posts', $callers === array() );

echo "{$checks} checks, {$failures} failures" . PHP_EOL;
exit( $failures ? 1 : 0 );
