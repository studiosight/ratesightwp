<?php
/**
 * Side-by-side installation discovery and fail-safe uninstall policy.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Installation {

	public const RETENTION_OPTION = 'ratesight_retain_on_uninstall';

	/**
	 * Build value-free status from explicit inventory inputs.
	 *
	 * @param string $current_basename Basename of the copy being inspected.
	 * @param array  $plugins          get_plugins()-compatible metadata.
	 * @param array  $active_plugins   Site-active plugin basenames.
	 * @param array  $network_active   Network-active plugin basenames.
	 * @param mixed  $retention        Stored retention option; absent defaults true.
	 */
	public static function status_from_inventory( string $current_basename, array $plugins, array $active_plugins, array $network_active, $retention = true ): array {
		$current_basename = self::normalize_basename( $current_basename );
		$installations = array();
		foreach ( $plugins as $basename => $metadata ) {
			$basename = self::normalize_basename( (string) $basename );
			$name = trim( (string) ( $metadata['Name'] ?? '' ) );
			$text_domain = trim( (string) ( $metadata['TextDomain'] ?? '' ) );
			if ( $basename !== '' && str_ends_with( $basename, '/ratesight.php' ) && ( $name === 'Ratesight' || $text_domain === 'ratesight' ) ) {
				$installations[ $basename ] = $metadata;
			}
		}
		ksort( $installations );

		$known = $current_basename !== '' && isset( $installations[ $current_basename ] );
		$siblings = $known ? array_diff( array_keys( $installations ), array( $current_basename ) ) : array_keys( $installations );
		$active = array_values( array_unique( array_merge( $active_plugins, $network_active ) ) );
		$current_active = $known && in_array( $current_basename, $active, true );
		$active_siblings = array_values( array_intersect( $siblings, $active ) );
		$retention_enabled = ! in_array( $retention, array( false, 0, '0' ), true );

		$block_reason = null;
		if ( ! $known ) {
			$block_reason = 'installation_identity_ambiguous';
		} elseif ( $current_active ) {
			$block_reason = 'current_installation_active';
		} elseif ( count( $active_siblings ) > 0 ) {
			$block_reason = 'active_sibling_present';
		} elseif ( count( $siblings ) > 0 ) {
			$block_reason = 'sibling_installation_present';
		} elseif ( $retention_enabled ) {
			$block_reason = 'retention_enabled';
		}

		$metadata = $known ? $installations[ $current_basename ] : array();
		$directory = $known ? dirname( $current_basename ) : '';
		return array(
			'releaseVersion'              => $known ? (string) ( $metadata['Version'] ?? '' ) : '',
			'pluginBasename'              => $known ? $current_basename : '',
			'directoryName'               => $known && $directory !== '.' ? basename( $directory ) : '',
			'active'                      => $current_active,
			'siblingCount'                => count( $siblings ),
			'destructiveUninstallAllowed' => $block_reason === null,
			'blockReason'                 => $block_reason,
		);
	}

	/**
	 * Discover installed copies using WordPress-local state only.
	 */
	public static function status( string $current_basename ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		return self::status_from_inventory(
			$current_basename,
			(array) get_plugins(),
			(array) get_option( 'active_plugins', array() ),
			$network_active,
			get_option( self::RETENTION_OPTION, true )
		);
	}

	/**
	 * Inventory every shared-state family touched by destructive uninstall.
	 */
	public static function shared_state_inventory( array $schema_options = array() ): array {
		$options = array_merge( $schema_options, array(
			'ratesight_db_version', 'ratesight_cpt_flushed', 'ratesight_gsc_last_sync',
			'ratesight_bing_last_sync', 'ratesight_gbp_last_sync', 'ratesight_gbp_performance_last_sync',
			'ratesight_link_last_scan', 'ratesight_indexnow_key', 'ratesight_indexnow_log',
			'ratesight_link_scan_running', 'ratesight_link_broken_running', 'ratesight_bulk_queue',
			'ratesight_bulk_publish_progress', 'ratesight_webhook_secret', 'ratesight_webhook_secret_previous',
			'ratesight_webhook_secret_previous_expires', 'ratesight_auth_mode', 'ratesight_auth_ever_enforced',
			'ratesight_auth_v2_readiness', 'ratesight_auth_audit', 'ratesight_api_key',
			'ratesight_notify_email', 'ratesight_notify_enabled', 'ratesight_rs_redirects',
			'ratesight_deepseek_api_key', 'ratesight_recovery_actions', 'ratesight_redirect_health_last',
			'ratesight_redirect_serve_log', 'ratesight_inbound_log', self::RETENTION_OPTION, 'ratesight_health_catch_all_urls',
		) );
		foreach ( array( 'gbp', 'gsc' ) as $service ) {
			foreach ( array( 'oauth', 'selection', 'locked', 'revoked', 'disconnect_reason', 'refresh_error', 'scope_error' ) as $suffix ) {
				$options[] = "ratesight_{$service}_{$suffix}";
			}
		}

		$options = array_values( array_unique( $options ) );
		sort( $options );
		return array(
			'options'           => $options,
			'optionPrefixes'    => array( 'ratesight_auth_nonce_' ),
			'tables'            => array( 'ratesight_logs', 'ratesight_performance', 'ratesight_keywords', 'ratesight_gbp_performance', 'ratesight_bing_performance', 'ratesight_bing_keywords', 'ratesight_link_cache' ),
			'postTypes'         => array( 'ratesight_page' ),
			'postMeta'          => array( '_ratesight_layout', '_ratesight_meta_description', '_ratesight_meta_title', '_ratesight_related_links', '_ratesight_show_title', '_rs_content_hash', '_rs_created', '_rs_custom_css_url', '_rs_layout', '_rs_manual_links', '_rs_meta_description', '_rs_meta_title', '_rs_pre_update_snapshot', '_rs_schema', '_rs_show_title' ),
			'userMeta'          => array( 'ratesight_wizard_dismissed' ),
			'transientPrefixes' => array( 'rs_', 'ratesight_' ),
			'cronHooks'         => array( 'ratesight_prune_logs', 'ratesight_sync_gsc', 'ratesight_sync_gbp_performance', 'ratesight_sync_bing', 'ratesight_retry_pending', 'ratesight_check_broken_links', 'ratesight_redirect_health', 'ratesight_prune_auth_nonces', 'ratesight_daily_digest', 'ratesight_process_bulk_queue', 'ratesight_bulk_publish_batch', 'ratesight_deferred_publish' ),
		);
	}

	private static function normalize_basename( string $basename ): string {
		$basename = str_replace( '\\', '/', trim( $basename ) );
		if ( $basename === '' || str_starts_with( $basename, '/' ) || str_contains( $basename, '../' ) ) {
			return '';
		}
		return trim( $basename, '/' );
	}
}
