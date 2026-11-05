<?php
/**
 * Image compression, phase 1: WebP on new uploads, a capability report and a dry-run inventory.
 *
 * Unreleased (no version bump). Off by default; nothing here changes existing media.
 *
 * - Capability report (capabilities.image_compression and GET /image-compression): which image
 *   editor WordPress would use (imagick or gd), whether WebP and AVIF can be written, the
 *   WordPress version. AVIF is reported usable only on WordPress 6.5+ and stays off in this phase.
 * - On upload, only while the option ratesight_image_compression is on, and only through core
 *   filters: image_editor_output_format (JPEG to WebP), wp_editor_set_quality (JPEG 82, WebP 80,
 *   AVIF 60, never below the floors 75/75/50) and big_image_size_threshold (2560). Core's
 *   original_image handling is left alone. PNG, GIF, SVG and every other type keep their format.
 *   A JPEG that is too large (over 20 MB or 40 megapixels), looks like a logo or line art by its
 *   file name, or is CMYK while GD is the editor keeps its format and the reason is recorded.
 *   When WebP cannot be written the original format is kept and format_unsupported is recorded.
 * - GET /image-compression (signed read): capability, settings, recent decisions and a dry-run
 *   inventory of image attachments by type with counts and estimated savings. Batched, time
 *   limited, writes nothing. Values that were not measured are null with a reason, never 0.
 * - POST /image-compression { enabled: bool, dry_run?: bool } (signed mutation) turns the option
 *   on or off. A hosting-only site (the visibility-hold marker of the page lifecycle, when this
 *   plugin build has it) is refused. Admins can turn it off on the AI SEO Pages tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Image_Compression {

	const ROUTE_NAMESPACE = 'ratesight/v1';
	const ROUTE_PATH      = '/image-compression';
	const OPTION          = 'ratesight_image_compression';
	const LOG_OPTION      = 'ratesight_image_compression_log';
	const LOG_MAX         = 50;
	const ADMIN_ACTION    = 'ratesight_image_compression_off';

	/** Target quality per output type; a site's own wp_editor_set_quality filter still runs after it. */
	const QUALITY = array( 'image/jpeg' => 82, 'image/webp' => 80, 'image/avif' => 60 );
	/** Floors: the final quality is never below these, whatever other filters return. */
	const FLOORS  = array( 'image/jpeg' => 75, 'image/webp' => 75, 'image/avif' => 50 );

	const BIG_IMAGE_THRESHOLD = 2560;
	const MAX_BYTES           = 20971520; // 20 MB.
	const MAX_PIXELS          = 40000000; // 40 megapixels.
	const LOGO_PATTERN        = '/(?:^|[-_.\s])(?:logo|logos|icon|icons|favicon|line[-_]?art|lineart|diagram|badge|seal|signature|wordmark)(?:[-_.\s]|$)/i';

	/** Assumed WebP (q80) saving over the stored JPEG for the dry-run estimate. Not measured. */
	const ASSUMED_WEBP_SAVING = 0.25;
	const INVENTORY_LIMIT     = 100;
	const INVENTORY_MAX_LIMIT = 200;
	const INVENTORY_SECONDS   = 2.0;

	/** The decision for the upload being processed in this request: array( 'file' => string, 'decision' => string ). */
	private static ?array $current = null;

	// -------------------------------------------------------------------------
	// Switch and hook registration
	// -------------------------------------------------------------------------

	public static function enabled(): bool {
		return (int) get_option( self::OPTION, 0 ) === 1;
	}

	/** Registers the upload filters only when the option is on. Returns whether it did. */
	public static function register(): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_handle_upload' ), 10, 2 );
		add_filter( 'image_editor_output_format', array( __CLASS__, 'filter_output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality_target' ), 5, 2 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality_floor' ), PHP_INT_MAX, 2 );
		add_filter( 'jpeg_quality', array( __CLASS__, 'filter_jpeg_floor' ), PHP_INT_MAX, 1 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'filter_big_image_threshold' ), 10, 1 );
		return true;
	}

	/** Removes every upload filter (used when the option is turned off mid-request). */
	public static function unregister(): void {
		remove_filter( 'wp_handle_upload', array( __CLASS__, 'on_handle_upload' ), 10 );
		remove_filter( 'image_editor_output_format', array( __CLASS__, 'filter_output_format' ), 10 );
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality_target' ), 5 );
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality_floor' ), PHP_INT_MAX );
		remove_filter( 'jpeg_quality', array( __CLASS__, 'filter_jpeg_floor' ), PHP_INT_MAX );
		remove_filter( 'big_image_size_threshold', array( __CLASS__, 'filter_big_image_threshold' ), 10 );
		self::$current = null;
	}

	public static function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_read' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_post' ),
				'permission_callback' => array( 'Ratesight_Request_Auth', 'authorize_mutation' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Capability
	// -------------------------------------------------------------------------

	public static function wp_version(): ?string {
		global $wp_version;
		return is_string( $wp_version ) && $wp_version !== '' ? $wp_version : null;
	}

	/** The editor core would pick for JPEG, mirroring _wp_image_editor_choose(): 'imagick', 'gd', another class name, or null. */
	public static function editor(): ?string {
		$editors = apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );
		foreach ( (array) $editors as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'test' ) ) {
				continue;
			}
			if ( ! call_user_func( array( $class, 'test' ), array( 'mime_type' => 'image/jpeg' ) ) ) {
				continue;
			}
			if ( ! call_user_func( array( $class, 'supports_mime_type' ), 'image/jpeg' ) ) {
				continue;
			}
			if ( $class === 'WP_Image_Editor_Imagick' ) {
				return 'imagick';
			}
			if ( $class === 'WP_Image_Editor_GD' ) {
				return 'gd';
			}
			return $class;
		}
		return null;
	}

	private static function imagick_formats(): array {
		if ( ! class_exists( 'Imagick' ) || ! method_exists( 'Imagick', 'queryFormats' ) ) {
			return array( 'available' => false, 'webp' => false, 'avif' => false );
		}
		try {
			return array(
				'available' => true,
				'webp'      => (bool) \Imagick::queryFormats( 'WEBP' ),
				'avif'      => (bool) \Imagick::queryFormats( 'AVIF' ),
			);
		} catch ( \Throwable $e ) {
			return array( 'available' => true, 'webp' => null, 'avif' => null, 'reason' => 'imagick_query_failed' );
		}
	}

	private static function gd_formats(): array {
		if ( ! function_exists( 'imagetypes' ) ) {
			return array( 'available' => false, 'webp' => false, 'avif' => false );
		}
		$types = (int) imagetypes();
		return array(
			'available' => true,
			'webp'      => defined( 'IMG_WEBP' ) && ( $types & IMG_WEBP ) !== 0,
			// GD writes AVIF only from PHP 8.1.
			'avif'      => PHP_VERSION_ID >= 80100 && defined( 'IMG_AVIF' ) && ( $types & IMG_AVIF ) !== 0,
		);
	}

	public static function webp_supported(): bool {
		return function_exists( 'wp_image_editor_supports' ) && (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	public static function capability(): array {
		$wp        = self::wp_version();
		$avif_lib  = function_exists( 'wp_image_editor_supports' ) && (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
		$wp_avif   = $wp !== null && version_compare( $wp, '6.5', '>=' );
		$avif_note = $avif_lib && ! $wp_avif ? 'wordpress_below_6_5' : ( $avif_lib ? null : 'editor_cannot_write_avif' );
		return array(
			'contract'     => 1,
			'editor'       => self::editor(),
			'webp'         => self::webp_supported(),
			'avif'         => $avif_lib && $wp_avif,
			'avif_reason'  => $avif_note,
			'avif_enabled' => false, // AVIF is opt-in and off in phase 1.
			'imagick'      => self::imagick_formats(),
			'gd'           => self::gd_formats(),
			'wp_version'   => $wp,
			'php_version'  => PHP_VERSION,
			'enabled'      => self::enabled(),
		);
	}

	// -------------------------------------------------------------------------
	// Upload filters
	// -------------------------------------------------------------------------

	/**
	 * Decide for one JPEG source file: 'convert', 'too_large', 'logo_or_line_art', 'cmyk_gd' or 'unreadable'.
	 * Reads only the file size and image header.
	 */
	public static function decide( string $file, ?string $editor ): string {
		$size = @filesize( $file );
		if ( $size === false ) {
			return 'unreadable';
		}
		if ( $size > self::MAX_BYTES ) {
			return 'too_large';
		}
		$info = @getimagesize( $file );
		if ( ! is_array( $info ) ) {
			return 'unreadable';
		}
		if ( (int) $info[0] * (int) $info[1] > self::MAX_PIXELS ) {
			return 'too_large';
		}
		if ( preg_match( self::LOGO_PATTERN, pathinfo( $file, PATHINFO_FILENAME ) ) ) {
			return 'logo_or_line_art';
		}
		if ( $editor === 'gd' && (int) ( $info['channels'] ?? 3 ) === 4 ) {
			return 'cmyk_gd';
		}
		return 'convert';
	}

	/** wp_handle_upload: inspect a new JPEG once, before core makes the -scaled file and sub-sizes. */
	public static function on_handle_upload( $upload, $context = 'upload' ) {
		self::$current = null;
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || ( $upload['type'] ?? '' ) !== 'image/jpeg' || empty( $upload['file'] ) ) {
			return $upload;
		}
		$file     = (string) $upload['file'];
		$decision = self::webp_supported() ? self::decide( $file, self::editor() ) : 'format_unsupported';
		self::$current = array( 'file' => $file, 'decision' => $decision );
		self::record( $decision === 'convert' ? 'webp_requested' : $decision, basename( $file ), 'image/jpeg', (string) $context );
		return $upload;
	}

	/**
	 * image_editor_output_format: JPEG to WebP for the upload inspected in this request.
	 * Every other type is returned unchanged. With no upload context (an edit in the media
	 * editor, a later sub-size pass) nothing is converted.
	 */
	public static function filter_output_format( $formats, $filename = null, $mime_type = null ) {
		$formats = is_array( $formats ) ? $formats : array();
		if ( $mime_type !== 'image/jpeg' ) {
			return $formats;
		}
		if ( self::$current === null || self::$current['decision'] !== 'convert' ) {
			return $formats;
		}
		if ( ! self::webp_supported() ) {
			return $formats;
		}
		$formats['image/jpeg'] = 'image/webp';
		return $formats;
	}

	public static function filter_quality_target( $quality, $mime_type = '' ) {
		return self::QUALITY[ $mime_type ] ?? $quality;
	}

	public static function filter_quality_floor( $quality, $mime_type = '' ) {
		if ( ! isset( self::FLOORS[ $mime_type ] ) ) {
			return $quality;
		}
		return min( 100, max( self::FLOORS[ $mime_type ], (int) $quality ) );
	}

	public static function filter_jpeg_floor( $quality ) {
		return self::filter_quality_floor( $quality, 'image/jpeg' );
	}

	public static function filter_big_image_threshold( $threshold ) {
		return self::BIG_IMAGE_THRESHOLD;
	}

	/** Newest LOG_MAX decisions and switch changes. File basenames only, never paths. */
	public static function record( string $event, string $file, string $mime, string $source ): void {
		$rows   = get_option( self::LOG_OPTION, array() );
		$rows   = is_array( $rows ) ? $rows : array();
		$rows[] = array( 'time' => gmdate( 'c' ), 'event' => $event, 'file' => $file, 'mime' => $mime, 'source' => $source );
		update_option( self::LOG_OPTION, array_slice( $rows, -self::LOG_MAX ), false );
	}

	// -------------------------------------------------------------------------
	// Hosting-only marker
	// -------------------------------------------------------------------------

	/**
	 * Whether this is a hosting-only site. The marker is the page lifecycle's visibility hold
	 * (reason hosting_only) when this plugin build has it; without it the answer says so.
	 */
	public static function hosting_only(): array {
		$constant = 'Ratesight_Page_Lifecycle::VISIBILITY_HOLD_META';
		if ( ! defined( $constant ) ) {
			return array( 'hosting_only' => false, 'basis' => 'marker_unavailable' );
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
			constant( $constant ),
			'%' . $wpdb->esc_like( '"hosting_only"' ) . '%'
		) );
		if ( $found === null && ! empty( $wpdb->last_error ) ) {
			return array( 'hosting_only' => null, 'basis' => 'marker_unreadable' );
		}
		return array( 'hosting_only' => $found !== null, 'basis' => 'visibility_hold_marker' );
	}

	// -------------------------------------------------------------------------
	// Dry-run inventory (reads only)
	// -------------------------------------------------------------------------

	/**
	 * @param int $limit    JPEG files to measure in this batch (1..200).
	 * @param int $after_id Cursor: measure JPEG attachments with an ID above this.
	 * @return array|null   Null when the attachment counts cannot be read.
	 */
	public static function inventory( int $limit = self::INVENTORY_LIMIT, int $after_id = 0 ): ?array {
		global $wpdb;
		$limit    = max( 1, min( self::INVENTORY_MAX_LIMIT, $limit ) );
		$after_id = max( 0, $after_id );
		$counts   = $wpdb->get_results(
			"SELECT post_mime_type AS mime, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' GROUP BY post_mime_type",
			ARRAY_A
		);
		if ( ! is_array( $counts ) ) {
			return null;
		}
		$webp     = self::webp_supported();
		$by_mime  = array();
		$jpeg_all = 0;
		foreach ( $counts as $row ) {
			$mime = (string) $row['mime'];
			$n    = (int) $row['n'];
			if ( $mime === 'image/jpeg' ) {
				$jpeg_all = $n;
			}
			$by_mime[] = array(
				'mime'     => $mime,
				'count'    => $n,
				'eligible' => $mime === 'image/jpeg' && $webp,
				'reason'   => $mime === 'image/jpeg' ? ( $webp ? null : 'format_unsupported' ) : 'not_converted_in_phase_1',
			);
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS id, pm.meta_value AS file FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/jpeg' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
			$after_id,
			$limit
		), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$base    = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$started = microtime( true );
		$sample  = array( 'files' => 0, 'measured' => 0, 'bytes' => 0, 'missing' => 0, 'too_large' => 0 );
		$last_id = $after_id;
		$timed   = false;
		foreach ( $rows as $row ) {
			if ( microtime( true ) - $started > self::INVENTORY_SECONDS ) {
				$timed = true;
				break;
			}
			$last_id = (int) $row['id'];
			$sample['files']++;
			$relative = (string) ( $row['file'] ?? '' );
			$path     = $base !== false && $relative !== '' ? realpath( $base . '/' . ltrim( $relative, '/' ) ) : false;
			if ( $path === false || ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
				$sample['missing']++;
				continue;
			}
			$size = @filesize( $path );
			if ( $size === false ) {
				$sample['missing']++;
				continue;
			}
			if ( $size > self::MAX_BYTES ) {
				$sample['too_large']++;
				continue;
			}
			$sample['measured']++;
			$sample['bytes'] += $size;
		}
		$more = $timed || count( $rows ) === $limit;

		$savings = array( 'sample_bytes' => null, 'all_jpeg_bytes' => null, 'basis' => 'assumed_ratio_not_measured', 'ratio' => self::ASSUMED_WEBP_SAVING, 'reason' => null );
		if ( ! $webp ) {
			$savings['reason'] = 'format_unsupported';
		} elseif ( $sample['measured'] === 0 ) {
			$savings['reason'] = $base === false ? 'uploads_dir_unreadable' : 'no_files_measured';
		} else {
			$savings['sample_bytes'] = (int) round( $sample['bytes'] * self::ASSUMED_WEBP_SAVING );
			// Extrapolated from the measured average unless every JPEG was measured in this batch.
			$savings['all_jpeg_bytes'] = (int) round( $sample['bytes'] / $sample['measured'] * $jpeg_all * self::ASSUMED_WEBP_SAVING );
			$savings['extrapolated']   = $sample['measured'] !== $jpeg_all;
		}

		return array(
			'dry_run'     => true,
			'writes'      => 'none',
			'scope'       => 'main_file_only',
			'by_mime'     => $by_mime,
			'jpeg_sample' => array(
				'after_id'      => $after_id,
				'limit'         => $limit,
				'files'         => $sample['files'],
				'measured'      => $sample['measured'],
				'measured_bytes' => $sample['measured'] > 0 ? $sample['bytes'] : null,
				'missing_files' => $sample['missing'],
				'too_large'     => $sample['too_large'],
				'too_large_pixels' => null,
				'too_large_pixels_reason' => 'not_measured_in_dry_run',
				'timed_out'     => $timed,
				'next_after_id' => $more ? $last_id : null,
			),
			'estimated_savings' => $savings,
		);
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public static function settings(): array {
		return array(
			'enabled'                  => self::enabled(),
			'avif_enabled'             => false,
			'quality'                  => self::QUALITY,
			'quality_floors'           => self::FLOORS,
			'big_image_size_threshold' => self::BIG_IMAGE_THRESHOLD,
			'max_bytes'                => self::MAX_BYTES,
			'max_pixels'               => self::MAX_PIXELS,
			'converts'                 => array( 'image/jpeg' => 'image/webp' ),
			'existing_media'           => 'unchanged',
		);
	}

	public static function handle_get( $request ) {
		$params    = $request && method_exists( $request, 'get_query_params' ) ? (array) $request->get_query_params() : array();
		$inventory = self::inventory( (int) ( $params['limit'] ?? self::INVENTORY_LIMIT ), (int) ( $params['after_id'] ?? 0 ) );
		if ( $inventory === null ) {
			return new \WP_Error( 'rs_media_inventory_unavailable', 'The media library could not be read.', array( 'status' => 503 ) );
		}
		$rows = get_option( self::LOG_OPTION, array() );
		return new \WP_REST_Response( array(
			'ok'           => true,
			'capability'   => self::capability(),
			'settings'     => self::settings(),
			'hosting_only' => self::hosting_only(),
			'recent'       => is_array( $rows ) ? array_values( array_slice( $rows, -25 ) ) : array(),
			'inventory'    => $inventory,
		), 200 );
	}

	public static function handle_post( $request ) {
		$data = $request->get_json_params() ?: $request->get_body_params();
		$data = is_array( $data ) ? $data : array();
		if ( ! array_key_exists( 'enabled', $data ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'code' => 'rs_image_compression_enabled_required', 'message' => '"enabled" is required (true or false).' ), 422 );
		}
		$enabled = is_bool( $data['enabled'] ) ? $data['enabled'] : filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( $enabled === null ) {
			return new \WP_REST_Response( array( 'ok' => false, 'code' => 'rs_image_compression_enabled_invalid', 'message' => '"enabled" must be true or false.' ), 422 );
		}
		$dry_run = filter_var( $data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
		$before  = self::enabled();
		if ( $enabled ) {
			$hosting = self::hosting_only();
			if ( $hosting['hosting_only'] !== false ) {
				return new \WP_REST_Response( array(
					'ok'           => false,
					'code'         => $hosting['hosting_only'] === null ? 'rs_image_compression_hosting_state_unknown' : 'rs_image_compression_hosting_only',
					'message'      => 'Image compression cannot be turned on for a hosting-only site.',
					'hosting_only' => $hosting,
					'enabled'      => $before,
				), 409 );
			}
		}
		$webp = self::webp_supported();
		$outcome = $enabled ? ( $webp ? 'webp_on_new_uploads' : 'format_unsupported' ) : 'off';
		if ( $dry_run ) {
			return new \WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'before' => array( 'enabled' => $before ), 'would' => array( 'enabled' => $enabled ), 'webp_conversion' => $outcome, 'existing_media' => 'unchanged' ), 200 );
		}
		if ( $enabled !== $before ) {
			update_option( self::OPTION, $enabled ? 1 : 0 );
			self::record( $enabled ? 'enabled' : 'disabled', '', '', 'signed_api' );
		}
		$after = self::enabled();
		if ( $after !== $enabled ) {
			return new \WP_REST_Response( array( 'ok' => false, 'code' => 'rs_image_compression_write_failed', 'message' => 'The setting did not save.', 'enabled' => $after ), 500 );
		}
		if ( ! $after ) {
			self::unregister();
		}
		return new \WP_REST_Response( array(
			'ok'              => true,
			'dry_run'         => false,
			'before'          => array( 'enabled' => $before ),
			'after'           => array( 'enabled' => $after ),
			'webp_conversion' => $outcome,
			'existing_media'  => 'unchanged',
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Admin: turn off
	// -------------------------------------------------------------------------

	public static function handle_admin_off(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to change this setting.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ADMIN_ACTION );
		if ( self::enabled() ) {
			update_option( self::OPTION, 0 );
			self::record( 'disabled', '', '', 'wp_admin' );
		}
		self::unregister();
		wp_safe_redirect( admin_url( 'admin.php?page=ratesight&tab=seo-pages&rs_image_compression=off' ) );
		exit;
	}
}
