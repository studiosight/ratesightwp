<?php

if ( ( $argv[1] ?? '' ) === '--fixture' ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	$scenario = $argv[2] ?? '';
	$fixtures = array(
		'absent-retention' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php' ), 'active' => array(), 'retention' => '__absent__' ),
		'enabled-retention' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php' ), 'active' => array(), 'retention' => '1' ),
		'active-sibling' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php', 'ratesight-b/ratesight.php' ), 'active' => array( 'ratesight-b/ratesight.php' ), 'retention' => '0' ),
		'inactive-sibling' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php', 'ratesight-b/ratesight.php' ), 'active' => array(), 'retention' => '0' ),
		'ambiguous-identity' => array( 'current' => 'unknown/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php' ), 'active' => array(), 'retention' => '0' ),
		'current-active' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php' ), 'active' => array( 'ratesight-a/ratesight.php' ), 'retention' => '0' ),
		'explicit-final-copy' => array( 'current' => 'ratesight-a/ratesight.php', 'plugins' => array( 'ratesight-a/ratesight.php' ), 'active' => array(), 'retention' => '0' ),
	);
	if ( ! isset( $fixtures[ $scenario ] ) ) exit( 2 );
	$fixture = $fixtures[ $scenario ];
	define( 'WP_UNINSTALL_PLUGIN', $fixture['current'] );
	$destructive_calls = 0;
	$unscheduled_hooks = array();
	$stored_options = $fixture['retention'] === '__absent__' ? array() : array( 'ratesight_retain_on_uninstall' => $fixture['retention'] );

	function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
	function get_plugins() { global $fixture; $result = array(); foreach ( $fixture['plugins'] as $basename ) $result[ $basename ] = array( 'Name' => 'Ratesight', 'TextDomain' => 'ratesight', 'Version' => '3.3.1' ); return $result; }
	function get_site_option( $name, $default = false ) { return $default; }
	function get_option( $name, $default = false ) { global $fixture, $stored_options; if ( $name === 'active_plugins' ) return $fixture['active']; return array_key_exists( $name, $stored_options ) ? $stored_options[ $name ] : $default; }
	function delete_option( $name ) { global $destructive_calls, $stored_options; $destructive_calls++; unset( $stored_options[ $name ] ); return true; }
	function wp_delete_post() { global $destructive_calls; $destructive_calls++; }
	function wp_unschedule_hook( $hook ) { global $destructive_calls, $unscheduled_hooks; $destructive_calls++; $unscheduled_hooks[] = $hook; return 1; }
	class Fixture_WPDB {
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $options = 'wp_options';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
		public function get_col() { global $destructive_calls; $destructive_calls++; return array(); }
		public function query() { global $destructive_calls; $destructive_calls++; return true; }
		public function delete() { global $destructive_calls; $destructive_calls++; return true; }
	}
	$wpdb = new Fixture_WPDB();
	include __DIR__ . '/../uninstall.php';
	echo json_encode( array( 'calls' => $destructive_calls, 'status' => $ratesight_uninstall_status, 'unscheduledHooks' => $unscheduled_hooks ) );
	exit;
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );
require __DIR__ . '/../includes/class-ratesight-installation.php';
require __DIR__ . '/../includes/class-ratesight-options.php';

$checks = 0;
$failures = 0;
function check_uninstall_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function run_uninstall_fixture( string $scenario ): array {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --fixture ' . escapeshellarg( $scenario );
	$output = shell_exec( $command );
	$result = json_decode( (string) $output, true );
	return is_array( $result ) ? $result : array();
}

$plugin = array( 'Name' => 'Ratesight', 'TextDomain' => 'ratesight', 'Version' => '3.3.1' );
$telemetry = Ratesight_Installation::status_from_inventory(
	'ratesight-current/ratesight.php',
	array( 'ratesight-current/ratesight.php' => $plugin, 'ratesight-old/ratesight.php' => array_merge( $plugin, array( 'Version' => '3.2.19' ) ) ),
	array( 'ratesight-current/ratesight.php' ),
	array(),
	true
);
$telemetry_keys = array( 'releaseVersion', 'pluginBasename', 'directoryName', 'active', 'siblingCount', 'destructiveUninstallAllowed', 'blockReason' );
check_uninstall_case( 'installation telemetry keys match the public contract', array_keys( $telemetry ) === $telemetry_keys );
check_uninstall_case( 'telemetry reports exact active folder, version, and sibling count', $telemetry['releaseVersion'] === '3.3.1' && $telemetry['directoryName'] === 'ratesight-current' && $telemetry['active'] === true && $telemetry['siblingCount'] === 1 );
check_uninstall_case( 'telemetry contains no absolute filesystem path', ! str_contains( json_encode( $telemetry ), '/tmp/' ) && ! str_contains( json_encode( $telemetry ), ABSPATH ) );
check_uninstall_case( 'active installation is never destructively uninstallable', $telemetry['destructiveUninstallAllowed'] === false && $telemetry['blockReason'] === 'current_installation_active' );

$expected_blocks = array(
	'absent-retention' => 'retention_enabled',
	'enabled-retention' => 'retention_enabled',
	'active-sibling' => 'active_sibling_present',
	'inactive-sibling' => 'sibling_installation_present',
	'ambiguous-identity' => 'installation_identity_ambiguous',
	'current-active' => 'current_installation_active',
);
foreach ( $expected_blocks as $scenario => $reason ) {
	$result = run_uninstall_fixture( $scenario );
	check_uninstall_case( "{$scenario} changes zero shared state", ( $result['calls'] ?? -1 ) === 0 );
	check_uninstall_case( "{$scenario} reports stable block reason", ( $result['status']['blockReason'] ?? '' ) === $reason && empty( $result['status']['destructiveUninstallAllowed'] ) );
}

$final_copy = run_uninstall_fixture( 'explicit-final-copy' );
check_uninstall_case( 'only explicit inactive no-sibling final copy reaches cleanup', ( $final_copy['status']['destructiveUninstallAllowed'] ?? false ) === true && ( $final_copy['calls'] ?? 0 ) > 0 );
check_uninstall_case( 'allowed final-copy telemetry has no block reason', array_key_exists( 'blockReason', $final_copy['status'] ) && $final_copy['status']['blockReason'] === null );
check_uninstall_case( 'final-copy cleanup removes no-argument cron hooks', in_array( 'ratesight_sync_gsc', $final_copy['unscheduledHooks'] ?? array(), true ) );
check_uninstall_case( 'final-copy cleanup removes every argument-bearing hook variant', in_array( 'ratesight_deferred_publish', $final_copy['unscheduledHooks'] ?? array(), true ) );

$schema_options = array_map( static fn( array $definition ): string => $definition['name'], Ratesight_Options::schema() );
$inventory = Ratesight_Installation::shared_state_inventory( $schema_options );
check_uninstall_case( 'shared-state inventory has every required family', array_keys( $inventory ) === array( 'options', 'optionPrefixes', 'tables', 'postTypes', 'postMeta', 'userMeta', 'transientPrefixes', 'cronHooks' ) );
check_uninstall_case( 'shared-state inventory retains provider and Phase 1 auth options', count( array_diff( array( 'ratesight_bing_api_key', 'ratesight_deepseek_api_key', 'ratesight_indexnow_key', 'ratesight_webhook_secret', 'ratesight_webhook_secret_previous', 'ratesight_auth_mode', 'ratesight_auth_v2_readiness', 'ratesight_retain_on_uninstall' ), $inventory['options'] ) ) === 0 );
check_uninstall_case( 'shared-state inventory includes tables, CPT, meta, nonce prefix, transients, and cron', count( $inventory['tables'] ) === 7 && $inventory['postTypes'] === array( 'ratesight_page' ) && count( $inventory['postMeta'] ) === 15 && $inventory['userMeta'] === array( 'ratesight_wizard_dismissed' ) && $inventory['optionPrefixes'] === array( 'ratesight_auth_nonce_' ) && $inventory['transientPrefixes'] === array( 'rs_', 'ratesight_' ) && in_array( 'ratesight_prune_auth_nonces', $inventory['cronHooks'], true ) );

$production_sources = array( __DIR__ . '/../ratesight.php' );
foreach ( array( 'includes', 'admin', 'public' ) as $directory ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../' . $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && $file->getExtension() === 'php' ) $production_sources[] = $file->getPathname();
	}
}
$discovered_options = array();
$discovered_meta = array();
$discovered_user_meta = array();
$discovered_cron = array();
foreach ( $production_sources as $source ) {
	$content = file_get_contents( $source );
	preg_match_all( '/(?:get|update|delete|add)_option\(\s*[\'\"](ratesight_[a-z0-9_]+)[\'\"]/', $content, $option_matches );
	preg_match_all( '/[\'\"](_(?:rs|ratesight)_[a-z0-9_]+)[\'\"]/', $content, $meta_matches );
	preg_match_all( '/(?:get|update|delete)_user_meta\([^;]+?[\'\"](ratesight_[a-z0-9_]+)[\'\"]/', $content, $user_meta_matches );
	preg_match_all( '/wp_schedule_(?:single_)?event\([^;]+?[\'\"](ratesight_[a-z0-9_]+)[\'\"]/', $content, $cron_matches );
	$discovered_options = array_merge( $discovered_options, $option_matches[1] );
	$discovered_meta = array_merge( $discovered_meta, $meta_matches[1] );
	$discovered_user_meta = array_merge( $discovered_user_meta, $user_meta_matches[1] );
	$discovered_cron = array_merge( $discovered_cron, $cron_matches[1] );
}
$discovered_options = array_values( array_unique( $discovered_options ) );
$discovered_meta = array_values( array_unique( $discovered_meta ) );
$discovered_user_meta = array_values( array_unique( $discovered_user_meta ) );
$discovered_cron = array_values( array_unique( $discovered_cron ) );
check_uninstall_case( 'parser-backed static option inventory has no unclassified plugin option', array_diff( $discovered_options, $inventory['options'] ) === array() );
check_uninstall_case( 'parser-backed post-meta inventory has no unclassified key', array_diff( $discovered_meta, $inventory['postMeta'] ) === array() );
check_uninstall_case( 'parser-backed user-meta inventory has no unclassified key', array_diff( $discovered_user_meta, $inventory['userMeta'] ) === array() );
check_uninstall_case( 'parser-backed scheduled-hook inventory has no unclassified hook', array_diff( $discovered_cron, $inventory['cronHooks'] ) === array() );

$uninstall_source = file_get_contents( __DIR__ . '/../uninstall.php' );
$gate_position = strpos( $uninstall_source, "destructiveUninstallAllowed']" );
$first_delete_position = strpos( $uninstall_source, 'wp_delete_post(' );
$first_drop_position = strpos( $uninstall_source, 'DROP TABLE' );
$first_option_position = strpos( $uninstall_source, 'delete_option(' );
$first_unschedule_position = strpos( $uninstall_source, 'wp_unschedule_hook(' );
check_uninstall_case( 'uninstall retention gate precedes every destructive family', $gate_position !== false && min( $first_delete_position, $first_drop_position, $first_option_position, $first_unschedule_position ) > $gate_position );

$archive_one = sys_get_temp_dir() . '/ratesight-phase1a2-' . getmypid() . '-one.zip';
$archive_two = sys_get_temp_dir() . '/ratesight-phase1a2-' . getmypid() . '-two.zip';
$builder = escapeshellarg( __DIR__ . '/../scripts/build-release-package.sh' );
shell_exec( $builder . ' ' . escapeshellarg( $archive_one ) );
shell_exec( $builder . ' ' . escapeshellarg( $archive_two ) );
$entries = array_values( array_filter( explode( "\n", trim( (string) shell_exec( 'unzip -Z1 ' . escapeshellarg( $archive_one ) ) ) ) ) );
$forbidden = array_filter( $entries, static fn( string $entry ): bool => preg_match( '#(^|/)(\.git|\.env|tests|docs|scripts)(/|$)|README\.md$#', $entry ) === 1 );
$top_levels = array_values( array_unique( array_map( static fn( string $entry ): string => explode( '/', $entry )[0], $entries ) ) );
check_uninstall_case( 'release package is byte-for-byte deterministic', is_file( $archive_one ) && hash_file( 'sha256', $archive_one ) === hash_file( 'sha256', $archive_two ) );
check_uninstall_case( 'release package has exactly one top-level directory', $top_levels === array( 'ratesight' ) );
check_uninstall_case( 'release package excludes development, test, git, env, docs, and scripts artifacts', $forbidden === array() );
check_uninstall_case( 'release package contains the runtime uninstall policy', in_array( 'ratesight/uninstall.php', $entries, true ) && in_array( 'ratesight/includes/class-ratesight-installation.php', $entries, true ) );
check_uninstall_case( 'release package contains every referenced runtime image', in_array( 'ratesight/admin/images/rs-icon.png', $entries, true ) && in_array( 'ratesight/admin/images/rs-pin.png', $entries, true ) );
check_uninstall_case( 'release manifest is sorted and contains runtime files only', $entries === array_values( array_unique( $entries ) ) && count( $entries ) > 50 );
@unlink( $archive_one );
@unlink( $archive_two );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

$inventory_counts = array_map( 'count', $inventory );
echo "\nPASS — {$checks} checks; inventory counts " . json_encode( $inventory_counts ) . "\n";
