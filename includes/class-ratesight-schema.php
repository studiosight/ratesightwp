<?php
/**
 * Schema markup manager.
 *
 * Scans post content to detect the appropriate schema type, generates
 * JSON-LD, and injects it into wp_head. Never overwrites existing schema.
 *
 * Schema types:
 *   FAQPage     — post/page with 3+ question headings (h2/h3 ending in ?)
 *   Article     — default for posts
 *   Service     — ratesight_page with service-related content
 *   LocalBusiness — ratesight_page with address/location signals
 *   WebPage     — generic fallback for ratesight_page
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


class Ratesight_Schema {

	const META_KEY = '_rs_schema';

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	/**
	 * Detect the most appropriate schema type for a post.
	 *
	 * @return string  'FAQPage'|'Article'|'Service'|'LocalBusiness'|'WebPage'
	 */
	public static function detect_type( int $post_id ) {
		$post    = get_post( $post_id );
		$content = $post ? wp_strip_all_tags( $post->post_content ) : '';
		$title   = $post ? strtolower( $post->post_title ) : '';

		// FAQPage — 3+ headings that end with a question mark.
		if ( $post ) {
			preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/i', $post->post_content, $matches );
			$questions = array_filter( $matches[1] ?? array(), static fn( $h ) => str_ends_with( trim( wp_strip_all_tags( $h ) ), '?' ) );
			if ( count( $questions ) >= 3 ) return 'FAQPage';
		}

		$post_type = get_post_type( $post_id );

		if ( $post_type === 'post' ) {
			return 'Article';
		}

		// ratesight_page — use content signals.
		$service_words  = array( 'service', 'plumb', 'electric', 'clean', 'repair', 'install', 'hvac', 'landscap', 'pest', 'roof', 'paint', 'consult', 'attorney', 'lawyer', 'dental', 'medical', 'therapy' );
		$location_words = array( 'address', 'location', 'visit us', 'directions', 'hours', 'open', 'closed', 'near me' );

		$content_lower = strtolower( $content );

		$service_hits  = count( array_filter( $service_words,  static fn( $w ) => str_contains( $content_lower, $w ) || str_contains( $title, $w ) ) );
		$location_hits = count( array_filter( $location_words, static fn( $w ) => str_contains( $content_lower, $w ) ) );

		if ( $location_hits >= 2 ) return 'LocalBusiness';
		if ( $service_hits  >= 2 ) return 'Service';

		return 'WebPage';
	}

	// -------------------------------------------------------------------------
	// Generation
	// -------------------------------------------------------------------------

	/**
	 * Generate the JSON-LD schema for a post.
	 *
	 * @param  string|null $type  Override auto-detected type.
	 * @return array  Schema array (not yet encoded).
	 */
	public static function generate( int $post_id, ?string $type = null ) {
		$post   = get_post( $post_id );
		if ( ! $post ) return array();

		$type   = $type ?? self::detect_type( $post_id );
		$url    = get_permalink( $post_id );
		$title  = get_the_title( $post_id );
		$desc   = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
			?: get_post_meta( $post_id, 'rank_math_description', true )
			?: get_post_meta( $post_id, '_aioseo_description', true )
			?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );

		$image_url = get_the_post_thumbnail_url( $post_id, 'large' ) ?: '';
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$base = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => $title,
			'url'      => $url,
		);

		if ( $desc ) $base['description'] = $desc;
		if ( $image_url ) {
			$base['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image_url,
			);
		}

		switch ( $type ) {
			case 'Article':
				$base['headline']      = $title;
				$base['datePublished'] = get_the_date( 'c', $post_id );
				$base['dateModified']  = get_the_modified_date( 'c', $post_id );
				$base['author']        = array(
					'@type' => 'Organization',
					'name'  => $site_name,
					'url'   => $site_url,
				);
				$base['publisher']     = array(
					'@type' => 'Organization',
					'name'  => $site_name,
					'url'   => $site_url,
				);
				break;

			case 'FAQPage':
				$base['mainEntity'] = self::extract_faq_entities( $post->post_content );
				break;

			case 'Service':
				$base['provider'] = array(
					'@type' => 'LocalBusiness',
					'name'  => $site_name,
					'url'   => $site_url,
				);
				$base['areaServed'] = '';  // Placeholder — can be filled in
				break;

			case 'LocalBusiness':
				$base['@type']     = 'LocalBusiness';
				$base['telephone'] = '';  // Placeholder
				$base['address']   = array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => '',
					'addressLocality' => '',
					'addressRegion'   => '',
					'postalCode'      => '',
					'addressCountry'  => 'US',
				);
				$base['openingHours'] = array();
				break;

			case 'WebPage':
			default:
				$base['isPartOf'] = array(
					'@type' => 'WebSite',
					'name'  => $site_name,
					'url'   => $site_url,
				);
				break;
		}

		return $base;
	}

	// -------------------------------------------------------------------------
	// FAQ extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract Q&A pairs from post content (h2/h3 followed by paragraph text).
	 */
	private static function extract_faq_entities( string $content ) {
		$entities = array();
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>\s*(<p[^>]*>.*?<\/p>)/is', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$question = wp_strip_all_tags( $match[1] );
			$answer   = wp_strip_all_tags( $match[2] );
			if ( str_ends_with( trim( $question ), '?' ) && $answer ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $answer,
					),
				);
			}
		}

		return $entities;
	}

	// -------------------------------------------------------------------------
	// Storage & injection
	// -------------------------------------------------------------------------

	public static function has_schema( int $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_KEY, true );
	}

	public static function get_schema( int $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		return $raw ? (array) json_decode( $raw, true ) : array();
	}

	public static function save_schema( int $post_id, array $schema ) {
		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	public static function remove_schema( int $post_id ) {
		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Inject schema into wp_head for any post that has _rs_schema meta.
	 * Hooked to wp_head.
	 */
	public static function inject() {
		if ( ! is_singular() ) return;

		$post_id = get_queried_object_id();
		if ( ! $post_id ) return;

		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $raw ) return;

		echo '<script type="application/ld+json">' . "\n";
		echo wp_kses_post( $raw ) . "\n";
		echo '</script>' . "\n";
	}

	// -------------------------------------------------------------------------
	// Bulk check helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all Ratesight post IDs that don't have schema yet.
	 */
	public static function get_posts_without_schema() {
		global $wpdb;
		$log_table = $wpdb->prefix . RATESIGHT_LOG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			"SELECT DISTINCT l.post_id
			 FROM `{$log_table}` l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
			 WHERE l.post_id IS NOT NULL
			 AND l.status = 'success'
			 AND p.post_status = 'publish'
			 AND NOT EXISTS (
			     SELECT 1 FROM {$wpdb->postmeta} pm
			     WHERE pm.post_id = l.post_id
			     AND pm.meta_key = '_rs_schema'
			 )"
		);

		return array_map( 'intval', $post_ids );
	}
}
