<?php
/**
 * Durable signed notification of ordinary WordPress blog publication transitions.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Publication_Events {
	public const CONTRACT       = 'ratesight-wordpress-publication-event-v1';
	public const ENDPOINT_ROUTE = '/api/public/wordpress/publication-events';
	public const SEQUENCE_META  = '_ratesight_publication_event_sequence';
	public const TABLE          = 'ratesight_publication_events';

	public static function on_transition( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		$oid    = trim( (string) Ratesight_Options::get( 'code_id' ) );
		$secret = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( ! preg_match( '/^[0-9]{1,20}$/', $oid ) || '' === $secret || ! Ratesight_Pairing::is_connected() ) {
			return;
		}

		$url = get_permalink( $post );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		$sequence = max( 0, (int) get_post_meta( $post->ID, self::SEQUENCE_META, true ) ) + 1;
		update_post_meta( $post->ID, self::SEQUENCE_META, $sequence );
		$published_at = self::iso_from_gmt( (string) $post->post_date_gmt );
		$modified_at  = self::iso_from_gmt( (string) $post->post_modified_gmt );
		$title        = wp_strip_all_tags( (string) get_the_title( $post ), true );
		$excerpt      = wp_strip_all_tags( (string) ( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 45, '' ) ), true );
		$image         = get_the_post_thumbnail_url( $post, 'full' );
		$content_hash  = hash( 'sha256', wp_json_encode( array( $url, $title, $excerpt, $image ?: null, $published_at, $modified_at, $sequence ), JSON_UNESCAPED_SLASHES ) );
		$event_hash   = substr( hash( 'sha256', implode( '|', array( $oid, $post->ID, $sequence, $url, $content_hash ) ) ), 0, 24 );
		$payload      = array(
			'contract'       => self::CONTRACT,
			'eventType'      => 'post.published',
			'eventId'        => "wpevt:v1:{$oid}:{$post->ID}:{$event_hash}",
			'oid'            => $oid,
			'siteOrigin'     => untrailingslashit( home_url( '/' ) ),
			'postId'         => (int) $post->ID,
			'postType'       => 'post',
			'canonicalUrl'   => esc_url_raw( $url ),
			'title'          => wp_html_excerpt( $title, 300, '' ),
			'excerpt'        => wp_html_excerpt( $excerpt, 1000, '' ),
			'imageUrl'       => $image ? esc_url_raw( $image ) : null,
			'publishedAt'    => $published_at,
			'modifiedAt'     => $modified_at,
			'updateSequence' => $sequence,
			'contentHash'    => $content_hash,
		);
		self::enqueue( $payload );
	}

	public static function drain(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( "SELECT event_id, body, attempts FROM `{$table}` WHERE next_attempt_at <= UTC_TIMESTAMP() ORDER BY queued_at ASC LIMIT 25", ARRAY_A );
		foreach ( (array) $items as $item ) {
			$event_id = (string) $item['event_id'];
			$result   = self::send( (string) $item['body'] );
			if ( true === $result ) {
				$wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%s' ) );
				continue;
			}
			$attempts = (int) ( $item['attempts'] ?? 0 ) + 1;
			$delay = min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 5, max( 0, $attempts - 1 ) ) ) );
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"UPDATE `{$table}` SET attempts=%d,last_error=%s,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND) WHERE event_id=%s",
				$attempts, sanitize_key( (string) $result ), $delay, $event_id
			) );
		}
	}

	public static function status(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) pending, MIN(queued_at) oldest FROM `{$table}`", ARRAY_A );
		return array(
			'pending'        => (int) ( $row['pending'] ?? 0 ),
			'oldestQueuedAt' => ! empty( $row['oldest'] ) ? gmdate( 'c', strtotime( (string) $row['oldest'] . ' UTC' ) ) : null,
		);
	}

	private static function enqueue( array $payload ): void {
		global $wpdb;
		$table    = $wpdb->prefix . self::TABLE;
		$event_id = (string) $payload['eventId'];
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"INSERT IGNORE INTO `{$table}` (event_id,body,attempts,queued_at,next_attempt_at) VALUES (%s,%s,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
			$event_id, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )
		) );
		wp_schedule_single_event( time() + 1, 'ratesight_retry_pending' );
	}

	private static function send( string $body ) {
		$secret = (string) get_option( 'ratesight_webhook_secret', '' );
		if ( '' === $secret ) return 'secret_missing';
		$endpoint = (string) apply_filters( 'ratesight_publication_event_endpoint', 'https://dash.ratesight.com' . self::ENDPOINT_ROUTE );
		$headers = Ratesight_Request_Auth::signed_headers( $secret, 'POST', self::ENDPOINT_ROUTE, array(), $body );
		$response = wp_remote_post( $endpoint, array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $headers ),
			'body'        => $body,
		) );
		if ( is_wp_error( $response ) ) return 'network_error';
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code   = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
		if ( 200 === $status && in_array( $code, array( 'wordpress_publication_event_accepted', 'wordpress_publication_event_duplicate' ), true ) ) return true;
		return $code ?: 'http_' . $status;
	}

	private static function iso_from_gmt( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s.000\Z', false === $timestamp ? time() : $timestamp );
	}
}
