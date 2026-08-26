<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
$registered = array();
function register_rest_route( $namespace, $path, $definitions ) {
	global $registered;
	$items = isset( $definitions['methods'] ) ? array( $definitions ) : $definitions;
	foreach ( $items as $definition ) {
		$registered[ $definition['methods'] . ' /' . $namespace . $path ] = $definition['permission_callback'];
	}
}
require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-webhook-handler.php';
require __DIR__ . '/../includes/class-ratesight-related-links.php';
require __DIR__ . '/../includes/class-ratesight-page-api.php';
require __DIR__ . '/../includes/class-ratesight-page-lifecycle.php';
( new Ratesight_Webhook_Handler() )->register_route();
Ratesight_Related_Links::register_routes();
( new Ratesight_Page_API() )->register_routes();
Ratesight_Page_Lifecycle::register_routes();

$failures = 0;
foreach ( Ratesight_Request_Auth::ROUTE_POLICIES as $route => $policy ) {
	$callback = $registered[ $route ] ?? null;
	$method = $policy === 'public_bootstrap' ? 'authorize_public' : ( $policy === 'signed_read' ? 'authorize_read' : 'authorize_mutation' );
	$ok = $callback === array( 'Ratesight_Request_Auth', $method );
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $route . ' => ' . $policy . PHP_EOL;
	if ( ! $ok ) $failures++;
}
$extra = array_diff( array_keys( $registered ), array_keys( Ratesight_Request_Auth::ROUTE_POLICIES ) );
if ( $extra ) {
	echo 'NOT OK unclassified routes: ' . implode( ', ', $extra ) . PHP_EOL;
	$failures += count( $extra );
}
echo $failures ? "{$failures} POLICY CHECKS FAILED\n" : 'ALL ' . count( $registered ) . " ROUTE POLICIES PASSED\n";
exit( $failures ? 1 : 0 );
