<?php
/**
 * REST API endpoint: POST /wp-json/ratesight/v1/create-page
 *
 * Creates the post as a draft and returns immediately.
 * Image download and publish are handled by Ratesight_Publisher via WP-Cron
 * ~15 seconds later, so the webhook response is always fast.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Webhook_Handler {

	public function register_route() {
		// Original: create or update by slug match.
		register_rest_route( 'ratesight/v1', '/create-page', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_auth' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_delete_page' ),
				'permission_callback' => array( $this, 'check_auth' ),
			),
		) );

		// Read or update an existing page identified by URL.
		register_rest_route( 'ratesight/v1', '/update-page', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_read_by_url' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'url' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_by_url' ),
				'permission_callback' => array( $this, 'check_auth' ),
			),
		) );

		// 404-recovery: set or remove a redirect. These mutate routing and must
		// never be callable unsigned — they require a configured secret and a
		// valid HMAC signature (check_auth_signed), unlike the create flow which
		// is IP-allowlisted via the Worker.
		register_rest_route( 'ratesight/v1', '/redirect', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_redirect' ),
				'permission_callback' => array( $this, 'check_auth_signed' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle_redirect_delete' ),
				'permission_callback' => array( $this, 'check_auth_signed' ),
			),
		) );

		// Site capabilities — what this plugin+site combo supports.
		register_rest_route( 'ratesight/v1', '/capabilities', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_capabilities' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Live redirect set — the current explicit redirect map, so an external
		// audit can read real state (not a local log) and stay idempotent.
		register_rest_route( 'ratesight/v1', '/redirects', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_redirects_list' ),
			'permission_callback' => array( $this, 'check_auth' ),
		) );

		// Redirect serve log — all served redirects (explicit + fuzzy) since a timestamp.
		register_rest_route( 'ratesight/v1', '/redirects-log', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_redirects_log' ),
			'permission_callback' => array( $this, 'check_auth' ),
			'args'                => array(
				'since' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
				'limit' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 100,
				),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// =========================================================================
	// ✅ TO ENABLE LICENSE ENFORCEMENT (when Cloudflare Worker /webhook is live)
	//
	// 1. Set LICENSE_ENFORCEMENT = true below
	// 2. That's it — the Worker already handles the license check before
	//    forwarding here. The IP allowlist ensures only the Worker can call in.
	//
	// The Worker (cloudflare-worker-webhook-proxy.js) must be deployed to
	// oauth.ratesight.com/webhook with the LICENSES KV namespace bound.
	// =========================================================================

	private const LICENSE_ENFORCEMENT = false; // ← flip to true when Worker is live

	// Cloudflare Worker egress IPs + localhost for the admin test button.
	// Only used when LICENSE_ENFORCEMENT = true.
	private const ALLOWED_IPS = array(
		'67.199.171.42',
		'67.199.171.43',
		'67.199.171.44',
		'67.199.171.45',
		'67.199.171.46',
		'209.90.88.39',
		'209.90.88.40',
		'127.0.0.1',
		'::1',
	);

	public function check_auth( \WP_REST_Request $request ): bool|WP_Error {
		// Content + read endpoints (create/update page, capabilities, redirect
		// listing). In production these are gated by the Worker's license + IP
		// allowlist (LICENSE_ENFORCEMENT); when it's off they accept the request —
		// the long-standing behaviour. A mismatched *optional* X-Ratesight-
		// Signature must NOT hard-block create-page: that silently dropped posts
		// (rejected before any activity-log entry). Redirect *mutations* still
		// require a valid signature via the stricter check_auth_signed().
		if ( ! self::LICENSE_ENFORCEMENT ) {
			return true;
		}

		$caller_ip = $this->get_caller_ip();
		if ( ! in_array( $caller_ip, self::ALLOWED_IPS, true ) ) {
			return new \WP_Error(
				'rs_ip_blocked',
				'Forbidden: your IP address is not permitted.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Strict permission callback for routing-mutation endpoints (set/delete
	 * redirect). Unlike check_auth — which stays lenient for backwards
	 * compatibility and IP-allowlisted Worker traffic — this fails closed:
	 * it REQUIRES a configured secret AND a valid HMAC signature. A site with
	 * no secret set rejects these writes outright rather than allowing anyone
	 * who knows the route to alter or delete redirects.
	 */
	public function check_auth_signed( \WP_REST_Request $request ): bool|WP_Error {
		$secret = get_option( 'ratesight_webhook_secret', '' );
		if ( $secret === '' ) {
			return new \WP_Error(
				'rs_secret_required',
				'Forbidden: this operation requires a configured webhook secret (Settings → Webhook). Unsigned redirect changes are not permitted.',
				array( 'status' => 403 )
			);
		}

		$sig_header = $request->get_header( 'x_ratesight_signature' ) ?? '';
		if ( $sig_header === '' ) {
			return new \WP_Error(
				'rs_signature_required',
				'Forbidden: X-Ratesight-Signature header is required for this operation.',
				array( 'status' => 403 )
			);
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $sig_header ) ) {
			return new \WP_Error(
				'rs_bad_signature',
				'Forbidden: invalid X-Ratesight-Signature.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Main handler
	// -------------------------------------------------------------------------

	/**
	 * DELETE /wp-json/ratesight/v1/create-page
	 * Body: { url } OR { slug }
	 * Permanently deletes a single RS page (or any post type) by URL or slug.
	 */
	public function handle_delete_page( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params() ?: $request->get_body_params();

		$post_id = 0;

		// Resolve by URL first, then slug.
		if ( ! empty( $data['url'] ) ) {
			$post_id = $this->resolve_post_id( esc_url_raw( $data['url'] ) );
		} elseif ( ! empty( $data['slug'] ) ) {
			$slug    = sanitize_title( $data['slug'] );
			$post    = get_page_by_path( $slug, OBJECT, array( 'ratesight_page', 'page', 'post' ) );
			$post_id = $post ? (int) $post->ID : 0;
		}

		if ( ! $post_id ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => 'No post found for the given url or slug.',
			), 404 );
		}

		$title    = get_the_title( $post_id );
		$deleted  = wp_delete_post( $post_id, true ); // true = skip trash

		if ( ! $deleted ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => "Failed to delete post #{$post_id}.",
			), 500 );
		}

		Ratesight_Logger::log_update(
			Ratesight_Logger::log_pending( "Deleted: {$title}", '', wp_json_encode( $data ) ),
			$post_id,
			Ratesight_Logger::STATUS_MODIFIED,
			"Post #{$post_id} ({$title}) permanently deleted via API."
		);

		return new \WP_REST_Response( array(
			'ok'      => true,
			'deleted' => true,
			'id'      => $post_id,
			'title'   => $title,
		), 200 );
	}

	public function handle_request( \WP_REST_Request $request ) {
		try {
			return $this->do_handle_request( $request );
		} catch ( \Throwable $e ) {
			$message = 'create-page fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
			Ratesight_Logger::log_error( $message, null, '', $request->get_body() );
			return new \WP_REST_Response( array( 'ok' => false, 'message' => $message ), 500 );
		}
	}

	private function do_handle_request( \WP_REST_Request $request ) {
		// get_sample_permalink() and other admin helpers aren't loaded during REST requests.
		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$raw_payload = $request->get_body();
		$data        = $request->get_json_params() ?: $request->get_body_params();

		// 1. Validate.
		$validation = $this->validate_payload( $data );
		if ( is_wp_error( $validation ) ) {
			Ratesight_Logger::log_error( $validation->get_error_message(), null, '', $raw_payload );
			return new \WP_REST_Response( array( 'ok' => false, 'message' => $validation->get_error_message() ), 422 );
		}

		// 2. Sanitise all incoming fields.
		// Accept content_html (tool contract) OR article (legacy field name).
		$title               = sanitize_text_field( $data['title'] );
		$slug                = ! empty( $data['slug'] )               ? sanitize_title( $data['slug'] )                     : sanitize_title( $title );
		$summary             = ! empty( $data['summary'] )            ? sanitize_textarea_field( $data['summary'] )          : '';
		$meta_title          = ! empty( $data['meta_title'] )         ? sanitize_text_field( $data['meta_title'] )           : $title;
		$meta_description    = ! empty( $data['meta_description'] )   ? sanitize_textarea_field( $data['meta_description'] ) : $summary;
		$featured_image_url  = ! empty( $data['featured_image_url'] ) ? esc_url_raw( $data['featured_image_url'] )           : '';
		$featured_image_name = ! empty( $data['featured_image_name'] )? sanitize_file_name( $data['featured_image_name'] )  : '';
		$custom_css_url      = ! empty( $data['custom_css_url'] )     ? esc_url_raw( $data['custom_css_url'] )               : '';
		$child_category      = ! empty( $data['child_category'] )     ? sanitize_text_field( $data['child_category'] )      : '';
		$parent_category     = ! empty( $data['parent_category'] )    ? sanitize_text_field( $data['parent_category'] )     : '';
		$content_html        = ! empty( $data['content_html'] )       ? $data['content_html']                               : ( $data['article'] ?? '' );
		$article             = wp_kses_post( $content_html );

		// post_type mapping:
		//   "rs_page" | "page" → ratesight_page (RS custom post type)
		//   "post"             → standard blog post
		//   omitted            → ratesight_page (default for 404-recovery)
		$raw_post_type = strtolower( trim( $data['post_type'] ?? 'rs_page' ) );
		$post_type     = in_array( $raw_post_type, array( 'rs_page', 'page' ), true ) ? 'ratesight_page' : 'post';

		// Guard: ensure ratesight_page CPT is registered before trying to use it.
		// The CPT registers on init priority 1 but in some REST contexts it may not
		// have fired yet — force-register if needed.
		if ( $post_type === 'ratesight_page' && ! post_type_exists( 'ratesight_page' ) ) {
			if ( class_exists( 'Ratesight_CPT' ) ) {
				( new Ratesight_CPT() )->register();
			} else {
				return new \WP_REST_Response( array(
					'ok'      => false,
					'message' => 'ratesight_page post type is not registered. Ensure the plugin is fully active.',
				), 500 );
			}
		}

		$layout_default = $post_type === 'ratesight_page'
			? (string) Ratesight_Options::get( 'default_page_layout' )
			: (string) Ratesight_Options::get( 'default_layout' );
		$layout              = ! empty( $data['layout'] )    ? sanitize_key( $data['layout'] )    : $layout_default;
		$raw_show_title      = isset( $data['show_title'] ) ? $data['show_title'] : (bool) Ratesight_Options::get( 'default_show_title' );
		$show_title          = filter_var( $raw_show_title, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true;
		$parent_slug         = ! empty( $data['parent_slug'] ) ? sanitize_title( $data['parent_slug'] ) : '';
		$valid_statuses      = array( 'publish', 'draft', 'pending', 'private' );
		$request_status      = ! empty( $data['status'] ) && in_array( $data['status'], $valid_statuses, true ) ? $data['status'] : 'draft';
		$dry_run             = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;

		// 3. Duplicate slug — update the existing post instead of rejecting.
		// Pass "update": false in the payload to force-create a new post instead.
		$force_create = isset( $data['update'] ) && $data['update'] === false;
		$existing     = get_page_by_path( $slug, OBJECT, $post_type );

		if ( $existing && ! $force_create ) {
			$post_id = $existing->ID;

			// dry_run: report what would happen without writing.
			if ( $dry_run ) {
				$content_hash = md5( $existing->post_content . $existing->post_title . $existing->post_excerpt );
				[ $pt, $pn ]  = get_sample_permalink( $post_id );
				return new \WP_REST_Response( array(
					'ok'           => true,
					'dry_run'      => true,
					'created'      => false,
					'updated'      => true,
					'id'           => $post_id,
					'url'          => str_replace( array( '%pagename%', '%postname%' ), $pn, $pt ),
					'content_hash' => $content_hash,
					'page_builder' => $this->detect_builder( $post_id, $existing->post_content )['name'],
				), 200 );
			}

			wp_update_post( array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $article,
				'post_excerpt' => $summary,
			) );

			( new Ratesight_SEO_Writer() )->write( $post_id, $meta_title, $meta_description );
			( new Ratesight_Layout_Writer() )->write( $post_id, $layout );
			( new Ratesight_Title_Writer() )->write( $post_id, $show_title );
			if ( $custom_css_url !== '' ) {
				update_post_meta( $post_id, '_rs_custom_css_url', $custom_css_url );
			}
			if ( $post_type === 'ratesight_page' && $child_category !== '' ) {
				$rs_parent_id = (int) Ratesight_Options::get( 'rs_page_parent_category' );
				$rs_term_id   = ( new Ratesight_RS_Category_Handler() )->resolve( $child_category, $rs_parent_id, $parent_category );
				if ( ! is_wp_error( $rs_term_id ) && $rs_term_id > 0 ) {
					wp_set_object_terms( $post_id, $rs_term_id, 'rs_category' );
				}
			}

			Ratesight_Link_Manager::reapply_manual_links( $post_id );

			$log_id = Ratesight_Logger::log_pending( $title, $child_category, $raw_payload );
			Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_MODIFIED, 'Updated existing post.' );

			wp_schedule_single_event( time() + 15, 'ratesight_deferred_publish', array(
				$post_id, $log_id, $featured_image_url, $featured_image_name, $title, $request_status,
			) );
			spawn_cron();

			$updated_post = get_post( $post_id );
			$content_hash = md5( $updated_post->post_content . $updated_post->post_title . $updated_post->post_excerpt );
			[ $permalink_template, $postname ] = get_sample_permalink( $post_id );
			$expected_url = str_replace( array( '%pagename%', '%postname%' ), $postname, $permalink_template );

			return new \WP_REST_Response( array(
				'ok'           => true,
				'created'      => false,
				'updated'      => true,
				'id'           => $post_id,
				'url'          => $expected_url,
				'content_hash' => $content_hash,
			), 200 );
		}

		// 4. Resolve parent page (ratesight_page only).
		$post_parent  = 0;
		$parent_notes = array();
		if ( $post_type === 'ratesight_page' && $parent_slug !== '' ) {
			$parent_page = get_page_by_path( $parent_slug, OBJECT, 'ratesight_page' );
			// Fall back to native pages if no ratesight_page parent found.
			if ( ! $parent_page ) {
				$parent_page = get_page_by_path( $parent_slug, OBJECT, 'page' );
			}
			if ( $parent_page ) {
				$post_parent = $parent_page->ID;
			} else {
				$parent_notes[] = "Parent slug \"{$parent_slug}\" not found — page created at root level.";
			}
		}

		// 5. Resolve category (posts only — skip for ratesight_page).
		$category_id = 0;
		if ( $post_type === 'post' && class_exists( 'Ratesight_Category_Handler' ) ) {
			$effective_parent = ! empty( $parent_category )
				? $parent_category
				: (string) get_term( (int) Ratesight_Options::get( 'parent_category' ) )?->name ?? '';
			$parent_id   = (int) Ratesight_Options::get( 'parent_category' );
			$category_id = ( new Ratesight_Category_Handler() )->resolve( $child_category, $parent_id, $effective_parent );
			if ( is_wp_error( $category_id ) ) {
				Ratesight_Logger::log_error( $category_id->get_error_message(), null, $title, $raw_payload );
				return new \WP_REST_Response( array( 'ok' => false, 'message' => $category_id->get_error_message() ), 500 );
			}
		}

		// 5b. Resolve RS Category for ratesight_page (separate taxonomy: rs_category).
		$rs_term_id = 0;
		if ( $post_type === 'ratesight_page' && $child_category !== '' && class_exists( 'Ratesight_RS_Category_Handler' ) ) {
			$rs_parent_id = (int) Ratesight_Options::get( 'rs_page_parent_category' );
			$rs_term_id   = ( new Ratesight_RS_Category_Handler() )->resolve( $child_category, $rs_parent_id, $parent_category );
			if ( is_wp_error( $rs_term_id ) ) {
				$rs_term_id = 0; // Non-fatal — continue without category.
			}
		}

		// 6. Create the post as a draft — publisher promotes it after image attaches.
		// dry_run: predict without writing.
		if ( $dry_run ) {
			return new \WP_REST_Response( array(
				'ok'           => true,
				'dry_run'      => true,
				'created'      => true,
				'updated'      => false,
				'id'           => null,
				'url'          => home_url( '/' . $slug . '/' ),
				'content_hash' => null,
				'page_builder' => 'classic',
			), 200 );
		}

		// Log pending first so we have a log_id to pass to the cron job.
		$log_id = Ratesight_Logger::log_pending( $title, $child_category, $raw_payload );

		$post_id = ( new Ratesight_Post_Creator() )->create( array(
			'title'       => $title,
			'slug'        => $slug,
			'summary'     => $summary,
			'article'     => $article,
			'post_type'   => $post_type,
			'post_parent' => $post_parent,
			'category_id' => $category_id,
		) );

		if ( is_wp_error( $post_id ) ) {
			Ratesight_Logger::log_update( $log_id, 0, Ratesight_Logger::STATUS_FAILED, $post_id->get_error_message() );
			return new \WP_REST_Response( array( 'ok' => false, 'message' => $post_id->get_error_message() ), 500 );
		}

		// 7. Write SEO, layout, and title meta synchronously — these are fast DB writes.
		( new Ratesight_SEO_Writer() )->write( $post_id, $meta_title, $meta_description );
		( new Ratesight_Layout_Writer() )->write( $post_id, $layout );
		( new Ratesight_Title_Writer() )->write( $post_id, $show_title );
		if ( $custom_css_url !== '' ) {
			update_post_meta( $post_id, '_rs_custom_css_url', $custom_css_url );
		}
		if ( $rs_term_id > 0 ) {
			wp_set_object_terms( $post_id, $rs_term_id, 'rs_category' );
		}

		// 8. Schedule deferred image download + publish (~15 seconds from now).
		wp_schedule_single_event( time() + 15, 'ratesight_deferred_publish', array(
			$post_id, $log_id, $featured_image_url, $featured_image_name, $title, $request_status,
		) );
		spawn_cron();

		// 9. Build the expected permalink.
		[ $permalink_template, $postname ] = get_sample_permalink( $post_id );
		$expected_url = str_replace( array( '%pagename%', '%postname%' ), $postname, $permalink_template );

		if ( ! empty( $parent_notes ) ) {
			Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_PENDING, implode( ' | ', $parent_notes ) );
		}

		$new_post     = get_post( $post_id );
		$content_hash = md5( $new_post->post_content . $new_post->post_title . $new_post->post_excerpt );

		Ratesight_Recovery_Log::log( 'recreate', home_url( '/' . $slug . '/' ), $expected_url, [ 'post_id' => $post_id, 'title' => $title ] );

		return new \WP_REST_Response( array(
			'ok'           => true,
			'created'      => true,
			'updated'      => false,
			'id'           => $post_id,
			'url'          => $expected_url,
			'content_hash' => $content_hash,
		), 200 );
	} // end do_handle_request

	// -------------------------------------------------------------------------
	// Shared: resolve URL → post ID
	// -------------------------------------------------------------------------

	private function resolve_post_id( string $url ): int {
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			$slug    = basename( rtrim( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/' ) );
			$post_id = $slug ? (int) get_page_by_path( $slug, OBJECT, array( 'ratesight_page', 'page', 'post' ) )?->ID : 0;
		}
		return $post_id;
	}

	// -------------------------------------------------------------------------
	// Shared: detect page builder
	// -------------------------------------------------------------------------

	private function detect_builder( int $post_id, string $content ): array {
		if ( str_contains( $content, '[et_pb_section' ) || str_contains( $content, '[et_pb_row' ) ) {
			return array(
				'name'             => 'divi',
				'update_content'   => true,
				'update_seo'       => true,
				'update_layout'    => true,
				'content_format'   => 'Divi shortcode string (e.g. [et_pb_section]…[/et_pb_section])',
				'note'             => null,
			);
		}
		if ( get_post_meta( $post_id, '_elementor_data', true ) ) {
			return array(
				'name'             => 'elementor',
				'update_content'   => false,
				'update_seo'       => true,
				'update_layout'    => false,
				'content_format'   => null,
				'note'             => 'Elementor stores page content in its own meta, not post_content. Content updates are not supported — only SEO fields (meta_title, meta_description) can be safely written.',
			);
		}
		if ( get_post_meta( $post_id, '_fl_builder_data', true ) ) {
			return array(
				'name'             => 'beaver_builder',
				'update_content'   => false,
				'update_seo'       => true,
				'update_layout'    => false,
				'content_format'   => null,
				'note'             => 'Beaver Builder stores page content in its own meta, not post_content. Content updates are not supported — only SEO fields can be safely written.',
			);
		}
		if ( str_contains( $content, '[vc_row' ) || str_contains( $content, '[vc_section' ) ) {
			return array(
				'name'             => 'wpbakery',
				'update_content'   => true,
				'update_seo'       => true,
				'update_layout'    => true,
				'content_format'   => 'WPBakery shortcode string (e.g. [vc_row][vc_column]…[/vc_column][/vc_row])',
				'note'             => null,
			);
		}
		if ( str_contains( $content, '<!-- wp:' ) ) {
			return array(
				'name'             => 'gutenberg',
				'update_content'   => true,
				'update_seo'       => true,
				'update_layout'    => true,
				'content_format'   => 'Gutenberg block markup (HTML with <!-- wp: --> block comments)',
				'note'             => null,
			);
		}
		return array(
			'name'             => 'classic',
			'update_content'   => true,
			'update_seo'       => true,
			'update_layout'    => true,
			'content_format'   => 'Plain HTML',
			'note'             => null,
		);
	}

	// -------------------------------------------------------------------------
	// Shared: purge page caches
	// -------------------------------------------------------------------------

	private function purge_cache( int $post_id ): void {
		clean_post_cache( $post_id );
		// WP Rocket
		if ( function_exists( 'rocket_clean_post' ) ) rocket_clean_post( $post_id );
		// LiteSpeed Cache
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_post' ) ) w3tc_flush_post( $post_id );
		// WP Super Cache
		if ( function_exists( 'wp_cache_post_change' ) ) wp_cache_post_change( $post_id );
		// Elementor (clear its generated CSS/files)
		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		// Generic WordPress hooks other caching plugins listen on
		do_action( 'flush_post_cache', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	// -------------------------------------------------------------------------
	// Read-by-URL handler (GET /update-page?url=…)
	// -------------------------------------------------------------------------

	public function handle_read_by_url( \WP_REST_Request $request ) {
		$lookup_url = $request->get_param( 'url' );
		$post_id    = $this->resolve_post_id( $lookup_url );

		if ( ! $post_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => "No published post found at: {$lookup_url}" ), 404 );
		}

		$post    = get_post( $post_id );
		$content = $post->post_content;
		$builder = $this->detect_builder( $post_id, $content );

		// content_hash: caller sends this back in POST as expected_hash to detect
		// conflicts (someone edited the page between GET and POST).
		$content_hash = md5( $content . $post->post_title . $post->post_excerpt );

		// SEO fields.
		$seo = Ratesight_SEO_Writer::read( $post_id );

		$thumb_id  = get_post_thumbnail_id( $post_id );

		// Elementor data only returned when that's the active builder.
		$elementor_data = $builder['name'] === 'elementor'
			? json_decode( get_post_meta( $post_id, '_elementor_data', true ), true )
			: null;

		return new \WP_REST_Response( array(
			'success'      => true,
			'post_id'      => $post_id,
			'url'          => get_permalink( $post_id ),
			'post_title'   => $post->post_title,
			'slug'         => $post->post_name,
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'content_hash' => $content_hash,

			// What the platform can actually do on this page.
			// Check these before attempting a POST.
			'capabilities' => array(
				'update_content' => $builder['update_content'],
				'update_seo'     => $builder['update_seo'],
				'update_layout'  => $builder['update_layout'],
				'content_format' => $builder['content_format'],
				'note'           => $builder['note'],
			),

			// Current page state.
			'page_builder'      => $builder['name'],
			'post_content'      => $content,
			'elementor_data'    => $elementor_data,
			'summary'           => $post->post_excerpt,
			'seo_plugin'        => $seo['source'],
			'meta_title'        => $seo['meta_title'],
			'meta_description'  => $seo['meta_description'],
			'layout'            => get_post_meta( $post_id, '_rs_layout', true ) ?: '',
			'show_title'        => (bool) get_post_meta( $post_id, '_rs_show_title', true ),
			'custom_css_url'    => get_post_meta( $post_id, '_rs_custom_css_url', true ) ?: '',
			'featured_image_url'=> $thumb_id ? wp_get_attachment_url( $thumb_id ) : '',
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Update-by-URL handler (POST /update-page)
	// -------------------------------------------------------------------------

	/**
	 * POST /wp-json/ratesight/v1/update-page
	 *
	 * Requires "url" to identify the page. Only updates fields explicitly present
	 * in the payload. Rejects if the page builder doesn't support content updates.
	 * Optionally validates a content_hash to detect conflicts.
	 */
	public function handle_update_by_url( \WP_REST_Request $request ) {
		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$raw_payload = $request->get_body();
		$data        = $request->get_json_params() ?: $request->get_body_params();

		if ( empty( $data['url'] ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Required field "url" is missing.' ), 422 );
		}

		$lookup_url = esc_url_raw( $data['url'] );
		$post_id    = $this->resolve_post_id( $lookup_url );

		if ( ! $post_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => "No published post found at: {$lookup_url}" ), 404 );
		}

		$post    = get_post( $post_id );
		$content = $post->post_content;
		$builder = $this->detect_builder( $post_id, $content );

		// ── Conflict detection ────────────────────────────────────────────────
		// If the caller sends expected_hash (from a prior GET), verify it still
		// matches. A mismatch means the page was edited since the GET.
		if ( ! empty( $data['expected_hash'] ) ) {
			$current_hash = md5( $content . $post->post_title . $post->post_excerpt );
			if ( ! hash_equals( $current_hash, (string) $data['expected_hash'] ) ) {
				return new \WP_REST_Response( array(
					'success' => false,
					'message' => 'Conflict: page content has changed since the GET. Re-fetch before updating.',
					'current_hash' => $current_hash,
				), 409 );
			}
		}

		// ── Capability checks ─────────────────────────────────────────────────
		$wants_content = array_key_exists( 'article', $data ) || array_key_exists( 'post_content', $data );
		$wants_layout  = array_key_exists( 'layout', $data ) || array_key_exists( 'show_title', $data );

		if ( $wants_content && ! $builder['update_content'] ) {
			return new \WP_REST_Response( array(
				'success'      => false,
				'page_builder' => $builder['name'],
				'message'      => "Content updates are not supported for {$builder['name']} pages. " . $builder['note'],
				'capabilities' => array(
					'update_content' => false,
					'update_seo'     => $builder['update_seo'],
					'update_layout'  => $builder['update_layout'],
				),
			), 422 );
		}

		if ( $wants_layout && ! $builder['update_layout'] ) {
			return new \WP_REST_Response( array(
				'success'      => false,
				'page_builder' => $builder['name'],
				'message'      => "Layout updates are not supported for {$builder['name']} pages. " . $builder['note'],
			), 422 );
		}

		// ── Snapshot before write (rollback reference) ────────────────────────
		$seo_title_before = get_post_meta( $post_id, '_yoast_wpseo_title', true )
			?: get_post_meta( $post_id, 'rank_math_title', true ) ?: '';
		$seo_desc_before  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
			?: get_post_meta( $post_id, 'rank_math_description', true ) ?: '';

		update_post_meta( $post_id, '_rs_pre_update_snapshot', array(
			'post_content'    => $content,
			'post_title'      => $post->post_title,
			'post_excerpt'    => $post->post_excerpt,
			'meta_title'      => $seo_title_before,
			'meta_description'=> $seo_desc_before,
			'snapshot_at'     => current_time( 'mysql' ),
		) );

		// ── Partial updates — only write fields explicitly in the payload ──────
		$post_data = array( 'ID' => $post_id );
		if ( array_key_exists( 'title', $data ) )       $post_data['post_title']   = sanitize_text_field( $data['title'] );
		if ( array_key_exists( 'article', $data ) )     $post_data['post_content'] = wp_kses_post( $data['article'] );
		if ( array_key_exists( 'summary', $data ) )     $post_data['post_excerpt'] = sanitize_textarea_field( $data['summary'] );
		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}

		// SEO fields — only if sent. write() returns what was actually stored.
		$seo_written = null;
		$meta_title       = array_key_exists( 'meta_title', $data )       ? sanitize_text_field( $data['meta_title'] ) : null;
		$meta_description = array_key_exists( 'meta_description', $data ) ? sanitize_textarea_field( $data['meta_description'] ) : null;
		if ( $meta_title !== null || $meta_description !== null ) {
			$writer = new Ratesight_SEO_Writer();
			$seo_written = $writer->write(
				$post_id,
				$meta_title       ?? $seo_title_before,
				$meta_description ?? $seo_desc_before
			);
		}

		// Layout / display — only if sent.
		if ( array_key_exists( 'layout', $data ) ) {
			( new Ratesight_Layout_Writer() )->write( $post_id, sanitize_key( $data['layout'] ) );
		}
		if ( array_key_exists( 'show_title', $data ) ) {
			$show_title = filter_var( $data['show_title'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true;
			( new Ratesight_Title_Writer() )->write( $post_id, $show_title );
		}
		if ( ! empty( $data['custom_css_url'] ) ) {
			update_post_meta( $post_id, '_rs_custom_css_url', esc_url_raw( $data['custom_css_url'] ) );
		}

		// Category (RS pages only) — only if sent.
		if ( ! empty( $data['child_category'] ) && get_post_type( $post_id ) === 'ratesight_page' ) {
			$rs_parent_id = (int) Ratesight_Options::get( 'rs_page_parent_category' );
			$rs_term_id   = ( new Ratesight_RS_Category_Handler() )->resolve(
				sanitize_text_field( $data['child_category'] ),
				$rs_parent_id,
				sanitize_text_field( $data['parent_category'] ?? '' )
			);
			if ( ! is_wp_error( $rs_term_id ) && $rs_term_id > 0 ) {
				wp_set_object_terms( $post_id, $rs_term_id, 'rs_category' );
			}
		}

		Ratesight_Link_Manager::reapply_manual_links( $post_id );

		// ── Cache purge ───────────────────────────────────────────────────────
		$this->purge_cache( $post_id );

		// ── Deferred image + publish ──────────────────────────────────────────
		$featured_image_url  = ! empty( $data['featured_image_url'] )  ? esc_url_raw( $data['featured_image_url'] )          : '';
		$featured_image_name = ! empty( $data['featured_image_name'] ) ? sanitize_file_name( $data['featured_image_name'] )  : '';
		$valid_statuses      = array( 'publish', 'draft', 'pending', 'private' );
		$request_status      = ! empty( $data['status'] ) && in_array( $data['status'], $valid_statuses, true ) ? $data['status'] : '';

		$log_title = $post_data['post_title'] ?? $post->post_title;
		$log_id    = Ratesight_Logger::log_pending( $log_title, $data['child_category'] ?? '', $raw_payload );
		Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_MODIFIED, "Updated via URL: {$lookup_url}" );

		wp_schedule_single_event( time() + 15, 'ratesight_deferred_publish', array(
			$post_id,
			$log_id,
			$featured_image_url,
			$featured_image_name,
			$log_title,
			$request_status,
		) );
		spawn_cron();

		[ $permalink_template, $postname ] = get_sample_permalink( $post_id );
		$expected_url = str_replace( array( '%pagename%', '%postname%' ), $postname, $permalink_template );

		return new \WP_REST_Response( array(
			'success'      => true,
			'updated'      => true,
			'post_id'      => $post_id,
			'post_url'     => $expected_url,
			'page_builder' => $builder['name'],
			'seo_plugin'   => Ratesight_SEO_Writer::active_plugin(),
			'seo_stored'   => $seo_written, // [ meta_title, meta_description, source ] — verify without a second GET
			'message'      => "Post #{$post_id} updated via URL.",
		), 200 );
	}

	// -------------------------------------------------------------------------
	// POST /redirect
	// -------------------------------------------------------------------------

	/**
	 * DELETE /wp-json/ratesight/v1/redirect
	 * Body: { from }
	 * Removes a redirect from all systems (native + Redirection plugin if active).
	 * Used to clean up stale or incorrect redirects.
	 */
	public function handle_redirect_delete( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params() ?: $request->get_body_params();
		$from = sanitize_text_field( $data['from'] ?? '' );

		if ( $from === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Required field "from" is missing.' ), 422 );
		}

		// Normalise to /slug/ format.
		$key    = '/' . trim( wp_parse_url( $from, PHP_URL_PATH ) ?? trim( $from, '/' ), '/' ) . '/';
		$removed = false;

		// Native.
		$redirects = get_option( 'ratesight_rs_redirects', array() );
		foreach ( array( $key, trim( $key, '/' ), ltrim( $key, '/' ), rtrim( $key, '/' ) ) as $try ) {
			if ( isset( $redirects[ $try ] ) ) {
				unset( $redirects[ $try ] );
				$removed = true;
			}
		}
		if ( $removed ) {
			update_option( 'ratesight_rs_redirects', $redirects, false );
		}

		// Redirection plugin.
		if ( class_exists( 'Red_Item' ) ) {
			try {
				$item = \Red_Item::get_for_url( $key );
				if ( $item ) $item->delete();
			} catch ( \Throwable $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}

		return new \WP_REST_Response( array( 'ok' => true, 'removed' => $removed, 'from' => $key ), 200 );
	}

	/**
	 * POST /wp-json/ratesight/v1/redirect
	 * Body: { from, to, code: 301|302, dry_run? }
	 * Idempotent by `from`. Same-site `to` only. Tries redirect systems in order:
	 * Redirection plugin → Rank Math → Yoast Premium → native (ratesight_rs_redirects).
	 */
	public function handle_redirect( \WP_REST_Request $request ): \WP_REST_Response {
		$data    = $request->get_json_params() ?: $request->get_body_params();

		// Delete shape: { from, delete:true } — delegate to the DELETE handler so
		// callers that can't send an HTTP DELETE can still remove a redirect.
		if ( ! empty( $data['delete'] ) ) {
			return $this->handle_redirect_delete( $request );
		}

		$from    = sanitize_text_field( $data['from'] ?? '' );
		$to      = sanitize_text_field( $data['to']   ?? '' );
		$code    = in_array( (int) ( $data['code'] ?? 301 ), array( 301, 302 ), true ) ? (int) $data['code'] : 301;
		$dry_run = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;

		if ( $from === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Required field "from" is missing.' ), 422 );
		}
		if ( $to === '' ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'Required field "to" is missing.' ), 422 );
		}

		// Normalise `from` to a path.
		$from = '/' . ltrim( wp_parse_url( $from, PHP_URL_PATH ) ?? ltrim( $from, '/' ), '/' );

		// Validate `to` — refuse open redirects (different domain).
		if ( str_starts_with( $to, 'http' ) ) {
			$to_host   = strtolower( wp_parse_url( $to, PHP_URL_HOST ) ?? '' );
			$site_host = strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' );
			if ( $to_host !== $site_host ) {
				return new \WP_REST_Response( array(
					'ok'      => false,
					'message' => "Open redirect refused: {$to_host} is not this site ({$site_host}). Only same-site redirects are allowed.",
				), 422 );
			}
		}

		$method = $this->detect_redirect_method();

		if ( $dry_run ) {
			return new \WP_REST_Response( array(
				'ok'      => true,
				'dry_run' => true,
				'applied' => false,
				'method'  => $method,
				'from'    => $from,
				'to'      => $to,
				'code'    => $code,
			), 200 );
		}

		$applied = $this->write_redirect( $from, $to, $code, $method );

		Ratesight_Logger::log_update(
			Ratesight_Logger::log_pending( "Redirect: {$from} → {$to}", '', wp_json_encode( $data ) ),
			0,
			Ratesight_Logger::STATUS_MODIFIED,
			"Redirect set via API ({$method}): {$code} {$from} → {$to}"
		);

		Ratesight_Recovery_Log::log( 'redirect', $from, $to, [ 'code' => $code, 'method' => $method ] );

		return new \WP_REST_Response( array(
			'ok'      => $applied,
			'applied' => $applied,
			'method'  => $method,
		), $applied ? 200 : 500 );
	}

	/**
	 * Detect which redirect system is available (first match wins).
	 */
	private function detect_redirect_method(): string {
		// Redirection plugin (John Godley).
		if ( class_exists( 'Red_Item' ) || function_exists( 'red_get_table_name' ) ) return 'redirection';
		// Rank Math Redirections module.
		if ( class_exists( 'RankMath\\Redirections\\Redirections' ) ) return 'rankmath';
		// Yoast SEO Premium redirects.
		if ( class_exists( 'WPSEO_Redirect' ) ) return 'yoast_premium';
		// Native: plugin's own ratesight_rs_redirects option.
		return 'native';
	}

	/**
	 * Write the redirect using the detected method. Idempotent (upsert by `from`).
	 */
	private function write_redirect( string $from, string $to, int $code, string $method ): bool {
		switch ( $method ) {
			case 'redirection':
				try {
					$existing = \Red_Item::get_for_url( $from );
					if ( $existing ) {
						$existing->update( array( 'url' => $from, 'action_data' => array( 'url' => $to ), 'action_code' => $code ) );
					} else {
						\Red_Item::create( array(
							'url'         => $from,
							'action_type' => 'url',
							'action_data' => array( 'url' => $to ),
							'action_code' => $code,
							'group_id'    => 1,
						) );
					}
					return true;
				} catch ( \Throwable $e ) {
					// Redirection API call failed — fall through to native.
					return $this->write_redirect( $from, $to, $code, 'native' );
				}

			case 'rankmath':
				try {
					global $wpdb;
					$table    = $wpdb->prefix . 'rank_math_redirections';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE sources LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $from ) . '%' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sources  = wp_json_encode( array( array( 'pattern' => $from, 'comparison' => 'exact' ) ) );
					if ( $existing ) {
						$wpdb->update( $table, array( 'url_to' => $to, 'header_code' => $code, 'sources' => $sources ), array( 'id' => (int) $existing ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					} else {
						$wpdb->insert( $table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
							'sources'     => $sources,
							'url_to'      => $to,
							'header_code' => $code,
							'status'      => 'active',
							'created'     => current_time( 'mysql' ),
							'updated'     => current_time( 'mysql' ),
						) );
					}
					return true;
				} catch ( \Throwable $e ) {
					return $this->write_redirect( $from, $to, $code, 'native' );
				}

			case 'yoast_premium':
				try {
					$manager = new \WPSEO_Redirect_Manager();
					$manager->delete_url( $from );
					$redirect = new \WPSEO_Redirect( $from, $to, $code );
					$manager->save_url_redirect( $redirect );
					return true;
				} catch ( \Throwable $e ) {
					return $this->write_redirect( $from, $to, $code, 'native' );
				}

			case 'native':
			default:
				$redirects          = get_option( 'ratesight_rs_redirects', array() );
				// Normalise key to /slug/ format so handle_redirects always finds it.
				$key                = '/' . trim( $from, '/' ) . '/';
				$redirects[ $key ]  = array(
					'redirect_to' => $to,
					'code'        => $code,
					'set_by_api'  => true,
					'created_at'  => current_time( 'mysql' ),
				);
				update_option( 'ratesight_rs_redirects', $redirects, false );
				return true;
		}
	}

	// -------------------------------------------------------------------------
	// GET /capabilities
	// -------------------------------------------------------------------------

	/**
	 * GET /wp-json/ratesight/v1/redirects-log?since=&limit=
	 * Returns every redirect served (explicit + fuzzy) since the given ISO timestamp.
	 * Omit since= to get the last {limit} entries (default 100).
	 */
	/**
	 * GET /wp-json/ratesight/v1/redirects
	 * Returns the current explicit redirect map so an external audit can read
	 * live state and stay idempotent (rather than replaying a local set-only log).
	 */
	public function handle_redirects_list( \WP_REST_Request $request ): \WP_REST_Response {
		$map       = get_option( 'ratesight_rs_redirects', array() );
		$redirects = array();
		foreach ( (array) $map as $from => $entry ) {
			$redirects[] = array(
				'from' => (string) $from,
				'to'   => (string) ( $entry['redirect_to'] ?? '' ),
				'code' => (int) ( $entry['code'] ?? 301 ),
			);
		}
		return new \WP_REST_Response( array(
			'count'     => count( $redirects ),
			'redirects' => $redirects,
		), 200 );
	}

	public function handle_redirects_log( \WP_REST_Request $request ): \WP_REST_Response {
		$since   = sanitize_text_field( $request->get_param( 'since' ) ?? '' );
		$limit   = min( absint( $request->get_param( 'limit' ) ?: 100 ), 1000 );
		$entries = Ratesight_Redirect_Serve_Log::since( $since, $limit );

		return new \WP_REST_Response( array(
			'count'   => count( $entries ),
			'since'   => $since ?: null,
			'entries' => $entries,
		), 200 );
	}

	/**
	 * GET /wp-json/ratesight/v1/capabilities
	 * Returns what this site supports so the tool knows before trying anything.
	 * Response: { create_page, set_redirect, redirect_method, page_builder, can_recreate }
	 */
	public function handle_capabilities( \WP_REST_Request $request ): \WP_REST_Response {
		$redirect_method = $this->detect_redirect_method();

		// Detect dominant page builder from plugin activation, not page content.
		// (Content-level detection requires a specific page — this is site-level.)
		$page_builder = 'classic';
		$can_recreate = true;
		$active       = (array) get_option( 'active_plugins', array() );

		// Network-active plugins.
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		foreach ( $active as $plugin_file ) {
			$slug = dirname( $plugin_file );
			if ( in_array( $slug, array( 'elementor', 'elementor-pro' ), true ) || class_exists( '\Elementor\Plugin' ) ) {
				$page_builder = 'elementor';
				$can_recreate = false;
				break;
			}
			if ( in_array( $slug, array( 'beaver-builder-lite-version', 'bb-plugin' ), true ) || class_exists( 'FLBuilder' ) ) {
				$page_builder = 'beaver_builder';
				$can_recreate = false;
				break;
			}
			if ( in_array( $slug, array( 'divi-builder', 'Divi' ), true ) || function_exists( 'et_setup_theme' ) ) {
				$page_builder = 'divi';
				break;
			}
			if ( class_exists( 'WPBMap' ) || function_exists( 'vc_map' ) ) {
				$page_builder = 'wpbakery';
				break;
			}
		}
		// Gutenberg is always available but only dominant if no other builder detected.
		if ( $page_builder === 'classic' && function_exists( 'register_block_type' ) ) {
			$page_builder = 'gutenberg';
		}

		return new \WP_REST_Response( array(
			'create_page'          => true,
			'set_redirect'         => true,
			'delete_redirect'      => true,
			'list_redirects'       => true,
			'redirect_method'      => $redirect_method,
			'page_builder'         => $page_builder,
			'can_recreate'         => $can_recreate,
			'seo_plugin'           => Ratesight_SEO_Writer::active_plugin(),
			'update_page'          => true,
			'related_links'        => true,
			'runtime_404_routing'  => true,
			'runtime_404_threshold'=> Ratesight_Runtime_404_Router::THRESHOLD,
			'post_types'           => array(
				'rs_page' => 'Ratesight page (city/service landing pages) — default if post_type omitted',
				'post'    => 'Standard WordPress blog post',
			),
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function validate_payload( mixed $data ): bool|WP_Error {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error( 'rs_empty_payload', 'Request body is empty or not valid JSON.' );
		}
		if ( empty( $data['title'] ) ) {
			return new \WP_Error( 'rs_missing_field', 'Required field "title" is missing or empty.' );
		}
		// Body content: accept content_html (tool contract) or article (legacy
		// field name) — do_handle_request() aliases one to the other.
		if ( empty( $data['content_html'] ) && empty( $data['article'] ) ) {
			return new \WP_Error( 'rs_missing_field', 'Required field "content_html" (or "article") is missing or empty.' );
		}
		return true;
	}

	/**
	 * Re-process a stored raw payload without going through the HTTP/auth layer.
	 * Used by the admin retry action. Returns a WP_REST_Response or WP_Error.
	 *
	 * @param string $raw_payload  JSON string from the log row's raw_payload column.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function replay_payload( string $raw_payload ) {
		$request = new \WP_REST_Request( 'POST', '/ratesight/v1/create-page' );
		$request->set_body( $raw_payload );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( (array) json_decode( $raw_payload, true ) );

		$handler = new self();
		return $handler->handle_request( $request );
	}

	private function get_caller_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
		}
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}
}
