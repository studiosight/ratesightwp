<?php
/**
 * REST API: set the alt text on one media attachment.
 *
 * WHY THIS EXISTS. Alt text was only ever settable IMPLICITLY, at image-upload time
 * (class-ratesight-image-uploader.php sets _wp_attachment_image_alt when it sideloads a file). There
 * was no way to correct alt text afterwards, for images this plugin did not upload, or for the
 * thousands of `image_alt_missing` / `image_alt_junk` findings the dashboard's SEO audit produces
 * against existing library images. The audit could see the problem and had nowhere to send the fix.
 *
 * SCOPE, deliberately narrow: one attachment, one meta key (`_wp_attachment_image_alt`), one value.
 * It does not touch post_content, captions, titles, filenames, or the file itself. Nothing here
 * re-renders or re-saves a page.
 *
 * Endpoint (namespace ratesight/v1):
 *   POST /media-alt   { attachment_id | url, alt_text, dry_run? }
 *
 * Returns the BEFORE and AFTER value so a caller can verify the write without a second request, and
 * so a caller that expected to change something and changed nothing can tell. Protected by the
 * shared Ratesight_Request_Auth signed_mutation policy.
 *
 * Since 3.4.0.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Media_Alt {

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/media-alt';
	const META_KEY        = '_wp_attachment_image_alt';

	/** WordPress stores alt text in a meta row; this bounds it so a bad caller cannot write an essay. */
	const MAX_ALT_LENGTH = 300;

	public static function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_set_alt' ),
			'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
		) );
	}

	/**
	 * POST /wp-json/ratesight/v1/media-alt
	 *
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_set_alt( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $request->get_json_params() ?: $request->get_body_params();

		if ( ! is_array( $data ) || ! array_key_exists( 'alt_text', $data ) ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'Required field "alt_text" is missing. Send an empty string to clear it deliberately.',
			), 422 );
		}

		$attachment_id = self::resolve_attachment_id( $data );
		if ( ! $attachment_id ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'No attachment found. Send attachment_id, or url pointing at a media file on this site.',
			), 404 );
		}

		// An id that resolves to something that is not an attachment would write this meta key onto a
		// post, where nothing reads it. Refuse rather than write somewhere harmless-looking.
		if ( get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_REST_Response( array(
				'success'       => false,
				'attachment_id' => $attachment_id,
				'message'       => 'Post #' . $attachment_id . ' is not an attachment.',
			), 422 );
		}

		$alt_text = sanitize_text_field( (string) $data['alt_text'] );
		// mbstring is not guaranteed on every host, and a length CHECK is not worth a fatal.
		$alt_length = function_exists( 'mb_strlen' ) ? mb_strlen( $alt_text ) : strlen( $alt_text );
		if ( $alt_length > self::MAX_ALT_LENGTH ) {
			return new \WP_REST_Response( array(
				'success' => false,
				'message' => 'alt_text exceeds ' . self::MAX_ALT_LENGTH . ' characters.',
			), 422 );
		}

		$alt_before = (string) get_post_meta( $attachment_id, self::META_KEY, true );
		$dry_run    = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;

		if ( $dry_run ) {
			return new \WP_REST_Response( array(
				'success'       => true,
				'dry_run'       => true,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'alt_before'    => $alt_before,
				'alt_after'     => $alt_before,
				'would_write'   => $alt_text,
				'changed'       => $alt_text !== $alt_before,
				'message'       => 'Dry run. Nothing was written.',
			), 200 );
		}

		if ( $alt_text === '' ) {
			delete_post_meta( $attachment_id, self::META_KEY );
		} else {
			update_post_meta( $attachment_id, self::META_KEY, $alt_text );
		}

		// Read back rather than echo what we sent: update_post_meta returns false both when the write
		// failed and when the value was already identical, so its return value cannot be a verdict.
		$alt_after = (string) get_post_meta( $attachment_id, self::META_KEY, true );

		if ( class_exists( 'Ratesight_Logger' ) ) {
			$title = get_the_title( $attachment_id );
			Ratesight_Logger::log_update(
				Ratesight_Logger::log_pending( "Alt text: {$title}", '', wp_json_encode( array( 'attachment_id' => $attachment_id, 'alt_text' => $alt_text ) ) ),
				$attachment_id,
				Ratesight_Logger::STATUS_MODIFIED,
				sprintf( 'Alt text set on attachment #%d: "%s" -> "%s".', $attachment_id, $alt_before, $alt_after )
			);
		}

		return new \WP_REST_Response( array(
			'success'       => $alt_after === $alt_text,
			'dry_run'       => false,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'alt_before'    => $alt_before,
			'alt_after'     => $alt_after,
			'changed'       => $alt_after !== $alt_before,
		), 200 );
	}

	/**
	 * Resolve the attachment from an explicit id or a media url.
	 *
	 * attachment_url_to_postid() matches the FULL-SIZE url only, so a caller holding a resized
	 * src (…-300x200.jpg) is retried against the stripped base name rather than reported missing.
	 *
	 * @param  array $data Request body.
	 * @return int         Attachment id, or 0.
	 */
	private static function resolve_attachment_id( array $data ): int {
		if ( ! empty( $data['attachment_id'] ) ) {
			return (int) $data['attachment_id'];
		}
		if ( empty( $data['url'] ) ) {
			return 0;
		}
		$url = esc_url_raw( (string) $data['url'] );
		$id  = (int) attachment_url_to_postid( $url );
		if ( $id ) {
			return $id;
		}
		$full = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
		return $full && $full !== $url ? (int) attachment_url_to_postid( $full ) : 0;
	}
}
