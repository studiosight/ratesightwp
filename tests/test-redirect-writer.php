<?php
/**
 * 3.15.5: Ratesight_Redirect_Writer: method native forced, Redirection plugin exact-match writes and
 * deletes (the lookup returns a list, regex rules ignored), WP_Error reported, native fallback.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
$options = array();
function get_option( $name, $default = false ) { global $options; return array_key_exists( $name, $options ) ? $options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $options; $options[ $name ] = $value; return true; }
function current_time( $type ) { return '2026-10-08 01:00:00'; }
class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} public function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// Minimal Redirection plugin stand-in (5.x API shape).
class Red_Item {
	public static array $rows = array();
	public static int $next = 1;
	public static bool $fail_create = false;
	public function __construct( public array $row ) {}
	public static function get_for_url( $url ) {
		$out = array();
		foreach ( self::$rows as $r ) {
			if ( $r['status'] !== 'enabled' ) continue;
			if ( $r['regex'] || rtrim( $r['url'], '/' ) === rtrim( $url, '/' ) ) $out[] = new Red_Item( $r );
		}
		return $out;
	}
	public static function create( array $d ) {
		if ( self::$fail_create ) return new WP_Error( 'redirect_create_failed', 'Invalid group' );
		$id = self::$next++;
		self::$rows[ $id ] = array( 'id' => $id, 'url' => $d['url'], 'to' => $d['action_data']['url'], 'code' => $d['action_code'], 'group_id' => $d['group_id'], 'regex' => false, 'status' => 'enabled' );
		return new Red_Item( self::$rows[ $id ] );
	}
	public function update( $d ) { self::$rows[ $this->row['id'] ]['to'] = $d['action_data']['url']; self::$rows[ $this->row['id'] ]['code'] = $d['action_code']; return true; }
	public function delete() { unset( self::$rows[ $this->row['id'] ] ); }
	public function get_url() { return $this->row['url']; }
	public function get_id() { return $this->row['id']; }
	public function get_group_id() { return $this->row['group_id']; }
	public function is_regex() { return $this->row['regex']; }
}
class Red_Group {
	public static function get_all( $params = array() ) {
		return array( array( 'id' => 3, 'module_id' => 2, 'enabled' => true ), array( 'id' => 4, 'module_id' => 1, 'enabled' => false ), array( 'id' => 5, 'module_id' => 1, 'enabled' => true ) );
	}
}

require __DIR__ . '/../includes/class-ratesight-redirect-writer.php';

$failures = 0;
$checks   = 0;
function check( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

check( 'detects Redirection when it is active', Ratesight_Redirect_Writer::detect_method() === 'redirection' );
check( 'method native is forced whatever is active', Ratesight_Redirect_Writer::method_for_request( 'native' ) === 'native' && Ratesight_Redirect_Writer::method_for_request( ' NATIVE ' ) === 'native' );
check( 'any other method value means the default', Ratesight_Redirect_Writer::method_for_request( 'rankmath' ) === 'redirection' && Ratesight_Redirect_Writer::method_for_request( null ) === 'redirection' );

// A regex rule on the site (the old code called ->update() on the list and threw).
Red_Item::$rows[99] = array( 'id' => 99, 'url' => '^/blog/(.*)$', 'to' => '/news/$1', 'code' => 301, 'group_id' => 1, 'regex' => true, 'status' => 'enabled' );

$forced = Ratesight_Redirect_Writer::write( '/old-page', '/new-page/', 301, 'native' );
check( 'forced native stores in the plugin map and says native', $forced === array( true, 'native', null ) && $options['ratesight_rs_redirects']['/old-page/']['redirect_to'] === '/new-page/' );
check( 'forced native leaves Redirection untouched', count( Red_Item::$rows ) === 1 );

$red = Ratesight_Redirect_Writer::write( '/team', '/our-team/', 301, 'redirection' );
$made = array_values( array_filter( Red_Item::$rows, fn( $r ) => ! $r['regex'] ) );
check( 'Redirection write creates one exact item in the enabled WordPress group, not touching the regex rule', $red === array( true, 'redirection', null ) && count( $made ) === 1 && $made[0]['group_id'] === 5 && Red_Item::$rows[99]['to'] === '/news/$1' );

$again = Ratesight_Redirect_Writer::write( '/team', '/staff/', 301, 'redirection' );
$made  = array_values( array_filter( Red_Item::$rows, fn( $r ) => ! $r['regex'] ) );
check( 'a second write updates the same item (upsert by source)', $again[0] === true && count( $made ) === 1 && $made[0]['to'] === '/staff/' );

Red_Item::$fail_create = true;
$fallback = Ratesight_Redirect_Writer::write( '/gone', '/here/', 301, 'redirection' );
check( 'a Redirection error falls back to native and says so', $fallback[0] === true && $fallback[1] === 'native' && strpos( (string) $fallback[2], 'Invalid group' ) !== false && isset( $options['ratesight_rs_redirects']['/gone/'] ) );
Red_Item::$fail_create = false;

list( $native_removed, $red_removed ) = Ratesight_Redirect_Writer::delete( '/team/' );
check( 'delete removes the exact Redirection item and keeps the regex rule', $red_removed === 1 && ! $native_removed && isset( Red_Item::$rows[99] ) && count( Red_Item::$rows ) === 1 );

list( $native_removed, $red_removed ) = Ratesight_Redirect_Writer::delete( 'old-page' );
check( 'delete removes the native rule in any slash form', $native_removed === true && $red_removed === 0 && ! isset( $options['ratesight_rs_redirects']['/old-page/'] ) );

list( $native_removed, $red_removed ) = Ratesight_Redirect_Writer::delete( '/never-there/' );
check( 'deleting nothing is a no-op', $native_removed === false && $red_removed === 0 );

echo PHP_EOL . ( $checks - $failures ) . '/' . $checks . ' checks passed' . PHP_EOL;
exit( $failures ? 1 : 0 );
