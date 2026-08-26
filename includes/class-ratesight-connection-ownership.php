<?php
/**
 * Value-free capability and external-consumer ownership inventory.
 *
 * This class is deliberately not exposed through REST or admin rendering. It is
 * a source contract used to prevent removal decisions from being inferred from
 * an incomplete repository search.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Connection_Ownership {

	private const BLOCKED_EXTERNAL = 'blocked_external_consumer';

	/**
	 * Return the complete, sanitized ownership inventory.
	 */
	public static function inventory(): array {
		$state = Ratesight_Installation::shared_state_inventory(
			array_map( static fn( array $definition ): string => $definition['name'], Ratesight_Options::schema() )
		);

		$inventory = array(
			'options'           => self::records( 'option', $state['options'], 'includes/class-ratesight-installation.php', 'wordpress_plugin', 'retained_wordpress' ),
			'optionPrefixes'    => self::records( 'option_prefix', $state['optionPrefixes'], 'includes/class-ratesight-installation.php', 'wordpress_plugin', 'retained_wordpress' ),
			'tables'            => self::records( 'table', $state['tables'], 'includes/class-ratesight-activator.php', 'wordpress_plugin', 'retained_wordpress' ),
			'postTypes'         => self::records( 'post_type', $state['postTypes'], 'includes/class-ratesight-cpt.php', 'wordpress_plugin', 'retained_wordpress' ),
			'postMeta'          => self::records( 'post_meta', $state['postMeta'], 'includes/', 'wordpress_plugin', 'retained_wordpress' ),
			'userMeta'          => self::records( 'user_meta', $state['userMeta'], 'admin/class-ratesight-admin.php', 'wordpress_plugin', 'retained_wordpress' ),
			'transientPrefixes' => self::records( 'transient_prefix', $state['transientPrefixes'], 'includes/class-ratesight-installation.php', 'wordpress_plugin', 'retained_wordpress' ),
			'cronHooks'         => self::records( 'cron_hook', $state['cronHooks'], 'includes/class-ratesight.php', 'wordpress_plugin', 'retained_wordpress' ),
			'adminControls'     => self::admin_controls(),
			'ajaxActions'       => self::records( 'ajax_action', self::ajax_actions(), 'includes/class-ratesight.php', 'wordpress_plugin', 'retained_wordpress' ),
			'restRoutes'        => self::rest_routes(),
			'oauthCallbacks'    => self::oauth_callbacks(),
			'providerClients'   => self::provider_clients(),
			'providerOperations'=> self::provider_operations(),
			'workerEndpoints'   => self::worker_endpoints(),
			'publicActions'     => self::public_actions(),
			'capabilities'      => self::capabilities(),
			'operatorSurface'   => self::operator_surface(),
			'phase6Gates'       => self::phase_6_gates(),
			'replacements'      => self::replacement_matrix(),
			'uninstallDeletes'  => self::uninstall_deletions( $state ),
		);

		return $inventory;
	}

	/**
	 * Surfaces that remain intentionally local to WordPress after a future cutover.
	 */
	public static function operator_surface(): array {
		return array(
			self::surface( 'app_auth_health', 'App authentication and health' ),
			self::surface( 'active_installation_status', 'Active installation status' ),
			self::surface( 'wordpress_local_behavior', 'WordPress-local behavior' ),
			self::surface( 'signed_event_health', 'Signed-event health' ),
			self::surface( 'indexnow_status', 'IndexNow status' ),
			self::surface( 'emergency_diagnostics', 'Emergency diagnostics' ),
		);
	}

	/**
	 * Destructive or operational Phase 6 actions remain independently gated.
	 */
	public static function phase_6_gates(): array {
		$definitions = array(
			'credential_deletion'       => 'Separate credential inventory, backup, rollback, and deletion authorization.',
			'ui_code_removal'           => 'Verified replacement parity plus separate UI and code removal authorization.',
			'observe_mode'              => 'Separate O1 operational authorization and acceptance evidence.',
			'enforce_mode'              => 'Separate E1 operational authorization after Observe acceptance.',
			'external_worker_change'    => 'Positive Worker ownership and consumer proof plus separate change authorization.',
			'outbound_link_migration'   => 'Dashboard parity and migration rollback proof plus separate authorization.',
			'live_site_binding'         => 'Operator-approved identity mapping and separately authorized live migration.',
		);
		$result = array();
		foreach ( $definitions as $id => $evidence ) {
			$result[] = array(
				'id' => $id, 'state' => 'blocked', 'authorization' => 'separate',
				'evidenceToUnblock' => $evidence,
			);
		}
		return $result;
	}

	private static function surface( string $id, string $label ): array {
		return array(
			'id' => $id, 'label' => $label, 'owner' => 'wordpress_plugin',
			'state' => 'retained', 'destination' => 'wordpress_connections',
		);
	}

	private static function admin_controls(): array {
		$ids = array(
			'ratesight_gbp_cta_type', 'ratesight_gbp_post_enabled',
			'rs-gbp-disconnect-btn', 'rs-gbp-disconnect-input', 'rs-gbp-filter', 'rs-gbp-location-select',
			'rs-gsc-disconnect-btn', 'rs-gsc-disconnect-input', 'rs-gsc-filter', 'rs-gsc-property-select',
			'rs-load-bing-sites', 'rs-load-gbp-locations', 'rs-load-gsc-properties',
			'rs-lock-bing-btn', 'rs-lock-gbp-btn', 'rs-lock-gsc-btn',
			'rs-bing-site-select', 'rs-sync-bing-now', 'rs-sync-gbp-conn-now', 'rs-sync-gsc-now',
			'rs-clear-indexnow-log', 'rs-test-ai-worker',
			'oauth-connect:gsc', 'oauth-connect:gbp', 'quick-disconnect:gsc', 'quick-disconnect:gbp',
			'settings-submit:ratesight_options_connections', 'reschedule-all',
			'secret:bing_api_key:replace', 'secret:bing_api_key:remove',
			'secret:deepseek_api_key:replace', 'secret:deepseek_api_key:remove',
		);
		return self::records( 'admin_control', $ids, 'admin/partials/tab-connections.php', 'wordpress_plugin', 'retained_wordpress' );
	}

	private static function records( string $type, array $ids, string $source, string $owner, string $decision ): array {
		return array_map( static fn( string $id ): array => array(
			'id'             => $id,
			'type'           => $type,
			'source'         => $source,
			'owner'          => $owner,
			'state'          => $decision,
			'replacement'    => null,
			'proofType'      => 'source_parser',
			'reasonCode'     => null,
			'evidenceToUnblock' => null,
		), $ids );
	}

	private static function ajax_actions(): array {
		return array(
			'ratesight_add_category', 'ratesight_ai_chat', 'ratesight_answer_question',
			'ratesight_bulk_publish_drafts', 'ratesight_bulk_publish_progress', 'ratesight_clear_indexnow_log',
			'ratesight_clear_logs', 'ratesight_connections_status', 'ratesight_cron_ping',
			'ratesight_debug_pending_logs', 'ratesight_disconnect_gbp', 'ratesight_disconnect_gsc',
			'ratesight_dismiss_wizard', 'ratesight_do_sync', 'ratesight_fix_log_status',
			'ratesight_get_attention_pages', 'ratesight_get_cannibalization', 'ratesight_get_improvement_queue',
			'ratesight_get_insights', 'ratesight_get_keywords', 'ratesight_get_last_sync', 'ratesight_get_logs',
			'ratesight_get_profile_health', 'ratesight_get_qa', 'ratesight_get_rankings',
			'ratesight_get_recommendations', 'ratesight_get_reviews', 'ratesight_get_site_overview',
			'ratesight_indexnow_status', 'ratesight_link_auto_fix', 'ratesight_link_broken_detail',
			'ratesight_link_bulk_check_broken', 'ratesight_link_check_broken', 'ratesight_link_fix_targets',
			'ratesight_link_get_manual', 'ratesight_link_ignore_broken', 'ratesight_link_insert',
			'ratesight_link_refresh_suggestions', 'ratesight_link_remove_manual', 'ratesight_link_replace',
			'ratesight_link_scan', 'ratesight_link_suggestions', 'ratesight_link_unignore_broken',
			'ratesight_link_unlink', 'ratesight_list_gbp', 'ratesight_list_gsc', 'ratesight_load_bing_sites',
			'ratesight_lock_bing_site', 'ratesight_lock_gbp', 'ratesight_lock_gsc', 'ratesight_preview_schema',
			'ratesight_recheck_pending', 'ratesight_redirect_delete', 'ratesight_redirect_update',
			'ratesight_regen_webhook_secret', 'ratesight_remove_schema', 'ratesight_reply_review',
			'ratesight_retry_gbp', 'ratesight_retry_log', 'ratesight_review_velocity', 'ratesight_rewrite_meta',
			'ratesight_save_bing_key', 'ratesight_save_meta', 'ratesight_save_schema', 'ratesight_send_test',
			'ratesight_set_auth_mode', 'ratesight_sitemap_status', 'ratesight_sync_bing_now',
			'ratesight_sync_gbp_now', 'ratesight_sync_gsc_finalise', 'ratesight_sync_gsc_keywords',
			'ratesight_sync_gsc_now', 'ratesight_test_ai_worker', 'ratesight_update_secret_setting',
		);
	}

	private static function rest_routes(): array {
		$definitions = array(
			'/auth-self-test' => array( 'GET' ), '/create-page' => array( 'POST', 'DELETE' ),
			'/update-page' => array( 'GET', 'POST' ), '/redirect' => array( 'POST', 'DELETE' ),
			'/capabilities' => array( 'GET' ), '/redirects' => array( 'GET' ),
			'/inbound-log' => array( 'GET' ), '/redirects-log' => array( 'GET' ),
			'/related-links' => array( 'GET', 'POST', 'DELETE' ), '/page' => array( 'GET', 'POST' ),
			'/trash-page' => array( 'POST' ), '/restore-page' => array( 'POST' ),
		);
		$result = array();
		foreach ( $definitions as $path => $methods ) {
			foreach ( $methods as $method ) {
				$result[] = array(
					'id' => $method . ' /ratesight/v1' . $path, 'type' => 'rest_route',
					'source' => str_contains( $path, 'related-links' ) ? 'includes/class-ratesight-related-links.php' : ( str_contains( $path, '/page' ) ? 'includes/class-ratesight-page-api.php' : ( str_contains( $path, 'trash-' ) || str_contains( $path, 'restore-' ) ? 'includes/class-ratesight-page-lifecycle.php' : 'includes/class-ratesight-webhook-handler.php' ) ),
					'owner' => 'wordpress_plugin', 'state' => 'retained_wordpress', 'replacement' => null, 'proofType' => 'route_registration',
					'reasonCode' => null, 'evidenceToUnblock' => null,
				);
			}
		}
		return $result;
	}

	private static function oauth_callbacks(): array {
		return array(
			self::record( 'oauth:gbp:worker-callback', 'oauth_callback', 'includes/class-ratesight-oauth-client.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker source/deploy owner and verify callback policy and active consumers.' ),
			self::record( 'oauth:gsc:worker-callback', 'oauth_callback', 'includes/class-ratesight-oauth-client.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker source/deploy owner and verify callback policy and active consumers.' ),
			self::record( 'oauth:wordpress:token-return', 'oauth_callback', 'admin/class-ratesight-admin.php', 'wordpress_plugin', 'retained_wordpress' ),
		);
	}

	private static function provider_clients(): array {
		return array(
			self::record( 'google_oauth_via_worker', 'provider_client', 'includes/class-ratesight-oauth-client.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker source/deploy owner and prove OAuth callback and refresh ownership.' ),
			self::record( 'google_business_profile', 'provider_client', 'includes/class-ratesight-gbp-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven' ),
			self::record( 'google_business_profile_performance', 'provider_client', 'includes/class-ratesight-gbp-insights-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven' ),
			self::record( 'google_search_console', 'provider_client', 'includes/class-ratesight-gsc-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven' ),
			self::record( 'bing_webmaster_performance', 'provider_client', 'includes/class-ratesight-bing-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven' ),
			self::record( 'indexnow_notification', 'provider_client', 'includes/class-ratesight-indexnow.php', 'wordpress_plugin', 'retained_wordpress' ),
			self::record( 'deepseek_via_worker', 'provider_client', 'includes/class-ratesight-ai-client.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker source, credential owner, model contract, and deployment policy.' ),
			self::record( 'ratesight_license_via_worker', 'provider_client', 'includes/class-ratesight-license.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker source/deploy owner and verify validation consumers.' ),
			self::record( 'ratesight_review_widget', 'provider_client', 'public/class-ratesight-public.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'external_widget_owner_unresolved', 'Identify widget service owner and consumer contract.' ),
			self::record( 'worksight_jobs_widget', 'provider_client', 'public/class-ratesight-public.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'external_widget_owner_unresolved', 'Identify jobs widget service owner and consumer contract.' ),
			self::record( 'archive_org_link_recovery', 'provider_client', 'includes/class-ratesight-link-manager.php', 'wordpress_plugin', 'retained_wordpress' ),
		);
	}

	private static function provider_operations(): array {
		$definitions = array(
			'admin/class-ratesight-admin.php::ajax_connections_status:wp_remote_head#1' => 'wordpress_read',
			'admin/class-ratesight-admin.php::ajax_lock_gsc:wp_remote_head#1' => 'wordpress_read',
			'admin/class-ratesight-admin.php::ajax_lock_gsc:wp_remote_request#1' => 'provider_write',
			'admin/class-ratesight-admin.php::ajax_send_test:wp_remote_get#1' => 'external_http_read',
			'admin/class-ratesight-admin.php::ajax_sitemap_status:wp_remote_get#1' => 'provider_read',
			'admin/class-ratesight-admin.php::ajax_sitemap_status:wp_remote_get#2' => 'external_worker_read',
			'admin/class-ratesight-admin.php::ajax_sitemap_status:wp_remote_head#1' => 'wordpress_read',
			'admin/class-ratesight-admin.php::ajax_sync_gbp_now:wp_remote_get#1' => 'provider_read',
			'includes/class-ratesight-ai-client.php::prompt:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-ai-client.php::get_insights:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-ai-client.php::get_recommendations:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-bing-client.php::api_get:wp_remote_get#1' => 'provider_read',
			'includes/class-ratesight-gbp-client.php::get:wp_remote_get#1' => 'provider_read',
			'includes/class-ratesight-gbp-client.php::post:wp_remote_post#1' => 'provider_write',
			'includes/class-ratesight-gbp-insights-client.php::get:wp_remote_get#1' => 'provider_read',
			'includes/class-ratesight-gbp-insights-client.php::post_request:wp_remote_post#1' => 'provider_write',
			'includes/class-ratesight-gsc-client.php::get:wp_remote_get#1' => 'provider_read',
			'includes/class-ratesight-gsc-client.php::post:wp_remote_post#1' => 'provider_read',
			'includes/class-ratesight-image-uploader.php::download:wp_safe_remote_get#1' => 'external_http_read',
			'includes/class-ratesight-indexnow.php::submit:wp_remote_post#1' => 'provider_write',
			'includes/class-ratesight-indexnow.php::submit_bulk:wp_remote_post#1' => 'provider_write',
			'includes/class-ratesight-indexnow.php::verify_key:wp_remote_get#1' => 'wordpress_read',
			'includes/class-ratesight-license.php::check_and_cache:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-link-manager.php::auto_fix_suggestions:wp_remote_get#1' => 'external_http_read',
			'includes/class-ratesight-link-manager.php::follow_redirect_chain:wp_remote_head#1' => 'external_http_read',
			'includes/class-ratesight-link-manager.php::head_url:wp_remote_get#1' => 'external_http_read',
			'includes/class-ratesight-link-manager.php::head_url:wp_remote_head#1' => 'external_http_read',
			'includes/class-ratesight-oauth-client.php::handle_token_return:wp_remote_post#1' => 'provider_write',
			'includes/class-ratesight-oauth-client.php::refresh_via_worker:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-publisher.php::auto_submit_to_bing:wp_remote_post#1' => 'external_worker_write',
			'includes/class-ratesight-redirect-health.php::check_url:wp_remote_head#1' => 'external_http_read',
			'includes/class-ratesight-redirect-health.php::check_url:wp_remote_head#2' => 'external_http_read',
			'public/class-ratesight-public.php::review_widget_embed' => 'browser_service_read',
			'public/class-ratesight-public.php::jobs_widget_embed' => 'browser_service_read',
		);
		$result = array();
		foreach ( $definitions as $id => $operation_type ) {
			$source = strstr( $id, '::', true ) ?: $id;
			$result[] = self::record( $id, $operation_type, $source, 'wordpress_plugin', 'retained_wordpress' );
		}
		return $result;
	}

	private static function worker_endpoints(): array {
		$result = array();
		foreach ( array( '/callback', '/refresh', '/validate', '/ai-chat', '/insights', '/recommend', '/auto-submit', '/sitemap-status' ) as $path ) {
			$result[] = self::record(
				'oauth.ratesight.com' . $path,
				'worker_endpoint',
				self::worker_source( $path ),
				'external_owner_unresolved',
				self::BLOCKED_EXTERNAL,
				'worker_owner_unresolved',
				'Locate Worker source/deploy owner and verify its route policy and consumers.'
			);
		}
		return $result;
	}

	private static function public_actions(): array {
		return array(
			self::record( 'wp_ajax_nopriv_ratesight_do_sync', 'public_action', 'includes/class-ratesight.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'external_consumer_unresolved', 'Identify every caller and prove an authenticated replacement before changing or removing this action.' ),
			self::record( 'wp_ajax_nopriv_ratesight_cron_ping', 'public_action', 'includes/class-ratesight.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'external_consumer_unresolved', 'Identify every scheduler/caller and prove an authenticated replacement before changing or removing this action.' ),
		);
	}

	private static function capabilities(): array {
		return array(
			self::record( 'gbp_measurement', 'capability', 'includes/class-ratesight-gbp-insights-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_parity_unproven', 'Prove identity, permissions, fields, freshness, and read parity.' ),
			self::record( 'gbp_publishing', 'capability', 'includes/class-ratesight-gbp-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_delivery_unproven', 'Prove identity, permission, idempotency, approval, delivery, and rollback in a separately authorized gate.' ),
			self::record( 'gsc_measurement', 'capability', 'includes/class-ratesight-gsc-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_parity_unproven', 'Prove identity, permissions, fields, freshness, and read parity.' ),
			self::record( 'gsc_sitemap_submission', 'capability', 'admin/class-ratesight-admin.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_delivery_unproven', 'Prove property identity, write permission, idempotency, delivery result, and rollback.' ),
			self::record( 'gbp_review_reply', 'capability', 'includes/class-ratesight-gbp-insights-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_delivery_unproven', 'Prove review identity, approval, write permission, delivery result, and rollback.' ),
			self::record( 'gbp_qa_read', 'capability', 'includes/class-ratesight-gbp-insights-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_parity_unproven', 'Prove question identity, permission, fields, and freshness.' ),
			self::record( 'gbp_qa_answer', 'capability', 'includes/class-ratesight-gbp-insights-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_delivery_unproven', 'Prove question identity, approval, write permission, delivery result, and rollback.' ),
			self::record( 'google_oauth_token_revoke', 'capability', 'includes/class-ratesight-oauth-client.php', 'wordpress_plugin', 'retained_wordpress' ),
			self::record( 'archive_link_recovery', 'capability', 'includes/class-ratesight-link-manager.php', 'wordpress_plugin', 'retained_wordpress' ),
			self::record( 'bing_performance', 'capability', 'includes/class-ratesight-bing-client.php', 'wordpress_plugin', 'dashboard_replacement_unproven', 'replacement_parity_unproven', 'Prove identity, permissions, fields, freshness, and read parity.' ),
			self::record( 'worker_sitemap_auto_submit', 'capability', 'includes/class-ratesight-publisher.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker implementation and identify every external consumer.' ),
			self::record( 'indexnow_notification', 'capability', 'includes/class-ratesight-indexnow.php', 'wordpress_plugin', 'retained_wordpress' ),
			self::record( 'deepseek_stored_option', 'capability', 'includes/class-ratesight-options.php', 'wordpress_plugin', 'retirement_candidate', 'removal_not_authorized', 'A later explicit migration/removal checkpoint must authorize deletion.' ),
			self::record( 'worker_ai', 'capability', 'includes/class-ratesight-ai-client.php', 'external_owner_unresolved', self::BLOCKED_EXTERNAL, 'worker_owner_unresolved', 'Locate Worker implementation, model contract, and deployment owner.' ),
			self::record( 'meta_social_publishing', 'capability', 'includes/class-ratesight-connection-ownership.php', 'dashboard', 'dashboard_replacement_unproven', 'plugin_delivery_absent', 'Prove the intended dashboard syndication delivery contract in a later checkpoint; do not add Meta credentials to the plugin.' ),
			self::record( 'outbound_broken_link_inventory', 'capability', 'includes/class-ratesight-link-manager.php', 'wordpress_plugin', 'retained_wordpress' ),
		);
	}

	private static function replacement_matrix(): array {
		return array(
			self::replacement( 'gbp', 'lib/google-business-profile.ts', 'dashboard' ),
			self::replacement( 'gsc', 'lib/google-search-console.ts', 'dashboard' ),
			self::replacement( 'bing', 'lib/bing-webmaster.ts', 'dashboard' ),
			self::replacement( 'meta', 'lib/meta.ts', 'dashboard' ),
		);
	}

	private static function uninstall_deletions( array $state ): array {
		$result = array();
		foreach ( array( 'options', 'optionPrefixes', 'tables', 'postTypes', 'postMeta', 'userMeta', 'transientPrefixes', 'cronHooks' ) as $family ) {
			$result[] = self::record( 'delete:' . $family, 'uninstall_deletion_family', 'uninstall.php', 'wordpress_plugin', 'blocked', 'default_retention_gate', 'Explicit final-copy proof and separately authorized retention opt-out are required.' );
		}
		return $result;
	}

	private static function replacement( string $id, string $source, string $owner ): array {
		return array(
			'id' => $id, 'type' => 'replacement', 'source' => $source, 'owner' => 'wordpress_plugin',
			'replacement' => $owner, 'state' => 'dashboard_replacement_unproven', 'proofType' => 'first_party_source_fixture',
			'reasonCode' => 'replacement_parity_unproven',
			'evidenceToUnblock' => 'Prove identity, permission, source capability, hermetic read parity, and separately authorized delivery for writes.',
		);
	}

	private static function record( string $id, string $type, string $source, string $owner, string $state, ?string $reason_code = null, ?string $evidence = null ): array {
		return array(
			'id' => $id, 'type' => $type, 'source' => $source, 'owner' => $owner,
			'state' => $state, 'replacement' => null, 'proofType' => 'first_party_source_search',
			'reasonCode' => $reason_code, 'evidenceToUnblock' => $evidence,
		);
	}

	private static function worker_source( string $path ): string {
		return match ( $path ) {
			'/callback', '/refresh' => 'includes/class-ratesight-oauth-client.php',
			'/validate' => 'includes/class-ratesight-license.php',
			'/auto-submit' => 'includes/class-ratesight-publisher.php',
			'/sitemap-status' => 'admin/class-ratesight-admin.php',
			default => 'includes/class-ratesight-ai-client.php',
		};
	}
}
