<?php

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'RATESIGHT_RELEASE_VERSION', '3.10.0' );
define( 'RATESIGHT_PLUGIN_DIR', sys_get_temp_dir() . '/wp-content/plugins/ratesight/' );

$control_signature_valid = true;
$dashboard_connected = true;
$file_modifications_allowed = true;

class WP_Error {
	public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_REST_Response {
	public function __construct( public array $data, public int $status ) {}
}
class WP_REST_Request {
	public function __construct( private array $params ) {}
	public function get_json_params(): array { return $this->params; }
}
class WP_REST_Server { public const CREATABLE = 'POST'; }
class WP_Automatic_Updater {
	public function should_update( $type, $item, $context ) { return false; }
}
class Ratesight_Request_Auth { public static function authorize_mutation() { return true; } }
class Ratesight_Pairing {
	public static function verify_control_plane_signature( string $body, string $signature ): bool { global $control_signature_valid; return $control_signature_valid; }
	public static function is_connected(): bool { global $dashboard_connected; return $dashboard_connected; }
}
function get_bloginfo() { return '6.6.0'; }
function wp_is_file_mod_allowed() { global $file_modifications_allowed; return $file_modifications_allowed; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function register_rest_route() {}
function get_plugin_data( $file ) {
	$contents = file_get_contents( $file );
	preg_match( '/Plugin Name:\s*(.+)/', $contents, $name );
	preg_match( '/Version:\s*([0-9.]+)/', $contents, $version );
	return array( 'Name' => trim( $name[1] ?? '' ), 'Version' => trim( $version[1] ?? '' ) );
}

require __DIR__ . '/../includes/class-ratesight-release-update.php';

$release_source = file_get_contents( __DIR__ . '/../includes/class-ratesight-release-update.php' );
$updater_method = new ReflectionMethod( Ratesight_Release_Update::class, 'explicit_updater' );
$explicit_updater = $updater_method->invoke( null );

function release_request( array $overrides = array(), string $action = 'preflight', bool $confirm = false ): WP_REST_Request {
	$manifest = array_merge( array(
		'contract' => Ratesight_Release_Update::CONTRACT,
		'version' => '3.10.1',
		'assetUrl' => 'https://github.com/studiosight/ratesightwp/releases/download/v3.10.1/ratesight-3.10.1-abcdef0.zip',
		'sha256' => str_repeat( 'a', 64 ),
		'minPhp' => '8.0',
		'minWordPress' => '6.3',
		'minManagedVersion' => '3.10.0',
	), $overrides );
	return new WP_REST_Request( array(
		'action' => $action,
		'confirm' => $confirm,
		'manifestJson' => json_encode( $manifest, JSON_UNESCAPED_SLASHES ),
		'signature' => str_repeat( 'A', 88 ),
	) );
}

$failures = 0;
function check_release_case( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}

$valid = Ratesight_Release_Update::handle( release_request() );
check_release_case( 'signed immutable Ratesight release is eligible', $valid instanceof WP_REST_Response && $valid->status === 200 && $valid->data['eligible'] === true && $valid->data['applySupported'] === true );
$wrong_host = Ratesight_Release_Update::handle( release_request( array( 'assetUrl' => 'https://attacker.invalid/ratesight.zip' ) ) );
check_release_case( 'non-Ratesight release host is refused', $wrong_host instanceof WP_Error && $wrong_host->get_error_code() === 'rs_release_manifest_invalid' );
$mutable_name = Ratesight_Release_Update::handle( release_request( array( 'assetUrl' => 'https://github.com/studiosight/ratesightwp/releases/download/v3.10.1/ratesight-3.10.1.zip' ) ) );
check_release_case( 'release asset requires an immutable commit suffix', $mutable_name instanceof WP_Error && $mutable_name->get_error_code() === 'rs_release_manifest_invalid' );
$old = Ratesight_Release_Update::handle( release_request( array( 'version' => '3.9.9', 'assetUrl' => 'https://github.com/studiosight/ratesightwp/releases/download/v3.9.9/ratesight-3.9.9-abcdef0.zip' ) ) );
check_release_case( 'non-newer target is ineligible', $old instanceof WP_REST_Response && in_array( 'target_not_newer', $old->data['blockedBy'], true ) );
$bridge = Ratesight_Release_Update::handle( release_request( array( 'minManagedVersion' => '3.10.1' ) ) );
check_release_case( 'too-old managed bridge is ineligible', $bridge instanceof WP_REST_Response && in_array( 'managed_bridge_too_old', $bridge->data['blockedBy'], true ) );
$dashboard_connected = false;
$unpaired = Ratesight_Release_Update::handle( release_request() );
$dashboard_connected = true;
check_release_case( 'unpaired site is ineligible', $unpaired instanceof WP_REST_Response && in_array( 'dashboard_pairing_required', $unpaired->data['blockedBy'], true ) );
$file_modifications_allowed = false;
$locked_host = Ratesight_Release_Update::handle( release_request() );
$file_modifications_allowed = true;
check_release_case( 'host-disabled file modifications are ineligible', $locked_host instanceof WP_REST_Response && in_array( 'file_modifications_disabled', $locked_host->data['blockedBy'], true ) );
$control_signature_valid = false;
$unsigned = Ratesight_Release_Update::handle( release_request() );
$control_signature_valid = true;
check_release_case( 'unknown release signer is refused', $unsigned instanceof WP_Error && $unsigned->get_error_code() === 'rs_release_signature_invalid' );
$unconfirmed = Ratesight_Release_Update::handle( release_request( array(), 'apply' ) );
check_release_case( 'apply requires literal confirmation', $unconfirmed instanceof WP_Error && $unconfirmed->get_error_code() === 'rs_release_confirmation_required' );
check_release_case( 'confirmed apply bypasses background-update eligibility policy', $explicit_updater->should_update( 'plugin', (object) array(), '' ) === true );
check_release_case( 'explicit updater remains scoped to plugin updates', $explicit_updater->should_update( 'theme', (object) array(), '' ) === false );
check_release_case( 'confirmed apply no longer depends on the auto-update preference filter', ! str_contains( $release_source, "add_filter( 'auto_update_plugin'" ) );

$package = sys_get_temp_dir() . '/ratesight-package-' . bin2hex( random_bytes( 8 ) );
mkdir( $package . '/ratesight', 0777, true );
file_put_contents( $package . '/ratesight/ratesight.php', "<?php\n/**\n * Plugin Name: Ratesight\n * Version: 3.10.1\n */\n" );
check_release_case( 'exact Ratesight package root and embedded version pass inspection', Ratesight_Release_Update::validate_extracted_package( $package, '3.10.1' ) === true );
mkdir( $package . '/extra' );
$extra_root = Ratesight_Release_Update::validate_extracted_package( $package, '3.10.1' );
check_release_case( 'additional package root is refused', $extra_root instanceof WP_Error && $extra_root->get_error_code() === 'rs_release_archive_layout_invalid' );
rmdir( $package . '/extra' );
$wrong_version = Ratesight_Release_Update::validate_extracted_package( $package, '3.10.2' );
check_release_case( 'embedded version mismatch is refused', $wrong_version instanceof WP_Error && $wrong_version->get_error_code() === 'rs_release_embedded_version_mismatch' );
$link_created = symlink( $package . '/ratesight/ratesight.php', $package . '/ratesight/linked.php' );
$linked = Ratesight_Release_Update::validate_extracted_package( $package, '3.10.1' );
check_release_case( 'symbolic link is refused', ! $link_created || ( $linked instanceof WP_Error && $linked->get_error_code() === 'rs_release_archive_layout_invalid' ) );

echo $failures ? "{$failures} RELEASE CHECKS FAILED\n" : "ALL RELEASE CHECKS PASSED\n";
exit( $failures ? 1 : 0 );
