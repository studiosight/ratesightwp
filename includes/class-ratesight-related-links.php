<?php
/**
 * REST API + render-time block: per-URL "Related services" internal links.
 *
 * This is a RENDER-TIME block, not a content edit. The stored builder content
 * (post_content) is never touched. The link list is kept in post meta and
 * appended to the_content at display time, only on the matching URL, AFTER the
 * builder content. That makes it:
 *
 *   - safe        — no layout damage; builders can re-save freely
 *   - idempotent  — upsert by URL; the block is re-rendered fresh on every load
 *   - verifiable  — re-fetch the page and the data-rs-block section is in the HTML
 *   - reversible  — clear the list (POST with [] or DELETE) and the block is gone
 *
 * Endpoints (namespace ratesight/v1):
 *   POST   /related-links   { url, links: [ { url, anchor } ], confirm }
 *                           Upsert the list for url's post. confirm=false is a
 *                           dry run (returns would_store without saving).
 *   GET    /related-links?url=…
 *                           Echo the stored list + capabilities.related_links.
 *   DELETE /related-links?url=…
 *                           Clear the list for url's post.
 *
 * Auth mirrors Ratesight_Webhook_Handler: an optional X-Ratesight-Signature
 * HMAC over the raw body, matched against the ratesight_webhook_secret option.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Related_Links {

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/related-links';
	const META_KEY        = '_ratesight_related_links';
	const BLOCK_NAME      = 'related-services';
	const MAX_LINKS       = 50;

	/** Guards against appending the block twice if the_content runs again for the same post. */
	private static array $rendered = array();

	// ── Route registration ────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
				'args'                => array(
					'url' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_post' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'handle_delete' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
				'args'                => array(
					'url' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
				),
			),
		) );
	}

	// ── Auth (mirrors Ratesight_Webhook_Handler::check_auth signature check) ────

	public static function check_auth( \WP_REST_Request $request ): bool|\WP_Error {
		$secret     = get_option( 'ratesight_webhook_secret', '' );
		$sig_header = $request->get_header( 'x_ratesight_signature' ) ?? '';

		if ( $secret !== '' && $sig_header !== '' ) {
			$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
			if ( ! hash_equals( $expected, $sig_header ) ) {
				return new \WP_Error( 'rs_bad_signature', 'Forbidden: invalid X-Ratesight-Signature.', array( 'status' => 403 ) );
			}
		}

		return true;
	}

	// ── GET ─────────────────────────────────────────────────────────────────--

	public static function handle_get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = self::resolve_post( $request, $error );
		if ( $error ) {
			return $error;
		}

		$links = self::get_links( $post_id );

		return rest_ensure_response( array(
			'post_id'      => $post_id,
			'url'          => get_permalink( $post_id ),
			'links'        => $links,
			'count'        => count( $links ),
			'capabilities' => array( 'related_links' => true ),
		) );
	}

	// ── POST (upsert by URL) ───────────────────────────────────────────────────

	public static function handle_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = self::resolve_post( $request, $error );
		if ( $error ) {
			return $error;
		}

		$links   = self::sanitize_links( $request->get_param( 'links' ) );
		$confirm = (bool) $request->get_param( 'confirm' );

		// Dry run — show exactly what would be stored, change nothing.
		if ( ! $confirm ) {
			return rest_ensure_response( array(
				'ok'          => true,
				'dry_run'     => true,
				'post_id'     => $post_id,
				'url'         => get_permalink( $post_id ),
				'would_store' => $links,
				'count'       => count( $links ),
			) );
		}

		if ( empty( $links ) ) {
			delete_post_meta( $post_id, self::META_KEY ); // empty list = clear (reversible)
		} else {
			update_post_meta( $post_id, self::META_KEY, $links ); // upsert
		}

		if ( class_exists( 'Ratesight_Logger' ) ) {
			Ratesight_Logger::log( array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'status'  => 'success',
				'notes'   => sprintf( 'Related links set: %d link%s.', count( $links ), count( $links ) === 1 ? '' : 's' ),
			) );
		}

		return rest_ensure_response( array(
			'ok'      => true,
			'dry_run' => false,
			'post_id' => $post_id,
			'url'     => get_permalink( $post_id ),
			'stored'  => $links,
			'count'   => count( $links ),
		) );
	}

	// ── DELETE (clear) ──────────────────────────────────────────────────────---

	public static function handle_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = self::resolve_post( $request, $error );
		if ( $error ) {
			return $error;
		}

		delete_post_meta( $post_id, self::META_KEY );

		return rest_ensure_response( array(
			'ok'      => true,
			'post_id' => $post_id,
			'url'     => get_permalink( $post_id ),
			'cleared' => true,
		) );
	}

	// ── Render (the_content filter) ─────────────────────────────────────────────

	/**
	 * Append the related-services block after the builder content, on the
	 * front-end singular view of the post only. Never mutates post_content.
	 */
	public static function render_block( string $content ): string {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || isset( self::$rendered[ $post_id ] ) ) {
			return $content;
		}

		$links = self::get_links( $post_id );
		if ( empty( $links ) ) {
			return $content;
		}

		self::$rendered[ $post_id ] = true;

		return $content . self::block_html( $links );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────---

	/** Stored link list for a post: array of [ 'url' => …, 'anchor' => … ]. */
	public static function get_links( int $post_id ): array {
		$links = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $links ) ? $links : array();
	}

	/** Resolve the request's url param to a post ID, or set $error to a WP_Error. */
	private static function resolve_post( \WP_REST_Request $request, &$error ): int {
		$error = null;
		$url   = esc_url_raw( (string) $request->get_param( 'url' ) );

		if ( $url === '' ) {
			$error = new \WP_Error( 'rs_no_url', 'Missing url parameter.', array( 'status' => 400 ) );
			return 0;
		}

		$post_id = self::url_to_post_id( $url );
		if ( ! $post_id ) {
			$error = new \WP_Error( 'rs_not_found', 'No published post found for this URL.', array( 'status' => 404 ) );
			return 0;
		}

		return $post_id;
	}

	/** Sanitise an incoming links array; drops invalid entries, caps the count. */
	private static function sanitize_links( $links ): array {
		if ( ! is_array( $links ) ) {
			return array();
		}

		$clean = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url    = esc_url_raw( trim( (string) ( $link['url'] ?? '' ) ) );
			$anchor = sanitize_text_field( (string) ( $link['anchor'] ?? '' ) );
			if ( $url === '' || $anchor === '' ) {
				continue;
			}
			$clean[] = array( 'url' => $url, 'anchor' => $anchor );
			if ( count( $clean ) >= self::MAX_LINKS ) {
				break;
			}
		}

		return $clean;
	}

	/** Escaped HTML for the render-time block. */
	private static function block_html( array $links ): string {
		$items = '';
		foreach ( $links as $link ) {
			$url    = isset( $link['url'] ) ? esc_url( $link['url'] ) : '';
			$anchor = isset( $link['anchor'] ) ? esc_html( $link['anchor'] ) : '';
			if ( $url === '' || $anchor === '' ) {
				continue;
			}
			$items .= '<li class="rs-related-services__item"><a href="' . $url . '">' . $anchor . '</a></li>';
		}

		if ( $items === '' ) {
			return '';
		}

		return "\n<section data-rs-block=\"" . esc_attr( self::BLOCK_NAME ) . '" class="rs-related-services" aria-label="Related services">'
			. '<h2 class="rs-related-services__title">Related Services</h2>'
			. '<ul class="rs-related-services__list">' . $items . '</ul>'
			. "</section>\n";
	}

	/**
	 * URL → published post ID. Mirrors Ratesight_Page_API: WP's resolver first,
	 * then a slug match that also covers the ratesight_page CPT.
	 */
	private static function url_to_post_id( string $url ): int {
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return $post_id;
		}

		$slug = basename( trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		if ( $slug ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_name = %s AND post_status = 'publish'
				   AND post_type IN ('post', 'page', 'ratesight_page')
				 LIMIT 1",
				$slug
			) );
			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}
}
