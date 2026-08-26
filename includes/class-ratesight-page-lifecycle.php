<?php
/**
 * Recoverable page lifecycle verbs.
 *
 *   POST /wp-json/ratesight/v1/trash-page    Body: { id|url|slug, confirm:true, dry_run? }
 *   POST /wp-json/ratesight/v1/restore-page  Body: { id|url|slug, confirm:true, dry_run? }
 *
 * WHY THIS EXISTS. The only removal verb the plugin had was
 * DELETE /create-page, which calls wp_delete_post( $id, true ) — a permanent,
 * unrecoverable delete that bypasses the WordPress trash. An integrator that
 * wants to take a zero-click page out of the index has no reversible option, so
 * the safe move is "do nothing", and dead pages stay live.
 *
 * These two verbs use wp_trash_post() / wp_untrash_post(), so every removal is
 * reversible from WP Admin (Trash) or through restore-page.
 *
 * CONTRACT
 *   - confirm:true is REQUIRED for any real write. A body without it is refused
 *     with 428 and NOTHING is written.
 *   - dry_run:true is honoured honestly: the post is resolved and the predicted
 *     before/after statuses are returned, but no write happens. dry_run does not
 *     require confirm (it cannot change anything).
 *   - Every response reports status_before / status_after so the caller can
 *     verify the outcome without a second read.
 *
 * AUTH. Signed only (check_auth_signed on the webhook handler): these verbs
 * remove content, so — like the redirect mutations — they require a configured
 * webhook secret AND a valid HMAC signature. A site with no secret refuses them.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Page_Lifecycle {

	/** Post types these verbs may resolve. Never touches anything else. */
	const POST_TYPES = array( 'ratesight_page', 'page', 'post' );

	public static function register_routes(): void {
		$handler = new Ratesight_Webhook_Handler();

		register_rest_route( 'ratesight/v1', '/trash-page', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_trash' ),
			'permission_callback' => array( $handler, 'check_auth_signed' ),
		) );

		register_rest_route( 'ratesight/v1', '/restore-page', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_restore' ),
			'permission_callback' => array( $handler, 'check_auth_signed' ),
		) );
	}

	// -------------------------------------------------------------------------
	// Pure decision core — no WordPress calls, unit-testable.
	// -------------------------------------------------------------------------

	/**
	 * Validate a trash/restore body and extract the selector.
	 *
	 * @param array $data Decoded request body.
	 * @return array {
	 *   ok       bool    Request may proceed.
	 *   status   int     HTTP status to return when ok is false.
	 *   message  string  Refusal reason when ok is false.
	 *   dry_run  bool    Caller asked for a prediction only.
	 *   by       string  'id' | 'url' | 'slug' (empty when ok is false).
	 *   value    mixed   Selector value (int for id, string otherwise).
	 * }
	 */
	public static function parse_intent( array $data ): array {
		$dry_run = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
		$confirm = filter_var( $data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;

		$refuse = static function ( int $status, string $message ) use ( $dry_run ): array {
			return array(
				'ok'      => false,
				'status'  => $status,
				'message' => $message,
				'dry_run' => $dry_run,
				'by'      => '',
				'value'   => null,
			);
		};

		// Selector — id wins, then url, then slug.
		$by    = '';
		$value = null;
		if ( isset( $data['id'] ) && (int) $data['id'] > 0 ) {
			$by    = 'id';
			$value = (int) $data['id'];
		} elseif ( ! empty( $data['url'] ) && is_string( $data['url'] ) ) {
			$by    = 'url';
			$value = trim( $data['url'] );
		} elseif ( ! empty( $data['slug'] ) && is_string( $data['slug'] ) ) {
			$by    = 'slug';
			$value = trim( $data['slug'] );
		}

		if ( $by === '' ) {
			return $refuse( 422, 'Required field missing: send one of "id", "url" or "slug".' );
		}

		// A real write needs an explicit confirmation. A dry run cannot write, so
		// it is allowed to preview without one.
		if ( ! $confirm && ! $dry_run ) {
			return $refuse( 428, 'Refused: this operation requires "confirm": true. Send dry_run:true to preview it instead.' );
		}

		return array(
			'ok'      => true,
			'status'  => 200,
			'message' => '',
			'dry_run' => $dry_run,
			'by'      => $by,
			'value'   => $value,
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * POST /wp-json/ratesight/v1/trash-page
	 * Moves a post to the WordPress trash. Recoverable — never a force delete.
	 */
	public static function handle_trash( \WP_REST_Request $request ): \WP_REST_Response {
		$data   = $request->get_json_params() ?: $request->get_body_params();
		$intent = self::parse_intent( is_array( $data ) ? $data : array() );

		if ( ! $intent['ok'] ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => $intent['message'],
				'dry_run' => $intent['dry_run'],
			), $intent['status'] );
		}

		$post_id = self::resolve( $intent['by'], $intent['value'] );
		if ( ! $post_id ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => 'No post found for the given id, url or slug.',
				'dry_run' => $intent['dry_run'],
			), 404 );
		}

		$post           = get_post( $post_id );
		$status_before  = (string) $post->post_status;
		$title          = (string) get_the_title( $post_id );

		if ( $status_before === 'trash' ) {
			return new \WP_REST_Response( array(
				'ok'            => true,
				'trashed'       => false,
				'already_trash' => true,
				'dry_run'       => $intent['dry_run'],
				'id'            => $post_id,
				'title'         => $title,
				'status_before' => $status_before,
				'status_after'  => $status_before,
				'message'       => "Post #{$post_id} is already in the trash — nothing to do.",
			), 200 );
		}

		if ( $intent['dry_run'] ) {
			return new \WP_REST_Response( array(
				'ok'            => true,
				'trashed'       => false,
				'dry_run'       => true,
				'id'            => $post_id,
				'title'         => $title,
				'status_before' => $status_before,
				'status_after'  => 'trash', // predicted, nothing was written
				'message'       => "Dry run: post #{$post_id} would be moved to the trash.",
			), 200 );
		}

		// Recoverable by design — wp_trash_post(), never wp_delete_post( .., true ).
		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return new \WP_REST_Response( array(
				'ok'            => false,
				'dry_run'       => false,
				'id'            => $post_id,
				'status_before' => $status_before,
				'status_after'  => (string) get_post_status( $post_id ),
				'message'       => "Failed to trash post #{$post_id}.",
			), 500 );
		}

		$status_after = (string) get_post_status( $post_id );

		Ratesight_Logger::log_update(
			Ratesight_Logger::log_pending( "Trashed: {$title}", '', wp_json_encode( $data ) ),
			$post_id,
			Ratesight_Logger::STATUS_MODIFIED,
			"Post #{$post_id} ({$title}) moved to trash via API ({$status_before} -> {$status_after}). Recoverable via restore-page or WP Admin."
		);

		return new \WP_REST_Response( array(
			'ok'            => true,
			'trashed'       => $status_after === 'trash',
			'dry_run'       => false,
			'id'            => $post_id,
			'title'         => $title,
			'status_before' => $status_before,
			'status_after'  => $status_after,
			'recoverable'   => true,
		), 200 );
	}

	/**
	 * POST /wp-json/ratesight/v1/restore-page
	 * Restores a trashed post to the status it held before it was trashed.
	 */
	public static function handle_restore( \WP_REST_Request $request ): \WP_REST_Response {
		$data   = $request->get_json_params() ?: $request->get_body_params();
		$intent = self::parse_intent( is_array( $data ) ? $data : array() );

		if ( ! $intent['ok'] ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => $intent['message'],
				'dry_run' => $intent['dry_run'],
			), $intent['status'] );
		}

		$post_id = self::resolve( $intent['by'], $intent['value'], true );
		if ( ! $post_id ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'message' => 'No post found for the given id, url or slug. A trashed post is usually only resolvable by id.',
				'dry_run' => $intent['dry_run'],
			), 404 );
		}

		$post          = get_post( $post_id );
		$status_before = (string) $post->post_status;
		$title         = (string) get_the_title( $post_id );

		if ( $status_before !== 'trash' ) {
			return new \WP_REST_Response( array(
				'ok'            => true,
				'restored'      => false,
				'dry_run'       => $intent['dry_run'],
				'id'            => $post_id,
				'title'         => $title,
				'status_before' => $status_before,
				'status_after'  => $status_before,
				'message'       => "Post #{$post_id} is not in the trash — nothing to restore.",
			), 200 );
		}

		// What wp_untrash_post() will restore to (WP >= 5.6 stores the prior status).
		$previous = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		if ( $previous === '' ) {
			$previous = 'draft';
		}

		if ( $intent['dry_run'] ) {
			return new \WP_REST_Response( array(
				'ok'            => true,
				'restored'      => false,
				'dry_run'       => true,
				'id'            => $post_id,
				'title'         => $title,
				'status_before' => 'trash',
				'status_after'  => $previous, // predicted, nothing was written
				'message'       => "Dry run: post #{$post_id} would be restored to \"{$previous}\".",
			), 200 );
		}

		$result = wp_untrash_post( $post_id );
		if ( ! $result ) {
			return new \WP_REST_Response( array(
				'ok'            => false,
				'dry_run'       => false,
				'id'            => $post_id,
				'status_before' => 'trash',
				'status_after'  => (string) get_post_status( $post_id ),
				'message'       => "Failed to restore post #{$post_id}.",
			), 500 );
		}

		$status_after = (string) get_post_status( $post_id );

		Ratesight_Logger::log_update(
			Ratesight_Logger::log_pending( "Restored: {$title}", '', wp_json_encode( $data ) ),
			$post_id,
			Ratesight_Logger::STATUS_MODIFIED,
			"Post #{$post_id} ({$title}) restored from trash via API (trash -> {$status_after})."
		);

		return new \WP_REST_Response( array(
			'ok'            => true,
			'restored'      => true,
			'dry_run'       => false,
			'id'            => $post_id,
			'title'         => $title,
			'status_before' => 'trash',
			'status_after'  => $status_after,
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve a selector to a post ID.
	 *
	 * @param string $by             'id' | 'url' | 'slug'.
	 * @param mixed  $value          Selector value.
	 * @param bool   $include_trash  Also match trashed posts (restore path).
	 */
	private static function resolve( string $by, $value, bool $include_trash = false ): int {
		if ( $by === 'id' ) {
			$post = get_post( (int) $value );
			return ( $post && in_array( $post->post_type, self::POST_TYPES, true ) ) ? (int) $post->ID : 0;
		}

		$slug = '';
		if ( $by === 'url' ) {
			$url     = esc_url_raw( (string) $value );
			$post_id = url_to_postid( $url );
			if ( $post_id ) {
				return (int) $post_id;
			}
			$slug = basename( rtrim( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ), '/' ) );
		} else {
			$slug = sanitize_title( (string) $value );
		}

		if ( $slug === '' ) {
			return 0;
		}

		$post = get_page_by_path( $slug, OBJECT, self::POST_TYPES );
		if ( $post ) {
			return (int) $post->ID;
		}

		if ( ! $include_trash ) {
			return 0;
		}

		// get_page_by_path() ignores trashed posts, so look them up directly.
		$found = get_posts( array(
			'name'             => $slug,
			'post_type'        => self::POST_TYPES,
			'post_status'      => 'trash',
			'numberposts'      => 1,
			'suppress_filters' => false,
		) );

		return $found ? (int) $found[0]->ID : 0;
	}
}
