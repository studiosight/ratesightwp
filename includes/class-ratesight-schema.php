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

	/** Class on the injected script tag, so a reader can tell this plugin's block from any other JSON-LD. Since 3.15.0. */
	const MARKER_CLASS = 'ratesight-schema';

	/** Largest JSON-LD document update-page accepts (bytes, encoded). Since 3.15.0. */
	const MAX_WRITE_BYTES = 32768;

	/** Flags for every JSON-LD this plugin prints: no raw <, >, & or ' can close the script element. */
	const PRINT_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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

	// -------------------------------------------------------------------------
	// Change-control write path (POST /update-page `schema`, since 3.15.0)
	// -------------------------------------------------------------------------

	/**
	 * The stored JSON-LD exactly as kept in _rs_schema ('' when none). GET /update-page reports it so a
	 * caller can compare, and send it back to restore it.
	 */
	public static function stored_json( int $post_id ): string {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		return is_string( $raw ) ? $raw : '';
	}

	/** sha256 of the stored JSON-LD ('' when none). */
	public static function stored_hash( int $post_id ): string {
		$raw = self::stored_json( $post_id );
		return $raw === '' ? '' : hash( 'sha256', $raw );
	}

	/**
	 * Validate a `schema` value sent to update-page. Accepts a JSON string or an already-decoded object.
	 * Returns array( 'remove' => true ) for null or '' (delete the stored block), array( 'json' => <canonical
	 * encoding>, 'data' => <array> ) for a valid document, or array( 'error' => <reason> ).
	 *
	 * A valid document is ONE JSON object whose @context is schema.org and which carries @type or @graph,
	 * at most MAX_WRITE_BYTES once encoded. Nothing else is interpreted: the caller (the dashboard's change
	 * control) owns what goes into it and verifies the served page after the write.
	 *
	 * @param mixed $value Value of the `schema` key.
	 */
	/** array_is_list() for PHP 8.0 (the plugin's minimum). */
	private static function is_list( array $value ): bool {
		return $value === array() || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	public static function validate_for_write( $value ): array {
		if ( $value === null || $value === '' ) {
			return array( 'remove' => true );
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( ! is_array( $decoded ) ) {
				return array( 'error' => 'schema is not valid JSON.' );
			}
			$value = $decoded;
		}
		if ( ! is_array( $value ) || self::is_list( $value ) ) {
			return array( 'error' => 'schema must be one JSON object (use @graph for several blocks).' );
		}
		$context = $value['@context'] ?? null;
		if ( ! is_string( $context ) || ! preg_match( '#^https?://schema\.org/?$#', $context ) ) {
			return array( 'error' => 'schema @context must be https://schema.org.' );
		}
		if ( ! isset( $value['@type'] ) && ! isset( $value['@graph'] ) ) {
			return array( 'error' => 'schema needs @type or @graph.' );
		}
		if ( isset( $value['@graph'] ) && ( ! is_array( $value['@graph'] ) || ! self::is_list( $value['@graph'] ) || ! $value['@graph'] ) ) {
			return array( 'error' => 'schema @graph must be a non-empty list.' );
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return array( 'error' => 'schema could not be encoded.' );
		}
		if ( strlen( $json ) > self::MAX_WRITE_BYTES ) {
			return array( 'error' => 'schema exceeds ' . self::MAX_WRITE_BYTES . ' bytes.' );
		}
		return array( 'json' => $json, 'data' => $value );
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

		// Since 3.15.0: decoded and re-encoded with every HTML-significant character escaped, instead of
		// passed through wp_kses_post, which turned a literal & inside a JSON string into &amp; (a changed
		// value) and could not make the text safe inside a script element anyway. A stored value that is
		// not JSON is not printed.
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) return;
		$json = wp_json_encode( $data, self::PRINT_FLAGS );
		if ( ! is_string( $json ) ) return;

		echo '<script type="application/ld+json" class="' . esc_attr( self::MARKER_CLASS ) . '">' . "\n";
		echo $json . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS: no character can close the script element.
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
