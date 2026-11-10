<?php
/**
 * Lossless image metadata rewriting: strip personal and location data, write the client's
 * business location.
 *
 * WHY PURE PHP. Neither GD nor WordPress's image editors can be trusted with metadata: GD drops
 * everything (including the EXIF Orientation a phone photo needs to display upright) and the
 * Imagick editor keeps most of it. This class walks the container format itself, so it works the
 * same on every server, needs no extension, never re-encodes pixels, and is unit-testable without
 * GD or Imagick.
 *
 * WHAT IS REMOVED. Every EXIF block (GPS from phones, camera make/model/serial, lens serial, owner
 * name, dates, maker notes, embedded thumbnails), every XMP packet (creator, edit history, location),
 * Photoshop/IPTC blocks, JPEG comments, PNG text chunks, and anything after the JPEG end-of-image
 * marker (phones append secondary images there, each with its own EXIF).
 *
 * WHAT IS KEPT. Pixels, ICC colour profiles, the JFIF header, the Adobe colour-transform segment,
 * animation and alpha. The EXIF Orientation value is carried over unless the caller says the
 * pixels have already been rotated.
 *
 * WHAT IS WRITTEN. Only when the site owner configured the business coordinates: a minimal EXIF
 * block (GPS IFD, WGS-84) plus an XMP packet with the same coordinates and the optional city and
 * state. Nothing is ever derived from the photo or the visitor; with no configured coordinates the
 * file is only stripped.
 *
 * Supported containers: JPEG, PNG, WebP. Anything else returns null (the caller leaves it alone).
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

final class Ratesight_Image_Metadata {

	/** Refuse to buffer a file larger than this (bytes). */
	const MAX_BYTES = 67108864;

	/** Containers this class can clean (and write the business location into). */
	const CLEANABLE = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** City / state are short labels; anything longer is cut. */
	const MAX_LABEL = 100;

	// -------------------------------------------------------------------------
	// Location
	// -------------------------------------------------------------------------

	/**
	 * Validate the configured business location. Returns null (strip only) unless BOTH coordinates
	 * are numeric and in range. 0,0 is treated as "not configured": it is the classic unset value
	 * and is in the Gulf of Guinea, where no client is.
	 *
	 * @param mixed $lat   Latitude, decimal degrees.
	 * @param mixed $lng   Longitude, decimal degrees.
	 * @param mixed $city  Optional city label.
	 * @param mixed $state Optional state / region label.
	 * @return array|null  array( lat, lng, city, state ) or null.
	 */
	public static function normalize_location( $lat, $lng, $city = '', $state = '' ): ?array {
		$lat = is_string( $lat ) ? trim( $lat ) : $lat;
		$lng = is_string( $lng ) ? trim( $lng ) : $lng;
		if ( $lat === '' || $lng === '' || $lat === null || $lng === null ) {
			return null;
		}
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( ! is_finite( $lat ) || ! is_finite( $lng ) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			return null;
		}
		if ( $lat === 0.0 && $lng === 0.0 ) {
			return null;
		}
		return array(
			'lat'   => $lat,
			'lng'   => $lng,
			'city'  => self::label( $city ),
			'state' => self::label( $state ),
		);
	}

	private static function label( $value ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? '' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_LABEL, 'UTF-8' );
		}
		return substr( $value, 0, self::MAX_LABEL );
	}

	// -------------------------------------------------------------------------
	// Files
	// -------------------------------------------------------------------------

	/**
	 * Rewrite one file in place (write to a sibling temp file, then rename).
	 *
	 * @param string     $path             Absolute path.
	 * @param array|null $location         normalize_location() result, or null to strip only.
	 * @param bool       $keep_orientation Carry the EXIF Orientation over (false once pixels were rotated).
	 * @return array { ok: bool, changed: bool, reason: string, gps: bool }
	 */
	public static function rewrite_file( string $path, ?array $location, bool $keep_orientation = true ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			return self::result( false, false, 'unreadable' );
		}
		$size = filesize( $path );
		if ( ! $size || $size > self::MAX_BYTES ) {
			return self::result( false, false, 'size' );
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $bytes ) || $bytes === '' ) {
			return self::result( false, false, 'unreadable' );
		}

		$out = self::rewrite_bytes( $bytes, $location, $keep_orientation );
		if ( $out === null ) {
			return self::result( false, false, self::sniff( $bytes ) === '' ? 'unsupported' : 'corrupt' );
		}
		$gps = $location !== null;
		if ( $out === $bytes ) {
			return self::result( true, false, 'unchanged', $gps );
		}

		$tmp = $path . '.rs-meta-' . bin2hex( random_bytes( 4 ) );
		if ( file_put_contents( $tmp, $out ) !== strlen( $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore
			return self::result( false, false, 'write' );
		}
		$perms = @fileperms( $path ); // phpcs:ignore
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return self::result( false, false, 'write' );
		}
		if ( $perms ) {
			@chmod( $path, $perms & 0777 ); // phpcs:ignore
		}
		clearstatcache( true, $path );
		return self::result( true, true, 'rewritten', $gps );
	}

	/**
	 * Read the EXIF Orientation (1-8) of a JPEG / PNG / WebP file without any extension. 1 when absent.
	 */
	public static function file_orientation( string $path ): int {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return 1;
		}
		$size = filesize( $path );
		if ( ! $size || $size > self::MAX_BYTES ) {
			return 1;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $bytes ) ? self::orientation( $bytes ) : 1;
	}

	// -------------------------------------------------------------------------
	// Bytes
	// -------------------------------------------------------------------------

	/** Container type from the magic bytes ('' when not JPEG / PNG / WebP). The extension is never trusted. */
	public static function sniff( string $bytes ): string {
		if ( strncmp( $bytes, "\xFF\xD8\xFF", 3 ) === 0 ) {
			return 'image/jpeg';
		}
		if ( strncmp( $bytes, "\x89PNG\r\n\x1A\n", 8 ) === 0 ) {
			return 'image/png';
		}
		if ( strlen( $bytes ) >= 12 && substr( $bytes, 0, 4 ) === 'RIFF' && substr( $bytes, 8, 4 ) === 'WEBP' ) {
			return 'image/webp';
		}
		return '';
	}

	/**
	 * @return string|null Rewritten bytes, or null when the container is unsupported or malformed
	 *                     (the caller must then leave the file untouched).
	 */
	public static function rewrite_bytes( string $bytes, ?array $location, bool $keep_orientation = true ): ?string {
		switch ( self::sniff( $bytes ) ) {
			case 'image/jpeg':
				return self::rewrite_jpeg( $bytes, $location, $keep_orientation );
			case 'image/png':
				return self::rewrite_png( $bytes, $location, $keep_orientation );
			case 'image/webp':
				return self::rewrite_webp( $bytes, $location, $keep_orientation );
		}
		return null;
	}

	/** Orientation (1-8) stored in the bytes, 1 when absent or unreadable. */
	public static function orientation( string $bytes ): int {
		switch ( self::sniff( $bytes ) ) {
			case 'image/jpeg':
				$parsed = self::parse_jpeg( $bytes );
				return $parsed ? $parsed['orientation'] : 1;
			case 'image/png':
				$parsed = self::parse_png( $bytes );
				return $parsed ? $parsed['orientation'] : 1;
			case 'image/webp':
				$parsed = self::parse_webp( $bytes );
				return $parsed ? $parsed['orientation'] : 1;
		}
		return 1;
	}

	// -------------------------------------------------------------------------
	// JPEG
	// -------------------------------------------------------------------------

	private static function rewrite_jpeg( string $b, ?array $location, bool $keep_orientation ): ?string {
		$parsed = self::parse_jpeg( $b );
		if ( $parsed === null ) {
			return null;
		}
		$orientation = $keep_orientation ? $parsed['orientation'] : 1;

		$meta = '';
		$exif = self::build_exif( $location, $orientation );
		if ( $exif !== '' ) {
			$segment = self::jpeg_segment( 0xE1, "Exif\0\0" . $exif );
			if ( $segment === null ) {
				return null;
			}
			$meta .= $segment;
		}
		$xmp = self::build_xmp( $location );
		if ( $xmp !== '' ) {
			$segment = self::jpeg_segment( 0xE1, "http://ns.adobe.com/xap/1.0/\0" . $xmp );
			if ( $segment === null ) {
				return null;
			}
			$meta .= $segment;
		}

		// JFIF stays first (readers expect it right after SOI), then the new metadata, then the
		// remaining kept header segments, then the scan data up to and including EOI.
		return "\xFF\xD8" . $parsed['jfif'] . $meta . $parsed['kept'] . $parsed['scan'];
	}

	/**
	 * Split a JPEG into the segments we keep and the scan data. null when malformed.
	 *
	 * @return array|null { jfif: string, kept: string, scan: string, orientation: int }
	 */
	private static function parse_jpeg( string $b ): ?array {
		$len = strlen( $b );
		if ( $len < 4 || substr( $b, 0, 2 ) !== "\xFF\xD8" ) {
			return null;
		}
		$pos         = 2;
		$jfif        = '';
		$kept        = '';
		$orientation = 1;

		while ( $pos + 4 <= $len ) {
			if ( $b[ $pos ] !== "\xFF" ) {
				return null;
			}
			$marker = ord( $b[ $pos + 1 ] );
			if ( $marker === 0xFF ) { // Fill byte.
				$pos++;
				continue;
			}
			if ( $marker === 0x01 || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) { // Stand-alone markers.
				$pos += 2;
				continue;
			}
			if ( $marker === 0xD9 ) { // EOI before any scan.
				return null;
			}
			$seglen = unpack( 'n', substr( $b, $pos + 2, 2 ) )[1];
			if ( $seglen < 2 || $pos + 2 + $seglen > $len ) {
				return null;
			}
			$segment = substr( $b, $pos, 2 + $seglen );
			$payload = substr( $b, $pos + 4, $seglen - 2 );

			if ( $marker === 0xDA ) { // Start of scan: everything to EOI is image data.
				$end = self::jpeg_scan_end( $b, $pos + 2 + $seglen );
				if ( $end === null ) {
					return null;
				}
				return array(
					'jfif'        => $jfif,
					'kept'        => $kept,
					'scan'        => substr( $b, $pos, $end - $pos ),
					'orientation' => $orientation,
				);
			}

			if ( $marker === 0xE1 ) {
				// EXIF or XMP: dropped. Only the orientation is read from EXIF.
				if ( strncmp( $payload, "Exif\0\0", 6 ) === 0 && $orientation === 1 ) {
					$orientation = self::tiff_orientation( substr( $payload, 6 ) );
				}
			} elseif ( $marker === 0xE0 ) {
				if ( $jfif === '' && strncmp( $payload, "JFIF\0", 5 ) === 0 ) {
					$jfif = $segment;
				}
			} elseif ( $marker === 0xE2 ) {
				if ( strncmp( $payload, "ICC_PROFILE\0", 12 ) === 0 ) {
					$kept .= $segment; // Colour profile: needed to render colours correctly.
				}
			} elseif ( $marker === 0xEE ) {
				if ( strncmp( $payload, 'Adobe', 5 ) === 0 ) {
					$kept .= $segment; // Colour transform flag: needed to decode CMYK/YCCK correctly.
				}
			} elseif ( ( $marker >= 0xE3 && $marker <= 0xEF ) || $marker === 0xFE ) {
				// Other APPn (IPTC/Photoshop, maker data, FlashPix, MPF index) and comments: dropped.
			} else {
				$kept .= $segment; // DQT, DHT, SOFn, DRI, DNL, ...
			}
			$pos += 2 + $seglen;
		}
		return null;
	}

	/** Offset just past EOI, walking entropy data and any segments between progressive scans. */
	private static function jpeg_scan_end( string $b, int $p ): ?int {
		$len = strlen( $b );
		while ( $p < $len ) {
			$ff = strpos( $b, "\xFF", $p );
			if ( $ff === false || $ff + 1 >= $len ) {
				return null;
			}
			$next = ord( $b[ $ff + 1 ] );
			if ( $next === 0x00 || ( $next >= 0xD0 && $next <= 0xD7 ) ) { // Stuffed byte or restart marker.
				$p = $ff + 2;
				continue;
			}
			if ( $next === 0xFF ) { // Fill byte: the marker starts at the next 0xFF.
				$p = $ff + 1;
				continue;
			}
			if ( $next === 0xD9 ) {
				return $ff + 2;
			}
			// A marker segment between scans (DHT, DQT, DRI, SOS, ...): skip its body.
			if ( $ff + 4 > $len ) {
				return null;
			}
			$seglen = unpack( 'n', substr( $b, $ff + 2, 2 ) )[1];
			if ( $seglen < 2 ) {
				return null;
			}
			$p = $ff + 2 + $seglen;
		}
		return null;
	}

	private static function jpeg_segment( int $marker, string $payload ): ?string {
		$length = strlen( $payload ) + 2;
		if ( $length > 0xFFFF ) {
			return null;
		}
		return "\xFF" . chr( $marker ) . pack( 'n', $length ) . $payload;
	}

	// -------------------------------------------------------------------------
	// PNG
	// -------------------------------------------------------------------------

	/** Chunks that can carry personal or location data. */
	const PNG_DROP = array( 'eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME' );

	private static function rewrite_png( string $b, ?array $location, bool $keep_orientation ): ?string {
		$parsed = self::parse_png( $b );
		if ( $parsed === null ) {
			return null;
		}
		$orientation = $keep_orientation ? $parsed['orientation'] : 1;

		$meta = '';
		$exif = self::build_exif( $location, $orientation );
		if ( $exif !== '' ) {
			$meta .= self::png_chunk( 'eXIf', $exif );
		}
		$xmp = self::build_xmp( $location );
		if ( $xmp !== '' ) {
			// iTXt: keyword, NUL, compression flag 0, method 0, empty language tag NUL, empty translated keyword NUL, text.
			$meta .= self::png_chunk( 'iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . $xmp );
		}

		$out      = "\x89PNG\r\n\x1A\n";
		$inserted = false;
		foreach ( $parsed['chunks'] as $chunk ) {
			if ( in_array( $chunk['type'], self::PNG_DROP, true ) ) {
				continue;
			}
			if ( ! $inserted && ( $chunk['type'] === 'IDAT' || $chunk['type'] === 'IEND' ) ) {
				$out     .= $meta; // eXIf must precede the image data.
				$inserted = true;
			}
			$out .= $chunk['raw'];
		}
		return $out;
	}

	private static function parse_png( string $b ): ?array {
		$len = strlen( $b );
		if ( $len < 20 || strncmp( $b, "\x89PNG\r\n\x1A\n", 8 ) !== 0 ) {
			return null;
		}
		$pos         = 8;
		$chunks      = array();
		$orientation = 1;
		$ended       = false;
		while ( $pos + 12 <= $len ) {
			$clen = unpack( 'N', substr( $b, $pos, 4 ) )[1];
			if ( $clen > $len - $pos - 12 ) {
				return null;
			}
			$type = substr( $b, $pos + 4, 4 );
			if ( $type === 'eXIf' && $orientation === 1 ) {
				$orientation = self::tiff_orientation( substr( $b, $pos + 8, $clen ) );
			}
			$chunks[] = array( 'type' => $type, 'raw' => substr( $b, $pos, 12 + $clen ) );
			$pos     += 12 + $clen;
			if ( $type === 'IEND' ) {
				$ended = true;
				break;
			}
		}
		if ( ! $ended || $chunks === array() || $chunks[0]['type'] !== 'IHDR' ) {
			return null;
		}
		return array( 'chunks' => $chunks, 'orientation' => $orientation );
	}

	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	// -------------------------------------------------------------------------
	// WebP
	// -------------------------------------------------------------------------

	const WEBP_FLAG_ANIM = 0x02;
	const WEBP_FLAG_XMP  = 0x04;
	const WEBP_FLAG_EXIF = 0x08;
	const WEBP_FLAG_ALPH = 0x10;

	private static function rewrite_webp( string $b, ?array $location, bool $keep_orientation ): ?string {
		$parsed = self::parse_webp( $b );
		if ( $parsed === null ) {
			return null;
		}
		$orientation = $keep_orientation ? $parsed['orientation'] : 1;
		$exif        = self::build_exif( $location, $orientation );
		$xmp         = self::build_xmp( $location );

		$body = '';
		if ( $parsed['vp8x'] !== null || $exif !== '' || $xmp !== '' ) {
			// Metadata needs the extended format. Keep the original flags (ICC, alpha, animation)
			// and set the metadata bits to what is actually written.
			$flags = $parsed['vp8x'] !== null ? ord( $parsed['vp8x'][0] ) : $parsed['simple_flags'];
			$flags &= ~( self::WEBP_FLAG_EXIF | self::WEBP_FLAG_XMP ) & 0xFF;
			if ( $exif !== '' ) {
				$flags |= self::WEBP_FLAG_EXIF;
			}
			if ( $xmp !== '' ) {
				$flags |= self::WEBP_FLAG_XMP;
			}
			$vp8x  = chr( $flags ) . "\0\0\0";
			$vp8x .= substr( pack( 'V', $parsed['width'] - 1 ), 0, 3 );
			$vp8x .= substr( pack( 'V', $parsed['height'] - 1 ), 0, 3 );
			$body .= self::webp_chunk( 'VP8X', $vp8x );
		}
		foreach ( $parsed['chunks'] as $chunk ) {
			$body .= self::webp_chunk( $chunk[0], $chunk[1] );
		}
		if ( $exif !== '' ) {
			$body .= self::webp_chunk( 'EXIF', $exif );
		}
		if ( $xmp !== '' ) {
			$body .= self::webp_chunk( 'XMP ', $xmp );
		}
		return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
	}

	private static function parse_webp( string $b ): ?array {
		$len = strlen( $b );
		if ( $len < 20 || substr( $b, 0, 4 ) !== 'RIFF' || substr( $b, 8, 4 ) !== 'WEBP' ) {
			return null;
		}
		$riff = unpack( 'V', substr( $b, 4, 4 ) )[1];
		$end  = min( $len, 8 + $riff );
		$pos  = 12;

		$vp8x        = null;
		$chunks      = array();
		$orientation = 1;
		$width       = 0;
		$height      = 0;
		$simple      = 0;
		while ( $pos + 8 <= $end ) {
			$fourcc = substr( $b, $pos, 4 );
			$size   = unpack( 'V', substr( $b, $pos + 4, 4 ) )[1];
			if ( $size > $end - $pos - 8 ) {
				return null;
			}
			$data = substr( $b, $pos + 8, $size );
			$pos += 8 + $size + ( $size & 1 );

			if ( $fourcc === 'EXIF' ) {
				if ( $orientation === 1 ) {
					$tiff        = strncmp( $data, "Exif\0\0", 6 ) === 0 ? substr( $data, 6 ) : $data;
					$orientation = self::tiff_orientation( $tiff );
				}
				continue;
			}
			if ( $fourcc === 'XMP ' ) {
				continue;
			}
			if ( $fourcc === 'VP8X' ) {
				if ( strlen( $data ) < 10 || $vp8x !== null ) {
					return null;
				}
				$vp8x   = $data;
				$width  = 1 + ( unpack( 'V', substr( $data, 4, 3 ) . "\0" )[1] );
				$height = 1 + ( unpack( 'V', substr( $data, 7, 3 ) . "\0" )[1] );
				continue;
			}
			if ( $vp8x === null && $width === 0 ) {
				if ( $fourcc === 'VP8 ' && strlen( $data ) >= 10 && substr( $data, 3, 3 ) === "\x9D\x01\x2A" ) {
					$width  = unpack( 'v', substr( $data, 6, 2 ) )[1] & 0x3FFF;
					$height = unpack( 'v', substr( $data, 8, 2 ) )[1] & 0x3FFF;
				} elseif ( $fourcc === 'VP8L' && strlen( $data ) >= 5 && ord( $data[0] ) === 0x2F ) {
					$bits   = unpack( 'V', substr( $data, 1, 4 ) )[1];
					$width  = ( $bits & 0x3FFF ) + 1;
					$height = ( ( $bits >> 14 ) & 0x3FFF ) + 1;
					if ( ( $bits >> 28 ) & 1 ) {
						$simple |= self::WEBP_FLAG_ALPH;
					}
				}
			}
			$chunks[] = array( $fourcc, $data );
		}
		if ( $chunks === array() || $width < 1 || $height < 1 ) {
			return null;
		}
		return array(
			'vp8x'         => $vp8x,
			'chunks'       => $chunks,
			'orientation'  => $orientation,
			'width'        => $width,
			'height'       => $height,
			'simple_flags' => $simple,
		);
	}

	private static function webp_chunk( string $fourcc, string $data ): string {
		$size = strlen( $data );
		return $fourcc . pack( 'V', $size ) . $data . ( $size & 1 ? "\0" : '' );
	}

	// -------------------------------------------------------------------------
	// EXIF / XMP builders
	// -------------------------------------------------------------------------

	/**
	 * Minimal little-endian TIFF/EXIF block: IFD0 (Orientation when not upright, GPS pointer) and a
	 * GPS IFD (version 2.3, lat/lng with refs, WGS-84). '' when there is nothing to write.
	 */
	public static function build_exif( ?array $location, int $orientation = 1 ): string {
		$has_orientation = $orientation >= 2 && $orientation <= 8;
		if ( $location === null && ! $has_orientation ) {
			return '';
		}

		$ifd0 = array();
		$gps  = array();
		if ( $has_orientation ) {
			$ifd0[0x0112] = array( 3, 1, pack( 'v', $orientation ) );
		}
		if ( $location !== null ) {
			$gps[0x0000]  = array( 1, 4, "\x02\x03\x00\x00" );
			$gps[0x0001]  = array( 2, 2, ( $location['lat'] < 0 ? 'S' : 'N' ) . "\0" );
			$gps[0x0002]  = array( 5, 3, self::dms_rationals( abs( $location['lat'] ) ) );
			$gps[0x0003]  = array( 2, 2, ( $location['lng'] < 0 ? 'W' : 'E' ) . "\0" );
			$gps[0x0004]  = array( 5, 3, self::dms_rationals( abs( $location['lng'] ) ) );
			$gps[0x0012]  = array( 2, 7, "WGS-84\0" );
			$ifd0[0x8825] = array( 4, 1, pack( 'V', 0 ) );
		}
		ksort( $ifd0 );
		ksort( $gps );

		$ifd0_offset = 8;
		$gps_offset  = $ifd0_offset + self::ifd_length( $ifd0 );
		if ( isset( $ifd0[0x8825] ) ) {
			$ifd0[0x8825][2] = pack( 'V', $gps_offset );
		}

		$tiff = "II*\0" . pack( 'V', $ifd0_offset ) . self::encode_ifd( $ifd0, $ifd0_offset );
		if ( $gps !== array() ) {
			$tiff .= self::encode_ifd( $gps, $gps_offset );
		}
		return $tiff;
	}

	/** Bytes an IFD occupies including its out-of-line values. */
	private static function ifd_length( array $entries ): int {
		$n = 2 + 12 * count( $entries ) + 4;
		foreach ( $entries as $entry ) {
			$l = strlen( $entry[2] );
			if ( $l > 4 ) {
				$n += $l + ( $l & 1 );
			}
		}
		return $n;
	}

	private static function encode_ifd( array $entries, int $offset ): string {
		$dir         = pack( 'v', count( $entries ) );
		$data        = '';
		$data_offset = $offset + 2 + 12 * count( $entries ) + 4;
		foreach ( $entries as $tag => $entry ) {
			list( $type, $count, $value ) = $entry;
			$dir .= pack( 'vvV', $tag, $type, $count );
			if ( strlen( $value ) <= 4 ) {
				$dir .= str_pad( $value, 4, "\0" );
			} else {
				$dir  .= pack( 'V', $data_offset + strlen( $data ) );
				$data .= $value;
				if ( strlen( $value ) & 1 ) {
					$data .= "\0";
				}
			}
		}
		return $dir . pack( 'V', 0 ) . $data;
	}

	/** Degrees, minutes, seconds as three EXIF RATIONALs; seconds to 1/10000 (about 3 mm). */
	public static function dms_rationals( float $value ): string {
		$total = (int) round( $value * 36000000 ); // 1/10000 arc-seconds.
		$deg   = intdiv( $total, 36000000 );
		$rest  = $total - $deg * 36000000;
		$min   = intdiv( $rest, 600000 );
		$sec   = $rest - $min * 600000;
		return pack( 'VVVVVV', $deg, 1, $min, 1, $sec, 10000 );
	}

	/** XMP coordinate notation "DDD,MM.mmmmmmK". */
	public static function xmp_coordinate( float $value, bool $is_lat ): string {
		$ref   = $is_lat ? ( $value < 0 ? 'S' : 'N' ) : ( $value < 0 ? 'W' : 'E' );
		$total = (int) round( abs( $value ) * 60000000 ); // micro-minutes.
		$deg   = intdiv( $total, 60000000 );
		$min   = ( $total - $deg * 60000000 ) / 1000000;
		return sprintf( '%d,%.6f%s', $deg, $min, $ref );
	}

	/** XMP packet with the business location; '' when no location is configured. */
	public static function build_xmp( ?array $location ): string {
		if ( $location === null ) {
			return '';
		}
		$attrs = array(
			'exif:GPSVersionID' => '2.3.0.0',
			'exif:GPSLatitude'  => self::xmp_coordinate( $location['lat'], true ),
			'exif:GPSLongitude' => self::xmp_coordinate( $location['lng'], false ),
			'exif:GPSMapDatum'  => 'WGS-84',
		);
		if ( ( $location['city'] ?? '' ) !== '' ) {
			$attrs['photoshop:City'] = $location['city'];
		}
		if ( ( $location['state'] ?? '' ) !== '' ) {
			$attrs['photoshop:State'] = $location['state'];
		}
		$out = '';
		foreach ( $attrs as $name => $value ) {
			$out .= ' ' . $name . '="' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '"';
		}
		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/"' . $out . '/>'
			. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
	}

	/** Orientation tag (0x0112) from IFD0 of a TIFF block; 1 when absent or malformed. */
	public static function tiff_orientation( string $tiff ): int {
		$len = strlen( $tiff );
		if ( $len < 8 ) {
			return 1;
		}
		$order = substr( $tiff, 0, 2 );
		if ( $order === 'II' ) {
			$s = 'v';
			$l = 'V';
		} elseif ( $order === 'MM' ) {
			$s = 'n';
			$l = 'N';
		} else {
			return 1;
		}
		$ifd = unpack( $l, substr( $tiff, 4, 4 ) )[1];
		if ( $ifd < 8 || $ifd + 2 > $len ) {
			return 1;
		}
		$count = unpack( $s, substr( $tiff, $ifd, 2 ) )[1];
		for ( $i = 0; $i < $count && $i < 1024; $i++ ) {
			$entry = $ifd + 2 + $i * 12;
			if ( $entry + 12 > $len ) {
				break;
			}
			if ( unpack( $s, substr( $tiff, $entry, 2 ) )[1] !== 0x0112 ) {
				continue;
			}
			$type  = unpack( $s, substr( $tiff, $entry + 2, 2 ) )[1];
			$value = 1;
			if ( $type === 3 ) {
				$value = unpack( $s, substr( $tiff, $entry + 8, 2 ) )[1];
			} elseif ( $type === 4 ) {
				$value = unpack( $l, substr( $tiff, $entry + 8, 4 ) )[1];
			}
			return ( $value >= 1 && $value <= 8 ) ? (int) $value : 1;
		}
		return 1;
	}

	private static function result( bool $ok, bool $changed, string $reason, bool $gps = false ): array {
		return array( 'ok' => $ok, 'changed' => $changed, 'reason' => $reason, 'gps' => $ok && $gps );
	}
}
