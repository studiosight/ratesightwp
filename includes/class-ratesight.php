<?php
/**
 * Core plugin class — wires all hooks through the loader.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight {

	private Ratesight_Loader $loader;

	public function __construct() {
		$this->loader = new Ratesight_Loader();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_webhook_hooks();
		$this->define_cron_hooks();
	}

	private function set_locale() {
		$i18n = new Ratesight_i18n();
		$this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
	}

	private function define_admin_hooks() {
		$admin = new Ratesight_Admin();
		$this->loader->add_action( 'admin_init',             $admin, 'register_settings'      );
		$this->loader->add_action( 'admin_init',             $admin, 'handle_oauth_callback', 5 );
		$this->loader->add_action( 'admin_notices',          $admin, 'license_notice'                 );
		$this->loader->add_action( 'admin_notices',          $admin, 'revocation_notice'              );
		$this->loader->add_action( 'admin_notices',          $admin, 'bulk_publish_progress_notice'   );
		$this->loader->add_action( 'admin_menu',             $admin, 'add_menu_page'           );
		$this->loader->add_action( 'admin_menu',             $admin, 'configure_submenu',   11 );
		$this->loader->add_filter( 'submenu_file',           $admin, 'fix_submenu_highlight'   );
		$this->loader->add_action( 'admin_enqueue_scripts',  $admin, 'enqueue_assets',     10  );
		$this->loader->add_action( 'admin_head',              $admin, 'prefill_recommended_title' );
		// Core
		$this->loader->add_action( 'wp_ajax_ratesight_clear_logs',            $admin, 'ajax_clear_logs'           );
		$this->loader->add_action( 'wp_ajax_ratesight_get_logs',              $admin, 'ajax_get_logs'             );
		$this->loader->add_action( 'wp_ajax_ratesight_fix_log_status',        $admin, 'ajax_fix_log_status'       );
		$this->loader->add_action( 'wp_ajax_ratesight_debug_pending_logs',    $admin, 'ajax_debug_pending_logs'   );
		$this->loader->add_action( 'wp_ajax_ratesight_bulk_publish_drafts',   $admin, 'ajax_bulk_publish_drafts'  );
		$this->loader->add_action( 'wp_ajax_ratesight_bulk_publish_progress', $admin, 'ajax_bulk_publish_progress' );
		$this->loader->add_action( 'ratesight_bulk_publish_batch',            $admin, 'cron_bulk_publish_batch'   );
		$this->loader->add_action( 'wp_ajax_ratesight_retry_log',           $admin, 'ajax_retry_log'           );
		$this->loader->add_action( 'wp_ajax_ratesight_retry_gbp',           $admin, 'ajax_retry_gbp'          );
		$this->loader->add_action( 'wp_ajax_ratesight_recheck_pending',      $admin, 'ajax_recheck_pending'    );
		$this->loader->add_action( 'wp_ajax_ratesight_send_test',           $admin, 'ajax_send_test'          );
		// GBP connection
		$this->loader->add_action( 'wp_ajax_ratesight_list_gbp',            $admin, 'ajax_list_gbp'           );
		$this->loader->add_action( 'wp_ajax_ratesight_lock_gbp',            $admin, 'ajax_lock_gbp'           );
		$this->loader->add_action( 'wp_ajax_ratesight_disconnect_gbp',      $admin, 'ajax_disconnect_gbp'     );
		// GSC connection
		$this->loader->add_action( 'wp_ajax_ratesight_list_gsc',            $admin, 'ajax_list_gsc'           );
		$this->loader->add_action( 'wp_ajax_ratesight_lock_gsc',            $admin, 'ajax_lock_gsc'           );
		$this->loader->add_action( 'wp_ajax_ratesight_disconnect_gsc',      $admin, 'ajax_disconnect_gsc'     );
		$this->loader->add_action( 'wp_ajax_ratesight_sync_gsc_now',        $admin, 'ajax_sync_gsc_now'       );
		$this->loader->add_action( 'wp_ajax_nopriv_ratesight_do_sync',       $admin, 'ajax_do_sync'             );
		$this->loader->add_action( 'wp_ajax_ratesight_sync_gsc_keywords',    $admin, 'ajax_sync_gsc_keywords'   );
		$this->loader->add_action( 'wp_ajax_ratesight_sync_gsc_finalise',    $admin, 'ajax_sync_gsc_finalise'   );
		$this->loader->add_action( 'wp_ajax_ratesight_cron_ping',            $admin, 'handle_cron_ping'        );
		$this->loader->add_action( 'wp_ajax_ratesight_get_last_sync',         $admin, 'ajax_get_last_sync'       );
		$this->loader->add_action( 'wp_ajax_nopriv_ratesight_cron_ping',     $admin, 'handle_cron_ping'        );
		$this->loader->add_action( 'wp_ajax_ratesight_get_rankings',        $admin, 'ajax_get_rankings'        );
		$this->loader->add_action( 'wp_ajax_ratesight_get_site_overview',   $admin, 'ajax_get_site_overview'   );
		// AI insights
		$this->loader->add_action( 'wp_ajax_ratesight_get_insights',        $admin, 'ajax_get_insights'       );
		$this->loader->add_action( 'wp_ajax_ratesight_get_attention_pages', $admin, 'ajax_get_attention_pages' );
		$this->loader->add_action( 'wp_ajax_ratesight_get_recommendations', $admin, 'ajax_get_recommendations' );
		$this->loader->add_action( 'wp_ajax_ratesight_ai_chat',             $admin, 'ajax_ai_chat'            );
		// Ranking / performance
		$this->loader->add_action( 'wp_ajax_ratesight_get_keywords',        $admin, 'ajax_get_keywords'       );
		$this->loader->add_action( 'wp_ajax_ratesight_sitemap_status',      $admin, 'ajax_sitemap_status'      );
		// Setup wizard
		$this->loader->add_action( 'wp_ajax_ratesight_dismiss_wizard',      $admin, 'ajax_dismiss_wizard'     );
		$this->loader->add_action( 'wp_ajax_ratesight_connections_status',  $admin, 'ajax_connections_status' );
		// Schema
		$this->loader->add_action( 'wp_ajax_ratesight_preview_schema',      $admin, 'ajax_preview_schema'     );
		$this->loader->add_action( 'wp_ajax_ratesight_save_schema',         $admin, 'ajax_save_schema'         );
		$this->loader->add_action( 'wp_ajax_ratesight_remove_schema',       $admin, 'ajax_remove_schema'       );
		// IndexNow
		$this->loader->add_action( 'wp_ajax_ratesight_indexnow_status',     $admin, 'ajax_indexnow_status'     );
		$this->loader->add_action( 'wp_ajax_ratesight_clear_indexnow_log',  $admin, 'ajax_clear_indexnow_log'  );
		$this->loader->add_action( 'wp_ajax_ratesight_add_category',        $admin, 'ajax_add_category'        );
		// GBP insights
		$this->loader->add_action( 'wp_ajax_ratesight_get_profile_health',  $admin, 'ajax_get_profile_health' );
		$this->loader->add_action( 'wp_ajax_ratesight_get_reviews',         $admin, 'ajax_get_reviews'        );
		$this->loader->add_action( 'wp_ajax_ratesight_reply_review',        $admin, 'ajax_reply_review'       );
		$this->loader->add_action( 'wp_ajax_ratesight_get_qa',              $admin, 'ajax_get_qa'             );
		$this->loader->add_action( 'wp_ajax_ratesight_answer_question',     $admin, 'ajax_answer_question'    );
		$this->loader->add_action( 'wp_ajax_ratesight_review_velocity',     $admin, 'ajax_review_velocity'    );
		$this->loader->add_action( 'wp_ajax_ratesight_sync_gbp_now',          $admin, 'ajax_sync_gbp_now'          );
		$this->loader->add_action( 'wp_ajax_ratesight_save_bing_key',         $admin, 'ajax_save_bing_key'         );
		$this->loader->add_action( 'wp_ajax_ratesight_load_bing_sites',       $admin, 'ajax_load_bing_sites'       );
		$this->loader->add_action( 'wp_ajax_ratesight_lock_bing_site',        $admin, 'ajax_lock_bing_site'        );
		$this->loader->add_action( 'wp_ajax_ratesight_sync_bing_now',         $admin, 'ajax_sync_bing_now'         );
		$this->loader->add_action( 'wp_ajax_ratesight_link_scan',             $admin, 'ajax_link_scan'             );
		$this->loader->add_action( 'wp_ajax_ratesight_link_check_broken',     $admin, 'ajax_link_check_broken'     );
		$this->loader->add_action( 'wp_ajax_ratesight_link_suggestions',      $admin, 'ajax_link_suggestions'      );
		$this->loader->add_action( 'wp_ajax_ratesight_link_insert',           $admin, 'ajax_link_insert'           );
		$this->loader->add_action( 'wp_ajax_ratesight_link_ignore_broken',    $admin, 'ajax_link_ignore_broken'    );
		$this->loader->add_action( 'wp_ajax_ratesight_link_unignore_broken',  $admin, 'ajax_link_unignore_broken'  );
		$this->loader->add_action( 'wp_ajax_ratesight_link_unlink',           $admin, 'ajax_link_unlink'           );
		$this->loader->add_action( 'wp_ajax_ratesight_link_auto_fix',         $admin, 'ajax_link_auto_fix'         );
		$this->loader->add_action( 'wp_ajax_ratesight_link_replace',          $admin, 'ajax_link_replace'          );
		$this->loader->add_action( 'wp_ajax_ratesight_link_broken_detail',    $admin, 'ajax_link_broken_detail'    );
		$this->loader->add_action( 'wp_ajax_ratesight_link_bulk_check_broken', $admin, 'ajax_link_bulk_check_broken' );
		$this->loader->add_action( 'wp_ajax_ratesight_link_fix_targets',       $admin, 'ajax_link_fix_targets'       );
		$this->loader->add_action( 'wp_ajax_ratesight_link_refresh_suggestions', $admin, 'ajax_link_refresh_suggestions' );
		$this->loader->add_action( 'wp_ajax_ratesight_test_ai_worker',           $admin, 'ajax_test_ai_worker'           );
		$this->loader->add_action( 'wp_ajax_ratesight_regen_webhook_secret',  $admin, 'ajax_regen_webhook_secret'  );
		$this->loader->add_action( 'wp_ajax_ratesight_link_get_manual',       $admin, 'ajax_link_get_manual'       );
		$this->loader->add_action( 'wp_ajax_ratesight_link_remove_manual',    $admin, 'ajax_link_remove_manual'    );
		$this->loader->add_action( 'wp_ajax_ratesight_redirect_update',       $admin, 'ajax_redirect_update'       );
		$this->loader->add_action( 'wp_ajax_ratesight_redirect_delete',       $admin, 'ajax_redirect_delete'       );
		// Organic intelligence
		$this->loader->add_action( 'wp_ajax_ratesight_get_cannibalization',  $admin, 'ajax_get_cannibalization'   );
		$this->loader->add_action( 'wp_ajax_ratesight_get_improvement_queue',$admin, 'ajax_get_improvement_queue' );
		$this->loader->add_action( 'wp_ajax_ratesight_rewrite_meta',        $admin, 'ajax_rewrite_meta'         );
		$this->loader->add_action( 'wp_ajax_ratesight_save_meta',           $admin, 'ajax_save_meta'            );
	}

	private function define_public_hooks() {
		$cpt = new Ratesight_CPT();
		// Priority 1 — must be registered before any wp_insert_post calls,
		// including those from the webhook handler (REST API fires after init).
		$this->loader->add_action( 'init', $cpt, 'register', 1 );

		$public = new Ratesight_Public();
		$this->loader->add_action( 'init',               $public, 'register_shortcodes'           );
		$this->loader->add_action( 'wp',                 $public, 'maybe_suppress_title'           );
		$this->loader->add_action( 'wp',                 $public, 'inject_rs_meta_tags'            );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_widgets',           999 );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_custom_css'             );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'maybe_enqueue_shortcode_styles' );

		// Static method hooks — registered directly since loader requires object instances.
		add_action( 'wp_head',           array( 'Ratesight_Schema',          'inject'          ), 5 );
		add_action( 'template_redirect', array( 'Ratesight_IndexNow',        'maybe_serve_key' ), 1 );
		add_action( 'post_updated',      array( 'Ratesight_Update_Detector', 'on_post_updated' ), 10, 3 );
		// Fallback SEO rendering — only activates when no SEO plugin is detected.
		add_action( 'init', array( 'Ratesight_Public', 'register_fallback_seo_hooks' ), 20 );

		// Render-time "Related services" block — appended after builder content.
		// Priority 20 so it runs after builders/shortcodes have rendered.
		add_filter( 'the_content', array( 'Ratesight_Related_Links', 'render_block' ), 20 );

		// Link Manager — invalidate cache when content changes, reapply manual links after webhook updates.
		add_action( 'post_updated', function( int $post_id, \WP_Post $after ) {
			if ( $after->post_type === 'ratesight_page' && $after->post_status === 'publish' ) {
				Ratesight_Link_Manager::invalidate( $post_id );
			}
		}, 10, 2 );
		add_action( 'ratesight_check_broken_links', array( 'Ratesight_Link_Manager', 'cron_check_broken' ) );

		// Remove link cache row when an RS page is trashed or permanently deleted.
		add_action( 'trashed_post',       array( 'Ratesight_Link_Manager', 'on_post_removed' ) );
		add_action( 'before_delete_post', array( 'Ratesight_Link_Manager', 'on_post_removed' ) );

		// 301 redirect deleted RS page URLs — priority 1 fires before any template.
		add_action( 'template_redirect',  array( 'Ratesight_Link_Manager', 'handle_redirects' ), 1 );

		// Remove the redirect when a page is restored from trash.
		add_action( 'untrashed_post', function( int $post_id ): void {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== 'ratesight_page' ) return;
			$url  = get_permalink( $post_id );
			if ( ! $url ) return;
			$path = trim( wp_make_link_relative( $url ), '/' );
			Ratesight_Link_Manager::delete_redirect( $path );
		} );
		add_action( 'ratesight_daily_digest',       array( 'Ratesight_Notifier',     'send_digest'       ) );

		// Bulk operations.
		$bulk = new Ratesight_Bulk_Operations();
		$bulk->register_hooks();
	}

	private function define_webhook_hooks() {
		$webhook = new Ratesight_Webhook_Handler();
		$this->loader->add_action( 'rest_api_init', $webhook, 'register_route' );

		// NOTE: the rest_pre_dispatch (repair_body_encoding) and rest_allowed_cors_
		// headers filters were removed — they ran on every REST request before the
		// handler and are the only request-path hooks the long-working May build did
		// not have. They were diagnostic/encoding additions, not features. Removing
		// them restores May's REST request handling while keeping every endpoint.

		// Related-services internal links — REST endpoints (static handlers).
		add_action( 'rest_api_init', array( 'Ratesight_Related_Links', 'register_routes' ) );
	}

	private function define_cron_hooks() {
		add_action( 'ratesight_prune_logs',           array( 'Ratesight_Logger',            'prune_logs'       ) );
		add_action( 'ratesight_sync_gsc',             array( 'Ratesight_GSC_Client',         'sync_performance'                ) );
		add_action( 'ratesight_sync_gsc',             array( 'Ratesight_Recovery_Log',        'remeasure'                       ) );
		add_action( 'ratesight_redirect_health',      array( 'Ratesight_Redirect_Health',     'run'                             ) );

		// Runtime 404 smart-router — fires after explicit redirect handler (priority 1).
		add_action( 'template_redirect',              array( 'Ratesight_Runtime_404_Router',  'maybe_route'                     ), 5 );
		// Invalidate post index when content changes.
		add_action( 'transition_post_status',         array( 'Ratesight_Runtime_404_Router',  'invalidate_index'                ), 10 );
		add_action( 'delete_post',                    array( 'Ratesight_Runtime_404_Router',  'invalidate_index'                ), 10 );
		add_action( 'ratesight_sync_gbp_performance', array( 'Ratesight_GBP_Insights_Client', 'sync_performance' ) );
		add_action( 'ratesight_sync_bing',            array( 'Ratesight_Bing_Client',         'sync_performance' ) );
		add_action( 'ratesight_process_bulk_queue',   array( new Ratesight_Bulk_Operations(), 'process_queue'   ) );

		add_action( 'ratesight_deferred_publish', function( int $post_id, int $log_id, string $image_url, string $image_name, string $post_title, string $request_status = '' ) {
			( new Ratesight_Publisher() )->run( $post_id, $log_id, $image_url, $image_name, $post_title, $request_status );
		}, 10, 6 );

		// Retry stuck pending posts — runs hourly, catches any deferred_publish
		// cron events that never fired (common when WP-Cron loopback fails).
		add_action( 'ratesight_retry_pending', array( $this, 'retry_pending_posts' ) );

		add_action( 'plugins_loaded', array( 'Ratesight_Activator', 'maybe_upgrade' ), 5 );
	}

	public function run() {
		$this->loader->run();
	}

	/**
	 * Retry posts stuck in pending state.
	 *
	 * Finds log rows that are still 'pending' with a post_id and a post that
	 * is still a draft after 30+ minutes. Simply flips them to published.
	 * This catches cases where the ratesight_deferred_publish WP-Cron event
	 * never fired due to loopback failures or low site traffic.
	 */
	public function retry_pending_posts(): void {
		global $wpdb;
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stuck = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT id, post_id FROM `{$log_table}`
			 WHERE status    = 'pending'
			 AND post_id  IS NOT NULL
			 AND post_id  > 0
			 AND received_at < DATE_SUB( NOW(), INTERVAL 30 MINUTE )
			 ORDER BY received_at DESC
			 LIMIT 50",
			ARRAY_A
		);

		if ( empty( $stuck ) ) return;

		foreach ( $stuck as $row ) {
			$post_id = (int) $row['post_id'];
			$log_id  = (int) $row['id'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				// Post was deleted — mark as failed.
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_FAILED, 'Post not found during retry.' );
				continue;
			}

			// Already published somehow — just mark success.
			if ( ! in_array( $post->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_SUCCESS, 'Resolved by retry check — post was already live.' );
				continue;
			}

			// Determine intended status from plugin settings.
			$post_type    = get_post_type( $post_id );
			$status_key   = ( $post_type === 'ratesight_page' ) ? 'page_status' : 'post_status';
			$final_status = Ratesight_Options::get( $status_key );
			if ( ! in_array( $final_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
				$final_status = 'publish';
			}

			$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $final_status ), true );

			if ( is_wp_error( $updated ) ) {
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_FAILED, 'Retry failed: ' . $updated->get_error_message() );
			} else {
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_SUCCESS, 'Published by retry cron — original deferred_publish event did not fire.' );
			}
		}
	}
}
