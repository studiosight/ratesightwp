<?php
/**
 * Admin-specific functionality.
 *
 * Single menu item "Ratesight" with four tabs:
 *   Widgets | AI SEO Pages | Activity Log | Payload Reference
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Admin {

	// -------------------------------------------------------------------------
	// Settings API
	// -------------------------------------------------------------------------

	public function register_settings() {
		foreach ( Ratesight_Options::schema() as $key => $def ) {
			register_setting(
				'ratesight_options_' . $def['group'],
				$def['name'],
				array(
					'sanitize_callback' => static function ( $value ) use ( $def, $key ) {
						$sanitized = Ratesight_Options::sanitise( $value, $def['type'] );
						// Bust the license cache whenever the Ratesight ID is saved.
						if ( $key === 'code_id' ) {
							Ratesight_License::clear_cache();
						}
						// Flush rewrite rules when the RS page base slug changes.
						if ( $key === 'rs_page_base' ) {
							flush_rewrite_rules( false );
						}
						return $sanitized;
					},
				)
			);
		}

		// Notification options — stored directly, not in the schema.
		register_setting( 'ratesight_options_seo_pages', 'ratesight_notify_enabled', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'ratesight_options_seo_pages', 'ratesight_notify_email',   array( 'sanitize_callback' => 'sanitize_email' ) );
	}

	/**
	 * Show a persistent admin notice when the license is inactive.
	 * Only shown on Ratesight pages to avoid polluting other screens.
	 */
	public function license_notice() {
		if ( empty( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'ratesight' ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( Ratesight_License::is_valid() ) {
			return;
		}

		$code_id     = Ratesight_Options::get( 'code_id' );
		$widgets_url = admin_url( 'admin.php?page=ratesight&tab=widgets' );

		if ( $code_id === '' ) {
			$msg = sprintf(
				'<strong>Ratesight:</strong> No Ratesight ID set — RS Pages are hidden and the webhook is disabled. <a href="%s">Enter your ID on the Widgets tab.</a>',
				esc_url( $widgets_url )
			);
		} else {
			$msg = sprintf(
				'<strong>Ratesight:</strong> License inactive — RS Pages are hidden and the webhook is disabled. Check your Ratesight ID on the <a href="%s">Widgets tab</a> or contact <a href="mailto:support@ratesight.com">support@ratesight.com</a>.',
				esc_url( $widgets_url )
			);
		}

		echo '<div class="notice notice-error"><p>' . wp_kses( $msg, array( 'strong' => array(), 'a' => array( 'href' => array() ), 'em' => array() ) ) . '</p></div>';
	}

	/**
	 * Show a sitewide admin notice when Google silently revokes a token.
	 * Clears itself once the user visits the Connections tab to reconnect.
	 */
	public function revocation_notice() {
		$connections_url = admin_url( 'admin.php?page=ratesight&tab=connections' );
		$notices         = array();

		foreach ( array( 'gsc' => 'Search Console', 'gbp' => 'Business Profile' ) as $service => $label ) {
			if ( get_option( 'ratesight_' . $service . '_revoked' ) ) {
				$notices[] = sprintf(
					'<strong>Ratesight — %s disconnected:</strong> Google revoked the connection (password change, access removed, or token expired). <a href="%s">Reconnect on the Connections tab →</a>',
					esc_html( $label ),
					esc_url( $connections_url )
				);
			}
		}

		// Scope error — token exists but was granted without the required permission.
		if ( get_option( 'ratesight_gsc_scope_error' ) ) {
			$notices[] = sprintf(
				'<strong>Ratesight — Search Console needs reauthorization:</strong> The connection is missing the Search Console permission. Please <a href="%s">disconnect and reconnect GSC</a> — when Google asks for permissions, make sure to click <strong>Allow</strong> on the Search Console access screen.',
				esc_url( $connections_url )
			);
		}

		foreach ( $notices as $msg ) {
			echo '<div class="notice notice-error"><p>' . wp_kses( $msg, array( 'strong' => array(), 'a' => array( 'href' => array() ) ) ) . '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function add_menu_page() {
		add_menu_page(
			__( 'Ratesight', 'ratesight' ),
			__( 'Ratesight', 'ratesight' ),
			'manage_options',
			'ratesight',
			array( $this, 'render_page' ),
			plugin_dir_url( __FILE__ ) . 'images/rs-icon.png'
		);

		// Add Pages list as a submenu — uses the CPT's native list table.
		add_submenu_page(
			'ratesight',
			__( 'Pages', 'ratesight' ),
			__( 'Pages', 'ratesight' ),
			'manage_options',
			'edit.php?post_type=ratesight_page'
		);
	}

	/**
	 * Runs at admin_menu priority 11 — after priority 10 has populated $submenu.
	 * Renames the first item to "Dashboard" and injects tab links.
	 */
	public function configure_submenu() {
		global $submenu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		if ( empty( $submenu['ratesight'] ) ) {
			return;
		}

		$base = 'admin.php?page=ratesight&tab=';
		$tabs = array(
			'connections' => 'Connections',
			'seo-pages'   => 'Settings',
			'performance' => 'Performance',
			'links'       => 'Links',
			'logs'        => 'Activity Log',
			'help'        => 'Reference',
		);

		// Build a fresh ordered list: Dashboard, then tabs, then everything else.
		$rebuilt = array();
		$rest    = array();

		foreach ( $submenu['ratesight'] as $entry ) {
			if ( isset( $entry[2] ) && $entry[2] === 'ratesight' ) {
				$entry[0] = 'Dashboard';
				array_unshift( $rebuilt, $entry );
			} else {
				$rest[] = $entry;
			}
		}

		foreach ( $tabs as $slug => $label ) {
			$rebuilt[] = array( $label, 'manage_options', $base . $slug, $label );
		}

		foreach ( $rest as $entry ) {
			$rebuilt[] = $entry;
		}

		$submenu['ratesight'] = $rebuilt;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'ratesight' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'widgets'; // phpcs:ignore
		require_once __DIR__ . '/partials/page-wrapper.php';
	}

	/**
	 * Highlights the correct submenu item when on a tab page.
	 */
	public function fix_submenu_highlight( $submenu_file ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$tab  = isset( $_GET['tab'] )  ? sanitize_key( $_GET['tab'] )  : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $page !== 'ratesight' || $tab === '' || $tab === 'widgets' ) {
			return $submenu_file;
		}

		return 'admin.php?page=ratesight&tab=' . $tab;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $screen_id ) {
		// $_GET['page'] is more reliable than screen ID which can vary by WP version.
		if ( empty( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'ratesight' ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_style(
			'ratesight-admin',
			RATESIGHT_PLUGIN_URL . 'admin/css/ratesight-admin.css',
			array(), RATESIGHT_VERSION
		);

		wp_enqueue_script(
			'ratesight-admin',
			RATESIGHT_PLUGIN_URL . 'admin/js/ratesight-admin.js',
			array( 'jquery' ), RATESIGHT_VERSION, true
		);

		$active_days_raw = isset( $_GET['gsc_days'] ) ? (int) $_GET['gsc_days'] : 30;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_days     = in_array( $active_days_raw, array( 7, 30, 90 ), true ) ? $active_days_raw : 30;

		wp_localize_script( 'ratesight-admin', 'RatesightAdmin', array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'ratesight_admin' ),
			'webhook_base' => rest_url( 'ratesight/v1/create-page' ),
			'last_sync'    => get_option( 'ratesight_gsc_last_sync', '' ),
			'active_days'  => $active_days,
		) );
	}

	/**
	 * Pre-fill the post title when creating a new ratesight_page via recommendation.
	 * Triggered by ?post_type=ratesight_page&rs_prefill_title=... on post-new.php.
	 */
	public function prefill_recommended_title() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			! is_admin()
			|| empty( $_GET['rs_prefill_title'] )
			|| empty( $_GET['post_type'] )
			|| sanitize_key( $_GET['post_type'] ) !== 'ratesight_page'
		) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$title = sanitize_text_field( wp_unslash( $_GET['rs_prefill_title'] ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $title ) return;
		?>
		<script>
		window.addEventListener( 'DOMContentLoaded', function () {
			var titleInput = document.getElementById( 'title' ) || document.querySelector( '[name="post_title"]' );
			if ( titleInput ) {
				titleInput.value = <?php echo wp_json_encode( $title ); ?>;
				titleInput.dispatchEvent( new Event( 'input' ) );
			}
		} );
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// OAuth callback — fires on admin_init at priority 5
	// -------------------------------------------------------------------------

	/**
	 * Handle the OAuth redirect from Google.
	 * Google sends the user back to wp-admin/admin.php?page=ratesight&tab=connections&code=XXX&state=YYY
	 */
	public function handle_oauth_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'ratesight' ) {
			return;
		}
		if ( empty( $_GET['tab'] ) || sanitize_key( $_GET['tab'] ) !== 'connections' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Clear cached client IDs on demand.
		if ( ! empty( $_GET['rs_clear_cache'] ) ) {
			delete_transient( 'ratesight_worker_client_id_gbp' );
			delete_transient( 'ratesight_worker_client_id_gsc' );
			wp_safe_redirect( admin_url( 'admin.php?page=ratesight&tab=connections' ) );
			exit;
		}

		// ── Worker returned an error ──────────────────────────────────────────
		if ( ! empty( $_GET['rs_oauth_error'] ) ) {
			// Already in the URL — the tab partial will display it.
			return;
		}

		// ── Worker returned a signed token payload ────────────────────────────
		if ( empty( $_GET['rs_oauth'] ) ) {
			return;
		}

		$raw_payload = sanitize_text_field( wp_unslash( $_GET['rs_oauth'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = Ratesight_OAuth_Client::handle_token_return( $raw_payload );

		// Redirect to clean URL so the payload doesn't stay in browser history.
		$redirect = admin_url( 'admin.php?page=ratesight&tab=connections' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'rs_oauth_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'rs_oauth_success', '1', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// GBP AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_list_gbp() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$locations = Ratesight_GBP_Client::list_all_locations();
		if ( is_wp_error( $locations ) ) {
			wp_send_json_error( array( 'message' => $locations->get_error_message() ) );
		}

		wp_send_json_success( array( 'locations' => $locations ) );
	}

	public function ajax_lock_gbp() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$location_id = sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );

		if ( empty( $location_id ) ) {
			wp_send_json_error( array( 'message' => 'No location selected.' ) );
		}

		Ratesight_GBP_Client::lock_selection( $location_id, $label );

		// Auto-trigger GBP performance sync — only if not already synced this week.
		$last_gbp_sync = get_option( 'ratesight_gbp_performance_last_sync', '' );
		$days_since    = $last_gbp_sync ? floor( ( time() - strtotime( $last_gbp_sync ) ) / DAY_IN_SECONDS ) : 99;
		if ( $days_since >= 7 ) {
			wp_schedule_single_event( time() + 10, 'ratesight_sync_gbp_performance' );
		}

		wp_send_json_success( array( 'message' => 'GBP location locked. Syncing performance data in the background.' ) );
	}

	public function ajax_disconnect_gbp() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$confirm = sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) );
		if ( $confirm !== 'DISCONNECT' ) {
			wp_send_json_error( array( 'message' => 'Type DISCONNECT to confirm.' ) );
		}

		Ratesight_OAuth_Client::disconnect( 'gbp' );
		wp_send_json_success( array( 'message' => 'GBP account disconnected.' ) );
	}

	// -------------------------------------------------------------------------
	// GSC AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_list_gsc() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$properties = Ratesight_GSC_Client::list_properties();
		if ( is_wp_error( $properties ) ) {
			wp_send_json_error( array( 'message' => $properties->get_error_message() ) );
		}

		wp_send_json_success( array( 'properties' => $properties ) );
	}

	public function ajax_lock_gsc() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$property_url = sanitize_text_field( wp_unslash( $_POST['property_url'] ?? '' ) );
		if ( empty( $property_url ) ) {
			wp_send_json_error( array( 'message' => 'No property selected.' ) );
		}

		Ratesight_GSC_Client::lock_selection( $property_url );

		$notes = array();

		// Auto-submit sitemap — but only if search engines are allowed.
		if ( ! get_option( 'blog_public' ) ) {
			$notes[] = 'Sitemap not submitted — this site is set to discourage search engines. Go to Settings → Reading and uncheck "Discourage search engines" to enable indexing.';
		} else {
			$sitemap_url = trailingslashit( home_url() ) . 'sitemap.xml';
			$check       = wp_remote_head( $sitemap_url, array( 'timeout' => 8 ) );

			if ( ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) === 200 ) {
				$token    = Ratesight_OAuth_Client::get_access_token( 'gsc' );
				$property = rawurlencode( $property_url );
				$feed     = rawurlencode( $sitemap_url );

				if ( ! is_wp_error( $token ) ) {
					$submit = wp_remote_request(
						"https://www.googleapis.com/webmasters/v3/sites/{$property}/sitemaps/{$feed}",
						array(
							'method'  => 'PUT',
							'timeout' => 10,
							'headers' => array(
								'Authorization'  => 'Bearer ' . $token,
								'Content-Length' => '0',
							),
							'body'    => '',
						)
					);

					if ( ! is_wp_error( $submit ) && wp_remote_retrieve_response_code( $submit ) < 300 ) {
						$notes[] = 'Sitemap automatically submitted to Search Console.';
					} else {
						$err_body = ! is_wp_error( $submit )
							? ( json_decode( wp_remote_retrieve_body( $submit ), true )['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $submit ) ) )
							: $submit->get_error_message();
						$notes[] = 'Property locked. Sitemap submit failed (' . $err_body . ') — submit manually in GSC.';
					}
				}
			} else {
				$notes[] = 'Property locked. No sitemap.xml found at ' . $sitemap_url . ' — submit manually once your sitemap is ready.';
			}
		}

		// Auto-trigger GSC sync in background — only if not already synced today.
		$last_sync = get_option( 'ratesight_gsc_last_sync', '' );
		if ( ! $last_sync || gmdate( 'Y-m-d', strtotime( $last_sync ) ) !== current_time( 'Y-m-d' ) ) {
			wp_schedule_single_event( time() + 10, 'ratesight_sync_gsc' );
		}

		wp_send_json_success( array(
			'message' => 'GSC property locked. Syncing performance data in the background.' . ( $notes ? ' ' . implode( ' ', $notes ) : '' ),
			'notes'   => $notes,
		) );
	}

	public function ajax_disconnect_gsc() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$confirm = sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) );
		if ( $confirm !== 'DISCONNECT' ) {
			wp_send_json_error( array( 'message' => 'Type DISCONNECT to confirm.' ) );
		}

		Ratesight_OAuth_Client::disconnect( 'gsc' );
		wp_send_json_success( array( 'message' => 'GSC account disconnected.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual GSC sync
	// -------------------------------------------------------------------------

	public function ajax_sync_gsc_now() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		if ( ! Ratesight_OAuth_Client::is_connected( 'gsc' ) ) {
			wp_send_json_error( array( 'message' => 'GSC not connected — go to Connections tab and reconnect.' ) );
		}

		if ( ! Ratesight_GSC_Client::is_locked() ) {
			wp_send_json_error( array( 'message' => 'No GSC property locked — go to Connections tab and lock a property.' ) );
		}

		// Step 1: fetch page-level data and return matched posts to the browser.
		// The JS will then call ratesight_sync_gsc_keywords for each post, then
		// ratesight_sync_gsc_finalise — no external Worker round-trip required.
		$matched = Ratesight_GSC_Client::sync_step_pages();

		if ( is_wp_error( $matched ) ) {
			wp_send_json_error( array( 'message' => $matched->get_error_message() ) );
		}

		wp_send_json_success( array(
			'posts'   => $matched,
			'message' => 'Fetched page data. Loading keywords…',
		) );
	}

	public function ajax_get_site_overview() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$data = Ratesight_GSC_Client::get_site_overview();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		wp_send_json_success( $data );
	}

	public function ajax_get_rankings() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$days = in_array( absint( wp_unslash( $_POST['days'] ?? 30 ) ), array( 7, 30, 90 ), true )
			? absint( wp_unslash( $_POST['days'] ) )
			: 30;

		$data = Ratesight_GSC_Client::get_performance_data( $days );

		// Add edit_url for each row so the JS can link the post title to the editor.
		foreach ( $data as &$row ) {
			$row['edit_url'] = ! empty( $row['post_id'] )
				? get_edit_post_link( (int) $row['post_id'], 'raw' )
				: '';
		}
		unset( $row );

		wp_send_json_success( array( 'rows' => $data, 'days' => $days ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: AI insights
	// -------------------------------------------------------------------------

	public function ajax_get_insights() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$force = ! empty( $_POST['force'] );

		// Always delete any stale transient before hitting the Worker.
		delete_transient( 'ratesight_ai_insights' );

		// Return cached insights unless force-refresh requested.
		if ( ! $force ) {
			$cached = get_transient( 'ratesight_ai_insights' );
			if ( $cached ) {
				wp_send_json_success( $cached );
			}
		}

		$data = Ratesight_GSC_Client::get_performance_data();
		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => 'No performance data yet — run a GSC sync first.' ) );
		}

		// Worker reads body.posts directly and verifies:
		//   rsHmac( JSON.stringify(body.posts), TOKEN_SECRET )
		// PHP must produce the same bytes as JS JSON.stringify — use
		// JSON_UNESCAPED_SLASHES (PHP escapes / as \/ by default; JS does not)
		// and JSON_UNESCAPED_UNICODE to match JS exactly.
		$posts_json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$auth       = Ratesight_OAuth_Client::sign_request( $posts_json );

		$request_args = array(
			'timeout' => 120,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'posts' => $data,
			) + $auth ),
		);

		// Use DeepSeek AI client directly.
		$body     = null;
		$last_err = 'AI request failed.';
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$result = Ratesight_AI_Client::get_insights( $data );
			if ( ! empty( $result['ok'] ) ) {
				$body = $result;
				break;
			}
			$last_err = $result['error'] ?? 'AI request failed.';
			if ( strpos( $last_err, 'parse' ) === false ) break;
		}

		if ( empty( $body['ok'] ) ) {
			wp_send_json_error( array( 'message' => $last_err ) );
		}

		// Normalise each item to a plain string — the AI occasionally returns
		// nested arrays or objects instead of flat strings.
		$normalize = static function ( array $items ) {
			$out = array();
			foreach ( $items as $item ) {
				if ( is_string( $item ) ) {
					$out[] = $item;
				} elseif ( is_array( $item ) ) {
					// Could be ["text"] or {"title":"...","metric":"..."} etc.
					$text = implode( ' — ', array_filter( array_values( $item ), 'is_string' ) );
					if ( $text ) $out[] = $text;
				}
			}
			return $out;
		};

		// Build a lightweight title => post_id map for the JS to render edit-link badges.
		$post_map = array();
		foreach ( $data as $row ) {
			if ( ! empty( $row['post_title'] ) && ! empty( $row['post_id'] ) ) {
				$post_map[] = array(
					'post_id'    => (int) $row['post_id'],
					'post_title' => (string) $row['post_title'],
				);
			}
		}

		$insights = array(
			'wins'          => $normalize( $body['wins']          ?? array() ),
			'opportunities' => $normalize( $body['opportunities'] ?? array() ),
			'generated_at'  => $body['generated_at']              ?? current_time( 'mysql' ),
			'posts'         => $post_map,
		);

		// Cache for 24 hours.
		set_transient( 'ratesight_ai_insights', $insights, DAY_IN_SECONDS );

		wp_send_json_success( $insights );
	}

	public function ajax_sync_gsc_keywords() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = sanitize_url( wp_unslash( $_POST['url'] ?? '' ) );

		if ( ! $post_id || ! $url ) {
			wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );
		}

		Ratesight_GSC_Client::sync_step_keywords( $post_id, $url );
		wp_send_json_success( array( 'done' => false ) );
	}

	public function ajax_sync_gsc_finalise() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		Ratesight_GSC_Client::sync_step_finalise();
		$last_sync = get_option( 'ratesight_gsc_last_sync', '' );
		wp_send_json_success( array(
			'done'      => true,
			'last_sync' => $last_sync,
			'message'   => 'Sync complete.',
		) );
	}

	public function ajax_do_sync() {
		// Called by the Cloudflare Worker in the background — no user nonce.
		// Security is via HMAC signature; only our Worker can trigger this.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$site_url = sanitize_url( wp_unslash( $_POST['site_url'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$hmac     = sanitize_text_field( wp_unslash( $_POST['hmac'] ?? '' ) );

		if ( ! $site_url || ! $hmac ) {
			wp_send_json_error( array( 'message' => 'Missing parameters.' ), 400 );
		}

		$expected = hash_hmac( 'sha256', $site_url . '|sync', Ratesight_OAuth_Client::active_secret() );
		if ( ! hash_equals( $expected, $hmac ) ) {
			wp_send_json_error( array( 'message' => 'Invalid HMAC.' ), 403 );
		}

		@set_time_limit( 0 ); // phpcs:ignore
		ignore_user_abort( true );

		Ratesight_GSC_Client::sync_performance();
		delete_transient( 'ratesight_gsc_site_overview' );
		delete_transient( 'ratesight_ai_insights' );

		wp_send_json_success( array( 'synced' => true ) );
	}

	public function handle_cron_ping() {
		// Fire any pending WP-Cron events immediately. No auth required — this
		// is called by a non-blocking loopback and must be accessible without login.
		spawn_cron( time() - 1 );
		wp_die();
	}

	// -------------------------------------------------------------------------
	// AJAX: attention pages (zero impressions after 30+ days)
	// -------------------------------------------------------------------------

	public function ajax_get_attention_pages() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';

		// Ratesight pages published > 30 days ago with zero total impressions ever.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$zero_impression_ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 WHERE p.post_type   = 'ratesight_page'
			   AND p.post_status = 'publish'
			   AND p.post_date   < DATE_SUB( NOW(), INTERVAL 30 DAY )
			   AND NOT EXISTS (
			       SELECT 1 FROM `{$perf_table}` perf
			       WHERE perf.post_id    = p.ID
			         AND perf.impressions > 0
			   )"
		);

		// Also: pages that had impressions in 31–60d window but zero in last 30d (dropped off).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dropped_ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN `{$perf_table}` prev
			     ON prev.post_id = p.ID
			     AND prev.impressions > 0
			     AND prev.date BETWEEN DATE_SUB( CURDATE(), INTERVAL 60 DAY ) AND DATE_SUB( CURDATE(), INTERVAL 31 DAY )
			 WHERE p.post_type   = 'ratesight_page'
			   AND p.post_status = 'publish'
			   AND NOT EXISTS (
			       SELECT 1 FROM `{$perf_table}` cur
			       WHERE cur.post_id    = p.ID
			         AND cur.impressions > 0
			         AND cur.date >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )
			   )"
		);

		$all_ids = array_unique( array_merge(
			array_map( 'intval', $zero_impression_ids ),
			array_map( 'intval', $dropped_ids )
		) );

		$selection = Ratesight_GSC_Client::get_selection();
		$property  = $selection['url'] ?? '';
		// Build GSC inspect URL base.
		$gsc_base  = 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&id=';

		$pages = array();
		foreach ( $all_ids as $post_id ) {
			$url   = get_permalink( $post_id );
			$title = get_the_title( $post_id );
			if ( ! $url ) continue;

			// Was it ever indexed (had impressions) or never?
			$type = in_array( $post_id, array_map( 'intval', $zero_impression_ids ), true )
				? 'never'
				: 'dropped';

			$pages[] = array(
				'post_id'   => $post_id,
				'title'     => $title,
				'url'       => $url,
				'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
				'gsc_url'   => $gsc_base . rawurlencode( $url ),
				'type'      => $type,
			);
		}

		wp_send_json_success( array( 'pages' => $pages ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: AI page recommendations
	// -------------------------------------------------------------------------

	public function ajax_get_recommendations() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		// Check transient first.
		$cached = get_transient( 'ratesight_recommendations' );
		if ( $cached && empty( $_POST['force'] ) ) {
			wp_send_json_success( $cached );
		}

		// Get existing Ratesight page titles to avoid duplicates.
		$existing = get_posts( array(
			'post_type'      => 'ratesight_page',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$existing_titles = array_map( 'get_the_title', $existing );

		// Top keywords from the last 30 days across all pages.
		global $wpdb;
		$kw_table = $wpdb->prefix . 'ratesight_keywords';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$keywords = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT query, SUM(impressions) AS impressions, AVG(position) AS position
			 FROM `{$kw_table}`
			 WHERE date >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )
			 GROUP BY query
			 ORDER BY impressions DESC
			 LIMIT 50",
			ARRAY_A
		);

		if ( empty( $keywords ) ) {
			wp_send_json_error( array( 'message' => 'Not enough keyword data yet — sync a few times first.' ) );
		}

		$hmac = hash_hmac( 'sha256', wp_json_encode( $keywords ) . '|recommend', Ratesight_OAuth_Client::active_secret() );

		$result = Ratesight_AI_Client::get_recommendations( $keywords, $existing_titles );

		if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ?? 'Recommendation request failed.' ) );
		}

		$raw_recos = $result['recommendations'] ?? array();

		// Filter out any AI suggestions that match existing page titles.
		// Normalise to lowercase + strip punctuation for fuzzy matching.
		$normalise = static function ( string $s ): string {
			return strtolower( preg_replace( '/[^a-z0-9 ]/i', '', $s ) );
		};
		$existing_normalised = array_map( $normalise, $existing_titles );

		$filtered = array();
		foreach ( $raw_recos as $rec ) {
			$title = is_array( $rec ) ? ( $rec['title'] ?? '' ) : (string) $rec;
			if ( ! $title ) continue;
			// Skip if this title normalises to something already in the existing list.
			if ( in_array( $normalise( $title ), $existing_normalised, true ) ) continue;
			// Also skip partial matches — if existing title is a substring or vice versa.
			$norm_new = $normalise( $title );
			$skip = false;
			foreach ( $existing_normalised as $en ) {
				if ( $en && ( str_contains( $norm_new, $en ) || str_contains( $en, $norm_new ) ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$filtered[] = array( 'title' => $title );
			}
		}

		$result = array(
			'recommendations' => $filtered,
			'generated_at'    => current_time( 'mysql' ),
		);

		set_transient( 'ratesight_recommendations', $result, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( $result );
	}

	public function ajax_get_last_sync() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		$last = get_option( 'ratesight_gsc_last_sync', '' );
		wp_send_json_success( array( 'last_sync' => $last ) );
	}

	public function ajax_generate_secret() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		wp_send_json_success( array( 'secret' => wp_generate_password( 40, false, false ) ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: clear all log entries
	// -------------------------------------------------------------------------

	public function ajax_clear_logs() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$table   = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$deleted = $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore

		if ( false === $deleted ) {
			wp_send_json_error( array( 'message' => 'Failed to clear logs.' ) );
		}

		wp_send_json_success( array( 'message' => 'Activity log cleared.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: return fresh activity log rows so the page doesn't need a full reload
	// -------------------------------------------------------------------------

	public function ajax_get_logs(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$logs = Ratesight_Logger::get_recent_logs( 100 );

		$pills = array(
			Ratesight_Logger::STATUS_PENDING          => array( 'Pending',  'pending'  ),
			Ratesight_Logger::STATUS_SUCCESS          => array( 'Success',  'success'  ),
			Ratesight_Logger::STATUS_SUCCESS_WARNINGS => array( 'Warnings', 'warnings' ),
			Ratesight_Logger::STATUS_FAILED           => array( 'Failed',   'failed'   ),
			Ratesight_Logger::STATUS_MODIFIED         => array( 'Modified', 'modified' ),
		);

		ob_start();
		foreach ( $logs as $log ) :
			[ $label, $cls ] = $pills[ $log['status'] ] ?? array( $log['status'], '' );
			$msg        = $log['notes'] ?: ( $log['error_message'] ?? '' );
			$is_err     = $log['status'] === Ratesight_Logger::STATUS_FAILED;
			$is_warning = $log['status'] === Ratesight_Logger::STATUS_SUCCESS_WARNINGS;
			$is_pending = $log['status'] === Ratesight_Logger::STATUS_PENDING;
			$gbp_failed = $is_warning && str_contains( (string) $msg, 'GBP post failed' );
			?>
			<tr>
				<td style="font-size:12px;color:#646970;"><?php echo esc_html( $log['received_at'] ); ?></td>
				<td><span class="rs-pill <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $label ); ?></span></td>
				<td><?php echo esc_html( $log['title'] ?: '—' ); ?></td>
				<td><?php echo esc_html( $log['child_category'] ?: '—' ); ?></td>
				<td>
					<?php if ( ! empty( $log['post_id'] ) ) :
						$edit       = get_edit_post_link( $log['post_id'] );
						$view       = get_permalink( $log['post_id'] );
						$is_rs_page = get_post_type( $log['post_id'] ) === 'ratesight_page';
					?>
						<?php if ( $is_rs_page ) : ?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="13" viewBox="0 0 24 30" style="vertical-align:middle;margin-right:3px;position:relative;top:-1px;" aria-label="Ratesight Page"><path d="M12 0C7.6 0 4 3.6 4 8c0 6 8 16 8 16s8-10 8-16c0-4.4-3.6-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="#1877F2"/></svg><?php endif; ?>
						<a class="rs-post-link" href="<?php echo esc_url( $edit ); ?>" target="_blank">#<?php echo esc_html( $log['post_id'] ); ?></a>
						<?php if ( $view ) : ?><a style="color:#646970;margin-left:2px;" href="<?php echo esc_url( $view ); ?>" target="_blank">↗</a><?php endif; ?>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td>
					<?php if ( $msg ) : ?>
						<span class="<?php echo $is_err ? 'rs-err' : 'rs-note'; ?>" title="<?php echo esc_attr( $msg ); ?>"><?php echo esc_html( $msg ); ?></span>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td>
					<?php if ( $is_err && ! empty( $log['raw_payload'] ) ) : ?>
						<button type="button" class="button button-small rs-retry-log" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Retry</button>
					<?php elseif ( $is_err ) : ?>
						<span style="color:#999;font-size:11px;" title="Enable Store Raw Payload to allow retries">No payload</span>
					<?php elseif ( $gbp_failed ) : ?>
						<button type="button" class="button button-small rs-retry-gbp" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Retry GBP</button>
					<?php elseif ( $is_pending ) : ?>
						<button type="button" class="button button-small rs-recheck-pending" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Recheck</button>
					<?php else : ?>—<?php endif; ?>
				</td>
			</tr>
		<?php endforeach;
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html, 'count' => count( $logs ) ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: debug — inspect raw pending rows to understand why fix isnt working
	// -------------------------------------------------------------------------

	public function ajax_debug_pending_logs(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$table     = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$posts_tbl = $wpdb->posts;

		// Sample 5 pending rows — show raw field values.
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, post_id, status, title, notes FROM `{$table}` WHERE status = %s LIMIT 5",
				Ratesight_Logger::STATUS_PENDING
			),
			ARRAY_A
		);

		// Count by post_id state.
		$null_post_id  = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND post_id IS NULL",
			Ratesight_Logger::STATUS_PENDING
		) );
		$zero_post_id  = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND post_id = 0",
			Ratesight_Logger::STATUS_PENDING
		) );
		$has_post_id   = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND post_id > 0",
			Ratesight_Logger::STATUS_PENDING
		) );

		// For rows that do have a post_id, what post_status are those posts in?
		$post_statuses = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.post_status, COUNT(*) as cnt
				 FROM `{$table}` l
				 INNER JOIN `{$posts_tbl}` p ON p.ID = l.post_id
				 WHERE l.status = %s
				 GROUP BY p.post_status",
				Ratesight_Logger::STATUS_PENDING
			),
			ARRAY_A
		);

		// Title match check — how many null post_id rows have a title matching a published post?
		$title_matches = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$table}` l
			 INNER JOIN `{$posts_tbl}` p
			   ON p.post_title = l.title
			   AND p.post_type = 'ratesight_page'
			   AND p.post_status = 'publish'
			 WHERE l.status = %s AND l.post_id IS NULL",
			Ratesight_Logger::STATUS_PENDING
		) );

		wp_send_json_success( array(
			'sample_rows'    => $rows,
			'null_post_id'   => $null_post_id,
			'zero_post_id'   => $zero_post_id,
			'has_post_id'    => $has_post_id,
			'post_statuses'  => $post_statuses,
			'title_matches'  => $title_matches,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: fix log status — resolve pending entries against actual post status
	// -------------------------------------------------------------------------

	public function ajax_fix_log_status(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$table     = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		$posts_tbl = $wpdb->posts;

		// Pass 1: pending entries WITH post_id — resolve against actual post status.
		$resolved = (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE `{$table}` l
			 INNER JOIN `{$posts_tbl}` p ON p.ID = l.post_id
			 SET l.status = %s, l.notes = %s
			 WHERE l.status = %s
			 AND p.post_status NOT IN ('draft','auto-draft','pending','trash')",
			Ratesight_Logger::STATUS_SUCCESS,
			'Resolved by log fix.',
			Ratesight_Logger::STATUS_PENDING
		) );

		// Pass 2: pending entries with post_id IS NULL — match by title.
		// Sets both post_id and status so the entry is fully resolved.
		$resolved += (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE `{$table}` l
			 INNER JOIN `{$posts_tbl}` p
			   ON p.post_title = l.title
			   AND p.post_type = 'ratesight_page'
			   AND p.post_status NOT IN ('draft','auto-draft','pending','trash')
			 SET l.status = %s, l.notes = %s, l.post_id = p.ID
			 WHERE l.status = %s
			 AND l.post_id IS NULL",
			Ratesight_Logger::STATUS_SUCCESS,
			'Resolved by title match.',
			Ratesight_Logger::STATUS_PENDING
		) );

		// Pass 3: pending entries whose post_id points to a deleted post — mark failed.
		$orphaned = (int) $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE `{$table}` l
			 LEFT JOIN `{$posts_tbl}` p ON p.ID = l.post_id
			 SET l.status = %s, l.notes = %s
			 WHERE l.status = %s
			 AND l.post_id IS NOT NULL
			 AND p.ID IS NULL",
			Ratesight_Logger::STATUS_FAILED,
			'Post no longer exists.',
			Ratesight_Logger::STATUS_PENDING
		) );

		// Count remaining pending entries (post still genuinely in draft).
		$still_pending = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$table}` WHERE status = %s",
			Ratesight_Logger::STATUS_PENDING
		) );

		wp_send_json_success( array(
			'resolved'      => $resolved,
			'orphaned'      => $orphaned,
			'still_pending' => $still_pending,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: retry a failed log entry by replaying its stored payload
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// AJAX: bulk-publish all ratesight_page posts stuck in draft
	// -------------------------------------------------------------------------

	/**
	 * Publishes a batch of draft ratesight_page posts and returns progress.
	 * The JS calls this repeatedly with an incrementing offset until done=true.
	 *
	 * POST params: offset (int, default 0), batch_size (int, default 50)
	 */
	public function ajax_bulk_publish_drafts() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		// If no progress record exists yet, initialise one.
		$progress = get_option( 'ratesight_bulk_publish_progress' );
		if ( ! $progress || ! empty( $progress['done'] ) ) {
			$total = (int) wp_count_posts( 'ratesight_page' )->draft
			       + (int) wp_count_posts( 'ratesight_page' )->{'auto-draft'}
			       + (int) wp_count_posts( 'ratesight_page' )->pending;

			if ( $total === 0 ) {
				wp_send_json_success( array( 'done' => true, 'published' => 0, 'total' => 0, 'message' => 'No draft pages found.' ) );
				return;
			}

			$progress = array( 'done' => false, 'published' => 0, 'failed' => 0, 'total' => $total );
		}

		// Process one batch synchronously — JS calls this endpoint repeatedly
		// until done=true, so numbers update after every single batch.
		$progress = $this->run_bulk_publish_batch( $progress );
		update_option( 'ratesight_bulk_publish_progress', $progress, false );

		// Schedule a cron event as a fallback in case the user navigates away
		// before JS can make the next call. wp_schedule_single_event is a no-op
		// if an identical event is already queued.
		if ( empty( $progress['done'] ) && ! wp_next_scheduled( 'ratesight_bulk_publish_batch' ) ) {
			wp_schedule_single_event( time() + 60, 'ratesight_bulk_publish_batch' );
		}

		wp_send_json_success( $progress );
	}

	// Cron fallback — runs when JS isn't driving (user navigated away).
	// Processes everything in a time-limited loop since it can't self-chain.
	public function cron_bulk_publish_batch() {
		$progress = get_option( 'ratesight_bulk_publish_progress' );
		if ( ! $progress || ! empty( $progress['done'] ) ) {
			return;
		}

		$start = microtime( true );

		while ( microtime( true ) - $start < 20 ) {
			$progress = $this->run_bulk_publish_batch( $progress );
			update_option( 'ratesight_bulk_publish_progress', $progress, false );
			if ( ! empty( $progress['done'] ) ) {
				break;
			}
		}

		// If still not done, schedule another cron run for the remainder.
		if ( empty( $progress['done'] ) && ! wp_next_scheduled( 'ratesight_bulk_publish_batch' ) ) {
			wp_schedule_single_event( time() + 30, 'ratesight_bulk_publish_batch' );
		}
	}

	// Core batch logic — shared by AJAX and cron paths.
	private function run_bulk_publish_batch( array $progress ): array {
		global $wpdb;
		$batch_size   = 50;
		$final_status = Ratesight_Options::get( 'page_status' );
		if ( ! in_array( $final_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$final_status = 'publish';
		}

		$posts = get_posts( array(
			'post_type'      => 'ratesight_page',
			'post_status'    => array( 'draft', 'auto-draft', 'pending' ),
			'posts_per_page' => $batch_size,
			'offset'         => 0,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$published_ids = array();

		foreach ( $posts as $post_id ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $final_status ), true );
			if ( is_wp_error( $result ) ) {
				$progress['failed']++;
			} else {
				$published_ids[] = (int) $post_id;
				$progress['published']++;
			}
		}

		// Update all pending log rows where the post is now live.
		// Using a JOIN rather than IN (ids) so we catch entries where post_id
		// wasn't stored correctly, and any that were published outside this batch.
		if ( ! empty( $published_ids ) ) {
			$table      = $wpdb->prefix . RATESIGHT_LOG_TABLE;
			$posts_tbl  = $wpdb->posts;
			$wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE `{$table}` l
				 INNER JOIN `{$posts_tbl}` p ON p.ID = l.post_id
				 SET l.status = %s, l.notes = %s
				 WHERE l.status = %s
				 AND p.post_status NOT IN ('draft','auto-draft','pending','trash')
				 AND p.post_type = 'ratesight_page'",
				Ratesight_Logger::STATUS_SUCCESS,
				'Published by bulk action.',
				Ratesight_Logger::STATUS_PENDING
			) );
		}

		if ( count( $posts ) < $batch_size ) {
			$progress['done'] = true;
		}

		return $progress;
	}

	// Returns current bulk publish progress for the toast polling loop.
	public function ajax_bulk_publish_progress() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		$progress = get_option( 'ratesight_bulk_publish_progress', array( 'done' => true, 'published' => 0, 'total' => 0 ) );
		wp_send_json_success( $progress );
	}

	// Admin notice shown on every WP admin page while bulk publish is running.
	public function bulk_publish_progress_notice(): void {
		$progress = get_option( 'ratesight_bulk_publish_progress' );
		if ( ! $progress ) {
			return;
		}

		$published = (int) $progress['published'];
		$total     = (int) $progress['total'];
		$done      = ! empty( $progress['done'] );
		$failed    = (int) ( $progress['failed'] ?? 0 );
		$pct       = $total > 0 ? round( ( $published / $total ) * 100 ) : 0;
		$nonce     = wp_create_nonce( 'ratesight_admin' );
		$ajax_url  = admin_url( 'admin-ajax.php' );

		if ( $done ) {
			delete_option( 'ratesight_bulk_publish_progress' );
		}

		$fail_note  = $failed > 0 ? ' (' . $failed . ' failed)' : '';
		$done_int   = $done ? 1 : 0;
		$done_label = esc_html( $published . ' / ' . $total . $fail_note );

		echo '
		<div id="rs-bulk-toast" style="
			position:fixed;bottom:24px;right:24px;z-index:99999;
			background:#1d2327;color:#fff;border-radius:8px;
			padding:16px 20px;min-width:260px;max-width:320px;
			box-shadow:0 4px 16px rgba(0,0,0,.35);
			font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;
			font-size:13px;line-height:1.5;
			transition:opacity .4s;
		">
			<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
				<div style="flex:1;">
					<div style="font-weight:600;margin-bottom:4px;" id="rs-toast-title">
						' . ( $done ? '✓ Bulk publish complete' : 'Publishing pages…' ) . '
					</div>
					<div style="color:#a7aaad;" id="rs-toast-sub">
						' . esc_html( $done_label ) . '
					</div>
					' . ( ! $done ? '
					<div style="margin-top:10px;background:#3c434a;border-radius:4px;height:4px;">
						<div id="rs-toast-bar" style="background:#2271b1;height:4px;border-radius:4px;width:' . esc_attr( $pct ) . '%;transition:width .4s;"></div>
					</div>' : '' ) . '
				</div>
				<button onclick="document.getElementById(\'rs-bulk-toast\').remove();" style="
					background:none;border:none;color:#a7aaad;cursor:pointer;
					font-size:16px;line-height:1;padding:0;flex-shrink:0;
				" aria-label="Dismiss">&times;</button>
			</div>
		</div>
		<script>
		(function(){
			var done=' . (int) $done_int . ';
			if(done){return;}
			var ajax=' . json_encode( $ajax_url ) . ',nonce=' . json_encode( $nonce ) . ';
			function runNext(){
				var xhr=new XMLHttpRequest();
				xhr.open("POST",ajax);
				xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
				xhr.onload=function(){
					try{
						var r=JSON.parse(xhr.responseText);
						if(!r.success){setTimeout(runNext,5000);return;}
						var d=r.data;
						var pct=d.total>0?Math.round((d.published/d.total)*100):0;
						var sub=document.getElementById("rs-toast-sub");
						var bar=document.getElementById("rs-toast-bar");
						var title=document.getElementById("rs-toast-title");
						var fail=d.failed>0?" ("+d.failed+" failed)":"";
						if(sub)sub.textContent=d.published+" / "+d.total+fail;
						if(bar)bar.style.width=pct+"%";
						if(d.done){
							if(title)title.textContent="✓ Bulk publish complete";
							if(bar&&bar.parentNode)bar.parentNode.style.display="none";
							var tbody=document.querySelector("#rs-log-table-wrap tbody");
							if(tbody){
								var xr=new XMLHttpRequest();
								xr.open("POST",ajax);
								xr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
								xr.onload=function(){try{var rr=JSON.parse(xr.responseText);if(rr.success&&rr.data.html!==undefined)tbody.innerHTML=rr.data.html;}catch(e){}};
								xr.send("action=ratesight_get_logs&nonce="+encodeURIComponent(nonce));
							}
							setTimeout(function(){
								var t=document.getElementById("rs-bulk-toast");
								if(t)t.style.opacity="0";
								setTimeout(function(){var t=document.getElementById("rs-bulk-toast");if(t)t.remove();},400);
							},4000);
						}else{runNext();}
					}catch(e){setTimeout(runNext,5000);}
				};
				xhr.onerror=function(){setTimeout(runNext,5000);};
				xhr.send("action=ratesight_bulk_publish_drafts&nonce="+encodeURIComponent(nonce));
			}
			runNext();
		})();
		</script>';
	}

	/**
	 * Re-runs the webhook pipeline for a single failed log row using the
	 * raw JSON payload that was captured when the request originally arrived.
	 * Requires store_raw_payload to have been on at the time of the failure.
	 */
	public function ajax_retry_log() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$log_id = absint( wp_unslash( $_POST['log_id'] ?? 0 ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => 'Invalid log ID.' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $log_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Log entry not found.' ) );
		}

		if ( empty( $row['raw_payload'] ) ) {
			wp_send_json_error( array( 'message' => 'No stored payload for this entry. Enable "Store Raw Payload" to allow retries.' ) );
		}

		$response = Ratesight_Webhook_Handler::replay_payload( $row['raw_payload'] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$data = $response->get_data();
		if ( empty( $data['success'] ) ) {
			wp_send_json_error( array( 'message' => $data['message'] ?? 'Retry failed.' ) );
		}

		wp_send_json_success( array(
			'message'  => 'Retry queued. Post will publish in ~15 seconds.',
			'post_url' => $data['post_url'] ?? '',
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: recheck a pending log entry
	// -------------------------------------------------------------------------

	/**
	 * Manually resolves a pending log row without waiting for the hourly cron.
	 *
	 * Two cases:
	 *  1. Log row has a post_id  → the post exists; just publish it and mark success.
	 *  2. No post_id yet         → find the scheduled ratesight_deferred_publish
	 *                              cron event for this log_id and fire it now.
	 */
	public function ajax_recheck_pending() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$log_id = absint( wp_unslash( $_POST['log_id'] ?? 0 ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => 'Invalid log ID.' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $log_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Log entry not found.' ) );
		}

		$post_id = (int) ( $row['post_id'] ?? 0 );

		// ── Case 1: post already exists — resolve it now ──────────────────────
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_FAILED, 'Post not found during manual recheck.' );
				wp_send_json_error( array( 'message' => "Post #{$post_id} no longer exists — marked as failed." ) );
			}

			if ( ! in_array( $post->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_SUCCESS, 'Resolved by manual recheck — post was already live.' );
				wp_send_json_success( array( 'message' => 'Post was already published. Log updated.' ) );
			}

			$post_type    = get_post_type( $post_id );
			$status_key   = ( $post_type === 'ratesight_page' ) ? 'page_status' : 'post_status';
			$final_status = Ratesight_Options::get( $status_key );
			if ( ! in_array( $final_status, array( 'publish', 'draft', 'private' ), true ) ) {
				$final_status = 'publish';
			}

			$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $final_status ), true );
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => 'Failed to publish post: ' . $updated->get_error_message() ) );
			}

			Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_SUCCESS, 'Published by manual recheck.' );
			wp_send_json_success( array(
				'message'   => 'Post published successfully.',
				'post_url'  => get_permalink( $post_id ),
				'post_id'   => $post_id,
			) );
		}

		// ── Case 2: no post_id yet — find and fire the scheduled cron event ───
		$cron_args = null;
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks['ratesight_deferred_publish'] ) ) continue;
			foreach ( $hooks['ratesight_deferred_publish'] as $event ) {
				$args = $event['args'] ?? array();
				// Args: [ $post_id, $log_id, $image_url, $image_name, $post_title, $status ]
				if ( isset( $args[1] ) && (int) $args[1] === $log_id ) {
					$cron_args = $args;
					break 2;
				}
			}
		}

		if ( $cron_args ) {
			// Fire the deferred publish action immediately.
			do_action( 'ratesight_deferred_publish', ...$cron_args );
			wp_send_json_success( array( 'message' => 'Cron event fired. Post will be published momentarily.' ) );
		}

		// ── Case 3: no post_id, no cron event — search by title ─────────────
		// The post may have been created but the log_id→post_id link was never
		// written (e.g. the request returned before log_update fired). Search
		// both post types for a draft matching the logged title.
		$title = sanitize_text_field( $row['title'] ?? '' );
		if ( $title !== '' ) {
			$matches = get_posts( array(
				'post_type'      => array( 'post', 'ratesight_page' ),
				'post_status'    => array( 'draft', 'auto-draft', 'pending' ),
				'title'          => $title,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );

			if ( ! empty( $matches ) ) {
				$post_id      = (int) $matches[0];
				$post_type    = get_post_type( $post_id );
				$status_key   = ( $post_type === 'ratesight_page' ) ? 'page_status' : 'post_status';
				$final_status = Ratesight_Options::get( $status_key );
				if ( ! in_array( $final_status, array( 'publish', 'draft', 'private' ), true ) ) {
					$final_status = 'publish';
				}

				$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $final_status ), true );
				if ( is_wp_error( $updated ) ) {
					wp_send_json_error( array( 'message' => 'Found draft post but failed to publish: ' . $updated->get_error_message() ) );
				}

				Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_SUCCESS, 'Published by manual recheck (matched by title).' );
				wp_send_json_success( array(
					'message'  => 'Found draft post and published it.',
					'post_url' => get_permalink( $post_id ),
					'post_id'  => $post_id,
				) );
			}
		}

		// Genuinely stuck — no post found anywhere.
		wp_send_json_error( array( 'message' => 'No scheduled cron event and no matching draft post found. Check your error log.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: retry only the GBP posting step for a warnings log entry
	// -------------------------------------------------------------------------

	/**
	 * Re-runs only the GBP "What's New" post step for an existing WordPress post.
	 * Used when GBP posting failed but the post itself was created successfully.
	 * Requires the log row to have a valid post_id.
	 */
	public function ajax_retry_gbp() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$log_id = absint( wp_unslash( $_POST['log_id'] ?? 0 ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => 'Invalid log ID.' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . RATESIGHT_LOG_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $log_id ), ARRAY_A );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Log entry not found.' ) );
		}

		$post_id = (int) ( $row['post_id'] ?? 0 );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'No valid post linked to this log entry.' ) );
		}

		$result = Ratesight_Publisher::post_to_gbp( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Update the log note to reflect the successful GBP retry.
		$wpdb->update(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$table,
			array( 'notes' => 'GBP post succeeded on retry.' ),
			array( 'id'    => $log_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'message' => 'GBP post published successfully.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: send a signed test request to the webhook endpoint
	// -------------------------------------------------------------------------

	/**
	 * Fires a real POST to the webhook endpoint using the stored secret,
	 * so the admin can verify the full stack (IP check, HMAC, post creation)
	 * without leaving WordPress.
	 */
	public function ajax_send_test() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$endpoint = rest_url( 'ratesight/v1/create-page' );

		$body = wp_json_encode( array(
			'title'            => '[Ratesight Test] ' . wp_date( 'Y-m-d H:i:s' ),
			'article'          => '<p>This is an automated test post created by the Ratesight plugin. You may delete it.</p>',
			'slug'             => 'ratesight-test-' . time(),
			'meta_title'       => 'Ratesight Test Post',
			'meta_description' => 'Automated test from the Ratesight plugin settings page.',
		) );

		// The test fires from this server to itself. WordPress loopback
		// requests originate from 127.0.0.1 — add that to the allowlist
		// if the test returns a 403, or temporarily clear the allowlist.
		$response = wp_remote_post( $endpoint, array(
			'timeout'   => 30,
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'body'      => $body,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 200 && ! empty( $body_decoded['success'] ) ) {
			wp_send_json_success( array(
				'message'  => 'Test post created. It will publish in ~15 seconds.',
				'post_url' => $body_decoded['post_url'] ?? '',
				'post_id'  => $body_decoded['post_id']  ?? null,
			) );
		} else {
			$message = $body_decoded['message'] ?? "Endpoint returned HTTP {$code}.";
			if ( $code === 403 ) {
				$message .= ' Add 127.0.0.1 to the allowlist to allow loopback test requests.';
			}
			wp_send_json_error( array( 'message' => $message, 'code' => $code ) );
		}
	}

	public function ajax_dismiss_wizard() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		update_user_meta( get_current_user_id(), 'ratesight_wizard_dismissed', 1 );
		wp_send_json_success();
	}

	public function ajax_connections_status() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$cached = get_transient( 'ratesight_connections_status' );
		if ( $cached ) {
			wp_send_json_success( $cached );
		}

		$status = array();

		// Sitemap accessible?
		$sitemap_url = trailingslashit( home_url() ) . 'sitemap.xml';
		$check       = wp_remote_head( $sitemap_url, array( 'timeout' => 6, 'redirection' => 3 ) );
		$status['sitemap_live'] = ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) === 200;

		// IndexNow key reachable?
		$status['indexnow_ok']  = Ratesight_IndexNow::verify_key();
		$status['indexnow_url'] = Ratesight_IndexNow::key_url();

		// Widget IDs configured?
		$status['widget_id_set'] = ! empty( Ratesight_Options::get( 'code_id' ) );

		// blog_public check
		$status['blog_public'] = (bool) get_option( 'blog_public' );

		// Cache for 1 hour
		set_transient( 'ratesight_connections_status', $status, HOUR_IN_SECONDS );

		wp_send_json_success( $status );
	}

	public function ajax_sitemap_status() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$home     = home_url();
		$host     = wp_parse_url( $home, PHP_URL_HOST );
		$sitemap  = trailingslashit( $home ) . 'sitemap.xml';
		$result   = array(
			'sitemap_url'  => $sitemap,
			'sitemap_live' => false,
			'gsc_submitted' => false,
			'bing_submitted' => false,
		);

		// Check sitemap exists
		$check = wp_remote_head( $sitemap, array( 'timeout' => 8, 'redirection' => 3 ) );
		if ( ! is_wp_error( $check ) && wp_remote_retrieve_response_code( $check ) === 200 ) {
			$result['sitemap_live'] = true;
		}

		// Check GSC
		if ( Ratesight_GSC_Client::is_locked() && Ratesight_OAuth_Client::is_connected( 'gsc' ) ) {
			$token    = Ratesight_OAuth_Client::get_access_token( 'gsc' );
			$selection = Ratesight_GSC_Client::get_selection();
			$property  = rawurlencode( $selection['url'] ?? '' );

			if ( ! is_wp_error( $token ) && $property ) {
				$url = "https://www.googleapis.com/webmasters/v3/sites/{$property}/sitemaps";
				$response = wp_remote_get( $url, array(
					'timeout' => 10,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				) );

				if ( ! is_wp_error( $response ) ) {
					$body = json_decode( wp_remote_retrieve_body( $response ), true );
					$sitemaps = $body['sitemap'] ?? array();
					foreach ( $sitemaps as $sm ) {
						if ( strpos( $sm['path'] ?? '', 'sitemap' ) !== false ) {
							$result['gsc_submitted'] = true;
							$result['gsc_sitemap_url'] = $sm['path'] ?? '';
							break;
						}
					}
				}
			}
		}

		// Check Bing via Worker
		$auth     = Ratesight_OAuth_Client::sign_request( $host . '|check' );
		$response = wp_remote_get(
			add_query_arg( array( 'host' => $host ) + $auth, 'https://oauth.ratesight.com/sitemap-status' ),
			array( 'timeout' => 8 )
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$result['bing_submitted'] = ! empty( $body['submitted'] );
			$result['bing_verified']  = ! empty( $body['verified'] );
		}

		wp_send_json_success( $result );
	}

	public function ajax_get_keywords() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing post_id.' ) );
		}

		$keywords = Ratesight_GSC_Client::get_keywords_for_post( $post_id );
		wp_send_json_success( array( 'keywords' => $keywords ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: schema
	// -------------------------------------------------------------------------

	public function ajax_preview_schema() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Missing post_id.' ) );

		$type   = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ) ?: null;
		$schema = Ratesight_Schema::generate( $post_id, $type ?: null );

		wp_send_json_success( array(
			'schema'          => $schema,
			'detected_type'   => Ratesight_Schema::detect_type( $post_id ),
			'has_schema'      => Ratesight_Schema::has_schema( $post_id ),
			'json'            => wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
	}

	public function ajax_save_schema() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$schema_json = wp_kses_post( wp_unslash( $_POST['schema_json'] ?? '' ) );

		if ( ! $post_id || ! $schema_json ) {
			wp_send_json_error( array( 'message' => 'Missing post_id or schema_json.' ) );
		}

		$schema = json_decode( $schema_json, true );
		if ( ! $schema ) {
			wp_send_json_error( array( 'message' => 'Invalid JSON.' ) );
		}

		Ratesight_Schema::save_schema( $post_id, $schema );
		wp_send_json_success( array( 'message' => 'Schema saved.' ) );
	}

	public function ajax_remove_schema() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Missing post_id.' ) );

		Ratesight_Schema::remove_schema( $post_id );
		wp_send_json_success( array( 'message' => 'Schema removed.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: IndexNow
	// -------------------------------------------------------------------------

	public function ajax_indexnow_status() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$key      = Ratesight_IndexNow::get_key();
		$key_url  = Ratesight_IndexNow::key_url();
		$verified = Ratesight_IndexNow::verify_key();

		wp_send_json_success( array(
			'key'      => $key,
			'key_url'  => $key_url,
			'verified' => $verified,
		) );
	}

	public function ajax_clear_indexnow_log(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		Ratesight_IndexNow::clear_log();
		wp_send_json_success( array( 'message' => 'Log cleared.' ) );
	}

	public function ajax_add_category(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? 'category' ) );

		if ( $name === '' ) {
			wp_send_json_error( array( 'message' => 'Name cannot be empty.' ) );
		}
		if ( ! in_array( $taxonomy, array( 'category', 'rs_category' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid taxonomy.' ) );
		}

		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing ) {
			wp_send_json_success( array(
				'term_id' => $existing->term_id,
				'name'    => $existing->name,
				'existed' => true,
			) );
		}

		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$term = get_term( $result['term_id'], $taxonomy );
		wp_send_json_success( array(
			'term_id' => $result['term_id'],
			'name'    => $term->name,
			'existed' => false,
		) );
	}

	public function ajax_get_qa() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$qa = Ratesight_GBP_Insights_Client::get_qa();
		if ( is_wp_error( $qa ) ) {
			wp_send_json_error( array( 'message' => $qa->get_error_message() ) );
		}
		wp_send_json_success( $qa );
	}

	public function ajax_answer_question() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$question_name = sanitize_text_field( wp_unslash( $_POST['question_name'] ?? '' ) );
		$text          = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( ! $question_name || ! $text ) {
			wp_send_json_error( array( 'message' => 'Missing question_name or text.' ) );
		}
		$result = Ratesight_GBP_Insights_Client::answer_question( $question_name, $text );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'Answer posted.' ) );
	}

	public function ajax_sync_gbp_now() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		if ( ! Ratesight_OAuth_Client::is_connected( 'gbp' ) || ! Ratesight_GBP_Client::is_locked() ) {
			wp_send_json_error( array( 'message' => 'GBP not connected or no location locked.' ) );
		}

		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => 'Token error: ' . $token->get_error_message() ) );
		}

		$selection     = Ratesight_GBP_Client::get_selection();
		$location_path = $selection['id'] ?? '';
		$parts         = explode( '/', $location_path );
		$loc_resource  = 'locations/' . end( $parts );

		// Test the API directly and return the raw response for debugging.
		$end_date   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		$url = 'https://businessprofileperformance.googleapis.com/v1/' . $loc_resource
			. ':fetchMultiDailyMetricsTimeSeries'
			. '?dailyMetrics=WEBSITE_CLICKS'
			. '&dailyMetrics=CALL_CLICKS'
			. '&dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'
			. '&dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
			. '&dailyRange.start_date.year='  . gmdate( 'Y', strtotime( $start_date ) )
			. '&dailyRange.start_date.month=' . gmdate( 'n', strtotime( $start_date ) )
			. '&dailyRange.start_date.day='   . gmdate( 'j', strtotime( $start_date ) )
			. '&dailyRange.end_date.year='    . gmdate( 'Y', strtotime( $end_date ) )
			. '&dailyRange.end_date.month='   . gmdate( 'n', strtotime( $end_date ) )
			. '&dailyRange.end_date.day='     . gmdate( 'j', strtotime( $end_date ) );

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );

		$http_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$body      = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'HTTP error: ' . $body ) );
		}

		if ( $http_code !== 200 ) {
			$api_msg = $decoded['error']['message'] ?? $body;
			wp_send_json_error( array( 'message' => "API error HTTP {$http_code}: " . substr( $api_msg, 0, 200 ) ) );
		}

		$series_count = count( $decoded['multiDailyMetricTimeSeries'] ?? array() );
		if ( $series_count === 0 ) {
			wp_send_json_error( array( 'message' => 'API returned OK but no metric series. Location may have no data yet.' ) );
		}

		// Log which metrics came back and their data point counts.
		$metric_log = array();
		foreach ( $decoded['multiDailyMetricTimeSeries'] ?? array() as $group ) {
			foreach ( $group['dailyMetricTimeSeries'] ?? array() as $series ) {
				$metric     = $series['dailyMetric'] ?? '?';
				$points     = count( $series['timeSeries']['datedValues'] ?? array() );
				$non_zero   = count( array_filter( $series['timeSeries']['datedValues'] ?? array(), static fn( $dv ) => (int)( $dv['value'] ?? 0 ) > 0 ) );
				$metric_log[] = "{$metric}: {$points} days, {$non_zero} non-zero";
			}
		}

		// Data confirmed — store directly from this response.
		delete_option( 'ratesight_gbp_performance_last_sync' );

		$review_count = 0;
		$avg_rating   = 0.0;
		$reviews = Ratesight_GBP_Insights_Client::get_reviews();
		if ( ! is_wp_error( $reviews ) ) {
			$review_count = (int)   ( $reviews['total']      ?? 0 );
			$avg_rating   = (float) ( $reviews['avg_rating'] ?? 0 );
		}

		Ratesight_GBP_Insights_Client::store_gbp_performance( $loc_resource, $decoded, $review_count, $avg_rating );
		update_option( 'ratesight_gbp_performance_last_sync', current_time( 'mysql' ) );

		// Check how many rows landed.
		global $wpdb;
		$table     = $wpdb->prefix . 'ratesight_gbp_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row_count = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT COUNT(*) FROM `{$table}` WHERE location_id = %s",
			$loc_resource
		) );
		$sample = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT date, search_impressions, maps_impressions, website_clicks FROM `{$table}` WHERE location_id = %s AND (search_impressions > 0 OR maps_impressions > 0) ORDER BY date DESC LIMIT 1",
			$loc_resource
		), ARRAY_A );

		// Clear YoY transients so comparison recalculates with fresh data.
		foreach ( array( 7, 28, 90 ) as $d ) {
			delete_transient( 'ratesight_gbp_yoy_' . $d );
		}

		wp_send_json_success( array(
			'message'   => "Synced — {$series_count} metric series for {$loc_resource}.",
			'metrics'   => $metric_log,
			'db_rows'   => $row_count,
			'db_sample' => $sample,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: Bing Webmaster Tools
	// -------------------------------------------------------------------------

	public function ajax_save_bing_key(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => 'API key cannot be empty.' ) );
		}
		update_option( Ratesight_Options::option_name( 'bing_api_key' ), $key );
		wp_send_json_success( array( 'message' => 'API key saved.' ) );
	}

	public function ajax_load_bing_sites(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$sites = Ratesight_Bing_Client::get_sites();
		if ( is_wp_error( $sites ) ) {
			wp_send_json_error( array( 'message' => $sites->get_error_message() ) );
		}
		wp_send_json_success( array( 'sites' => $sites ) );
	}

	public function ajax_lock_bing_site(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
		if ( $site_url === '' ) {
			wp_send_json_error( array( 'message' => 'No site selected.' ) );
		}
		update_option( Ratesight_Options::option_name( 'bing_site_url' ), $site_url );
		wp_send_json_success( array( 'message' => 'Site locked.' ) );
	}

	public function ajax_sync_bing_now(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		if ( ! Ratesight_Bing_Client::is_connected() ) {
			wp_send_json_error( array( 'message' => 'Bing API key not configured.' ) );
		}
		if ( ! Ratesight_Bing_Client::is_locked() ) {
			wp_send_json_error( array( 'message' => 'No Bing site locked.' ) );
		}
		$result = Ratesight_Bing_Client::sync_performance();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array(
			'message' => "Sync complete — {$result['total_rows']} rows from Bing, {$result['stored']} matched posts, {$result['skipped']} skipped, {$result['kw_stored']} keywords stored.",
			'debug'   => $result,
		) );
	}

	// ─── Link Manager ────────────────────────────────────────────────────────

	public function ajax_link_scan(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$result = Ratesight_Link_Manager::scan_all();
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_link_check_broken(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post ID.' ) );
		}
		$result = Ratesight_Link_Manager::check_broken( $post_id );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_link_suggestions(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'No post ID.' ) );
		}
		$suggestions = Ratesight_Link_Manager::get_suggestions( $post_id );
		if ( is_wp_error( $suggestions ) ) {
			wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
		}
		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	public function ajax_link_insert(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$anchor  = sanitize_text_field( wp_unslash( $_POST['anchor'] ?? '' ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $anchor || ! $url ) {
			wp_send_json_error( array( 'message' => 'Missing post_id, anchor, or url.' ) );
		}
		$result = Ratesight_Link_Manager::insert_link( $post_id, $anchor, $url );
		if ( ! $result['ok'] ) wp_send_json_error( array( 'message' => $result['message'] ) );
		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	public function ajax_link_ignore_broken(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $url ) wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );
		wp_send_json_success( Ratesight_Link_Manager::ignore_broken( $post_id, $url ) );
	}

	public function ajax_link_unignore_broken(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $url ) wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );
		wp_send_json_success( Ratesight_Link_Manager::unignore_broken( $post_id, $url ) );
	}

	public function ajax_link_unlink(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $url ) wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );
		$result = Ratesight_Link_Manager::unlink_url( $post_id, $url );
		if ( ! $result['ok'] ) wp_send_json_error( array( 'message' => $result['message'] ) );
		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	public function ajax_link_auto_fix(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $url ) wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );
		wp_send_json_success( Ratesight_Link_Manager::auto_fix_suggestions( $post_id, $url ) );
	}

	public function ajax_link_replace(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$old_url = sanitize_text_field( wp_unslash( $_POST['old_url'] ?? '' ) );
		$new_url = sanitize_text_field( wp_unslash( $_POST['new_url'] ?? '' ) );
		if ( ! $post_id || ! $old_url || ! $new_url ) wp_send_json_error( array( 'message' => 'Missing parameters.' ) );
		$result = Ratesight_Link_Manager::replace_broken( $post_id, $old_url, $new_url );
		if ( ! $result['ok'] ) wp_send_json_error( array( 'message' => $result['message'] ) );
		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	public function ajax_link_broken_detail(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Missing post_id.' ) );
		wp_send_json_success( Ratesight_Link_Manager::get_broken_detail( $post_id ) );
	}

	public function ajax_test_ai_worker(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$result = Ratesight_AI_Client::prompt( 'Reply with the single word: ok', array(), 15 );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => 'Worker reachable — DeepSeek responded.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Worker unreachable: ' . ( $result['error'] ?? 'unknown error' ) ) );
		}
	}

	public function ajax_link_refresh_suggestions(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Missing post_id.' ) );
		delete_transient( 'rs_link_suggest_' . $post_id );
		$suggestions = Ratesight_Link_Manager::get_suggestions( $post_id );
		if ( is_wp_error( $suggestions ) ) wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	public function ajax_link_fix_targets(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$result = Ratesight_Link_Manager::fix_all_link_targets();
		wp_send_json_success( $result );
	}

	public function ajax_link_bulk_check_broken(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );

		global $wpdb;
		$table  = $wpdb->prefix . 'ratesight_link_cache';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$offset = max( 0, absint( wp_unslash( $_POST['offset'] ?? 0 ) ) );
		$batch  = 30; // pages per request

		// First call: reset all pages to unchecked.
		if ( $offset === 0 ) {
			$wpdb->query( "UPDATE `{$table}` SET broken_count = -1, broken_urls = NULL" ); // phpcs:ignore
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore

		if ( $total === 0 ) {
			wp_send_json_error( array( 'message' => 'No pages in cache yet — click Scan All Pages first.' ) );
		}

		// Get next batch of unchecked pages.
		$post_ids = $wpdb->get_col( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT post_id FROM `{$table}` WHERE broken_count = -1 ORDER BY post_id ASC LIMIT %d",
			$batch
		) );

		foreach ( $post_ids as $pid ) {
			Ratesight_Link_Manager::check_broken( (int) $pid );
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE broken_count = -1" ); // phpcs:ignore
		$checked   = $total - $remaining;
		$done      = $remaining === 0;

		wp_send_json_success( array(
			'done'      => $done,
			'total'     => $total,
			'checked'   => $checked,
			'remaining' => $remaining,
			'offset'    => $offset + $batch,
		) );
	}

	public function ajax_regen_webhook_secret(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$secret = bin2hex( random_bytes( 24 ) );
		update_option( 'ratesight_webhook_secret', $secret, false );
		wp_send_json_success( array( 'secret' => $secret ) );
	}

	public function ajax_link_get_manual(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error( array( 'message' => 'Missing post_id.' ) );
		$links = get_post_meta( $post_id, Ratesight_Link_Manager::META_MANUAL_LINKS, true ) ?: array();
		wp_send_json_success( array( 'links' => array_values( $links ) ) );
	}

	public function ajax_link_remove_manual(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $post_id || ! $url ) wp_send_json_error( array( 'message' => 'Missing post_id or url.' ) );

		// Remove from meta so it won't be re-applied.
		$links = get_post_meta( $post_id, Ratesight_Link_Manager::META_MANUAL_LINKS, true ) ?: array();
		$links = array_values( array_filter( $links, fn( $l ) => ( $l['url'] ?? '' ) !== $url ) );
		update_post_meta( $post_id, Ratesight_Link_Manager::META_MANUAL_LINKS, $links );

		// Also unlink from live content right now.
		$result = Ratesight_Link_Manager::unlink_url( $post_id, $url );
		wp_send_json_success( array( 'message' => $result['message'], 'remaining' => count( $links ) ) );
	}

	public function ajax_redirect_update(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$dest = esc_url_raw( wp_unslash( $_POST['destination'] ?? '' ) );
		if ( ! $path ) wp_send_json_error( array( 'message' => 'Missing path.' ) );
		Ratesight_Link_Manager::update_redirect( $path, $dest );
		wp_send_json_success( array( 'message' => 'Redirect updated.' ) );
	}

	public function ajax_redirect_delete(): void {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		if ( ! $path ) wp_send_json_error( array( 'message' => 'Missing path.' ) );
		Ratesight_Link_Manager::delete_redirect( $path );
		wp_send_json_success( array( 'message' => 'Redirect removed.' ) );
	}

	public function ajax_review_velocity() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		wp_send_json_success( Ratesight_GBP_Insights_Client::get_review_velocity() );
	}

	// -------------------------------------------------------------------------
	// AJAX: keyword cannibalization
	// -------------------------------------------------------------------------

	public function ajax_get_cannibalization() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$kw_table   = $wpdb->prefix . 'ratesight_keywords';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$perf_table = $wpdb->prefix . 'ratesight_performance';

		// Find queries where 2+ pages appear in the top keywords.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT k.query, k.post_id, k.impressions, k.position, wp.post_title,
			        p.impressions AS page_impressions
			 FROM `{$kw_table}` k
			 INNER JOIN (
			     SELECT query FROM `{$kw_table}`
			     WHERE date = ( SELECT MAX(date) FROM `{$kw_table}` )
			     GROUP BY query
			     HAVING COUNT(DISTINCT post_id) > 1
			 ) dupe ON dupe.query = k.query
			 INNER JOIN (
			     SELECT post_id, MAX(date) AS max_date FROM `{$kw_table}` GROUP BY post_id
			 ) latest ON latest.post_id = k.post_id AND latest.max_date = k.date
			 INNER JOIN {$wpdb->posts} wp ON wp.ID = k.post_id
			 LEFT JOIN (
			     SELECT post_id, impressions FROM `{$perf_table}` p2
			     WHERE p2.date = ( SELECT MAX(date) FROM `{$perf_table}` WHERE post_id = p2.post_id )
			 ) p ON p.post_id = k.post_id
			 ORDER BY k.query, k.impressions DESC",
			ARRAY_A
		);

		// Build brand term filter — queries containing the site name or domain
		// are navigational/brand queries and will naturally appear across all pages.
		$site_name   = strtolower( get_bloginfo( 'name' ) );
		$domain      = strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$domain_base = preg_replace( '/^www\./', '', $domain );

		// Extract significant words from site name (3+ chars, not stop words).
		$stop_words  = array( 'the', 'and', 'for', 'llc', 'inc', 'co', 'ltd' );
		$brand_words = array_filter(
			explode( ' ', $site_name ),
			static fn( $w ) => strlen( $w ) >= 3 && ! in_array( $w, $stop_words, true )
		);

		$is_brand_query = static function ( string $query ) use ( $brand_words, $domain_base ): bool {
			$q = strtolower( $query );
			// Flag if query contains any brand word or the domain.
			foreach ( $brand_words as $word ) {
				if ( str_contains( $q, $word ) ) return true;
			}
			if ( str_contains( $q, $domain_base ) ) return true;
			return false;
		};

		// Group by query, skipping brand terms.
		$groups = array();
		foreach ( $rows as $row ) {
			$q = $row['query'];
			// Skip brand/navigational queries — not real cannibalization.
			if ( $is_brand_query( $q ) ) continue;
			// Skip very low volume queries (noise).
			if ( (int) $row['impressions'] < 3 ) continue;
			if ( ! isset( $groups[ $q ] ) ) $groups[ $q ] = array();
			$groups[ $q ][] = $row;
		}

		// For each group, only flag genuine competition:
		// - 2+ pages both ranking in top 30
		// - positions within 15 of each other (both realistically competing)
		$conflicts = array();
		foreach ( $groups as $query => $pages ) {
			if ( count( $pages ) < 2 ) continue;

			// Only include pages ranking in top 30 with meaningful impressions.
			$pages = array_filter( $pages, static fn( $p ) =>
				(float) $p['position'] > 0
				&& (float) $p['position'] <= 30
				&& (int) $p['impressions'] >= 3
			);
			$pages = array_values( $pages );
			if ( count( $pages ) < 2 ) continue;

			// Check that the top two are within 15 positions of each other.
			usort( $pages, static fn( $a, $b ) => (float) $a['position'] <=> (float) $b['position'] );
			if ( abs( (float) $pages[0]['position'] - (float) $pages[1]['position'] ) > 15 ) continue;

			// Primary = page with most impressions overall.
			usort( $pages, static fn( $a, $b ) => (int) $b['page_impressions'] - (int) $a['page_impressions'] );
			$conflicts[] = array(
				'query'     => $query,
				'primary'   => $pages[0],
				'competing' => array_slice( $pages, 1 ),
			);
		}

		wp_send_json_success( array( 'conflicts' => $conflicts ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: content improvement queue
	// -------------------------------------------------------------------------

	public function ajax_get_improvement_queue() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		global $wpdb;
		$perf_table = $wpdb->prefix . 'ratesight_performance';

		// Pages ranked 6-20, 500+ impressions, CTR below 3% — most improvable.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT p.post_id, p.url, p.impressions, p.clicks, p.position, p.ctr,
			        wp.post_title
			 FROM `{$perf_table}` p
			 INNER JOIN (
			     SELECT post_id, MAX(date) AS max_date FROM `{$perf_table}` GROUP BY post_id
			 ) latest ON latest.post_id = p.post_id AND latest.max_date = p.date
			 INNER JOIN {$wpdb->posts} wp ON wp.ID = p.post_id
			 WHERE p.position BETWEEN 6 AND 20
			 AND p.impressions >= 500
			 AND p.ctr < 3
			 ORDER BY p.impressions DESC
			 LIMIT 20",
			ARRAY_A
		);

		// Attach current meta title and description for each post.
		foreach ( $rows as &$row ) {
			$post_id           = (int) $row['post_id'];
			$row['meta_title'] = get_post_meta( $post_id, '_yoast_wpseo_title', true )
				?: get_post_meta( $post_id, 'rank_math_title', true )
				?: get_post_meta( $post_id, '_aioseo_title', true )
				?: get_the_title( $post_id );
			$row['meta_desc']  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
				?: get_post_meta( $post_id, 'rank_math_description', true )
				?: get_post_meta( $post_id, '_aioseo_description', true )
				?: '';
			$row['edit_url']   = get_edit_post_link( $post_id, 'raw' );
		}
		unset( $row );

		wp_send_json_success( array( 'pages' => $rows ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: AI rewrite for title/meta
	// -------------------------------------------------------------------------

	public function ajax_rewrite_meta() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id    = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$title      = sanitize_text_field( wp_unslash( $_POST['current_title'] ?? '' ) );
		$desc       = sanitize_textarea_field( wp_unslash( $_POST['current_desc'] ?? '' ) );
		$query      = sanitize_text_field( wp_unslash( $_POST['top_query'] ?? '' ) );
		$impressions = absint( wp_unslash( $_POST['impressions'] ?? 0 ) );
		$position    = floatval( wp_unslash( $_POST['position'] ?? 0 ) );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing post_id.' ) );
		}

		$prompt = "You are an SEO expert. Rewrite the title tag and meta description for a page that is underperforming.

Current title: {$title}
Current meta description: {$desc}
Top query driving impressions: {$query}
Current position: #{$position}
Current impressions: {$impressions} (but CTR is below 3%)

Rules:
- Title: 50-60 characters, include the main keyword naturally, make it compelling
- Meta description: 140-155 characters, include keyword, add a clear benefit or call to action
- Do NOT use clickbait or misleading language
- Keep it relevant to the actual page content

Return ONLY valid JSON: {\"title\": \"...\", \"meta_description\": \"...\"}";

		$body = Ratesight_AI_Client::prompt( $prompt, array(), 30 );

		if ( empty( $body['ok'] ) ) {
			wp_send_json_error( array( 'message' => $body['error'] ?? 'AI request failed.' ) );
		}

		// Parse JSON from AI reply.
		$reply = $body['reply'] ?? '';
		preg_match( '/\{[\s\S]*\}/', $reply, $matches );
		if ( empty( $matches[0] ) ) {
			wp_send_json_error( array( 'message' => 'Could not parse AI response.' ) );
		}

		$result = json_decode( $matches[0], true );
		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'Invalid AI JSON response.' ) );
		}

		wp_send_json_success( array(
			'title'            => $result['title'] ?? '',
			'meta_description' => $result['meta_description'] ?? '',
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: save rewritten meta
	// -------------------------------------------------------------------------

	public function ajax_save_meta() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$title   = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$desc    = sanitize_textarea_field( wp_unslash( $_POST['meta_description'] ?? '' ) );

		if ( ! $post_id || ! $title ) {
			wp_send_json_error( array( 'message' => 'Missing post_id or title.' ) );
		}

		// Write to all common SEO plugins — whichever is active will use it.
		$meta_keys_title = array( '_yoast_wpseo_title', 'rank_math_title', '_aioseo_title' );
		$meta_keys_desc  = array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' );

		$saved_any = false;
		foreach ( $meta_keys_title as $key ) {
			if ( get_post_meta( $post_id, $key, true ) !== false ) {
				update_post_meta( $post_id, $key, $title );
				$saved_any = true;
			}
		}
		foreach ( $meta_keys_desc as $key ) {
			if ( get_post_meta( $post_id, $key, true ) !== false ) {
				update_post_meta( $post_id, $key, $desc );
			}
		}

		// If no SEO plugin detected, save to a generic meta key.
		if ( ! $saved_any ) {
			update_post_meta( $post_id, '_rs_meta_title', $title );
			update_post_meta( $post_id, '_rs_meta_description', $desc );
		}

		wp_send_json_success( array( 'message' => 'Meta saved.' ) );
	}

	public function ajax_get_profile_health() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$health = Ratesight_GBP_Insights_Client::get_profile_health();
		if ( is_wp_error( $health ) ) {
			wp_send_json_error( array( 'message' => $health->get_error_message() ) );
		}

		wp_send_json_success( $health );
	}

	// -------------------------------------------------------------------------
	// AJAX: GBP reviews
	// -------------------------------------------------------------------------

	public function ajax_get_reviews() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$reviews = Ratesight_GBP_Insights_Client::get_reviews();
		if ( is_wp_error( $reviews ) ) {
			wp_send_json_error( array( 'message' => $reviews->get_error_message() ) );
		}

		wp_send_json_success( $reviews );
	}

	// -------------------------------------------------------------------------
	// AJAX: reply to a review
	// -------------------------------------------------------------------------

	public function ajax_reply_review() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$review_name = sanitize_text_field( wp_unslash( $_POST['review_name'] ?? '' ) );
		$comment     = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );

		if ( ! $review_name || ! $comment ) {
			wp_send_json_error( array( 'message' => 'Missing review_name or comment.' ) );
		}

		$result = Ratesight_GBP_Insights_Client::reply_to_review( $review_name, $comment );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Reply posted successfully.' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: AI chat (contextual, per-tab)
	// -------------------------------------------------------------------------

	public function ajax_ai_chat() {
		check_ajax_referer( 'ratesight_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$prompt  = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$context = sanitize_key( wp_unslash( $_POST['context'] ?? 'organic' ) ); // 'organic' | 'local'

		if ( ! $prompt ) {
			wp_send_json_error( array( 'message' => 'No prompt provided.' ) );
		}

		// Build context data to send to the Worker.
		$context_data = array();

		if ( $context === 'organic' ) {
			$rs_pages      = Ratesight_GSC_Client::get_performance_data( 30 );
			$site_overview = Ratesight_GSC_Client::get_site_overview();

			if ( empty( $rs_pages ) && ( is_wp_error( $site_overview ) || empty( $site_overview['total_impressions'] ) ) ) {
				wp_send_json_error( array( 'message' => 'No GSC data yet — run a sync first.' ) );
			}

			// Include business profile so the AI can make specific keyword inferences.
			$profile = Ratesight_GBP_Insights_Client::get_profile_health();

			$context_data = array(
				'type'       => 'organic',
				// Top 10 non-RS site pages — opportunity context
				'site_pages' => array_slice( ! is_wp_error( $site_overview ) ? ( $site_overview['opportunities'] ?? array() ) : array(), 0, 10 ),
				'site_stats' => ! is_wp_error( $site_overview ) ? array(
					'total_impressions' => $site_overview['total_impressions'] ?? 0,
					'avg_position'      => $site_overview['avg_position']      ?? 0,
					'page_count'        => $site_overview['page_count']        ?? 0,
				) : array(),
				// All RS-published pages — full performance + trend data
				'rs_pages'        => $rs_pages,
				// Business identity — lets the AI name real services and locations
				'business_name'   => ! is_wp_error( $profile ) ? ( $profile['name']           ?? '' ) : '',
				'categories'      => ! is_wp_error( $profile ) ? ( $profile['all_categories'] ?? array() ) : array(),
				'services'        => ! is_wp_error( $profile ) ? ( $profile['services']        ?? array() ) : array(),
				'service_areas'   => ! is_wp_error( $profile ) ? ( $profile['service_areas']   ?? array() ) : array(),
			);
		} elseif ( $context === 'local' ) {
			$gbp_stats = Ratesight_GBP_Insights_Client::get_overview_stats();
			// Fall back to profile + review data if performance hasn't synced yet.
			$reviews = Ratesight_GBP_Insights_Client::get_reviews();
			$profile = Ratesight_GBP_Insights_Client::get_profile_health();

			$context_data = array(
				'type'             => 'local',
				'stats'            => $gbp_stats,
				'avg_rating'       => ! is_wp_error( $reviews ) ? ( $reviews['avg_rating'] ?? 0 ) : 0,
				'total_reviews'    => ! is_wp_error( $reviews ) ? ( $reviews['total'] ?? 0 ) : 0,
				'unanswered'       => ! is_wp_error( $reviews ) ? ( $reviews['unanswered_count'] ?? 0 ) : 0,
				// Business identity — lets the AI give specific rather than placeholder advice.
				'business_name'    => ! is_wp_error( $profile ) ? ( $profile['name']           ?? '' ) : '',
				'categories'       => ! is_wp_error( $profile ) ? ( $profile['all_categories'] ?? array() ) : array(),
				'services'         => ! is_wp_error( $profile ) ? ( $profile['services']        ?? array() ) : array(),
				'description'      => ! is_wp_error( $profile ) ? ( $profile['description']     ?? '' ) : '',
				'service_areas'    => ! is_wp_error( $profile ) ? ( $profile['service_areas']   ?? array() ) : array(),
			);
			// Don't block on empty stats — profile/review data is enough to chat.
		} else {
			$context_data = array( 'type' => 'general' );
		}

		$result = Ratesight_AI_Client::contextual_chat( $prompt, $context_data );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ?? 'AI request failed.' ) );
		}

		wp_send_json_success( array( 'reply' => $result['reply'] ) );
	}
}

