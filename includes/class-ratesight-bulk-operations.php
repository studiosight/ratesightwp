<?php
/**
 * Bulk operations for Ratesight-created posts and pages.
 *
 * Adds bulk actions to the WordPress admin list tables for both
 * 'post' and 'ratesight_page' post types:
 *
 *   - Submit to IndexNow   (queues URLs for IndexNow submission)
 *   - Check/Add Schema     (auto-generates schema for selected posts)
 *   - Submit Sitemap       (re-submits sitemap to GSC and Bing)
 *
 * Bulk actions are processed via a lightweight cron queue so selecting
 * 200+ posts doesn't time out.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Bulk_Operations {

	const QUEUE_OPTION   = 'ratesight_bulk_queue';
	const QUEUE_CRON     = 'ratesight_process_bulk_queue';
	const BATCH_SIZE     = 25;

	// -------------------------------------------------------------------------
	// Admin hooks
	// -------------------------------------------------------------------------

	public function register_hooks() {
		foreach ( array( 'post', 'ratesight_page' ) as $type ) {
			add_filter( "bulk_actions-edit-{$type}",          array( $this, 'register_bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$type}",   array( $this, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );
		add_action( self::QUEUE_CRON, array( $this, 'process_queue' ) );
	}

	public function register_bulk_actions( array $actions ) {
		$actions['rs_submit_indexnow'] = '🔍 Submit to IndexNow';
		$actions['rs_add_schema']      = '📋 Add Schema Markup';
		return $actions;
	}

	public function handle_bulk_action( string $redirect_url, string $action, array $post_ids ) {
		if ( ! in_array( $action, array( 'rs_submit_indexnow', 'rs_add_schema' ), true ) ) {
			return $redirect_url;
		}

		if ( empty( $post_ids ) ) {
			return $redirect_url;
		}

		$queued = 0;
		$queue  = get_option( self::QUEUE_OPTION, array() );

		foreach ( $post_ids as $post_id ) {
			$queue[] = array(
				'action'  => $action,
				'post_id' => (int) $post_id,
			);
			$queued++;
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		// Schedule processing if not already scheduled.
		if ( ! wp_next_scheduled( self::QUEUE_CRON ) ) {
			wp_schedule_single_event( time() + 5, self::QUEUE_CRON );
		}

		$redirect_url = add_query_arg( array(
			'rs_bulk_action'  => $action,
			'rs_bulk_queued'  => $queued,
		), $redirect_url );

		return $redirect_url;
	}

	public function bulk_action_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['rs_bulk_action'] ) || empty( $_GET['rs_bulk_queued'] ) ) return;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$action  = sanitize_key( $_GET['rs_bulk_action'] );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queued  = (int) $_GET['rs_bulk_queued'];  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$labels  = array(
			'rs_submit_indexnow' => 'IndexNow submission',
			'rs_add_schema'      => 'Schema generation',
		);

		$label = $labels[ $action ] ?? $action;
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html( sprintf( '%s queued for %d post%s. Processing in the background.', $label, $queued, $queued !== 1 ? 's' : '' ) ) .
			'</p></div>';
	}

	// -------------------------------------------------------------------------
	// Queue processing
	// -------------------------------------------------------------------------

	/**
	 * Process one batch from the queue.
	 * Reschedules itself if items remain.
	 */
	public function process_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) return;

		$batch     = array_splice( $queue, 0, self::BATCH_SIZE );
		$remaining = $queue;

		update_option( self::QUEUE_OPTION, $remaining, false );

		foreach ( $batch as $item ) {
			$this->process_item( $item['action'], (int) $item['post_id'] );
		}

		// Reschedule if more remain.
		if ( ! empty( $remaining ) ) {
			wp_schedule_single_event( time() + 10, self::QUEUE_CRON );
		}
	}

	private function process_item( string $action, int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) return;

		switch ( $action ) {
			case 'rs_submit_indexnow':
				$url = get_permalink( $post_id );
				if ( $url ) {
					Ratesight_IndexNow::submit( $url );
				}
				break;

			case 'rs_add_schema':
				// Don't overwrite existing schema.
				if ( ! Ratesight_Schema::has_schema( $post_id ) ) {
					$schema = Ratesight_Schema::generate( $post_id );
					if ( ! empty( $schema ) ) {
						Ratesight_Schema::save_schema( $post_id, $schema );
					}
				}
				break;
		}
	}
}
