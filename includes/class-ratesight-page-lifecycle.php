<?php
/**
 * Recoverable page lifecycle verbs.
 *
 *   POST /wp-json/ratesight/v1/trash-page    Body: { id|url|slug, confirm:true, dry_run? }
 *   POST /wp-json/ratesight/v1/restore-page  Body: { id|url|slug, confirm:true, dry_run? }
 *   POST /wp-json/ratesight/v1/unpublish-rs-pages  Body: { reason:"hosting_only", ids?, dry_run?|confirm? }
 *   POST /wp-json/ratesight/v1/republish-rs-pages  Body: { ids?, dry_run?|confirm? }
 *
 * The two rs-pages verbs (3.15.7) only ever change post_type ratesight_page;
 * see the "rs_pages visibility hold" section below and docs/page-lifecycle.md.
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
 * AUTH. These routes use the centralized request-auth mutation policy, including
 * negotiated rs-hmac-v2 verification and replay protection.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Page_Lifecycle {

	/** Post types these verbs may resolve. Never touches anything else. */
	const POST_TYPES = array( 'ratesight_page', 'page', 'post' );

	public static function register_routes(): void {
		register_rest_route( 'ratesight/v1', '/trash-page', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_trash' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );

		register_rest_route( 'ratesight/v1', '/restore-page', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_restore' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );

		register_rest_route( 'ratesight/v1', '/unpublish-rs-pages', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_unpublish_rs_pages' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );

		register_rest_route( 'ratesight/v1', '/republish-rs-pages', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_republish_rs_pages' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
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
		// Pinned so kses cannot re-filter stored content on this write.
		$result = self::with_pinned_fields( $post_id, self::PINNED_FIELDS, static function () use ( $post_id ) {
			return wp_trash_post( $post_id );
		} );
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

		// Since WordPress 5.6 wp_untrash_post() restores to "draft" unless told
		// otherwise (wp_untrash_post_status). Restore the status the post held
		// before it was trashed, scoped to this one post and this one call.
		$restore_status = static function ( $new_status, $untrash_id, $previous_status ) use ( $post_id ) {
			return ( (int) $untrash_id === $post_id && is_string( $previous_status ) && $previous_status !== '' ) ? $previous_status : $new_status;
		};
		add_filter( 'wp_untrash_post_status', $restore_status, PHP_INT_MAX, 3 );
		try {
			$result = self::with_pinned_fields( $post_id, self::PINNED_FIELDS, static function () use ( $post_id ) {
				return wp_untrash_post( $post_id );
			} );
		} finally {
			remove_filter( 'wp_untrash_post_status', $restore_status, PHP_INT_MAX );
		}
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

	// -------------------------------------------------------------------------
	// rs_pages visibility hold (3.15.7): hosting-only clients
	// -------------------------------------------------------------------------
	//
	//   POST /wp-json/ratesight/v1/unpublish-rs-pages
	//        Body: { reason:"hosting_only", ids?:int[], dry_run?:bool, confirm?:bool }
	//   POST /wp-json/ratesight/v1/republish-rs-pages
	//        Body: { ids?:int[], dry_run?:bool, confirm?:bool }
	//
	// Takes Ratesight-generated pages (post_type ratesight_page ONLY) out of
	// public display and the sitemap by setting them to draft, and puts them
	// back. Content, meta, revisions and slugs are kept: the write is a
	// status-only wp_update_post(), and the stored title, content, excerpt and
	// slug are pinned for that write so kses or slug de-duplication cannot alter
	// them. The status a page held is recorded in _ratesight_visibility_hold and
	// every change is appended to ratesight_rs_pages_visibility_log.
	//
	// Any id that is not a ratesight_page is refused per item with
	// not_ratesight_page. The client's own pages and posts are never loaded for
	// writing, so they cannot be affected.

	/** The only post type the visibility verbs may change. */
	const VISIBILITY_POST_TYPE = 'ratesight_page';

	/** Post meta holding {prior_status, reason, at} while a page is held. */
	const VISIBILITY_HOLD_META = '_ratesight_visibility_hold';

	/** Append-only history option, capped at the newest VISIBILITY_LOG_CAP entries. */
	const VISIBILITY_LOG_OPTION = 'ratesight_rs_pages_visibility_log';
	const VISIBILITY_LOG_CAP    = 1000;

	/** Reasons unpublish-rs-pages accepts. */
	const VISIBILITY_REASONS = array( 'hosting_only' );

	/** Statuses a hold may restore. Unpublish only ever records publish. */
	const VISIBILITY_RESTORABLE = array( 'publish' );

	/** Upper bound on an explicit ids list. */
	const VISIBILITY_MAX_IDS = 1000;

	/**
	 * Validate an unpublish/republish body. Pure: no WordPress calls.
	 *
	 * @param array $data           Decoded request body.
	 * @param bool  $require_reason True for unpublish (reason must be in VISIBILITY_REASONS).
	 * @return array { ok, status, message, dry_run, ids (int[]|null, null = all), reason (string|null) }
	 */
	public static function parse_visibility_intent( array $data, bool $require_reason ): array {
		$dry_run = ( filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false ) === true;
		$confirm = ( filter_var( $data['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false ) === true;
		$reason  = isset( $data['reason'] ) && is_string( $data['reason'] ) ? trim( $data['reason'] ) : null;

		$refuse = static function ( int $status, string $message ) use ( $dry_run, $reason ): array {
			return array( 'ok' => false, 'status' => $status, 'message' => $message, 'dry_run' => $dry_run, 'ids' => null, 'reason' => $reason );
		};

		if ( $dry_run && $confirm ) {
			return $refuse( 422, 'Send exactly one of "dry_run": true or "confirm": true, not both.' );
		}
		if ( ! $dry_run && ! $confirm ) {
			return $refuse( 428, 'Refused: this operation requires "confirm": true. Send dry_run:true to preview it instead.' );
		}

		if ( $require_reason ) {
			if ( $reason === null || ! in_array( $reason, self::VISIBILITY_REASONS, true ) ) {
				return $refuse( 422, 'Required field "reason" must be one of: ' . implode( ', ', self::VISIBILITY_REASONS ) . '.' );
			}
		} elseif ( $reason !== null && ! in_array( $reason, self::VISIBILITY_REASONS, true ) ) {
			$reason = null;
		}

		$ids = null;
		if ( array_key_exists( 'ids', $data ) ) {
			if ( ! is_array( $data['ids'] ) || $data['ids'] === array() ) {
				return $refuse( 422, 'Field "ids" must be a non-empty array of post ids, or omitted to mean every eligible Ratesight page.' );
			}
			$ids = array();
			foreach ( $data['ids'] as $raw ) {
				if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
					$id = (int) $raw;
					if ( $id > 0 ) {
						$ids[ $id ] = $id;
						continue;
					}
				}
				return $refuse( 422, 'Field "ids" may only contain positive integer post ids.' );
			}
			$ids = array_values( $ids );
			if ( count( $ids ) > self::VISIBILITY_MAX_IDS ) {
				return $refuse( 422, 'Field "ids" may hold at most ' . self::VISIBILITY_MAX_IDS . ' ids per request.' );
			}
		}

		return array( 'ok' => true, 'status' => 200, 'message' => '', 'dry_run' => $dry_run, 'ids' => $ids, 'reason' => $reason );
	}

	/**
	 * Decide what happens to one post. Pure: no WordPress calls.
	 *
	 * @param string      $action    'unpublish' | 'republish'.
	 * @param string|null $post_type Post type, or null when the id does not exist.
	 * @param string      $status    Current post_status.
	 * @param array|null  $hold      Decoded hold meta, or null when absent or unreadable.
	 * @return array { change bool, result string, to string|null }
	 */
	public static function decide_visibility( string $action, ?string $post_type, string $status, ?array $hold ): array {
		if ( $post_type !== self::VISIBILITY_POST_TYPE ) {
			return array( 'change' => false, 'result' => 'not_ratesight_page', 'to' => null );
		}
		if ( $action === 'unpublish' ) {
			if ( $status !== 'publish' ) {
				return array( 'change' => false, 'result' => 'skipped', 'to' => $status );
			}
			return array( 'change' => true, 'result' => 'unpublished', 'to' => 'draft' );
		}
		$prior = is_array( $hold ) ? (string) ( $hold['prior_status'] ?? '' ) : '';
		if ( $status !== 'draft' || ! in_array( $prior, self::VISIBILITY_RESTORABLE, true ) ) {
			return array( 'change' => false, 'result' => 'skipped', 'to' => $status );
		}
		return array( 'change' => true, 'result' => 'republished', 'to' => $prior );
	}

	/** Append entries to the history log, keeping the newest VISIBILITY_LOG_CAP. Pure. */
	public static function append_visibility_log( $log, array $entries ): array {
		$log = is_array( $log ) ? array_values( $log ) : array();
		foreach ( $entries as $entry ) {
			$log[] = $entry;
		}
		if ( count( $log ) > self::VISIBILITY_LOG_CAP ) {
			$log = array_slice( $log, -self::VISIBILITY_LOG_CAP );
		}
		return $log;
	}

	/**
	 * POST /wp-json/ratesight/v1/unpublish-rs-pages
	 */
	public static function handle_unpublish_rs_pages( \WP_REST_Request $request ): \WP_REST_Response {
		return self::handle_visibility( $request, 'unpublish' );
	}

	/**
	 * POST /wp-json/ratesight/v1/republish-rs-pages
	 */
	public static function handle_republish_rs_pages( \WP_REST_Request $request ): \WP_REST_Response {
		return self::handle_visibility( $request, 'republish' );
	}

	private static function handle_visibility( \WP_REST_Request $request, string $action ): \WP_REST_Response {
		$data   = $request->get_json_params() ?: $request->get_body_params();
		$intent = self::parse_visibility_intent( is_array( $data ) ? $data : array(), $action === 'unpublish' );

		if ( ! $intent['ok'] ) {
			return new \WP_REST_Response( array(
				'ok'      => false,
				'dry_run' => $intent['dry_run'],
				'reason'  => $intent['reason'],
				'count'   => 0,
				'pages'   => array(),
				'message' => $intent['message'],
			), $intent['status'] );
		}

		$ids = $intent['ids'];
		if ( $ids === null ) {
			$query = array(
				'post_type'        => self::VISIBILITY_POST_TYPE,
				'post_status'      => $action === 'unpublish' ? 'publish' : 'draft',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			);
			if ( $action === 'republish' ) {
				$query['meta_key'] = self::VISIBILITY_HOLD_META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			}
			$ids = array_map( 'intval', get_posts( $query ) );
		}

		$now     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$pages   = array();
		$entries = array();
		$count   = 0;
		$failed  = 0;

		foreach ( $ids as $post_id ) {
			$post   = get_post( $post_id );
			$type   = $post instanceof \WP_Post ? (string) $post->post_type : null;
			$status = $post instanceof \WP_Post ? (string) $post->post_status : '';
			$hold   = $type === self::VISIBILITY_POST_TYPE ? self::read_hold( $post_id ) : null;
			$plan   = self::decide_visibility( $action, $type, $status, $hold );

			$row = array(
				'id'     => $post_id,
				'slug'   => $type === self::VISIBILITY_POST_TYPE ? (string) $post->post_name : null,
				'from'   => $type === self::VISIBILITY_POST_TYPE ? $status : null,
				'to'     => $plan['to'],
				'result' => $plan['result'],
			);

			if ( ! $plan['change'] ) {
				$pages[] = $row;
				continue;
			}

			if ( $intent['dry_run'] ) {
				$row['result'] = 'would_' . ( $action === 'unpublish' ? 'unpublish' : 'republish' );
				$pages[]       = $row;
				$count++;
				continue;
			}

			$log_reason = $action === 'unpublish' ? $intent['reason'] : (string) ( $hold['reason'] ?? '' );
			$hold_added = false;
			if ( $action === 'unpublish' && $hold === null ) {
				// Recorded before the status write. An existing hold is never overwritten.
				$hold_added = (bool) add_post_meta( $post_id, self::VISIBILITY_HOLD_META, wp_slash( wp_json_encode( array(
					'prior_status' => $status,
					'reason'       => $intent['reason'],
					'at'           => $now,
				) ) ), true );
			}

			$written = self::set_status_only( $post, $plan['to'] );
			$after   = (string) get_post_status( $post_id );

			if ( ! $written || $after !== $plan['to'] ) {
				if ( $hold_added ) {
					delete_post_meta( $post_id, self::VISIBILITY_HOLD_META );
				}
				$row['to']     = $after;
				$row['result'] = 'failed';
				$pages[]       = $row;
				$failed++;
				continue;
			}

			if ( $action === 'republish' ) {
				delete_post_meta( $post_id, self::VISIBILITY_HOLD_META );
			}

			$entries[] = array(
				'action'  => $action,
				'post_id' => $post_id,
				'slug'    => (string) $post->post_name,
				'from'    => $status,
				'to'      => $after,
				'reason'  => $log_reason,
				'at'      => $now,
			);
			$pages[] = $row;
			$count++;
		}

		if ( $entries ) {
			update_option( self::VISIBILITY_LOG_OPTION, self::append_visibility_log( get_option( self::VISIBILITY_LOG_OPTION, array() ), $entries ), false );
		}

		return new \WP_REST_Response( array(
			'ok'      => $failed === 0,
			'dry_run' => $intent['dry_run'],
			'reason'  => $intent['reason'],
			'count'   => $count,
			'pages'   => $pages,
		), 200 );
	}

	/** Decoded hold meta for a post, or null when absent or not valid JSON. */
	private static function read_hold( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::VISIBILITY_HOLD_META, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$hold = json_decode( $raw, true );
		return is_array( $hold ) ? $hold : null;
	}

	/**
	 * Change post_status only, through wp_update_post() so core status hooks,
	 * caches and the sitemap/404-index invalidation run as normal. The stored
	 * title, content, excerpt, content_filtered and slug are pinned for the write
	 * (see with_pinned_fields()), so only post_status and post_modified change.
	 */
	private static function set_status_only( \WP_Post $post, string $new_status ): bool {
		$target = (int) $post->ID;
		$result = self::with_pinned_fields( $target, self::PINNED_WITH_SLUG, static function () use ( $target, $new_status ) {
			return wp_update_post( array( 'ID' => $target, 'post_status' => $new_status ), true );
		} );
		return ! is_wp_error( $result ) && (int) $result === $target;
	}

	/** Fields a status-only write must never change. */
	const PINNED_FIELDS    = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered' );
	const PINNED_WITH_SLUG = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered', 'post_name' );

	/**
	 * Run a core status write with the post's stored fields pinned.
	 *
	 * wp_update_post(), wp_trash_post() and wp_untrash_post() all re-save the
	 * whole row through wp_insert_post(). For a signed REST request there is no
	 * user with unfiltered_html, so the stored content would be passed through
	 * kses again and lose markup (iframes, inline scripts such as JSON-LD) that
	 * was stored legitimately. For this one post and this one call, the listed
	 * fields are reset to their current database values on wp_insert_post_data.
	 * Trash and untrash leave post_name out because core manages the __trashed
	 * slug suffix there.
	 *
	 * @param int      $post_id Post being written.
	 * @param string[] $fields  Columns to pin.
	 * @param callable $write   The core call to run.
	 * @return mixed What $write returned.
	 */
	private static function with_pinned_fields( int $post_id, array $fields, callable $write ) {
		$raw = get_post( $post_id, OBJECT, 'raw' );
		if ( ! $raw instanceof \WP_Post ) {
			return $write();
		}
		$pinned = array();
		foreach ( $fields as $field ) {
			$pinned[ $field ] = (string) $raw->$field;
		}
		$pin = static function ( $data, $postarr ) use ( $pinned, $post_id ) {
			if ( (int) ( $postarr['ID'] ?? 0 ) !== $post_id ) {
				return $data;
			}
			foreach ( $pinned as $field => $value ) {
				$data[ $field ] = wp_slash( $value );
			}
			return $data;
		};

		add_filter( 'wp_insert_post_data', $pin, PHP_INT_MAX, 2 );
		try {
			return $write();
		} finally {
			remove_filter( 'wp_insert_post_data', $pin, PHP_INT_MAX );
		}
	}
}
