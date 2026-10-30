<?php
/**
 * 3.15.6 pairing recovery: connection notice states, the admin-only reset and recovery-secret actions,
 * their audit rows, and the guarantee that a recovery secret never makes is_connected() true.
 * Runs the REAL Ratesight_Request_Auth, Ratesight_Pairing and Ratesight_Admin handlers against in-memory
 * WordPress stubs.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$options = array( 'wp_ratesight_code_id' => '2' );
$caps    = array( 'manage_options' => true );
$nonce_ok = true;
$user    = (object) array( 'ID' => 7, 'user_login' => 'owner' );
$headers_sent = 0;

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
class Send_Json extends Exception { public function __construct( public bool $ok, public $data, public int $status ) { parent::__construct( 'json' ); } }
class Ratesight_Options {
	public static function get( string $key ) { return $key === 'code_id' ? get_option( 'wp_ratesight_code_id', '' ) : ''; }
	public static function option_name( string $key ): string { return 'wp_ratesight_code_id'; }
}
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value ) { global $options; $options[ $name ] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
function add_option( $name, $value ) { global $options; if ( array_key_exists( $name, $options ) ) return false; $options[ $name ] = $value; return true; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function home_url() { return 'https://example.com/'; }
function wp_parse_url( $v ) { return parse_url( $v ); }
function apply_filters( $n, $v ) { return $v; }
function check_ajax_referer( $a, $f ) { global $nonce_ok; if ( ! $nonce_ok ) throw new Send_Json( false, 'bad nonce', 403 ); }
function current_user_can( $c ) { global $caps; return ! empty( $caps[ $c ] ); }
function wp_unslash( $v ) { return $v; }
function wp_get_current_user() { global $user; return $user; }
function nocache_headers() { global $headers_sent; $headers_sent++; }
function wp_send_json_success( $data = null, $status = 200 ) { throw new Send_Json( true, $data, $status ); }
function wp_send_json_error( $data = null, $status = 400 ) { throw new Send_Json( false, $data, $status ); }

require __DIR__ . '/../includes/class-ratesight-request-auth.php';
require __DIR__ . '/../includes/class-ratesight-pairing.php';
require __DIR__ . '/../admin/class-ratesight-admin.php';

$failures = 0;
function check_rec( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
	if ( ! $ok ) $failures++;
}
function call_admin( string $method, array $post ): Send_Json {
	global $nonce_ok;
	$_POST = $post;
	try {
		( new Ratesight_Admin() )->$method();
	} catch ( Send_Json $e ) {
		return $e;
	}
	return new Send_Json( false, 'no response', 0 );
}
function paired_state(): void {
	global $options;
	$secret = str_repeat( 'a', 64 );
	$options['ratesight_webhook_secret'] = $secret;
	$options['ratesight_webhook_secret_previous'] = str_repeat( 'b', 64 );
	$options['ratesight_webhook_secret_previous_expires'] = time() + 1000;
	$options['ratesight_pairing_receipt'] = array( 'source' => 'dashboard', 'key_id' => Ratesight_Request_Auth::key_id( $secret ), 'paired_at' => time() );
	$options['ratesight_auth_v2_readiness'] = array( 'key_id' => Ratesight_Request_Auth::key_id( $secret ), 'completed_at' => time() );
	$options['ratesight_auth_mode'] = 'observe_v2';
}

// ── notice states (A) ─────────────────────────────────────────────────────────
$options = array();
check_rec( 'no Ratesight ID: Setup in progress', Ratesight_Pairing::notice()['state'] === 'setup' && Ratesight_Pairing::notice()['heading'] === 'Setup in progress' );
$options = array( 'wp_ratesight_code_id' => '2' );
$n = Ratesight_Pairing::notice();
check_rec( 'ID stored but not paired: "Registered, not paired yet" with the plain line', $n['state'] === 'registered' && $n['heading'] === 'Registered, not paired yet' && $n['line'] === 'Your Ratesight team can finish pairing this site. No action is needed here.' );
paired_state();
check_rec( 'really paired: Connected', Ratesight_Pairing::is_connected() && Ratesight_Pairing::notice()['state'] === 'paired' && Ratesight_Pairing::notice()['heading'] === 'Connected' );
$options['ratesight_webhook_secret'] = '';
check_rec( 'receipt without a secret is not paired (registered)', Ratesight_Pairing::notice()['state'] === 'registered' );
$source = file_get_contents( __DIR__ . '/../admin/partials/tab-seo-pages.php' );
check_rec( 'the settings notice uses the real test, not the stored ID', str_contains( $source, 'Ratesight_Pairing::notice()' ) && ! str_contains( $source, "if ( \$ratesight_id !== '' ) :" ) );

// ── permission, nonce, confirmation ───────────────────────────────────────────
paired_state();
$caps = array( 'manage_options' => false );
$r = call_admin( 'ajax_reset_pairing', array( 'confirm' => 'RESET' ) );
check_rec( 'reset: a non-admin is refused (403) and nothing changes', ! $r->ok && $r->status === 403 && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
$r = call_admin( 'ajax_generate_recovery_secret', array( 'confirm' => 'GENERATE' ) );
check_rec( 'generate: a non-admin is refused (403) and nothing changes', ! $r->ok && $r->status === 403 && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
$caps = array( 'manage_options' => true );
$nonce_ok = false;
$r = call_admin( 'ajax_reset_pairing', array( 'confirm' => 'RESET' ) );
check_rec( 'reset: a bad nonce is refused and nothing changes', ! $r->ok && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
$r = call_admin( 'ajax_generate_recovery_secret', array( 'confirm' => 'GENERATE' ) );
check_rec( 'generate: a bad nonce is refused and nothing changes', ! $r->ok && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
$nonce_ok = true;
foreach ( array( '', 'reset', 'YES', 'GENERATE' ) as $word ) {
	$r = call_admin( 'ajax_reset_pairing', array( 'confirm' => $word ) );
	check_rec( "reset: confirmation '$word' is refused (needs the typed word RESET)", ! $r->ok && ( $r->data['code'] ?? '' ) === 'confirmation_required' && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
}
foreach ( array( '', 'generate', 'RESET' ) as $word ) {
	$r = call_admin( 'ajax_generate_recovery_secret', array( 'confirm' => $word ) );
	check_rec( "generate: confirmation '$word' is refused (needs the typed word GENERATE)", ! $r->ok && ( $r->data['code'] ?? '' ) === 'confirmation_required' && get_option( 'ratesight_webhook_secret' ) === str_repeat( 'a', 64 ) );
}
check_rec( 'refused calls wrote no audit row', get_option( 'ratesight_auth_audit', array() ) === array() );

// ── reset (B) ─────────────────────────────────────────────────────────────────
paired_state();
$options['ratesight_auth_audit'] = array();
$options['wp_ratesight_gsc_marker'] = 'keep';
$options['ratesight_gbp_token'] = 'oauth-keep';
$before = $options;
$r = call_admin( 'ajax_reset_pairing', array( 'confirm' => 'RESET' ) );
$cleared = array_diff_key( $before, $options );
check_rec( 'reset succeeds for an admin with the typed word', $r->ok && $r->data['cleared_key_id'] === Ratesight_Request_Auth::key_id( str_repeat( 'a', 64 ) ) );
check_rec( 'reset deletes exactly the secret, the previous secret and its expiry, the receipt and the readiness proof', array_keys( $cleared ) === array( 'ratesight_webhook_secret', 'ratesight_webhook_secret_previous', 'ratesight_webhook_secret_previous_expires', 'ratesight_pairing_receipt', 'ratesight_auth_v2_readiness' ) || ( count( $cleared ) === 5 && isset( $cleared['ratesight_webhook_secret'], $cleared['ratesight_webhook_secret_previous'], $cleared['ratesight_webhook_secret_previous_expires'], $cleared['ratesight_pairing_receipt'], $cleared['ratesight_auth_v2_readiness'] ) ) );
check_rec( 'reset keeps the Ratesight ID and every unrelated setting', $options['wp_ratesight_code_id'] === '2' && $options['wp_ratesight_gsc_marker'] === 'keep' && $options['ratesight_gbp_token'] === 'oauth-keep' );
check_rec( 'a never-enforced site goes back to legacy and is not paired; state is registered', $options['ratesight_auth_mode'] === 'legacy' && ! Ratesight_Pairing::is_connected() && $r->data['state'] === 'registered' && $r->data['notice']['heading'] === 'Registered, not paired yet' );
$audit = get_option( 'ratesight_auth_audit', array() );
$row   = end( $audit );
check_rec( 'reset wrote one audit row with user id, login, action and the cleared key id', count( $audit ) === 1 && $row['user_id'] === 7 && $row['user_login'] === 'owner' && $row['result'] === 'pairing_reset' && $row['key_id'] === Ratesight_Request_Auth::key_id( str_repeat( 'a', 64 ) ) );
check_rec( 'the audit row contains no secret', ! str_contains( json_encode( $audit ), str_repeat( 'a', 64 ) ) && ! str_contains( json_encode( $audit ), str_repeat( 'b', 64 ) ) );
class Rec_Request {
	public function __construct( private array $headers = array() ) {}
	public function get_method() { return 'POST'; }
	public function get_route() { return '/ratesight/v1/update-page'; }
	public function get_query_params() { return array(); }
	public function get_query_string() { return ''; }
	public function get_body() { return '{}'; }
	public function get_header( $name ) { return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? ''; }
}
$decision = Ratesight_Request_Auth::authorize_mutation( new Rec_Request( array( 'x-ratesight-signature' => 'sha256=' . str_repeat( '0', 64 ) ) ) );
check_rec( 'after reset a signed route is refused with rs_secret_required, not accepted (no half-open state)', is_wp_error( $decision ) && $decision->get_error_code() === 'rs_secret_required' );

// An enforced site is NOT downgraded: it stays strict.
paired_state();
$options['ratesight_auth_mode'] = 'enforce_v2';
$options['ratesight_auth_ever_enforced'] = true;
$r = call_admin( 'ajax_reset_pairing', array( 'confirm' => 'RESET' ) );
check_rec( 'an enforced site stays enforce_v2 after reset (never made less strict)', $r->ok && Ratesight_Request_Auth::mode() === 'enforce_v2' && $r->data['mode'] === 'enforce_v2' && $r->data['ever_enforced'] === true );
unset( $options['ratesight_auth_ever_enforced'], $options['ratesight_auth_mode'] );

// ── recovery secret (C1) ──────────────────────────────────────────────────────
paired_state();
$options['ratesight_auth_audit'] = array();
$old = $options['ratesight_webhook_secret'];
$r = call_admin( 'ajax_generate_recovery_secret', array( 'confirm' => 'GENERATE' ) );
$secret = $r->data['secret'] ?? '';
check_rec( 'generate returns a 64-hex secret and its 16-hex key id, once, with no-cache headers', $r->ok && preg_match( '/^[a-f0-9]{64}$/', $secret ) === 1 && $r->data['key_id'] === Ratesight_Request_Auth::key_id( $secret ) && $headers_sent > 0 );
check_rec( 'the new secret is stored only as ratesight_webhook_secret; the old one is kept 7 days', $options['ratesight_webhook_secret'] === $secret && $options['ratesight_webhook_secret_previous'] === $old && $options['ratesight_webhook_secret_previous_expires'] > time() + 6 * DAY_IN_SECONDS );
$elsewhere = array_filter( $options, static fn( $v, $k ) => $k !== 'ratesight_webhook_secret' && str_contains( json_encode( $v ), $secret ), ARRAY_FILTER_USE_BOTH );
check_rec( 'the secret appears in no other option, including the audit and the receipt', $elsewhere === array() );
$receipt = $options['ratesight_pairing_receipt'];
check_rec( "the receipt says source manual_recovery and has NO paired_at", $receipt['source'] === 'manual_recovery' && ! isset( $receipt['paired_at'] ) && $receipt['key_id'] === $r->data['key_id'] );
check_rec( 'is_connected() stays false after a recovery secret (it cannot be spoofed)', ! Ratesight_Pairing::is_connected() && Ratesight_Pairing::status()['source'] === null && Ratesight_Pairing::status()['pairedAt'] === null && Ratesight_Pairing::status()['recovery'] === true );
check_rec( 'readiness was cleared and the mode allows the signed self-test (observe or enforce)', ! isset( $options['ratesight_auth_v2_readiness'] ) && in_array( Ratesight_Request_Auth::mode(), array( 'observe_v2', 'enforce_v2' ), true ) );
$audit = get_option( 'ratesight_auth_audit', array() );
$row   = end( $audit );
check_rec( 'generate wrote one audit row with who, action and key id, no secret', count( $audit ) === 1 && $row['user_login'] === 'owner' && $row['result'] === 'recovery_secret_generated' && $row['key_id'] === $r->data['key_id'] && ! str_contains( json_encode( $audit ), $secret ) );
$second = call_admin( 'ajax_generate_recovery_secret', array( 'confirm' => 'GENERATE' ) );
check_rec( 'each generate is a new secret (the first is not reused or re-shown)', $second->data['secret'] !== $secret );

// A signed pairing afterwards is the only way to become connected.
$options['ratesight_pairing_receipt'] = array( 'source' => 'dashboard', 'key_id' => $second->data['key_id'], 'paired_at' => time() );
$options['ratesight_auth_v2_readiness'] = array( 'key_id' => $second->data['key_id'], 'completed_at' => time() );
check_rec( 'only a receipt written by the signed pairing route makes it connected', Ratesight_Pairing::is_connected() );

// ── audit cap ─────────────────────────────────────────────────────────────────
$options['ratesight_auth_audit'] = array_fill( 0, 100, array( 'result' => 'x' ) );
Ratesight_Request_Auth::record_admin_event( 7, 'owner', 'pairing_reset', '' );
check_rec( 'the audit keeps its 100 row cap', count( get_option( 'ratesight_auth_audit' ) ) === 100 );

// ── registration and wiring ───────────────────────────────────────────────────
$loader = file_get_contents( __DIR__ . '/../includes/class-ratesight.php' );
check_rec( 'both actions are registered as logged-in admin AJAX only (no nopriv)', str_contains( $loader, "'wp_ajax_ratesight_reset_pairing'" ) && str_contains( $loader, "'wp_ajax_ratesight_generate_recovery_secret'" ) && ! str_contains( $loader, 'nopriv_ratesight_reset_pairing' ) && ! str_contains( $loader, 'nopriv_ratesight_generate_recovery_secret' ) );
$admin_src = file_get_contents( __DIR__ . '/../admin/class-ratesight-admin.php' );
check_rec( 'the old dormant generator (48-hex, no audit) is gone', ! str_contains( $admin_src, 'ajax_regen_webhook_secret' ) );
$card = file_get_contents( __DIR__ . '/../admin/partials/tab-connections-dashboard.php' );
check_rec( 'the Connections card shows the controls to administrators only', str_contains( $card, "current_user_can( 'manage_options' )" ) && str_contains( $card, 'Disconnect / reset pairing' ) && str_contains( $card, 'Generate recovery secret' ) );
$js = file_get_contents( __DIR__ . '/../admin/js/ratesight-admin.js' );
check_rec( 'the browser asks for confirmation and a typed word and shows the clipboard warning', str_contains( $js, "window.confirm( 'Generate a new recovery secret?" ) && str_contains( $js, "window.confirm( 'Clear this site" ) && str_contains( $js, 'Anyone who sees this secret can act as Ratesight on this site; paste it only into the dashboard field and do not send it by chat or email.' ) );

echo PHP_EOL . ( $failures ? "FAIL: $failures" : 'ALL PAIRING RECOVERY CHECKS PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
