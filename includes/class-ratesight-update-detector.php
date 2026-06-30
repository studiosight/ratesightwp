<?php
/**
 * Detects when a Ratesight-created post is modified by an editor.
 *
 * On creation, stores a hash of the original title + content.
 * On every subsequent save, compares the hash and logs a note
 * if the content changed significantly.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Update_Detector {

	const META_HASH = '_rs_content_hash';

	/**
	 * Store the original content hash when a post is first created.
	 * Called by the publisher after a post is promoted to publish.
	 */
	public static function store_original_hash( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return;

		// Only store once — don't overwrite if it already exists.
		if ( get_post_meta( $post_id, self::META_HASH, true ) ) return;

		update_post_meta( $post_id, self::META_HASH, self::hash( $post ) );
	}

	/**
	 * Hook: post_updated
	 * Fires whenever a post is saved. Checks if content changed vs original.
	 */
	public static function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ) {
		// Only care about published posts.
		if ( $post_after->post_status !== 'publish' ) return;

		// Only care about post types we manage.
		if ( ! in_array( $post_after->post_type, array( 'post', 'ratesight_page' ), true ) ) return;

		$original_hash = get_post_meta( $post_id, self::META_HASH, true );

		// Not a Ratesight-created post — skip.
		if ( ! $original_hash ) return;

		$current_hash = self::hash( $post_after );

		// No change — skip.
		if ( hash_equals( $original_hash, $current_hash ) ) return;

		// Determine what changed.
		$changes = array();
		if ( $post_after->post_title   !== $post_before->post_title   ) $changes[] = 'title';
		if ( $post_after->post_content !== $post_before->post_content ) $changes[] = 'content';
		if ( $post_after->post_excerpt !== $post_before->post_excerpt ) $changes[] = 'excerpt';

		if ( empty( $changes ) ) return;

		// Log the modification.
		global $wpdb;
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;

		$editor = wp_get_current_user();
		$editor_name = $editor && $editor->ID ? $editor->display_name : 'Unknown';

		// Insert a note row referencing the original post.
		$wpdb->insert( $log_table, array(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			'received_at'    => current_time( 'mysql' ),
			'post_id'        => $post_id,
			'title'          => $post_after->post_title,
			'status'         => 'modified',
			'notes'          => sprintf(
				'Post modified by %s — changed: %s',
				$editor_name,
				implode( ', ', $changes )
			),
		), array( '%s', '%d', '%s', '%s', '%s' ) );

		// Update the stored hash so we only log each unique change once.
		update_post_meta( $post_id, self::META_HASH, $current_hash );

		// Invalidate schema cache — content changed so schema may need regenerating.
		Ratesight_Schema::remove_schema( $post_id );
	}

	private static function hash( \WP_Post $post ) {
		return md5( $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt );
	}
}
