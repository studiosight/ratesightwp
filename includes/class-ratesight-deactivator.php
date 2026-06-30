<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Deactivator {

	public static function deactivate() {
		foreach ( array( 'ratesight_prune_logs', 'ratesight_sync_gsc' ) as $event ) {
			$ts = wp_next_scheduled( $event );
			if ( $ts ) {
				wp_unschedule_event( $ts, $event );
			}
		}
		delete_transient( Ratesight_License::TRANSIENT_KEY );
	}
}
