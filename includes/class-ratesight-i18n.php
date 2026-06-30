<?php
/**
 * Internationalisation.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_i18n {

	public function load_plugin_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain(
			'ratesight',
			false,
			dirname( plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' ) ) . '/languages/'
		);
	}
}
