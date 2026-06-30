<?php
/**
 * Downloads a remote image and adds it to the WordPress Media Library.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Image_Uploader {

	/**
	 * @param string $image_url   Remote URL of the image.
	 * @param string $image_name  Desired filename hint (e.g. "my-image.jpg").
	 * @param string $post_title  Used as the attachment title and alt text.
	 * @return int|\WP_Error  Attachment ID on success.
	 */
	public function upload( string $image_url, string $image_name, string $post_title = '' ): int|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $this->download( $image_url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename   = $this->resolve_filename( $image_name, $image_url, $tmp );
		$file_array = array( 'name' => $filename, 'tmp_name' => $tmp );

		$attachment_id = media_handle_sideload( $file_array, 0, $post_title );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $attachment_id;
		}

		if ( ! empty( $post_title ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $post_title ) );
		}

		return (int) $attachment_id;
	}

	// -------------------------------------------------------------------------

	private function download( string $url ): string|WP_Error {
		$response = wp_safe_remote_get( $url, array(
			'timeout'  => 30,
			'stream'   => true,
			'filename' => wp_tempnam(),
		) );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rs_download_failed', 'Image download failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'rs_download_http', sprintf( 'Image URL returned HTTP %d.', $code ) );
		}

		return $response['filename'];
	}

	private function resolve_filename( string $image_name, string $image_url, string $tmp ) {
		if ( ! empty( $image_name ) ) {
			$name = sanitize_file_name( $image_name );
			if ( $this->has_image_ext( $name ) ) {
				return $name;
			}
		}

		$url_path = wp_parse_url( $image_url, PHP_URL_PATH );
		if ( $url_path ) {
			$base = basename( $url_path );
			if ( $this->has_image_ext( $base ) ) {
				return sanitize_file_name( $base );
			}
		}

		$ext_map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' );
		$mime    = mime_content_type( $tmp );
		$ext     = $ext_map[ $mime ] ?? 'jpg';

		return 'ratesight-image-' . time() . '.' . $ext;
	}

	private function has_image_ext( string $filename ) {
		return in_array(
			strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ),
			array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff' ),
			true
		);
	}
}
