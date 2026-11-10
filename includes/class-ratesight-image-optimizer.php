<?php
/**
 * Image optimization: resize oversized uploads, compress / convert to WebP (AVIF when the server
 * can write it), strip personal and location metadata, and write the client's configured business
 * location. Off until a site owner turns it on (Ratesight > Image optimization).
 *
 * NEW UPLOADS (Media Library, block editor, REST, media_handle_sideload, this plugin's own
 * Ratesight_Image_Uploader) are processed in the wp_handle_upload filter, BEFORE WordPress creates
 * the attachment and its sizes. The attachment is therefore created with the optimized file, URL
 * and MIME type from the start: nothing has linked to the original yet. Every size WordPress then
 * generates is scrubbed in wp_generate_attachment_metadata.
 *
 * EXISTING IMAGES (bulk tool) are optimized IN PLACE: same file names, same format, same URLs.
 * Existing posts, page-builder data, CDN and cache entries keep pointing at files that still exist.
 * Format conversion is deliberately limited to new uploads for that reason.
 *
 * Idempotent: an optimized attachment carries the META_KEY record and is never processed again by
 * the bulk tool. A size regeneration (e.g. Regenerate Thumbnails) re-scrubs the new size files only.
 *
 * No remote calls, no shell commands. Pixel work goes through WordPress's own image editors
 * (Imagick or GD); metadata work is pure PHP (Ratesight_Image_Metadata).
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

final class Ratesight_Image_Optimizer {

	/** Attachment meta: the optimization record (presence = done). */
	const META_KEY = '_rs_img_opt';
	/** Attachment meta: last failure, so the bulk tool does not retry the same file forever. */
	const ERROR_KEY = '_rs_img_opt_error';
	/** Attachment meta: 1 when an in-place optimization kept backups and can be rolled back. */
	const ROLLBACK_KEY = '_rs_img_opt_rollback';
	/** Attachment meta: in-place run in progress (written before the first file is replaced). */
	const JOURNAL_KEY = '_rs_img_opt_journal';
	/** Interrupted runs of one attachment before the bulk tool gives up on it. */
	const MAX_ATTEMPTS = 2;

	const RECORD_VERSION = 1;
	const BACKUP_DIR     = 'ratesight-originals';
	const LOCK           = 'ratesight_img_opt_lock';
	const NONCE          = 'ratesight_img_opt';

	/** Upload types this feature touches. GIF (animation) and SVG are never touched. */
	const UPLOAD_MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'image/tiff' );
	/** Existing-image types the bulk tool optimizes in place. */
	const BULK_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	const DEFAULT_MAX_EDGE = 2000;
	const DEFAULT_QUALITY  = 82;
	const BATCH_DEFAULT    = 3;
	const BATCH_MAX        = 10;
	const BATCH_SECONDS    = 15;

	/** Files processed by wp_handle_upload in this request: normalized path => record. */
	private static $pending = array();

	/** Editor capability cache: mime => bool. */
	private static $supports = array();

	/** Files created by the upload currently being processed (temp encodes, backups, converted copies). */
	private static $created = null;

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	public static function register(): void {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'filter_handle_upload' ), 20, 2 );
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'filter_big_image_threshold' ), 20, 3 );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality' ), 20, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'filter_generate_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		add_action( 'wp_ajax_ratesight_img_opt_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_ratesight_img_opt_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_ratesight_img_opt_rollback', array( __CLASS__, 'ajax_rollback' ) );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function settings(): array {
		$edge    = self::clamp_edge( Ratesight_Options::get( 'img_opt_max_edge' ) );
		$quality = self::clamp_quality( Ratesight_Options::get( 'img_opt_quality' ) );
		$format  = (string) Ratesight_Options::get( 'img_opt_format' );
		return array(
			'enabled'       => (bool) Ratesight_Options::get( 'img_opt_enabled' ),
			'max_edge'      => $edge,
			'format'        => in_array( $format, array( 'webp', 'avif', 'original' ), true ) ? $format : 'webp',
			'quality'       => $quality,
			'keep_original' => (bool) Ratesight_Options::get( 'img_opt_keep_original' ),
			'location'      => Ratesight_Image_Metadata::normalize_location(
				Ratesight_Options::get( 'img_geo_lat' ),
				Ratesight_Options::get( 'img_geo_lng' ),
				Ratesight_Options::get( 'img_geo_city' ),
				Ratesight_Options::get( 'img_geo_state' )
			),
		);
	}

	/** 0 = never resize; otherwise 320..12000 px. */
	public static function clamp_edge( $value ): int {
		if ( $value === '' || $value === null || ! is_numeric( $value ) ) {
			return self::DEFAULT_MAX_EDGE;
		}
		$value = (int) $value;
		if ( $value <= 0 ) {
			return 0;
		}
		return max( 320, min( 12000, $value ) );
	}

	public static function clamp_quality( $value ): int {
		if ( $value === '' || $value === null || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return self::DEFAULT_QUALITY;
		}
		return max( 40, min( 100, (int) $value ) );
	}

	// -------------------------------------------------------------------------
	// Server capabilities
	// -------------------------------------------------------------------------

	/** Can a WordPress image editor on this server load AND write this type? */
	public static function editor_supports( string $mime ): bool {
		if ( isset( self::$supports[ $mime ] ) ) {
			return self::$supports[ $mime ];
		}
		$ok = function_exists( 'wp_image_editor_supports' ) && (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
		if ( $ok && $mime === 'image/avif' ) {
			// WordPress learned the AVIF type (extension map, upload checks, srcset) in 6.5, and
			// getimagesize() reads AVIF only from PHP 8.2 (8.0/8.1 report nothing or 0x0), so an
			// AVIF encode could not be verified there. AVIF is skipped cleanly: WebP is used instead.
			$ok = PHP_VERSION_ID >= 80200
				&& function_exists( 'get_bloginfo' ) && version_compare( (string) get_bloginfo( 'version' ), '6.5', '>=' );
		}
		self::$supports[ $mime ] = $ok;
		return $ok;
	}

	/** For the settings screen: which libraries and output formats this server has. */
	public static function environment(): array {
		return array(
			'gd'      => extension_loaded( 'gd' ),
			'imagick' => extension_loaded( 'imagick' ),
			'jpeg'    => self::editor_supports( 'image/jpeg' ),
			'webp'    => self::editor_supports( 'image/webp' ),
			'avif'    => self::editor_supports( 'image/avif' ),
		);
	}

	/**
	 * Output type for a new upload. AVIF only when the server can write it, else WebP, else the
	 * original type: a server without WebP support keeps working exactly as before (plus metadata
	 * stripping, which needs no extension).
	 */
	public static function target_mime( string $source_mime, string $format ): string {
		if ( $format === 'avif' && self::editor_supports( 'image/avif' ) ) {
			return 'image/avif';
		}
		if ( ( $format === 'avif' || $format === 'webp' ) && self::editor_supports( 'image/webp' ) ) {
			return 'image/webp';
		}
		return $source_mime;
	}

	private static function extension_for( string $mime ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/bmp'  => 'bmp',
			'image/tiff' => 'tif',
		);
		return $map[ $mime ] ?? 'jpg';
	}

	/** Animated GIF / WebP / APNG are never re-encoded (the editors would keep one frame). */
	public static function is_animated( string $path, string $mime ): bool {
		if ( $mime === 'image/gif' ) {
			return true;
		}
		if ( $mime !== 'image/webp' && $mime !== 'image/png' ) {
			return false;
		}
		$head = @file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore
		if ( ! is_string( $head ) ) {
			return true; // Unreadable: treat as not safe to re-encode.
		}
		if ( $mime === 'image/webp' ) {
			return strlen( $head ) > 20 && substr( $head, 12, 4 ) === 'VP8X' && ( ord( $head[20] ) & 0x02 );
		}
		$idat = strpos( $head, 'IDAT' );
		$actl = strpos( $head, 'acTL' );
		return $actl !== false && ( $idat === false || $actl < $idat );
	}

	// -------------------------------------------------------------------------
	// Safety helpers (pure: no WordPress state besides the uploads directory)
	// -------------------------------------------------------------------------

	/** Log without secrets: where it happened and the error class / message only. */
	private static function log( string $where, ?\Throwable $error = null, string $message = '' ): void {
		$detail = $error !== null ? get_class( $error ) . ': ' . $error->getMessage() : $message;
		error_log( 'Ratesight image optimization (' . $where . '): ' . substr( $detail, 0, 300 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Is this an existing file inside the uploads directory (symlinks resolved), and not inside
	 * the plugin's own backup directory? Every file the plugin writes must pass this.
	 */
	public static function is_inside_uploads( string $path ): bool {
		$base = self::uploads_base();
		if ( $base === '' || $path === '' ) {
			return false;
		}
		$real_base = realpath( $base );
		$real      = realpath( $path );
		if ( $real_base === false || $real === false || ! is_file( $real ) ) {
			return false;
		}
		$real_base = trailingslashit( wp_normalize_path( $real_base ) );
		$real      = wp_normalize_path( $real );
		return strpos( $real, $real_base ) === 0 && strpos( $real, $real_base . self::BACKUP_DIR . '/' ) !== 0;
	}

	/**
	 * An encoder result may replace or delete an original only when it is a real, non-empty image
	 * of the expected type with valid dimensions (getimagesize is core PHP, no extension needed).
	 */
	public static function is_valid_image_file( string $path, string $expected_mime ): bool {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || (int) filesize( $path ) <= 0 ) {
			return false;
		}
		$info = @getimagesize( $path ); // phpcs:ignore
		if ( ! is_array( $info ) || (int) ( $info[0] ?? 0 ) < 1 || (int) ( $info[1] ?? 0 ) < 1 ) {
			return false;
		}
		return ( $info['mime'] ?? '' ) === $expected_mime;
	}

	/**
	 * Decide whether an upload can be processed so that the stored file is guaranteed clean.
	 *
	 * JPEG / PNG / WebP: cleaned before encoding (no encoder can carry phone EXIF into any output,
	 * AVIF included) and cleaned again after. BMP has no metadata container. TIFF cannot be cleaned
	 * in place, so it is only processed when it is converted to a type that can be cleaned (WebP,
	 * or the chosen JPEG/PNG/WebP); otherwise it is refused and left exactly as uploaded.
	 *
	 * @return array { target: string, refuse: bool, must_convert: bool, reason: string }
	 */
	public static function plan( string $source, string $target, bool $webp_writable ): array {
		$plan = array( 'target' => $target, 'refuse' => false, 'must_convert' => false, 'reason' => '' );
		if ( $source === 'image/tiff' ) {
			$plan['must_convert'] = true;
			if ( ! in_array( $target, Ratesight_Image_Metadata::CLEANABLE, true ) ) {
				if ( ! $webp_writable ) {
					$plan['refuse'] = true;
					$plan['reason'] = 'tiff_metadata_not_cleanable';
					return $plan;
				}
				$plan['target'] = 'image/webp';
				$plan['reason'] = 'tiff_converted_to_webp';
			}
			return $plan;
		}
		if ( in_array( $source, Ratesight_Image_Metadata::CLEANABLE, true ) || $source === 'image/bmp' ) {
			return $plan;
		}
		$plan['refuse'] = true;
		$plan['reason'] = 'unsupported_type';
		return $plan;
	}

	// -------------------------------------------------------------------------
	// New uploads
	// -------------------------------------------------------------------------

	/**
	 * wp_handle_upload: runs for admin uploads, REST uploads and sideloads, after the file is in
	 * the uploads directory and before the attachment exists. Fails open: on any error the upload
	 * is returned exactly as WordPress handed it over.
	 *
	 * @param array  $upload  { file, url, type } (or { error }).
	 * @param string $context 'upload' or 'sideload'.
	 */
	public static function filter_handle_upload( $upload, $context = 'upload' ) {
		self::$created = array();
		try {
			$result        = self::handle_upload( $upload, (string) $context );
			self::$created = null;
			return $result;
		} catch ( \Throwable $e ) {
			self::log( 'upload', $e );
			self::remove_orphans( is_array( $upload ) ? (string) ( $upload['file'] ?? '' ) : '' );
			return $upload;
		}
	}

	private static function track( string $path ): void {
		if ( is_array( self::$created ) && $path !== '' ) {
			self::$created[] = $path;
		}
	}

	/**
	 * After an exception mid-upload: delete what this run created (temp encodes, backups, a
	 * converted copy), but only while the original upload is still in place, so the returned,
	 * unchanged upload always points at a real file.
	 */
	private static function remove_orphans( string $original ): void {
		$created       = is_array( self::$created ) ? self::$created : array();
		self::$created = null;
		if ( $original === '' || ! is_file( $original ) ) {
			return;
		}
		$keep = wp_normalize_path( $original );
		foreach ( array_unique( $created ) as $path ) {
			if ( wp_normalize_path( $path ) !== $keep && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
		}
	}

	private static function handle_upload( $upload, string $context ) {
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], self::UPLOAD_MIMES, true ) ) {
			return $upload;
		}
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return $upload;
		}
		/**
		 * Filters whether one upload is optimized. Return false for uploads that are not Media
		 * Library images (e.g. a plugin that stores a file and expects its exact name and type).
		 */
		if ( ! apply_filters( 'ratesight_image_optimize_upload', true, $upload, $context ) ) {
			return $upload;
		}

		$result = self::optimize_upload_file( (string) $upload['file'], (string) $upload['type'], $settings );
		$out    = $upload;
		if ( $result['file'] !== $upload['file'] ) {
			$old_name   = wp_basename( $upload['file'] );
			$new_name   = wp_basename( $result['file'] );
			$url        = (string) $upload['url'];
			$out['url'] = substr( $url, -strlen( $old_name ) ) === $old_name
				? substr( $url, 0, -strlen( $old_name ) ) . $new_name
				: trailingslashit( dirname( $url ) ) . $new_name;
			$out['file'] = $result['file'];
			$out['type'] = $result['mime'];
		}
		if ( $result['record'] !== null ) {
			self::$pending[ wp_normalize_path( $result['file'] ) ] = $result['record'];
		}
		return $out;
	}

	/**
	 * Only when our resize already applied the owner's long-edge limit: WordPress must not add a
	 * second "-scaled" copy of that file. Files we did not resize keep WordPress's threshold.
	 */
	public static function filter_big_image_threshold( $threshold, $imagesize = null, $file = '' ) {
		if ( is_string( $file ) && $file !== '' ) {
			$record = self::$pending[ wp_normalize_path( $file ) ] ?? null;
			if ( is_array( $record ) && ! empty( $record['resized'] ) ) {
				return false;
			}
		}
		return $threshold;
	}

	/** Quality for every size WordPress encodes while the feature is on. */
	public static function filter_quality( $quality, $mime = '' ) {
		if ( ! in_array( $mime, array( 'image/webp', 'image/avif', 'image/jpeg' ), true ) ) {
			return $quality;
		}
		$settings = self::settings();
		return $settings['enabled'] ? $settings['quality'] : $quality;
	}

	/**
	 * Scrub every generated size and store the record. Also runs on size regeneration for an
	 * attachment that is already optimized (only the size files are touched then). Fails open.
	 */
	public static function filter_generate_metadata( $metadata, $attachment_id = 0 ) {
		try {
			return self::generate_metadata( $metadata, (int) $attachment_id );
		} catch ( \Throwable $e ) {
			self::log( 'metadata #' . (int) $attachment_id, $e );
			return $metadata;
		}
	}

	private static function generate_metadata( $metadata, int $attachment_id ) {
		if ( ! is_array( $metadata ) || $attachment_id <= 0 ) {
			return $metadata;
		}
		$file = (string) get_attached_file( $attachment_id );
		if ( $file === '' ) {
			return $metadata;
		}
		$key    = wp_normalize_path( $file );
		$record = self::$pending[ $key ] ?? null;
		if ( $record === null && ! empty( $metadata['original_image'] ) ) {
			// WordPress rotated or scaled our file into a new full size: match on the original.
			$key    = wp_normalize_path( trailingslashit( dirname( $file ) ) . wp_basename( $metadata['original_image'] ) );
			$record = self::$pending[ $key ] ?? null;
		}
		$settings = self::settings();

		if ( $record === null ) {
			$existing = get_post_meta( $attachment_id, self::META_KEY, true );
			if ( ! is_array( $existing ) || ! $settings['enabled'] ) {
				return $metadata;
			}
			return self::scrub_sizes( $file, $metadata, $settings['location'] );
		}

		unset( self::$pending[ $key ] );
		if ( $key !== wp_normalize_path( $file ) && self::is_inside_uploads( $file ) ) {
			// The new full size (-scaled / -rotated) was written by WordPress from our file.
			Ratesight_Image_Metadata::rewrite_file( $file, $settings['location'], true );
		}
		$metadata = self::scrub_sizes( $file, $metadata, $settings['location'] );
		$metadata = self::scrub_image_meta( $metadata );
		if ( isset( $record['backups'] ) && $record['backups'] === array() ) {
			unset( $record['backups'] );
		}
		update_post_meta( $attachment_id, self::META_KEY, $record );
		delete_post_meta( $attachment_id, self::ERROR_KEY );
		return $metadata;
	}

	/**
	 * Resize / convert / clean one freshly uploaded file. Any path that cannot end in a verified
	 * clean file returns the upload unchanged with record null (unmarked, nothing claimed).
	 *
	 * @return array { file: string, mime: string, record: array|null }
	 */
	private static function optimize_upload_file( string $path, string $mime, array $settings ): array {
		$unchanged = array( 'file' => $path, 'mime' => $mime, 'record' => null );
		if ( ! self::is_inside_uploads( $path ) ) {
			return $unchanged;
		}
		$plan = self::plan( $mime, self::target_mime( $mime, $settings['format'] ), self::editor_supports( 'image/webp' ) );
		if ( $plan['refuse'] ) {
			self::log( 'upload', null, 'left unprocessed (' . $plan['reason'] . '): ' . wp_basename( $path ) );
			return $unchanged;
		}

		$bytes_before = (int) @filesize( $path ); // phpcs:ignore
		$record       = array(
			'v'            => self::RECORD_VERSION,
			'mode'         => 'upload',
			'at'           => time(),
			'source_mime'  => $mime,
			'mime'         => $mime,
			'quality'      => $settings['quality'],
			'max_edge'     => $settings['max_edge'],
			'resized'      => false,
			'converted'    => false,
			'stripped'     => false,
			'gps'          => false,
			'bytes_before' => $bytes_before,
			'bytes_after'  => $bytes_before,
			'backups'      => array(),
			'notes'        => $plan['reason'] !== '' ? array( $plan['reason'] ) : array(),
		);

		if ( $settings['keep_original'] ) {
			$backup = self::make_backup( $path );
			if ( is_wp_error( $backup ) ) {
				// The owner asked for a (clean) backup; without one, leave the upload exactly as
				// WordPress has it and unmarked, so the bulk tool can pick it up later.
				self::log( 'upload', null, 'backup failed: ' . $backup->get_error_message() );
				return $unchanged;
			}
			$record['backups'][ self::uploads_relative( $path ) ] = $backup;
		}

		// Clean the source BEFORE any encoder sees it (Imagick copies EXIF into its output,
		// including AVIF, which this plugin cannot rewrite afterwards). Orientation is kept so
		// the encoder can still rotate the pixels.
		if ( in_array( $mime, Ratesight_Image_Metadata::CLEANABLE, true ) ) {
			$pre = Ratesight_Image_Metadata::rewrite_file( $path, null, true );
			if ( ! $pre['ok'] ) {
				self::log( 'upload', null, 'metadata could not be cleaned (' . $pre['reason'] . '): ' . wp_basename( $path ) );
				self::delete_backups( $record['backups'] );
				return $unchanged;
			}
		}

		$out_path      = $path;
		$out_mime      = $mime;
		$from_editor   = false;
		$delete_source = false;

		if ( ! self::is_animated( $path, $mime ) && self::editor_supports( $mime ) ) {
			// Try the chosen type, then WebP (AVIF asked but not encodable here), then the source type.
			$targets = array( $plan['target'] );
			if ( $plan['target'] === 'image/avif' && self::editor_supports( 'image/webp' ) ) {
				$targets[] = 'image/webp';
			}
			if ( ! $plan['must_convert'] ) {
				$targets[] = $mime;
			}
			$encoded = null;
			foreach ( array_unique( $targets ) as $try ) {
				$encoded = self::reencode( $path, $try, $settings['max_edge'], $settings['quality'] );
				if ( ! is_wp_error( $encoded ) ) {
					break;
				}
				$record['notes'][] = 'encode_failed:' . $try;
			}
			if ( is_wp_error( $encoded ) || $encoded === null ) {
				$encoded = null;
			} elseif ( $plan['must_convert'] || $encoded['resized'] || $encoded['rotated'] || (int) filesize( $encoded['path'] ) < $bytes_before ) {
				if ( $encoded['mime'] === $mime ) {
					if ( self::replace_file( $encoded['path'], $path ) ) {
						$from_editor = true;
					} else {
						$record['notes'][] = 'replace_failed';
					}
				} else {
					$dir  = dirname( $path );
					$name = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '.' . self::extension_for( $encoded['mime'] ) );
					$dest = trailingslashit( $dir ) . $name;
					self::track( $dest );
					if ( @rename( $encoded['path'], $dest ) ) { // phpcs:ignore
						$out_path      = $dest;
						$out_mime      = $encoded['mime'];
						$from_editor   = true;
						$delete_source = true; // Only once the new file is verified clean, below.
					} else {
						@unlink( $encoded['path'] ); // phpcs:ignore
						$record['notes'][] = 'rename_failed';
					}
				}
				$record['resized'] = $from_editor && $encoded['resized'];
			} else {
				@unlink( $encoded['path'] ); // phpcs:ignore
				$record['notes'][] = 'kept_original_encoding_smaller';
			}
		} else {
			$record['notes'][] = self::is_animated( $path, $mime ) ? 'animated_skipped' : 'no_image_editor';
		}

		if ( $plan['must_convert'] && $out_mime === $mime ) {
			// TIFF that could not be converted: its metadata cannot be cleaned, so nothing is claimed.
			self::log( 'upload', null, 'TIFF left unprocessed (could not convert): ' . wp_basename( $path ) );
			self::delete_backups( $record['backups'] );
			return $unchanged;
		}

		if ( in_array( $out_mime, Ratesight_Image_Metadata::CLEANABLE, true ) ) {
			// Editor output is upright already: drop the orientation tag then.
			$meta = Ratesight_Image_Metadata::rewrite_file( $out_path, $settings['location'], ! $from_editor );
			if ( ! $meta['ok'] ) {
				if ( $delete_source ) {
					@unlink( $out_path ); // phpcs:ignore -- The cleaned source is still in place.
					$out_path = $path;
					$out_mime = $mime;
				}
				self::log( 'upload', null, 'output could not be verified clean (' . $meta['reason'] . '): ' . wp_basename( $out_path ) );
				self::delete_backups( $record['backups'] );
				return array( 'file' => $out_path, 'mime' => $out_mime, 'record' => null );
			}
			$record['gps'] = $meta['gps'];
		} else {
			// AVIF encoded from a cleaned source, or BMP (no metadata container): clean, but this
			// plugin cannot write the business location into it.
			$record['notes'][] = 'location_not_written:' . $out_mime;
		}
		$record['stripped'] = true;
		if ( $delete_source ) {
			// The original stays out of the public uploads path (a cleaned copy is in the backup
			// directory only when the owner asked for backups).
			@unlink( $path ); // phpcs:ignore
			$record['converted'] = true;
		}
		clearstatcache( true, $out_path );
		$record['mime']        = $out_mime;
		$record['bytes_after'] = (int) @filesize( $out_path ); // phpcs:ignore
		return array( 'file' => $out_path, 'mime' => $out_mime, 'record' => $record );
	}

	// -------------------------------------------------------------------------
	// Pixel work (WordPress image editors)
	// -------------------------------------------------------------------------

	/**
	 * Load, apply the EXIF orientation, shrink to the long-edge limit (never enlarge), encode at the
	 * quality into a sibling temp file of the target type. The result is verified (non-empty,
	 * getimagesize, expected type) before it is returned; otherwise it is deleted and an error comes
	 * back, so a broken encode can never replace or delete an original.
	 *
	 * @return array|\WP_Error { path, mime, width, height, resized, rotated }
	 */
	private static function reencode( string $path, string $target, int $max_edge, int $quality ) {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return new \WP_Error( 'rs_img_no_editor', 'No image editor.' );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$orientation = Ratesight_Image_Metadata::file_orientation( $path );
		$editor      = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$rotated = false;
		if ( $orientation > 1 ) {
			$turned = self::apply_orientation( $editor, $orientation );
			if ( is_wp_error( $turned ) ) {
				return $turned;
			}
			$rotated = true;
		}

		$size    = $editor->get_size();
		$width   = (int) ( $size['width'] ?? 0 );
		$height  = (int) ( $size['height'] ?? 0 );
		$resized = false;
		if ( $max_edge > 0 && max( $width, $height ) > $max_edge ) {
			// resize() fits inside the box and keeps the aspect ratio; it never upscales.
			$done = $editor->resize( $max_edge, $max_edge, false );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$resized = true;
		}
		$editor->set_quality( $quality );

		$tmp   = trailingslashit( dirname( $path ) ) . pathinfo( $path, PATHINFO_FILENAME ) . '-rs-tmp-' . strtolower( wp_generate_password( 8, false, false ) ) . '.' . self::extension_for( $target );
		self::track( $tmp );
		$saved = $editor->save( $tmp, $target );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$saved_path = (string) ( $saved['path'] ?? '' );
		self::track( $saved_path );
		$saved_mime = (string) ( $saved['mime-type'] ?? $target );
		if ( $saved_path === '' || ! self::is_valid_image_file( $saved_path, $saved_mime ) ) {
			if ( $saved_path !== '' && is_file( $saved_path ) ) {
				@unlink( $saved_path ); // phpcs:ignore
			}
			return new \WP_Error( 'rs_img_invalid', 'The encoded file failed verification.' );
		}
		return array(
			'path'    => $saved_path,
			'mime'    => $saved_mime,
			'width'   => (int) ( $saved['width'] ?? 0 ),
			'height'  => (int) ( $saved['height'] ?? 0 ),
			'resized' => $resized,
			'rotated' => $rotated,
		);
	}

	/** Same transforms as WP_Image_Editor::maybe_exif_rotate(), from our own (extension-free) EXIF read. */
	private static function apply_orientation( $editor, int $orientation ) {
		switch ( $orientation ) {
			case 2:
				return $editor->flip( false, true );
			case 3:
				return $editor->flip( true, true );
			case 4:
				return $editor->flip( true, false );
			case 5:
				$r = $editor->rotate( 90 );
				return is_wp_error( $r ) ? $r : $editor->flip( true, false );
			case 6:
				return $editor->rotate( 270 );
			case 7:
				$r = $editor->rotate( 90 );
				return is_wp_error( $r ) ? $r : $editor->flip( false, true );
			case 8:
				return $editor->rotate( 90 );
		}
		return true;
	}

	private static function replace_file( string $from, string $to ): bool {
		$perms = @fileperms( $to ); // phpcs:ignore
		if ( ! @rename( $from, $to ) ) { // phpcs:ignore
			@unlink( $from ); // phpcs:ignore
			return false;
		}
		if ( $perms ) {
			@chmod( $to, $perms & 0777 ); // phpcs:ignore
		}
		clearstatcache( true, $to );
		return true;
	}

	// -------------------------------------------------------------------------
	// Sizes and attachment metadata
	// -------------------------------------------------------------------------

	/** Strip + write the business location into every size file; refresh recorded file sizes. */
	private static function scrub_sizes( string $file, array $metadata, ?array $location ): array {
		$dir = trailingslashit( dirname( $file ) );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = $dir . wp_basename( $size['file'] );
				if ( ! self::is_inside_uploads( $path ) ) {
					continue;
				}
				$done = Ratesight_Image_Metadata::rewrite_file( $path, $location, true );
				if ( $done['changed'] ) {
					$metadata['sizes'][ $name ]['filesize'] = (int) filesize( $path );
				}
			}
		}
		if ( is_file( $file ) ) {
			clearstatcache( true, $file );
			$metadata['filesize'] = (int) filesize( $file );
		}
		return $metadata;
	}

	/** WordPress copies camera and credit (owner) fields into attachment metadata: clear them. */
	public static function scrub_image_meta( array $metadata ): array {
		if ( isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ) {
			foreach ( array( 'camera', 'credit' ) as $field ) {
				if ( isset( $metadata['image_meta'][ $field ] ) ) {
					$metadata['image_meta'][ $field ] = '';
				}
			}
		}
		return $metadata;
	}

	// -------------------------------------------------------------------------
	// Backups
	// -------------------------------------------------------------------------

	private static function uploads_base(): string {
		$uploads = wp_get_upload_dir();
		return empty( $uploads['basedir'] ) ? '' : trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}

	private static function uploads_relative( string $path ): string {
		$base = self::uploads_base();
		$path = wp_normalize_path( $path );
		return ( $base !== '' && strpos( $path, $base ) === 0 ) ? substr( $path, strlen( $base ) ) : '';
	}

	/** Absolute path for an uploads-relative path, or '' when it escapes the uploads directory. */
	private static function uploads_absolute( string $relative, bool $backup_only = false ): string {
		$base = self::uploads_base();
		if ( $base === '' || $relative === '' || strpos( $relative, '..' ) !== false || strpos( $relative, "\0" ) !== false ) {
			return '';
		}
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( $backup_only && strpos( $relative, self::BACKUP_DIR . '/' ) !== 0 ) {
			return '';
		}
		return $base . $relative;
	}

	private static function backup_root() {
		$base = self::uploads_base();
		if ( $base === '' ) {
			return new \WP_Error( 'rs_img_backup', 'Uploads directory unavailable.' );
		}
		$root = $base . self::BACKUP_DIR;
		if ( ! wp_mkdir_p( $root ) ) {
			return new \WP_Error( 'rs_img_backup', 'Backup directory could not be created.' );
		}
		// Best effort: deny direct web access on Apache / LiteSpeed and stop directory listings.
		if ( ! file_exists( $root . '/index.php' ) ) {
			@file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			@file_put_contents( $root . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		return $root;
	}

	/**
	 * Copy one uploads file into the backup directory and clean the COPY's personal and location
	 * metadata (pixels untouched, nothing written). A copy that cannot be cleaned (TIFF) is deleted
	 * and refused. Returns the uploads-relative backup path.
	 *
	 * @return string|\WP_Error
	 */
	private static function make_backup( string $path ) {
		if ( ! self::is_inside_uploads( $path ) ) {
			return new \WP_Error( 'rs_img_backup', 'File is outside the uploads directory.' );
		}
		$root = self::backup_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$relative = self::uploads_relative( $path );
		if ( $relative === '' ) {
			return new \WP_Error( 'rs_img_backup', 'File is outside the uploads directory.' );
		}
		$dest = $root . '/' . $relative;
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new \WP_Error( 'rs_img_backup', 'Backup directory could not be created.' );
		}
		if ( file_exists( $dest ) ) {
			$dest = trailingslashit( dirname( $dest ) ) . wp_unique_filename( dirname( $dest ), wp_basename( $dest ) );
		}
		self::track( $dest );
		if ( ! @copy( $path, $dest ) ) { // phpcs:ignore
			return new \WP_Error( 'rs_img_backup', 'Backup copy failed.' );
		}
		$clean = Ratesight_Image_Metadata::rewrite_file( $dest, null, true );
		$head  = (string) @file_get_contents( $dest, false, null, 0, 2 ); // phpcs:ignore
		if ( ! $clean['ok'] && $head !== 'BM' ) { // BMP has no metadata to clean.
			@unlink( $dest ); // phpcs:ignore
			return new \WP_Error( 'rs_img_backup', 'Backup could not be cleaned of metadata.' );
		}
		return self::uploads_relative( $dest );
	}

	private static function delete_backups( array $backups ): void {
		foreach ( $backups as $backup ) {
			$abs = self::uploads_absolute( (string) $backup, true );
			if ( $abs !== '' && is_file( $abs ) ) {
				@unlink( $abs ); // phpcs:ignore
			}
		}
	}

	/**
	 * Copy every backup over its file. All sources are checked before the first copy.
	 *
	 * @return string '' on success, else the reason.
	 */
	private static function restore_backups( array $backups ): string {
		$pairs = array();
		foreach ( $backups as $relative => $backup ) {
			$dst = self::uploads_absolute( (string) $relative );
			$src = self::uploads_absolute( (string) $backup, true );
			if ( $dst === '' || $src === '' || ! is_file( $src ) ) {
				return 'A backup file is missing; nothing was restored.';
			}
			$pairs[ $src ] = $dst;
		}
		foreach ( $pairs as $src => $dst ) {
			if ( ! @copy( $src, $dst ) ) { // phpcs:ignore
				return 'Restoring a file failed; the attachment keeps its backups.';
			}
		}
		return '';
	}

	/** Deleting an attachment also deletes its backups. */
	public static function on_delete_attachment( $post_id ): void {
		try {
			foreach ( array( self::META_KEY, self::JOURNAL_KEY ) as $key ) {
				$entry = get_post_meta( (int) $post_id, $key, true );
				if ( is_array( $entry ) && ! empty( $entry['backups'] ) && is_array( $entry['backups'] ) ) {
					self::delete_backups( $entry['backups'] );
				}
			}
		} catch ( \Throwable $e ) {
			self::log( 'delete #' . (int) $post_id, $e );
		}
	}

	// -------------------------------------------------------------------------
	// Existing images (bulk, in place)
	// -------------------------------------------------------------------------

	/**
	 * Optimize one existing attachment in place: same names, same format, same URLs.
	 *
	 * Journal: before the first file is replaced, JOURNAL_KEY holds the earlier metadata, the
	 * backups (when kept) and the rollback flag. Each file is noted before and after it is
	 * replaced. A run that dies mid-way leaves the journal; the next run restores the backups
	 * first and starts over from the originals (or, without backups, re-processes the files,
	 * which is safe because each step only keeps a verified smaller or resized image). After
	 * MAX_ATTEMPTS interrupted runs the attachment is skipped with an error. Never throws.
	 *
	 * @return array { id, status: 'optimized'|'failed'|'skipped', message, bytes_before, bytes_after }
	 */
	public static function optimize_existing( int $attachment_id, array $settings ): array {
		try {
			return self::optimize_existing_run( $attachment_id, $settings );
		} catch ( \Throwable $e ) {
			self::log( 'existing #' . $attachment_id, $e );
			$out = array( 'id' => $attachment_id, 'status' => 'failed', 'message' => 'Unexpected error; skipped.', 'bytes_before' => 0, 'bytes_after' => 0 );
			try {
				update_post_meta( $attachment_id, self::ERROR_KEY, array( 'code' => 'exception', 'message' => 'Unexpected error.', 'at' => time() ) );
			} catch ( \Throwable $ignored ) {
				// Nothing more can be done safely.
			}
			return $out;
		}
	}

	private static function optimize_existing_run( int $attachment_id, array $settings ): array {
		$out = array( 'id' => $attachment_id, 'status' => 'failed', 'message' => '', 'bytes_before' => 0, 'bytes_after' => 0 );
		if ( get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			$out['status']  = 'skipped';
			$out['message'] = 'Already optimized.';
			return $out;
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );
		if ( ! in_array( $mime, self::BULK_MIMES, true ) ) {
			return self::fail( $attachment_id, $out, 'unsupported_type', 'Type not optimized in place.' );
		}
		if ( $file === '' || ! is_file( $file ) || ! is_writable( $file ) ) {
			return self::fail( $attachment_id, $out, 'missing_file', 'File missing or not writable.' );
		}
		if ( ! self::is_inside_uploads( $file ) ) {
			return self::fail( $attachment_id, $out, 'outside_uploads', 'File is outside the uploads directory; skipped.' );
		}

		// Resume after an interrupted run.
		$journal  = get_post_meta( $attachment_id, self::JOURNAL_KEY, true );
		$journal  = is_array( $journal ) ? $journal : null;
		$attempts = $journal !== null ? (int) ( $journal['attempts'] ?? 0 ) : 0;
		$backups  = $journal !== null && is_array( $journal['backups'] ?? null ) ? $journal['backups'] : array();
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return self::fail( $attachment_id, $out, 'interrupted', 'Processing was interrupted ' . $attempts . ' times; skipped. Roll back or check the file.' );
		}
		if ( $backups !== array() ) {
			$problem = self::restore_backups( $backups );
			if ( $problem !== '' ) {
				return self::fail( $attachment_id, $out, 'resume_failed', $problem );
			}
			if ( is_array( $journal['prev_meta'] ?? null ) ) {
				wp_update_attachment_metadata( $attachment_id, $journal['prev_meta'] );
			}
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$previous = $journal !== null && is_array( $journal['prev_meta'] ?? null ) ? $journal['prev_meta'] : $metadata;
		$dir      = trailingslashit( dirname( $file ) );
		$notes    = array();

		// Every file of the attachment: the full size, each generated size, WordPress's own
		// pre-scaling original. Anything that resolves outside the uploads directory is skipped.
		$files = array( wp_normalize_path( $file ) => 'full' );
		$extra = array();
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$extra[ wp_normalize_path( $dir . wp_basename( $size['file'] ) ) ] = 'size:' . $name;
			}
		}
		if ( ! empty( $metadata['original_image'] ) ) {
			$extra[ wp_normalize_path( $dir . wp_basename( $metadata['original_image'] ) ) ] = 'original_image';
		}
		foreach ( $extra as $path => $role ) {
			if ( isset( $files[ $path ] ) || ! is_file( $path ) ) {
				continue;
			}
			if ( ! self::is_inside_uploads( $path ) ) {
				$notes[] = $role . ':outside_uploads_skipped';
				continue;
			}
			$files[ $path ] = $role;
		}

		$before = 0;
		foreach ( array_keys( $files ) as $path ) {
			$before += (int) filesize( $path );
		}
		$out['bytes_before'] = $before;

		// Backups of the ORIGINALS: made once; a resumed run reuses the journal's backups.
		if ( $settings['keep_original'] && $backups === array() ) {
			foreach ( array_keys( $files ) as $path ) {
				$backup = self::make_backup( $path );
				if ( is_wp_error( $backup ) ) {
					self::delete_backups( $backups );
					return self::fail( $attachment_id, $out, 'backup_failed', $backup->get_error_message() );
				}
				$backups[ self::uploads_relative( $path ) ] = $backup;
			}
		}

		// Journal BEFORE the first file is replaced.
		$journal = array(
			'v'         => self::RECORD_VERSION,
			'state'     => 'started',
			'at'        => time(),
			'attempts'  => $attempts + 1,
			'prev_meta' => $previous,
			'backups'   => $backups,
			'done'      => array(),
			'current'   => '',
		);
		update_post_meta( $attachment_id, self::JOURNAL_KEY, $journal );
		if ( $backups !== array() ) {
			update_post_meta( $attachment_id, self::ROLLBACK_KEY, 1 );
		}

		$editable = ! self::is_animated( $file, $mime ) && self::editor_supports( $mime );
		$resized  = false;
		foreach ( $files as $path => $role ) {
			$journal['current'] = self::uploads_relative( $path );
			update_post_meta( $attachment_id, self::JOURNAL_KEY, $journal );

			$size_mime = $mime;
			if ( strpos( $role, 'size:' ) === 0 ) {
				$size_mime = (string) ( $metadata['sizes'][ substr( $role, 5 ) ]['mime-type'] ?? $mime );
			}
			$from_editor = false;
			// WordPress's pre-scaling original is never displayed: clean its metadata only.
			if ( $editable && $role !== 'original_image' && in_array( $size_mime, self::BULK_MIMES, true ) ) {
				$edge    = $role === 'full' ? $settings['max_edge'] : 0;
				$encoded = self::reencode( $path, $size_mime, $edge, $settings['quality'] );
				if ( is_wp_error( $encoded ) ) {
					$notes[] = $role . ':reencode_skipped';
				} elseif ( $encoded['mime'] !== $size_mime ) {
					@unlink( $encoded['path'] ); // phpcs:ignore
				} elseif ( $encoded['resized'] || $encoded['rotated'] || (int) filesize( $encoded['path'] ) < (int) filesize( $path ) ) {
					if ( self::replace_file( $encoded['path'], $path ) ) {
						$from_editor = true;
						if ( $role === 'full' ) {
							$resized = $encoded['resized'];
							if ( $encoded['width'] > 0 && $encoded['height'] > 0 ) {
								$metadata['width']  = $encoded['width'];
								$metadata['height'] = $encoded['height'];
							}
						} elseif ( strpos( $role, 'size:' ) === 0 && $encoded['width'] > 0 ) {
							$metadata['sizes'][ substr( $role, 5 ) ]['width']  = $encoded['width'];
							$metadata['sizes'][ substr( $role, 5 ) ]['height'] = $encoded['height'];
						}
					}
				} else {
					@unlink( $encoded['path'] ); // phpcs:ignore
				}
			}
			$meta = Ratesight_Image_Metadata::rewrite_file( $path, $settings['location'], ! $from_editor );
			if ( ! $meta['ok'] ) {
				$notes[] = $role . ':metadata_' . $meta['reason'];
			}
			clearstatcache( true, $path );
			if ( strpos( $role, 'size:' ) === 0 && is_file( $path ) ) {
				$metadata['sizes'][ substr( $role, 5 ) ]['filesize'] = (int) filesize( $path );
			}

			$journal['done'][]  = self::uploads_relative( $path );
			$journal['current'] = '';
			update_post_meta( $attachment_id, self::JOURNAL_KEY, $journal );
		}

		$after = 0;
		foreach ( array_keys( $files ) as $path ) {
			$after += is_file( $path ) ? (int) filesize( $path ) : 0;
		}
		if ( is_file( $file ) ) {
			clearstatcache( true, $file );
			$metadata['filesize'] = (int) filesize( $file );
			// Dimensions from the file itself: correct even when a resumed run (no backups) found
			// the full size already resized by the interrupted run.
			$dims = @getimagesize( $file ); // phpcs:ignore
			if ( is_array( $dims ) && (int) $dims[0] > 0 && (int) $dims[1] > 0 ) {
				$metadata['width']  = (int) $dims[0];
				$metadata['height'] = (int) $dims[1];
			}
		}
		$metadata = self::scrub_image_meta( $metadata );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$record = array(
			'v'            => self::RECORD_VERSION,
			'mode'         => 'in_place',
			'at'           => time(),
			'source_mime'  => $mime,
			'mime'         => $mime,
			'quality'      => $settings['quality'],
			'max_edge'     => $settings['max_edge'],
			'resized'      => $resized,
			'converted'    => false,
			'stripped'     => ! preg_grep( '/:metadata_/', $notes ),
			'gps'          => $settings['location'] !== null,
			'bytes_before' => $before,
			'bytes_after'  => $after,
			'notes'        => $notes,
		);
		if ( $backups !== array() ) {
			$record['backups']   = $backups;
			$record['prev_meta'] = $previous;
		}
		update_post_meta( $attachment_id, self::META_KEY, $record );
		delete_post_meta( $attachment_id, self::JOURNAL_KEY );
		delete_post_meta( $attachment_id, self::ERROR_KEY );

		$out['status']      = 'optimized';
		$out['bytes_after'] = $after;
		$out['message']     = $notes === array() ? 'Optimized.' : 'Optimized with notes: ' . implode( ', ', $notes );
		return $out;
	}

	private static function fail( int $attachment_id, array $out, string $code, string $message ): array {
		update_post_meta( $attachment_id, self::ERROR_KEY, array( 'code' => $code, 'message' => $message, 'at' => time() ) );
		$out['status']  = 'failed';
		$out['message'] = $message;
		return $out;
	}

	/**
	 * Restore one in-place optimized (or interrupted) attachment from its backups. Uploads that
	 * were converted to a new format are never rolled back: posts may already link to the new file.
	 */
	public static function rollback( int $attachment_id ): array {
		$out = array( 'id' => $attachment_id, 'status' => 'failed', 'message' => '' );
		try {
			$record  = get_post_meta( $attachment_id, self::META_KEY, true );
			$journal = get_post_meta( $attachment_id, self::JOURNAL_KEY, true );
			$source  = null;
			if ( is_array( $record ) && ( $record['mode'] ?? '' ) === 'in_place' && ! empty( $record['backups'] ) && is_array( $record['backups'] ) ) {
				$source = $record;
			} elseif ( ! is_array( $record ) && is_array( $journal ) && ! empty( $journal['backups'] ) && is_array( $journal['backups'] ) ) {
				$source = $journal; // Interrupted run: the journal holds the originals.
			}
			if ( $source === null ) {
				delete_post_meta( $attachment_id, self::ROLLBACK_KEY );
				$out['status']  = 'skipped';
				$out['message'] = 'No backups to restore.';
				return $out;
			}
			$problem = self::restore_backups( $source['backups'] );
			if ( $problem !== '' ) {
				$out['message'] = $problem;
				return $out;
			}
			if ( isset( $source['prev_meta'] ) && is_array( $source['prev_meta'] ) ) {
				wp_update_attachment_metadata( $attachment_id, self::scrub_image_meta( $source['prev_meta'] ) );
			}
			self::delete_backups( $source['backups'] );
			delete_post_meta( $attachment_id, self::META_KEY );
			delete_post_meta( $attachment_id, self::JOURNAL_KEY );
			delete_post_meta( $attachment_id, self::ROLLBACK_KEY );
			delete_post_meta( $attachment_id, self::ERROR_KEY );
			$out['status']  = 'restored';
			$out['message'] = 'Restored from backup.';
			return $out;
		} catch ( \Throwable $e ) {
			self::log( 'rollback #' . $attachment_id, $e );
			$out['message'] = 'Unexpected error; nothing more was changed.';
			return $out;
		}
	}

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	private static function mime_placeholders(): string {
		return implode( ',', array_fill( 0, count( self::BULK_MIMES ), '%s' ) );
	}

	/** Attachment ids still to optimize (no record, no recorded failure), oldest first. */
	public static function candidate_ids( int $limit ): array {
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::mime_placeholders() . ")
			AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN (%s, %s) )
			ORDER BY p.ID ASC LIMIT %d";
		$args = array_merge( self::BULK_MIMES, array( self::META_KEY, self::ERROR_KEY, max( 1, $limit ) ) );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function status_counts(): array {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::mime_placeholders() . ")
			AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN (%s, %s) )";
		$candidates = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( self::BULK_MIMES, array( self::META_KEY, self::ERROR_KEY ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$count_key = static function ( string $key ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		};

		return array(
			'candidates'  => $candidates,
			'optimized'   => $count_key( self::META_KEY ),
			'failed'      => $count_key( self::ERROR_KEY ),
			'rollbackable' => $count_key( self::ROLLBACK_KEY ),
		);
	}

	private static function rollback_ids( int $limit ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d",
			self::ROLLBACK_KEY,
			max( 1, $limit )
		) ) );
	}

	private static function batch_size(): int {
		$size = (int) apply_filters( 'ratesight_image_optimize_batch_size', self::BATCH_DEFAULT );
		return max( 1, min( self::BATCH_MAX, $size ) );
	}

	// -------------------------------------------------------------------------
	// AJAX (admin only)
	// -------------------------------------------------------------------------

	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
	}

	/** Dry run: counts only, nothing is written. */
	public static function ajax_status(): void {
		self::guard();
		$settings = self::settings();
		wp_send_json_success( array_merge( self::status_counts(), array(
			'dry_run'          => true,
			'enabled'          => $settings['enabled'],
			'keep_original'    => $settings['keep_original'],
			'location_set'     => $settings['location'] !== null,
			'max_edge'         => $settings['max_edge'],
			'quality'          => $settings['quality'],
			'environment'      => self::environment(),
			'message'          => 'Dry run. Nothing was written.',
		) ) );
	}

	public static function ajax_apply(): void {
		self::guard();
		$confirm = isset( $_POST['confirm'] ) ? sanitize_key( wp_unslash( $_POST['confirm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( $confirm !== 'apply' ) {
			wp_send_json_error( array( 'message' => 'Run the dry-run count first, then confirm.' ), 400 );
		}
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			wp_send_json_error( array( 'message' => 'Turn on Image optimization and save the settings first.' ), 409 );
		}
		if ( get_transient( self::LOCK ) ) {
			wp_send_json_error( array( 'message' => 'Another batch is still running. Try again shortly.', 'busy' => true ), 409 );
		}
		set_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );

		$started = microtime( true );
		$items   = array();
		foreach ( self::candidate_ids( self::batch_size() ) as $id ) {
			if ( microtime( true ) - $started > self::BATCH_SECONDS ) {
				break;
			}
			$items[] = self::optimize_existing( $id, $settings );
		}
		delete_transient( self::LOCK );

		$counts = self::status_counts();
		wp_send_json_success( array(
			'items'     => $items,
			'remaining' => $counts['candidates'],
			'done'      => $counts['candidates'] === 0 || $items === array(),
			'counts'    => $counts,
		) );
	}

	public static function ajax_rollback(): void {
		self::guard();
		$confirm = isset( $_POST['confirm'] ) ? sanitize_key( wp_unslash( $_POST['confirm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( $confirm !== 'rollback' ) {
			wp_send_json_error( array( 'message' => 'Confirmation missing.' ), 400 );
		}
		if ( get_transient( self::LOCK ) ) {
			wp_send_json_error( array( 'message' => 'Another batch is still running. Try again shortly.', 'busy' => true ), 409 );
		}
		set_transient( self::LOCK, time(), 2 * MINUTE_IN_SECONDS );

		$started = microtime( true );
		$items   = array();
		foreach ( self::rollback_ids( self::batch_size() ) as $id ) {
			if ( microtime( true ) - $started > self::BATCH_SECONDS ) {
				break;
			}
			$items[] = self::rollback( $id );
		}
		delete_transient( self::LOCK );

		$counts   = self::status_counts();
		$progress = array_filter( $items, static fn( array $item ): bool => $item['status'] !== 'failed' );
		wp_send_json_success( array(
			'items'     => $items,
			'remaining' => $counts['rollbackable'],
			// Stop when nothing is left, or when a whole batch failed (missing backups would loop).
			'done'      => $counts['rollbackable'] === 0 || $progress === array(),
			'counts'    => $counts,
		) );
	}
}
