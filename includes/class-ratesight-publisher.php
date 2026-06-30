<?php
/**
 * Deferred post publisher.
 *
 * Hooked to the 'ratesight_deferred_publish' WP-Cron event.
 * Runs ~15 seconds after the webhook creates a draft post, so the webhook
 * response is always fast regardless of how long the image download takes.
 *
 * Sequence:
 *   1. Verify the post still exists and is still a draft.
 *   2. Download and attach the featured image (if a URL was provided).
 *   3. Flip the post to its intended final status.
 *   4. Update the log row with the outcome and any warnings.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Publisher {

	/**
	 * Entry point for the WP-Cron event.
	 *
	 * @param int    $post_id     Post to publish.
	 * @param int    $log_id      Log row created by log_pending() in the webhook handler.
	 * @param string $image_url   Remote image URL (empty string = no image).
	 * @param string $image_name  Filename hint for the image.
	 * @param string $post_title  Used as image alt text.
	 */
	public function run( int $post_id, int $log_id, string $image_url, string $image_name, string $post_title, string $request_status = '' ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			Ratesight_Logger::log_update( $log_id, $post_id, Ratesight_Logger::STATUS_FAILED, 'Post not found during deferred publish.' );
			return;
		}

		$warnings       = array();
		$already_live   = ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true );

		// ── 1. Attach featured image — always run this regardless of status ───
		if ( $image_url !== '' ) {
			$result = ( new Ratesight_Image_Uploader() )->upload( $image_url, $image_name, $post_title );

			if ( is_wp_error( $result ) ) {
				$warnings[] = 'Image upload failed: ' . $result->get_error_message();
			} else {
				set_post_thumbnail( $post_id, (int) $result );
				wp_update_post( array( 'ID' => (int) $result, 'post_parent' => $post_id ) );
			}
		}

		// ── 2. Flip to final status — skip if already published ───────────────
		if ( $already_live ) {
			$status = empty( $warnings ) ? Ratesight_Logger::STATUS_SUCCESS : Ratesight_Logger::STATUS_SUCCESS_WARNINGS;
			Ratesight_Logger::log_update( $log_id, $post_id, $status, trim( 'Post was already live before cron fired. ' . implode( ' | ', $warnings ) ) );
			return;
		}

		// ── 3. Flip to intended final status ─────────────────────────────────
		// Per-request status (from webhook payload) takes priority over the global setting.
		$post_type    = get_post_type( $post_id );
		$status_key   = ( $post_type === 'ratesight_page' ) ? 'page_status' : 'post_status';
		$final_status = $request_status !== '' ? $request_status : Ratesight_Options::get( $status_key );
		if ( ! in_array( $final_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$final_status = 'publish';
		}

		$updated = wp_update_post( array( 'ID' => $post_id, 'post_status' => $final_status ), true );

		if ( is_wp_error( $updated ) ) {
			$warnings[] = 'Status update failed: ' . $updated->get_error_message();
			Ratesight_Logger::log_update(
				$log_id,
				$post_id,
				Ratesight_Logger::STATUS_FAILED,
				implode( ' | ', $warnings )
			);
			return;
		}

		// ── 3. Post to GBP if enabled, connected, locked, and this is a post ──
		if ( $final_status === 'publish'
			&& Ratesight_Options::get( 'gbp_post_enabled' )
			&& Ratesight_GBP_Client::is_locked()
			&& get_post_type( $post_id ) === 'post'
		) {
			$gbp_result = self::post_to_gbp( $post_id );
			if ( is_wp_error( $gbp_result ) ) {
				$warnings[] = 'GBP post failed: ' . $gbp_result->get_error_message();
			}
		}

		// ── 4. Auto-submit to Bing — silent, non-blocking ────────────────────
		if ( $final_status === 'publish' ) {
			$this->auto_submit_to_bing( $post_id, $warnings );
		}

		// ── 5. Submit to IndexNow — silent ────────────────────────────────────
		if ( $final_status === 'publish' ) {
			$url    = get_permalink( $post_id );
			$result = $url ? Ratesight_IndexNow::submit( $url ) : null;
			if ( is_wp_error( $result ) ) {
				// Only warn for real failures, not "not registered" type messages.
				$msg = $result->get_error_message();
				if ( strpos( $msg, '403' ) !== false || strpos( $msg, 'key' ) !== false ) {
					$warnings[] = 'IndexNow: ' . $msg;
				}
			}
		}

		// ── 6. Auto-generate schema if none exists ────────────────────────────
		if ( $final_status === 'publish' && ! Ratesight_Schema::has_schema( $post_id ) ) {
			$schema = Ratesight_Schema::generate( $post_id );
			if ( ! empty( $schema ) ) {
				Ratesight_Schema::save_schema( $post_id, $schema );
			}
		}

		// ── 7. Store content hash for update detection ────────────────────────
		if ( $final_status === 'publish' ) {
			Ratesight_Update_Detector::store_original_hash( $post_id );
		}

		// ── 8. Log outcome ────────────────────────────────────────────────────
		$status = empty( $warnings )
			? Ratesight_Logger::STATUS_SUCCESS
			: Ratesight_Logger::STATUS_SUCCESS_WARNINGS;

		Ratesight_Logger::log_update( $log_id, $post_id, $status, implode( ' | ', $warnings ) );
	}

	// -------------------------------------------------------------------------

	/**
	 * Silently submit a newly published URL to Bing via the Worker.
	 * Non-blocking — failure adds a warning but never stops the publish flow.
	 *
	 * @param int   $post_id
	 * @param array &$warnings  Passed by reference to append any warning.
	 */
	private function auto_submit_to_bing( int $post_id, array &$warnings ) {
		$url  = get_permalink( $post_id );
		$host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';

		if ( ! $url || ! $host ) return;

		$hmac     = hash_hmac( 'sha256', $host . '|' . $url, Ratesight_OAuth_Client::token_secret() );
		$response = wp_remote_post( 'https://oauth.ratesight.com/auto-submit', array(
			'timeout'  => 8,
			'blocking' => true, // Keep blocking so we can log the result
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'host' => $host,
				'url'  => $url,
				'hmac' => $hmac,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			// Network failure — silent, no user-facing warning
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Only warn if Bing is registered but submission failed — not if it just
		// isn't registered yet (that's expected for new installs).
		if ( ! empty( $body['ok'] ) ) {
			// Success — no log entry needed, submission is expected behaviour.
			return;
		}

		$error = $body['error'] ?? '';
		if ( $error && strpos( $error, 'not registered' ) === false && strpos( $error, 'not verified' ) === false ) {
			$warnings[] = 'Bing submit: ' . $error;
		}
	}

	/**
	 * Create a GBP "What's New" post for the published post.
	 * Uses the post excerpt as the summary and the featured image URL if set.
	 */
	public static function post_to_gbp( int $post_id ): bool|WP_Error {
		$selection = Ratesight_GBP_Client::get_selection();
		$location  = $selection['id'] ?? '';
		if ( $location === '' ) {
			return new \WP_Error( 'rs_gbp_no_location', 'No GBP location selected.' );
		}

		$post    = get_post( $post_id );
		// Use the excerpt as the GBP summary. Strip HTML/entities here so the
		// GBP client receives plain UTF-8 text and doesn't double-encode anything.
		$summary = $post ? wp_strip_all_tags( html_entity_decode( get_the_excerpt( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		$url     = get_permalink( $post_id );

		// Fall back to post title if excerpt is empty.
		if ( empty( $summary ) && $post ) {
			$summary = html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$image_url = (string) get_the_post_thumbnail_url( $post_id, 'large' );

		$result = Ratesight_GBP_Client::create_post( $location, $summary, $url, $image_url );

		return is_wp_error( $result ) ? $result : true;
	}
}
