<?php
define( 'ABSPATH', __DIR__ );
define( 'RATESIGHT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
$options = array();
$active = true;
$auth = array( 'configured' => false, 'mode' => 'legacy', 'readiness_current' => false );
$enrollment = array( 'blocked' => false, 'accepted' => false, 'outcome' => 'retrying' );
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function plugin_basename( $path ) { return basename( $path ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
class Ratesight_Installation { public static function status( $path ) { global $active; return array( 'active' => $active ); } }
class Ratesight_Request_Auth { public static function capability_auth() { global $auth; return $auth; } }
class Ratesight_Enrollment { public static function status() { global $enrollment; return $enrollment; } }
require __DIR__ . '/../includes/class-ratesight-pairing.php';
function check( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function render_connection() { ob_start(); require __DIR__ . '/../admin/partials/publishing-connection-status.php'; return ob_get_clean(); }

$options['ratesight_code_id'] = '2';
$html = render_connection();
check( strpos( $html, '>Connected<' ) === false, 'code_id alone must not show Connected' );
check( strpos( $html, 'Setup in progress' ) !== false, 'unpaired setup is pending' );
$enrollment['accepted'] = true;
check( strpos( render_connection(), 'Pending approval' ) !== false, 'accepted enrollment is not pairing' );
$options['ratesight_pairing_receipt'] = array( 'source' => 'dashboard', 'paired_at' => time() );
check( strpos( render_connection(), 'Needs repair' ) !== false, 'saved pairing without auth needs repair' );
$auth = array( 'configured' => true, 'mode' => 'observe_v2', 'readiness_current' => true );
$html = render_connection();
check( strpos( $html, '>Connected<' ) !== false, 'actual pairing plus configured V2 auth is connected' );
check( strpos( $html, 'does not verify an actual CRM blog delivery' ) !== false, 'do not claim publishing proof' );
$auth['readiness_current'] = false;
$html = render_connection();
check( strpos( $html, '>Connected<' ) !== false && strpos( $html, 'readiness needs a fresh check' ) !== false, 'expired readiness is separate from connection truth' );
$enrollment['blocked'] = true;
$html = render_connection();
check( strpos( $html, '>Blocked<' ) !== false && strpos( $html, '>Connected<' ) === false, 'explicit block remains visible' );
$enrollment['blocked'] = false;
$active = false;
check( strpos( render_connection(), '>Connected<' ) === false, 'inactive installation is not connected' );
$active = true;
$auth['mode'] = 'legacy';
check( strpos( render_connection(), '>Connected<' ) === false, 'legacy auth is not signed connection' );
check( strpos( file_get_contents( __DIR__ . '/../admin/partials/tab-seo-pages.php' ), "require __DIR__ . '/publishing-connection-status.php'" ) !== false, 'actual Publishing tab must use the tested partial' );
echo "Publishing connection render checks passed\n";
