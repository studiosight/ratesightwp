<?php

define( 'ABSPATH', sys_get_temp_dir() . '/' );
require __DIR__ . '/../includes/class-ratesight-options.php';
require __DIR__ . '/../includes/class-ratesight-installation.php';
require __DIR__ . '/../includes/class-ratesight-connection-ownership.php';
require __DIR__ . '/../includes/class-ratesight-request-auth.php';

$checks = 0;
$failures = 0;
function check_ownership_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function inventory_ids( array $records ): array {
	$ids = array_column( $records, 'id' );
	sort( $ids );
	return $ids;
}
function source_php_files(): array {
	$files = array( realpath( __DIR__ . '/../ratesight.php' ), realpath( __DIR__ . '/../uninstall.php' ) );
	foreach ( array( 'includes', 'admin', 'public' ) as $directory ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../' . $directory, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) $files[] = $file->getRealPath();
		}
	}
	return $files;
}

$inventory = Ratesight_Connection_Ownership::inventory();
$required_families = array( 'options', 'optionPrefixes', 'tables', 'postTypes', 'postMeta', 'userMeta', 'transientPrefixes', 'cronHooks', 'adminControls', 'ajaxActions', 'restRoutes', 'oauthCallbacks', 'providerClients', 'providerOperations', 'workerEndpoints', 'publicActions', 'capabilities', 'replacements', 'uninstallDeletes' );
check_ownership_case( 'inventory exposes every required family exactly once', array_keys( $inventory ) === $required_families );

$duplicates = array();
$unknown_owners = array();
$allowed_owners = array( 'wordpress_plugin', 'dashboard', 'external_owner_unresolved' );
$allowed_states = array( 'retained_wordpress', 'dashboard_replacement_unproven', 'retirement_candidate', 'blocked_external_consumer', 'blocked' );
foreach ( $inventory as $family => $records ) {
	$keys = array_map( static fn( array $record ): string => $record['type'] . ':' . $record['id'], $records );
	if ( count( $keys ) !== count( array_unique( $keys ) ) ) $duplicates[] = $family;
	foreach ( $records as $record ) {
		if ( ! in_array( $record['owner'], $allowed_owners, true ) || ! in_array( $record['state'], $allowed_states, true ) ) $unknown_owners[] = $record['id'];
		foreach ( array( 'id', 'type', 'source', 'owner', 'state', 'replacement', 'proofType', 'reasonCode', 'evidenceToUnblock' ) as $key ) {
			if ( ! array_key_exists( $key, $record ) ) $unknown_owners[] = $family . ':missing:' . $key;
		}
	}
}
check_ownership_case( 'duplicate inventory count is zero', $duplicates === array() );
check_ownership_case( 'unknown owner and incomplete record count is zero', $unknown_owners === array() );

$shared = Ratesight_Installation::shared_state_inventory( array_map( static fn( array $definition ): string => $definition['name'], Ratesight_Options::schema() ) );
foreach ( array( 'options', 'optionPrefixes', 'tables', 'postTypes', 'postMeta', 'userMeta', 'transientPrefixes', 'cronHooks' ) as $family ) {
	$expected = $shared[ $family ];
	sort( $expected );
	check_ownership_case( "{$family} maps every shared-state discovery exactly once", inventory_ids( $inventory[ $family ] ) === $expected );
}

$sources = '';
foreach ( source_php_files() as $file ) $sources .= "\n" . file_get_contents( $file );
preg_match_all( '/wp_ajax_(?:nopriv_)?(ratesight_[a-z0-9_]+)/', $sources, $ajax_matches );
$discovered_ajax = array_values( array_unique( $ajax_matches[1] ) );
sort( $discovered_ajax );
check_ownership_case( 'every registered AJAX input action maps exactly once', inventory_ids( $inventory['ajaxActions'] ) === $discovered_ajax );

$connections_source = file_get_contents( __DIR__ . '/../admin/partials/tab-connections.php' );
preg_match_all( '/<(?:input|select|button)\b[^>]*\bid=["\']([^"\']+)["\']/i', $connections_source, $control_matches );
$literal_controls = array_values( array_filter( array_unique( $control_matches[1] ), static fn( string $id ): bool => ! str_contains( $id, '<?php' ) ) );
preg_match_all( '/<(?:input|select)\b[^>]*\bname=["\'](ratesight_[a-z0-9_]+)["\']/i', $connections_source, $named_matches );
preg_match_all( '/\$secret_setting_key\s*=\s*["\']([a-z0-9_]+)["\']/', $connections_source, $secret_matches );
$secret_controls = array();
foreach ( array_values( array_unique( $secret_matches[1] ) ) as $setting ) {
	$secret_controls[] = "secret:{$setting}:replace";
	$secret_controls[] = "secret:{$setting}:remove";
}
preg_match_all( '/get_auth_url\(\s*["\'](gbp|gsc)["\']\s*\)/', $connections_source, $oauth_control_matches );
$oauth_controls = array_map( static fn( string $service ): string => 'oauth-connect:' . $service, array_values( array_unique( $oauth_control_matches[1] ) ) );
preg_match_all( '/rs-quick-disconnect[^>]+data-service=["\'](gbp|gsc)["\']/', $connections_source, $disconnect_matches );
$disconnect_controls = array_map( static fn( string $service ): string => 'quick-disconnect:' . $service, array_values( array_unique( $disconnect_matches[1] ) ) );
$generated_controls = array();
if ( str_contains( $connections_source, "settings_fields( 'ratesight_options_connections' )" ) && str_contains( $connections_source, 'submit_button(' ) ) $generated_controls[] = 'settings-submit:ratesight_options_connections';
if ( str_contains( $connections_source, "add_query_arg( 'rs_reschedule', '1' )" ) ) $generated_controls[] = 'reschedule-all';
$discovered_controls = array_values( array_unique( array_merge( $literal_controls, $named_matches[1], $secret_controls, $oauth_controls, $disconnect_controls, $generated_controls ) ) );
sort( $discovered_controls );
check_ownership_case( 'every Connections input and control maps exactly once', inventory_ids( $inventory['adminControls'] ) === $discovered_controls );
check_ownership_case( 'every secret control maps to explicit replace and remove actions', count( array_filter( $discovered_controls, static fn( string $id ): bool => str_starts_with( $id, 'secret:' ) ) ) === 4 );

preg_match_all( '/wp_ajax_nopriv_(ratesight_[a-z0-9_]+)/', $sources, $public_matches );
$discovered_public = array_map( static fn( string $action ): string => 'wp_ajax_nopriv_' . $action, array_values( array_unique( $public_matches[1] ) ) );
sort( $discovered_public );
check_ownership_case( 'every public nopriv action maps exactly once', inventory_ids( $inventory['publicActions'] ) === $discovered_public );

$route_ids = array_keys( Ratesight_Request_Auth::ROUTE_POLICIES );
sort( $route_ids );
check_ownership_case( 'every protected/public REST method and route maps exactly once', inventory_ids( $inventory['restRoutes'] ) === $route_ids );

$cron_ids = inventory_ids( $inventory['cronHooks'] );
check_ownership_case( 'publish and argument-bearing deferred hook remain separately inventoried', in_array( 'ratesight_deferred_publish', $cron_ids, true ) && in_array( 'ratesight_process_bulk_queue', $cron_ids, true ) );

$worker_ids = inventory_ids( $inventory['workerEndpoints'] );
$expected_workers = array_map( static fn( string $path ): string => 'oauth.ratesight.com' . $path, array( '/callback', '/refresh', '/validate', '/ai-chat', '/insights', '/recommend', '/auto-submit', '/sitemap-status' ) );
sort( $expected_workers );
check_ownership_case( 'every documented Worker endpoint maps exactly once', $worker_ids === $expected_workers );

$discovered_remote_calls = array();
foreach ( source_php_files() as $file ) {
	$relative = substr( $file, strlen( dirname( __DIR__ ) ) + 1 );
	if ( ! str_starts_with( $relative, 'includes/' ) && ! str_starts_with( $relative, 'admin/' ) && ! str_starts_with( $relative, 'public/' ) ) continue;
	$function = 'file_scope';
	$occurrences = array();
	foreach ( file( $file ) as $line ) {
		if ( preg_match( '/(?:public|private|protected)?\s*(?:static\s+)?function\s+([a-z0-9_]+)/i', $line, $function_match ) ) $function = $function_match[1];
		preg_match_all( '/wp_((?:safe_)?remote_(?:get|post|head|request))\s*\(/', $line, $remote_matches );
		foreach ( $remote_matches[1] as $helper ) {
			$key = "{$relative}::{$function}:wp_{$helper}";
			$occurrences[ $key ] = ( $occurrences[ $key ] ?? 0 ) + 1;
			$discovered_remote_calls[] = $key . '#' . $occurrences[ $key ];
		}
	}
}
$public_source = file_get_contents( __DIR__ . '/../public/class-ratesight-public.php' );
if ( str_contains( $public_source, 'WIDGET_BASE' ) ) $discovered_remote_calls[] = 'public/class-ratesight-public.php::review_widget_embed';
if ( str_contains( $public_source, 'https://worksight.co/scripts/jobs-page.js' ) ) $discovered_remote_calls[] = 'public/class-ratesight-public.php::jobs_widget_embed';
sort( $discovered_remote_calls );
check_ownership_case( 'every outbound PHP HTTP call and browser service embed maps exactly once', inventory_ids( $inventory['providerOperations'] ) === $discovered_remote_calls );

$client_files = array_map( static fn( string $path ): string => substr( realpath( $path ), strlen( dirname( __DIR__ ) ) + 1 ), glob( __DIR__ . '/../includes/class-ratesight-*-client.php' ) ?: array() );
$client_sources = array_values( array_unique( array_column( $inventory['providerClients'], 'source' ) ) );
check_ownership_case( 'every provider-capable Client class has a provider-client ownership record', array_diff( $client_files, $client_sources ) === array() );
$provider_writes = array_filter( $inventory['providerOperations'], static fn( array $record ): bool => $record['type'] === 'provider_write' );
check_ownership_case( 'GSC sitemap Google token revoke GBP and IndexNow writes are explicit', count( array_diff( array(
	'admin/class-ratesight-admin.php::ajax_lock_gsc:wp_remote_request#1',
	'includes/class-ratesight-gbp-client.php::post:wp_remote_post#1',
	'includes/class-ratesight-gbp-insights-client.php::post_request:wp_remote_post#1',
	'includes/class-ratesight-indexnow.php::submit:wp_remote_post#1',
	'includes/class-ratesight-indexnow.php::submit_bulk:wp_remote_post#1',
	'includes/class-ratesight-oauth-client.php::handle_token_return:wp_remote_post#1',
), array_column( $provider_writes, 'id' ) ) ) === 0 );

$blocked_external = array_merge(
	array_filter( $inventory['publicActions'], static fn( array $record ): bool => $record['state'] === 'blocked_external_consumer' ),
	array_filter( $inventory['workerEndpoints'], static fn( array $record ): bool => $record['state'] === 'blocked_external_consumer' ),
	array_filter( $inventory['capabilities'], static fn( array $record ): bool => $record['state'] === 'blocked_external_consumer' )
);
check_ownership_case( 'unresolved external consumers have blockers and evidence-to-unblock', $blocked_external !== array() && array_reduce( $blocked_external, static fn( bool $ok, array $record ): bool => $ok && is_string( $record['reasonCode'] ) && $record['reasonCode'] !== '' && is_string( $record['evidenceToUnblock'] ) && $record['evidenceToUnblock'] !== '', true ) );

$capabilities = array_column( $inventory['capabilities'], 'state', 'id' );
check_ownership_case( 'Bing performance is separate and not removal-authorized', ( $capabilities['bing_performance'] ?? '' ) === 'dashboard_replacement_unproven' );
check_ownership_case( 'Worker sitemap/auto-submit is a blocked external capability', ( $capabilities['worker_sitemap_auto_submit'] ?? '' ) === 'blocked_external_consumer' );
check_ownership_case( 'IndexNow remains a separately retained notification capability', ( $capabilities['indexnow_notification'] ?? '' ) === 'retained_wordpress' );
check_ownership_case( 'stored DeepSeek and Worker AI are separate capabilities', ( $capabilities['deepseek_stored_option'] ?? '' ) === 'retirement_candidate' && ( $capabilities['worker_ai'] ?? '' ) === 'blocked_external_consumer' );

$replacement_ids = inventory_ids( $inventory['replacements'] );
check_ownership_case( 'GBP GSC Bing and Meta replacement matrix is complete', $replacement_ids === array( 'bing', 'gbp', 'gsc', 'meta' ) );
check_ownership_case( 'provider replacement candidates remain blocked pending parity evidence', array_reduce( $inventory['replacements'], static fn( bool $ok, array $record ): bool => $ok && $record['state'] === 'dashboard_replacement_unproven' && $record['reasonCode'] !== null && $record['evidenceToUnblock'] !== null, true ) );

$uninstall_ids = inventory_ids( $inventory['uninstallDeletes'] );
$expected_deletes = array_map( static fn( string $family ): string => 'delete:' . $family, array( 'options', 'optionPrefixes', 'tables', 'postTypes', 'postMeta', 'userMeta', 'transientPrefixes', 'cronHooks' ) );
sort( $expected_deletes );
check_ownership_case( 'every uninstall deletion family maps once and remains retention-blocked', $uninstall_ids === $expected_deletes && array_reduce( $inventory['uninstallDeletes'], static fn( bool $ok, array $record ): bool => $ok && $record['state'] === 'blocked' && $record['reasonCode'] === 'default_retention_gate', true ) );

$oauth_ids = inventory_ids( $inventory['oauthCallbacks'] );
check_ownership_case( 'GBP GSC Worker and WordPress token-return callbacks map separately', $oauth_ids === array( 'oauth:gbp:worker-callback', 'oauth:gsc:worker-callback', 'oauth:wordpress:token-return' ) );

$serialized = json_encode( $inventory );
check_ownership_case( 'inventory is sanitized and contains no credential values or runtime content', is_string( $serialized ) && ! preg_match( '/"(?:access_token|refresh_token|ciphertext|wordpressSecret)"\s*:/i', $serialized ) && ! str_contains( $serialized, '/home/ubuntu/studiosight/runtime' ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

$counts = array_map( 'count', $inventory );
echo "\nPASS — {$checks} checks; sanitized counts " . json_encode( $counts ) . "\n";
