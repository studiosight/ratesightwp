<?php
/**
 * REST API: Page SEO read/write endpoints.
 *
 * GET  /wp-json/ratesight/v1/page?url=…
 *   Returns current seo_title, meta_description, post_title, slug, seo_plugin.
 *
 * POST /wp-json/ratesight/v1/page
 *   Body: { url, fields: { seo_title?, meta_description? }, dry_run?, reason? }
 *   Writes only the fields supplied. Returns before/after per field.
 *   Logs every write to the activity log.
 *
 * Auth: X-Ratesight-Key header matched against ratesight_api_key option.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Page_API {

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/page';

	// ── SEO plugin meta key map ───────────────────────────────────────────────
	// Each adapter: [ title_key, description_key ]
	private static array $adapters = array(
		'yoast'    => array( '_yoast_wpseo_title',    '_yoast_wpseo_metadesc'    ),
		'rankmath' => array( 'rank_math_title',        'rank_math_description'    ),
		'aioseo'   => array( '_aioseo_title',          '_aioseo_description'      ),
		'squirrly' => array( '_sq_title',              '_sq_description'          ),
	);

	// ── Route registration ────────────────────────────────────────────────────

	public function register_routes(): void {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => 'Full page URL to look up.',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'url'     => array( 'required' => true, 'type' => 'string' ),
					'fields'  => array( 'required' => true, 'type' => 'object' ),
					'dry_run' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
					'reason'  => array( 'required' => false, 'type' => 'string', 'default' => '' ),
				),
			),
		) );
	}

	// ── Auth ──────────────────────────────────────────────────────────────────

	public function check_auth( WP_REST_Request $request ): bool|WP_Error {
		// HTTPS-only in production.
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'rs_https_required', 'HTTPS required.', array( 'status' => 403 ) );
		}

		$stored_key = trim( (string) get_option( 'ratesight_api_key', '' ) );
		if ( $stored_key === '' ) {
			return new WP_Error( 'rs_no_key', 'API key not configured.', array( 'status' => 403 ) );
		}

		$provided = trim( (string) $request->get_header( 'x_ratesight_key' ) );
		if ( ! hash_equals( $stored_key, $provided ) ) {
			return new WP_Error( 'rs_bad_key', 'Invalid X-Ratesight-Key.', array( 'status' => 403 ) );
		}

		return true;
	}

	// ── GET handler ───────────────────────────────────────────────────────────

	public function handle_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url     = $request->get_param( 'url' );
		$post_id = $this->url_to_post_id( $url );

		if ( ! $post_id ) {
			return new WP_Error( 'rs_not_found', 'No published post found for this URL.', array( 'status' => 404 ) );
		}

		$post    = get_post( $post_id );
		$plugin  = $this->detect_seo_plugin();
		$meta    = $this->read_seo_meta( $post_id, $plugin );

		return rest_ensure_response( array(
			'post_id'          => $post_id,
			'url'              => get_permalink( $post_id ),
			'post_title'       => $post->post_title,
			'slug'             => $post->post_name,
			'seo_plugin'       => $plugin,
			'seo_title'        => $meta['seo_title'],
			'meta_description' => $meta['meta_description'],
		) );
	}

	// ── POST handler ──────────────────────────────────────────────────────────

	public function handle_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url     = esc_url_raw( $request->get_param( 'url' ) );
		$fields  = (array) $request->get_param( 'fields' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$reason  = sanitize_text_field( $request->get_param( 'reason' ) );

		$post_id = $this->url_to_post_id( $url );
		if ( ! $post_id ) {
			return new WP_Error( 'rs_not_found', 'No published post found for this URL.', array( 'status' => 404 ) );
		}

		// Validate allowed fields.
		$allowed = array( 'seo_title', 'meta_description' );
		$unknown = array_diff( array_keys( $fields ), $allowed );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'rs_unknown_fields',
				'Unknown fields: ' . implode( ', ', $unknown ) . '. Allowed: ' . implode( ', ', $allowed ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'rs_no_fields', 'No fields provided.', array( 'status' => 400 ) );
		}

		$plugin  = $this->detect_seo_plugin();
		$before  = $this->read_seo_meta( $post_id, $plugin );
		$changes = array();

		foreach ( $fields as $field => $new_value ) {
			$new_value = sanitize_text_field( $new_value );
			$old_value = $before[ $field ] ?? '';

			$change = array(
				'field'  => $field,
				'before' => $old_value,
				'after'  => $new_value,
				'saved'  => false,
			);

			if ( ! $dry_run && $new_value !== $old_value ) {
				$saved = $this->write_seo_meta( $post_id, $plugin, $field, $new_value );
				$change['saved'] = $saved;
			} elseif ( $dry_run ) {
				$change['saved'] = null; // null = would be saved
			}

			$changes[] = $change;
		}

		// Log the operation (even dry runs, clearly marked).
		if ( class_exists( 'Ratesight_Logger' ) ) {
			$saved_count = count( array_filter( $changes, fn( $c ) => $c['saved'] === true ) );
			$summary     = array();
			foreach ( $changes as $c ) {
			$summary[] = $c['field'] . ': "' . $c['before'] . '" -> "' . $c['after'] . '"';			}
			$notes = ( $dry_run ? '[DRY RUN] ' : '' )
				. ( $reason ? "Reason: {$reason}. " : '' )
				. implode( '; ', $summary );

			Ratesight_Logger::log( array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'status'  => $dry_run ? 'dry_run' : ( $saved_count > 0 ? 'success' : 'no_change' ),
				'notes'   => $notes,
			) );
		}

		return rest_ensure_response( array(
			'post_id' => $post_id,
			'url'     => get_permalink( $post_id ),
			'dry_run' => $dry_run,
			'plugin'  => $plugin,
			'changes' => $changes,
		) );
	}

	// ── URL → Post ID ─────────────────────────────────────────────────────────

	private function url_to_post_id( string $url ): int {
		// Try WordPress's built-in resolver first.
		$post_id = url_to_postid( $url );
		if ( $post_id ) return $post_id;

		// Fallback: slug match for RS pages (url_to_postid doesn't cover CPTs on all configs).
		$slug = trim( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/' );
		$slug = basename( $slug );
		if ( $slug ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_name = %s AND post_status = 'publish'
				   AND post_type IN ('post', 'page', 'ratesight_page')
				 LIMIT 1",
				$slug
			) );
			if ( $id ) return $id;
		}

		return 0;
	}

	// ── SEO plugin detection ──────────────────────────────────────────────────

	private function detect_seo_plugin(): string {
		$active = (array) get_option( 'active_plugins', array() );

		// Also check network-active plugins.
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		if ( in_array( 'wordpress-seo/wp-seo.php',                  $active, true ) ) return 'yoast';
		if ( in_array( 'rank-math/rank-math.php',                    $active, true ) ) return 'rankmath';
		if ( in_array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', $active, true ) ) return 'aioseo';
		if ( in_array( 'squirrly-seo/squirrly.php',                  $active, true ) ) return 'squirrly';

		// Class-based fallback in case the plugin file path differs.
		if ( class_exists( 'WPSEO_Options' ) || defined( 'WPSEO_VERSION' ) )      return 'yoast';
		if ( class_exists( 'RankMath' )       || defined( 'RANK_MATH_VERSION' ) )  return 'rankmath';
		if ( defined( 'AIOSEO_VERSION' ) )                                          return 'aioseo';
		if ( defined( 'SQ_VERSION' ) )                                              return 'squirrly';

		return 'none';
	}

	// ── Read SEO meta ─────────────────────────────────────────────────────────

	private function read_seo_meta( int $post_id, string $plugin ): array {
		switch ( $plugin ) {
			case 'squirrly':
				return $this->read_squirrly( $post_id );

			default:
				// Yoast / RankMath / AIOSEO / none all store as simple post meta.
				$keys = self::$adapters[ $plugin ] ?? array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
				return array(
					'seo_title'        => (string) get_post_meta( $post_id, $keys[0], true ),
					'meta_description' => (string) get_post_meta( $post_id, $keys[1], true ),
				);
		}
	}

	private function read_squirrly( int $post_id ): array {
		// Squirrly stores SEO in _sq_post_meta as a serialised array.
		$sq = get_post_meta( $post_id, '_sq_post_meta', true );
		if ( is_array( $sq ) ) {
			return array(
				'seo_title'        => (string) ( $sq['seo_title'] ?? $sq['title'] ?? '' ),
				'meta_description' => (string) ( $sq['seo_description'] ?? $sq['description'] ?? '' ),
			);
		}
		return array( 'seo_title' => '', 'meta_description' => '' );
	}

	// ── Write SEO meta ────────────────────────────────────────────────────────

	private function write_seo_meta( int $post_id, string $plugin, string $field, string $value ): bool {
		switch ( $plugin ) {
			case 'squirrly':
				return $this->write_squirrly( $post_id, $field, $value );

			default:
				$keys      = self::$adapters[ $plugin ] ?? array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
				$meta_key  = $field === 'seo_title' ? $keys[0] : $keys[1];
				$result    = update_post_meta( $post_id, $meta_key, $value );
				// update_post_meta returns false if value unchanged — that's fine, it still succeeded.
				return $result !== false || get_post_meta( $post_id, $meta_key, true ) === $value;
		}
	}

	private function write_squirrly( int $post_id, string $field, string $value ): bool {
		$sq = get_post_meta( $post_id, '_sq_post_meta', true );
		$sq = is_array( $sq ) ? $sq : array();

		if ( $field === 'seo_title' ) {
			$sq['seo_title'] = $value;
			$sq['title']     = $value; // keep both keys in sync
		} else {
			$sq['seo_description'] = $value;
			$sq['description']     = $value;
		}

		return update_post_meta( $post_id, '_sq_post_meta', $sq ) !== false;
	}
}
