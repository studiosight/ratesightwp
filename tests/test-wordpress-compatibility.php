<?php
// Isolated core files only; no real WordPress or customer mutation.
$root = sys_get_temp_dir() . '/ratesight-core-version-' . getmypid();
mkdir( $root . '/wp-includes', 0777, true );
define( 'ABSPATH', $root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'RATESIGHT_RELEASE_VERSION', '3.15.5' );
$wp_version = '42';
$paired = true;
$routes = array();
class WP_REST_Server { const READABLE = 'GET'; }
class WP_REST_Request {}
class WP_REST_Response { public function __construct( public $data, public $status ) {} }
class WP_Error { public function __construct( public $code, public $message, public $data ) {} }
class Ratesight_Options { public static function get( $key ) { return '2'; } }
class Ratesight_Pairing { public static function is_connected() { global $paired; return $paired; } }
function register_rest_route( $namespace, $route, $definition ) { global $routes; $routes[$namespace . $route] = $definition; }
require __DIR__ . '/../includes/class-ratesight-wordpress-compatibility.php';
$failures = 0;
function check_core( $label, $ok ) { global $failures; echo ($ok ? 'ok ' : 'NOT OK ') . $label . PHP_EOL; if (!$ok) $failures++; }
function core_file( $version ) { global $root; file_put_contents( $root . '/wp-includes/version.php', '<?php $wp_version = ' . var_export( $version, true ) . ';' ); }
core_file( '6.6.2' );
check_core( 'older WordPress reads local core, not spoofed global', Ratesight_WordPress_Compatibility::core_version() === '6.6.2' && $wp_version === '42' );
core_file( '6.9-RC1' );
check_core( 'prerelease version is retained, not silently promoted to stable', Ratesight_WordPress_Compatibility::core_version() === '6.9-RC1' );
core_file( '42' );
check_core( 'invalid or masked core value is unknown', Ratesight_WordPress_Compatibility::core_version() === null );
core_file( '42.0' );
check_core( 'implausible major cannot bypass compatibility', Ratesight_WordPress_Compatibility::core_version() === null );
unlink( $root . '/wp-includes/version.php' );
check_core( 'missing core is unknown', Ratesight_WordPress_Compatibility::core_version() === null );
core_file( '6.6.2' );
Ratesight_WordPress_Compatibility::register_route();
check_core( 'private route requires existing signed read authorization', $routes['ratesight/v1/wordpress-compatibility']['permission_callback'] === array( 'Ratesight_Request_Auth', 'authorize_read' ) );
$response = Ratesight_WordPress_Compatibility::handle( new WP_REST_Request() );
check_core( 'evidence binds the client and exact plugin version without secrets', $response->data === array( 'contract' => 'ratesight-wordpress-compatibility-v1', 'oid' => '2', 'pluginVersion' => '3.15.5', 'wordpressVersion' => '6.6.2', 'source' => 'wordpress_core' ) );
$paired = false;
check_core( 'unpaired site cannot return evidence', Ratesight_WordPress_Compatibility::handle( new WP_REST_Request() ) instanceof WP_Error );
// Define the modern API only after exercising the older-WordPress fallback.
eval( 'function wp_get_wp_version() { return "6.8.3"; }' );
check_core( 'modern WordPress uses the unmodified core API', Ratesight_WordPress_Compatibility::core_version() === '6.8.3' && $wp_version === '42' );
unlink( $root . '/wp-includes/version.php' );
rmdir( $root . '/wp-includes' );
rmdir( $root );
exit( $failures ? 1 : 0 );
