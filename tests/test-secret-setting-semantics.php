<?php
define( 'ABSPATH', sys_get_temp_dir() . '/' );

$options = array();
$remote_body = '';
$registered_settings = array();
$fail_update = false;
$fail_delete = false;

function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value ) { global $options, $fail_update; if ( $fail_update ) return false; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options, $fail_delete; if ( $fail_delete ) return false; unset( $options[ $name ] ); return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_generate_password() { return 'generated-indexnow-key-fixture'; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function home_url() { return 'https://fixture.invalid/'; }
function wp_remote_get() { global $remote_body; return array( 'body' => $remote_body ); }
function is_wp_error() { return false; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function register_setting( $group, $name, $args ) { global $registered_settings; $registered_settings[ $name ] = array( 'group' => $group, 'args' => $args ); }

require __DIR__ . '/../includes/class-ratesight-options.php';
require __DIR__ . '/../includes/class-ratesight-indexnow.php';
require __DIR__ . '/../admin/class-ratesight-admin.php';

$checks = 0;
$failures = 0;
function check_secret_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function secret_digests(): array {
	global $options;
	return array(
		'bing'     => hash( 'sha256', (string) ( $options['ratesight_bing_api_key'] ?? '' ) ),
		'deepseek' => hash( 'sha256', (string) ( $options['ratesight_deepseek_api_key'] ?? '' ) ),
	);
}

$bing_sentinel = 'fixture-bing-secret-never-render';
$deepseek_sentinel = 'fixture-deepseek-secret-never-render';
$options['ratesight_bing_api_key'] = $bing_sentinel;
$options['ratesight_deepseek_api_key'] = $deepseek_sentinel;
$before = secret_digests();

$expected_status_keys = array( 'configured', 'replaceAllowed', 'removeAllowed' );
$status = Ratesight_Options::secret_setting_status( 'bing_api_key' );
check_secret_case( 'secret status exposes only the allowlisted contract', array_keys( $status ) === $expected_status_keys );
check_secret_case( 'configured secret status is value-free', $status === array( 'configured' => true, 'replaceAllowed' => true, 'removeAllowed' => true ) );

foreach ( array( '', '   ', null, array( 'unexpected' ), '********', 'xxxxxxxx', '••••••••' ) as $unchanged_value ) {
	$result = Ratesight_Options::update_secret_setting( 'bing_api_key', $unchanged_value );
	check_secret_case( 'blank/omitted/mask-like input preserves both secret digests', $result['intent'] === 'unchanged' && secret_digests() === $before );
}

$admin = new Ratesight_Admin();
$admin->register_settings();
$deepseek_sanitizer = $registered_settings['ratesight_deepseek_api_key']['args']['sanitize_callback'];
$bing_sanitizer = $registered_settings['ratesight_bing_api_key']['args']['sanitize_callback'];
check_secret_case( 'an unrelated Connections save preserves the DeepSeek digest', $deepseek_sanitizer( null ) === $deepseek_sentinel && secret_digests() === $before );
check_secret_case( 'an unrelated Connections save preserves the Bing digest', $bing_sanitizer( null ) === $bing_sentinel && secret_digests() === $before );

$replacement = 'fixture-bing-replacement-never-render';
$result = Ratesight_Options::update_secret_setting( 'bing_api_key', $replacement );
$after_replace = secret_digests();
check_secret_case( 'explicit replacement changes only the selected secret', $result['intent'] === 'replace' && $result['applied'] === true && $after_replace['bing'] !== $before['bing'] && $after_replace['deepseek'] === $before['deepseek'] );
check_secret_case( 'replacement response remains value-free', strpos( json_encode( $result ), $replacement ) === false );

$fail_update = true;
$failed_replacement = Ratesight_Options::update_secret_setting( 'bing_api_key', 'fixture-rejected-replacement' );
$fail_update = false;
check_secret_case( 'failed replacement is non-applied and preserves the stored secret', $failed_replacement['applied'] === false && secret_digests() === $after_replace );

$fail_delete = true;
$failed_removal = Ratesight_Options::update_secret_setting( 'bing_api_key', '', true );
$fail_delete = false;
check_secret_case( 'failed removal is non-applied and preserves the stored secret', $failed_removal['applied'] === false && secret_digests() === $after_replace && $failed_removal['status']['configured'] === true );

$result = Ratesight_Options::update_secret_setting( 'bing_api_key', '', true );
$after_remove = secret_digests();
check_secret_case( 'explicit removal changes only the selected secret', $result['intent'] === 'remove' && $result['applied'] === true && $after_remove['bing'] !== $after_replace['bing'] && $after_remove['deepseek'] === $after_replace['deepseek'] );
check_secret_case( 'removed secret reports configured false and remove disabled', $result['status'] === array( 'configured' => false, 'replaceAllowed' => true, 'removeAllowed' => false ) );

$secret_setting_key = 'deepseek_api_key';
$secret_setting_input_id = 'fixture-deepseek-input';
$secret_setting_label = 'DeepSeek API key';
$secret_setting_placeholder = 'Paste a replacement DeepSeek API key';
ob_start();
require __DIR__ . '/../admin/partials/inc-secret-setting.php';
$html = ob_get_clean();
check_secret_case( 'production secret control HTML never contains the stored sentinel', strpos( $html, $deepseek_sentinel ) === false );
check_secret_case( 'production secret control is empty and write-only', str_contains( $html, 'type="password"' ) && str_contains( $html, 'value=""' ) && ! str_contains( $html, 'name="ratesight_deepseek_api_key"' ) );
check_secret_case( 'configured production control offers a separate remove action', str_contains( $html, 'rs-remove-secret' ) && str_contains( $html, 'Remove Saved Key' ) );

$indexnow_sentinel = 'fixture-indexnow-secret-never-render';
$options['ratesight_indexnow_key'] = $indexnow_sentinel;
$remote_body = $indexnow_sentinel;
$indexnow = Ratesight_IndexNow::status();
check_secret_case( 'IndexNow status keys match the allowlist', array_keys( $indexnow ) === array( 'configured', 'verified', 'errorCode' ) );
check_secret_case( 'verified IndexNow status contains no key or key-bearing URL', $indexnow === array( 'configured' => true, 'verified' => true, 'errorCode' => null ) && strpos( json_encode( $indexnow ), $indexnow_sentinel ) === false );
$remote_body = 'wrong-key-fixture';
check_secret_case( 'IndexNow verification failure uses a stable value-free error', Ratesight_IndexNow::status() === array( 'configured' => true, 'verified' => false, 'errorCode' => 'key_unreachable' ) );

$admin_source = file_get_contents( __DIR__ . '/../admin/class-ratesight-admin.php' );
$javascript_source = file_get_contents( __DIR__ . '/../admin/js/ratesight-admin.js' );
check_secret_case( 'admin status surfaces no legacy IndexNow key or URL fields', ! str_contains( $admin_source, "'indexnow_url'" ) && ! str_contains( $admin_source, "'key_url'" ) && ! str_contains( $admin_source, "'key'      =>" ) );
check_secret_case( 'secret removal requires separate browser and server confirmation', str_contains( $javascript_source, "confirm( 'Remove the saved '" ) && str_contains( $javascript_source, "confirm: 'REMOVE'" ) && str_contains( $admin_source, "!== 'REMOVE'" ) );

$root = dirname( __DIR__ );
$reader_matches = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$path = $file->getPathname();
	$relative = substr( $path, strlen( $root ) + 1 );
	if ( ! $file->isFile() || ! preg_match( '/\.(php|js)$/', $relative ) || str_starts_with( $relative, 'tests/' ) ) continue;
	if ( strpos( file_get_contents( $path ), 'deepseek_api_key' ) === false ) continue;
	$reader_matches[] = $relative;
}
sort( $reader_matches );
$non_runtime_surfaces = array( 'admin/class-ratesight-admin.php', 'admin/partials/tab-connections.php', 'includes/class-ratesight-options.php', 'uninstall.php' );
$production_readers = array_values( array_diff( $reader_matches, $non_runtime_surfaces ) );
check_secret_case( 'DeepSeek option occurrences are limited to registration/status/uninstall', $reader_matches === $non_runtime_surfaces );
check_secret_case( 'DeepSeek zero-reader classification is retirement_candidate', $production_readers === array() );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} checks, value-free digests recorded\n";
