<?php
/**
 * 3.14.0: the fuzzy_mode sanitiser never pins an unset option to a value nobody chose.
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
$wp_options = array();
function get_option( $name, $default = false ) { global $wp_options; return array_key_exists( $name, $wp_options ) ? $wp_options[ $name ] : $default; }
require __DIR__ . '/../includes/class-ratesight-options.php';

$failures = 0;
$checks   = 0;
function check_fuzzy_case( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

check_fuzzy_case( 'schema default is same-city-or-hub', Ratesight_Options::schema()['fuzzy_mode']['default'] === 'same-city-or-hub' );
check_fuzzy_case( 'unset option reads as same-city-or-hub through the options registry', Ratesight_Options::get( 'fuzzy_mode' ) === 'same-city-or-hub' );
foreach ( array( 'legacy', 'same-city-or-hub', 'off' ) as $mode ) {
	check_fuzzy_case( "posted {$mode} is stored", Ratesight_Options::sanitise( $mode, 'fuzzy_mode' ) === $mode );
}
check_fuzzy_case( 'another form saving with the option unset writes nothing (false equals the missing option)', Ratesight_Options::sanitise( null, 'fuzzy_mode' ) === false );
check_fuzzy_case( 'invalid input with the option unset writes nothing', Ratesight_Options::sanitise( 'weird', 'fuzzy_mode' ) === false );
$wp_options['ratesight_fuzzy_mode'] = 'legacy';
check_fuzzy_case( 'another form saving preserves an explicit legacy choice', Ratesight_Options::sanitise( null, 'fuzzy_mode' ) === 'legacy' );
$wp_options['ratesight_fuzzy_mode'] = 'same-city-or-hub';
check_fuzzy_case( 'another form saving preserves an explicit same-city-or-hub choice', Ratesight_Options::sanitise( null, 'fuzzy_mode' ) === 'same-city-or-hub' );
$wp_options['ratesight_fuzzy_mode'] = 'junk';
check_fuzzy_case( 'invalid stored value is cleared rather than preserved', Ratesight_Options::sanitise( null, 'fuzzy_mode' ) === false );

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
