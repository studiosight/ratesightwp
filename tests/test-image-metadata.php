<?php
/**
 * Ratesight_Image_Metadata: lossless strip of personal / location metadata and the write of the
 * configured business location, on hand-built JPEG, PNG and WebP containers. Pure PHP: runs on any
 * PHP 8 with no extensions. The last section re-checks real encoder output and is skipped when GD
 * (or the exif extension, for reading GPS back) is not loaded.
 *
 * Run: php tests/test-image-metadata.php
 */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
require __DIR__ . '/../includes/class-ratesight-image-metadata.php';

$failures = 0;
$checks   = 0;
$skipped  = 0;
function check_meta( string $name, bool $ok ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}
function skip_meta( string $name, string $why ): void {
	global $skipped;
	$skipped++;
	echo 'skip   ' . $name . ' (' . $why . ')' . PHP_EOL;
}

// ── Test-side builders and readers ───────────────────────────────────────────

/** Generic TIFF writer (big-endian, to exercise the MM reader). $ifds: list of [tag => [type, count, bytes]]; IFD n+1 is pointed to by tag 0x8825 of IFD 0. */
function tiff_mm( array $ifd0, array $gps = array() ): string {
	$encode = static function ( array $entries, int $offset ): string {
		ksort( $entries );
		$dir  = pack( 'n', count( $entries ) );
		$data = '';
		$doff = $offset + 2 + 12 * count( $entries ) + 4;
		foreach ( $entries as $tag => $e ) {
			$dir .= pack( 'nnN', $tag, $e[0], $e[1] );
			if ( strlen( $e[2] ) <= 4 ) { $dir .= str_pad( $e[2], 4, "\0" ); }
			else { $dir .= pack( 'N', $doff + strlen( $data ) ); $data .= $e[2]; if ( strlen( $e[2] ) & 1 ) $data .= "\0"; }
		}
		return $dir . pack( 'N', 0 ) . $data;
	};
	if ( $gps ) $ifd0[0x8825] = array( 4, 1, pack( 'N', 0 ) );
	$first = $encode( $ifd0, 8 );
	if ( $gps ) {
		$ifd0[0x8825] = array( 4, 1, pack( 'N', 8 + strlen( $first ) ) );
		$first = $encode( $ifd0, 8 );
	}
	$tiff = "MM\0*" . pack( 'N', 8 ) . $first;
	if ( $gps ) $tiff .= $encode( $gps, 8 + strlen( $first ) );
	return $tiff;
}

/** Phone-style EXIF: owner, camera, serial, orientation, GPS of somewhere that is NOT the business. */
function phone_exif( int $orientation = 6 ): string {
	$ascii = static fn( string $s ): array => array( 2, strlen( $s ) + 1, $s . "\0" );
	$rat   = static fn( int ...$v ): string => implode( '', array_map( static fn( $x ) => pack( 'NN', $x, 1 ), $v ) );
	$ifd0  = array(
		0x010F => $ascii( 'PhoneCo' ),
		0x0110 => $ascii( 'PhoneCo Model X' ),
		0x013B => $ascii( 'Jane Owner' ),
		0xA431 => $ascii( 'SERIAL-XYZ-123' ),
	);
	if ( $orientation > 1 ) $ifd0[0x0112] = array( 3, 1, pack( 'n', $orientation ) );
	$gps = array(
		0x0001 => array( 2, 2, "N\0" ),
		0x0002 => array( 5, 3, $rat( 51, 30, 26 ) ),
		0x0003 => array( 2, 2, "W\0" ),
		0x0004 => array( 5, 3, $rat( 0, 7, 39 ) ),
	);
	return tiff_mm( $ifd0, $gps );
}

function seg( int $marker, string $payload ): string { return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload; }

const ENTROPY_1 = "\x12\x34\xFF\x00\x56\xFF\xD0\x78\x9A";
const ENTROPY_2 = "\xAB\xFF\x00\xCD";

function fake_jpeg( bool $with_meta = true, bool $progressive = false, int $orientation = 6 ): string {
	$b  = "\xFF\xD8";
	$b .= seg( 0xE0, "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00" );
	if ( $with_meta ) {
		$b .= seg( 0xE1, "Exif\0\0" . phone_exif( $orientation ) );
		$b .= seg( 0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><dc:creator>Jane Owner</dc:creator><exif:GPSLatitude>51,30.43N</exif:GPSLatitude></x:xmpmeta>" );
		$b .= seg( 0xED, "Photoshop 3.0\0" . '8BIM' . 'Jane Owner' );
		$b .= seg( 0xE2, "MPF\0" . 'index' );
		$b .= seg( 0xFE, 'SERIAL-XYZ-123 comment' );
	}
	$b .= seg( 0xE2, "ICC_PROFILE\0\x01\x01FAKEPROFILE" );
	$b .= seg( 0xEE, "Adobe\x00\x64\x00\x00\x00\x00\x01" );
	$b .= seg( 0xDB, "\x00" . str_repeat( "\x01", 64 ) );
	$b .= seg( 0xC0, "\x08\x00\x10\x00\x10\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01" );
	$b .= seg( 0xC4, "\x00" . str_repeat( "\x00", 16 ) );
	$b .= seg( 0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00" ) . ENTROPY_1;
	if ( $progressive ) {
		$b .= seg( 0xC4, "\x10" . str_repeat( "\x00", 16 ) );
		$b .= seg( 0xDA, "\x01\x01\x00\x00\x3F\x00" ) . ENTROPY_2;
	}
	$b .= "\xFF\xD9";
	if ( $with_meta ) $b .= "\xFF\xD8" . seg( 0xE1, "Exif\0\0" . phone_exif() ) . 'Jane Owner trailing secondary image' . "\xFF\xD9";
	return $b;
}

/** JPEG segments before SOS: list of [marker, payload]. */
function jpeg_segments( string $b ): array {
	$out = array();
	$pos = 2;
	while ( $pos + 4 <= strlen( $b ) ) {
		$m = ord( $b[ $pos + 1 ] );
		$l = unpack( 'n', substr( $b, $pos + 2, 2 ) )[1];
		$out[] = array( $m, substr( $b, $pos + 4, $l - 2 ) );
		if ( $m === 0xDA ) break;
		$pos += 2 + $l;
	}
	return $out;
}

/** Read IFD0 + GPS from a TIFF block (either byte order). */
function read_tiff( string $t ): array {
	$s = substr( $t, 0, 2 ) === 'II' ? 'v' : 'n';
	$l = substr( $t, 0, 2 ) === 'II' ? 'V' : 'N';
	$read_ifd = static function ( int $off ) use ( $t, $s, $l ): array {
		$n = unpack( $s, substr( $t, $off, 2 ) )[1];
		$entries = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$e = $off + 2 + 12 * $i;
			$tag = unpack( $s, substr( $t, $e, 2 ) )[1];
			$type = unpack( $s, substr( $t, $e + 2, 2 ) )[1];
			$count = unpack( $l, substr( $t, $e + 4, 4 ) )[1];
			$size = array( 1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8 )[ $type ] * $count;
			$raw = $size <= 4 ? substr( $t, $e + 8, $size ) : substr( $t, unpack( $l, substr( $t, $e + 8, 4 ) )[1], $size );
			$entries[ $tag ] = array( $type, $count, $raw );
		}
		return $entries;
	};
	$ifd0 = $read_ifd( unpack( $l, substr( $t, 4, 4 ) )[1] );
	$gps  = isset( $ifd0[0x8825] ) ? $read_ifd( unpack( $l, $ifd0[0x8825][2] )[1] ) : array();
	$deg  = static function ( string $raw ) use ( $l ): float {
		$v = array_values( unpack( $l . '6', $raw ) );
		return $v[0] / $v[1] + $v[2] / $v[3] / 60 + $v[4] / $v[5] / 3600;
	};
	$out = array( 'tags' => array_keys( $ifd0 ), 'orientation' => isset( $ifd0[0x0112] ) ? unpack( $s, $ifd0[0x0112][2] )[1] : null, 'gps' => null );
	if ( $gps ) {
		$lat = $deg( $gps[0x0002][2] ) * ( rtrim( $gps[0x0001][2], "\0" ) === 'S' ? -1 : 1 );
		$lng = $deg( $gps[0x0004][2] ) * ( rtrim( $gps[0x0003][2], "\0" ) === 'W' ? -1 : 1 );
		$out['gps'] = array( 'lat' => $lat, 'lng' => $lng, 'datum' => rtrim( $gps[0x0012][2] ?? '', "\0" ), 'version' => bin2hex( $gps[0x0000][2] ?? '' ) );
	}
	return $out;
}

function exif_of_jpeg( string $b ): ?string {
	foreach ( jpeg_segments( $b ) as $s ) if ( $s[0] === 0xE1 && strncmp( $s[1], "Exif\0\0", 6 ) === 0 ) return substr( $s[1], 6 );
	return null;
}
function xmp_of_jpeg( string $b ): ?string {
	foreach ( jpeg_segments( $b ) as $s ) if ( $s[0] === 0xE1 && strncmp( $s[1], 'http://ns.adobe.com/xap/1.0/', 28 ) === 0 ) return $s[1];
	return null;
}

$business = Ratesight_Image_Metadata::normalize_location( '40.4406248', '-79.9958864', 'Pitt & <burgh>', 'PA' );
$south    = Ratesight_Image_Metadata::normalize_location( -33.8688197, 151.2092955 );
$personal = array( 'Jane Owner', 'PhoneCo', 'SERIAL-XYZ-123', 'Photoshop 3.0', 'MPF', 'trailing secondary' );
$leaks    = static fn( string $b ): array => array_values( array_filter( $personal, static fn( $p ) => str_contains( $b, $p ) ) );

// ── Location validation ──────────────────────────────────────────────────────
check_meta( 'a valid pair is accepted, with optional labels', $business !== null && abs( $business['lat'] - 40.4406248 ) < 1e-9 && $business['city'] === 'Pitt & <burgh>' && $business['state'] === 'PA' );
check_meta( 'a missing longitude means no location (strip only)', Ratesight_Image_Metadata::normalize_location( '40.44', '' ) === null );
check_meta( 'non-numeric input means no location', Ratesight_Image_Metadata::normalize_location( '40.44N', '-79.99' ) === null );
check_meta( 'latitude out of range means no location', Ratesight_Image_Metadata::normalize_location( '91', '10' ) === null );
check_meta( 'longitude out of range means no location', Ratesight_Image_Metadata::normalize_location( '10', '-180.5' ) === null );
check_meta( '0,0 (the unset value) means no location', Ratesight_Image_Metadata::normalize_location( '0', '0.0' ) === null );
check_meta( 'labels are trimmed, control characters removed, cut to 100 characters', Ratesight_Image_Metadata::normalize_location( 1, 1, "  A\x01B  ", str_repeat( 'x', 150 ) )['city'] === 'A B' && strlen( Ratesight_Image_Metadata::normalize_location( 1, 1, '', str_repeat( 'x', 150 ) )['state'] ) === 100 );

// ── Coordinate encoding ──────────────────────────────────────────────────────
$r = array_values( unpack( 'V6', Ratesight_Image_Metadata::dms_rationals( 79.9958864 ) ) );
$back = $r[0] / $r[1] + $r[2] / $r[3] / 60 + $r[4] / $r[5] / 3600;
check_meta( 'EXIF degree/minute/second rationals round-trip to within 1e-7 degrees', abs( $back - 79.9958864 ) < 1e-7 && $r[0] === 79 && $r[2] === 59 );
$r = array_values( unpack( 'V6', Ratesight_Image_Metadata::dms_rationals( 10.9999999999 ) ) );
check_meta( 'seconds never round up to 60 (carry goes into minutes / degrees)', $r[4] < 600000 && $r[2] < 60 );
check_meta( 'XMP coordinate notation DDD,MM.mmmmmmK', Ratesight_Image_Metadata::xmp_coordinate( -79.9958864, false ) === '79,59.753184W' && Ratesight_Image_Metadata::xmp_coordinate( 40.5, true ) === '40,30.000000N' );

// ── JPEG ─────────────────────────────────────────────────────────────────────
$src = fake_jpeg();
check_meta( 'the reader sees the phone orientation (big-endian EXIF)', Ratesight_Image_Metadata::orientation( $src ) === 6 );
$out = Ratesight_Image_Metadata::rewrite_bytes( $src, $business );
check_meta( 'JPEG: rewritten', is_string( $out ) );
$segs = jpeg_segments( $out );
check_meta( 'JPEG: SOI then JFIF first', substr( $out, 0, 2 ) === "\xFF\xD8" && $segs[0][0] === 0xE0 && str_starts_with( $segs[0][1], 'JFIF' ) );
check_meta( 'JPEG: no owner, camera, serial, IPTC, MPF or trailing image left: ' . json_encode( $leaks( $out ) ), $leaks( $out ) === array() );
check_meta( 'JPEG: ICC profile and Adobe segment kept', str_contains( $out, "ICC_PROFILE\0\x01\x01FAKEPROFILE" ) && str_contains( $out, "Adobe\x00\x64" ) );
check_meta( 'JPEG: scan data kept byte for byte and the file ends at EOI', str_contains( $out, ENTROPY_1 . "\xFF\xD9" ) && str_ends_with( $out, ENTROPY_1 . "\xFF\xD9" ) );
$tiff = read_tiff( (string) exif_of_jpeg( $out ) );
check_meta( 'JPEG: GPS is the configured business location, not the phone location', $tiff['gps'] !== null && abs( $tiff['gps']['lat'] - 40.4406248 ) < 1e-6 && abs( $tiff['gps']['lng'] + 79.9958864 ) < 1e-6 );
check_meta( 'JPEG: GPS version 2.3, datum WGS-84', $tiff['gps']['version'] === '02030000' && $tiff['gps']['datum'] === 'WGS-84' );
check_meta( 'JPEG: orientation 6 carried over so the photo stays upright', $tiff['orientation'] === 6 );
check_meta( 'JPEG: IFD0 holds only Orientation and the GPS pointer', $tiff['tags'] === array( 0x0112, 0x8825 ) );
$xmp = (string) xmp_of_jpeg( $out );
check_meta( 'JPEG: XMP carries the coordinates and the city/state, XML-escaped', str_contains( $xmp, 'exif:GPSLatitude="40,26.437488N"' ) && str_contains( $xmp, 'photoshop:City="Pitt &amp; &lt;burgh&gt;"' ) && str_contains( $xmp, 'photoshop:State="PA"' ) && ! str_contains( $xmp, '<burgh>' ) );
check_meta( 'JPEG: rewriting twice gives the same bytes (idempotent)', Ratesight_Image_Metadata::rewrite_bytes( $out, $business ) === $out );

$south_out = Ratesight_Image_Metadata::rewrite_bytes( $src, $south );
$st        = read_tiff( (string) exif_of_jpeg( $south_out ) );
check_meta( 'JPEG: southern / eastern hemisphere refs', abs( $st['gps']['lat'] + 33.8688197 ) < 1e-6 && abs( $st['gps']['lng'] - 151.2092955 ) < 1e-6 && str_contains( (string) xmp_of_jpeg( $south_out ), '151,12.557730E' ) );

$upright = read_tiff( (string) exif_of_jpeg( Ratesight_Image_Metadata::rewrite_bytes( $src, $business, false ) ) );
check_meta( 'JPEG: after the pixels were rotated, the orientation tag is dropped', $upright['orientation'] === null && $upright['gps'] !== null );

$strip = Ratesight_Image_Metadata::rewrite_bytes( $src, null );
$st    = read_tiff( (string) exif_of_jpeg( $strip ) );
check_meta( 'JPEG, no location configured: no GPS written, none invented', $st['gps'] === null && xmp_of_jpeg( $strip ) === null && $leaks( $strip ) === array() );
check_meta( 'JPEG, no location configured: orientation still carried over', $st['orientation'] === 6 );
$plain = Ratesight_Image_Metadata::rewrite_bytes( fake_jpeg( true, false, 1 ), null );
check_meta( 'JPEG, no location and upright: no EXIF block at all', exif_of_jpeg( $plain ) === null && $leaks( $plain ) === array() );
check_meta( 'JPEG with nothing to remove and nothing to add is unchanged', Ratesight_Image_Metadata::rewrite_bytes( fake_jpeg( false ), null ) === fake_jpeg( false ) );

$prog = Ratesight_Image_Metadata::rewrite_bytes( fake_jpeg( true, true ), $business );
check_meta( 'progressive JPEG: segments between scans and both scans kept', is_string( $prog ) && str_contains( $prog, ENTROPY_1 ) && str_ends_with( $prog, ENTROPY_2 . "\xFF\xD9" ) && $leaks( $prog ) === array() );
check_meta( 'truncated JPEG (no EOI) is refused, not guessed at', Ratesight_Image_Metadata::rewrite_bytes( substr( fake_jpeg( false ), 0, -2 ), $business ) === null );
check_meta( 'a non-image is refused', Ratesight_Image_Metadata::rewrite_bytes( 'GIF89a....', $business ) === null );

// ── PNG ──────────────────────────────────────────────────────────────────────
function png_chunk( string $type, string $data ): string { return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) ); }
function png_chunks( string $b ): array {
	$out = array(); $pos = 8;
	while ( $pos + 12 <= strlen( $b ) ) {
		$len = unpack( 'N', substr( $b, $pos, 4 ) )[1];
		$type = substr( $b, $pos + 4, 4 );
		$data = substr( $b, $pos + 8, $len );
		$crc_ok = unpack( 'N', substr( $b, $pos + 8 + $len, 4 ) )[1] === crc32( $type . $data );
		$out[] = array( $type, $data, $crc_ok );
		$pos += 12 + $len;
	}
	return $out;
}
$png = "\x89PNG\r\n\x1A\n" . png_chunk( 'IHDR', pack( 'NN', 16, 16 ) . "\x08\x06\x00\x00\x00" ) . png_chunk( 'iCCP', "icc\0\0data" )
	. png_chunk( 'tEXt', "Author\0Jane Owner" ) . png_chunk( 'eXIf', phone_exif( 3 ) ) . png_chunk( 'iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x>Jane Owner</x>" )
	. png_chunk( 'IDAT', 'pixels' ) . png_chunk( 'tIME', "\x07\xE9\x01\x01\x00\x00\x00" ) . png_chunk( 'IEND', '' );
$pout   = Ratesight_Image_Metadata::rewrite_bytes( $png, $business );
$chunks = png_chunks( (string) $pout );
$types  = array_column( $chunks, 0 );
check_meta( 'PNG: owner text, phone EXIF and timestamps removed', is_string( $pout ) && $leaks( $pout ) === array() && ! in_array( 'tEXt', $types, true ) && ! in_array( 'tIME', $types, true ) );
check_meta( 'PNG: chunk order IHDR, iCCP, eXIf, iTXt(XMP), IDAT, IEND', $types === array( 'IHDR', 'iCCP', 'eXIf', 'iTXt', 'IDAT', 'IEND' ) );
check_meta( 'PNG: every chunk CRC is valid', ! in_array( false, array_column( $chunks, 2 ), true ) );
$pt = read_tiff( $chunks[2][1] );
check_meta( 'PNG: eXIf has the business GPS and the original orientation', abs( $pt['gps']['lat'] - 40.4406248 ) < 1e-6 && $pt['orientation'] === 3 );
check_meta( 'PNG: idempotent', Ratesight_Image_Metadata::rewrite_bytes( $pout, $business ) === $pout );
$pstrip = Ratesight_Image_Metadata::rewrite_bytes( $png, null );
check_meta( 'PNG, no location: no GPS', ! str_contains( $pstrip, 'WGS-84' ) && $leaks( $pstrip ) === array() );

// ── WebP ─────────────────────────────────────────────────────────────────────
function webp_chunk( string $fourcc, string $data ): string { return $fourcc . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) & 1 ? "\0" : '' ); }
function webp( string ...$chunks ): string { $body = implode( '', $chunks ); return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body; }
function webp_chunks( string $b ): array {
	$out = array(); $pos = 12;
	while ( $pos + 8 <= strlen( $b ) ) {
		$size = unpack( 'V', substr( $b, $pos + 4, 4 ) )[1];
		$out[] = array( substr( $b, $pos, 4 ), substr( $b, $pos + 8, $size ) );
		$pos += 8 + $size + ( $size & 1 );
	}
	return $out;
}
$vp8l   = "\x2F" . pack( 'V', ( 300 - 1 ) | ( ( 200 - 1 ) << 14 ) | ( 1 << 28 ) ) . 'odd'; // 8 bytes... plus one below to make it odd
$vp8l  .= 'x';
$simple = webp( webp_chunk( 'VP8L', $vp8l ) );
$wout   = Ratesight_Image_Metadata::rewrite_bytes( $simple, $business );
$wc     = webp_chunks( (string) $wout );
check_meta( 'WebP (simple, lossless): extended header added, order VP8X, VP8L, EXIF, XMP', array_column( $wc, 0 ) === array( 'VP8X', 'VP8L', 'EXIF', 'XMP ' ) );
$flags = ord( $wc[0][1][0] );
$w     = 1 + unpack( 'V', substr( $wc[0][1], 4, 3 ) . "\0" )[1];
$h     = 1 + unpack( 'V', substr( $wc[0][1], 7, 3 ) . "\0" )[1];
check_meta( 'WebP: VP8X flags EXIF + XMP + alpha, canvas 300x200 from the bitstream', $flags === ( 0x08 | 0x04 | 0x10 ) && $w === 300 && $h === 200 );
check_meta( 'WebP: RIFF size and odd-chunk padding are correct', unpack( 'V', substr( $wout, 4, 4 ) )[1] === strlen( $wout ) - 8 && strlen( $wout ) % 2 === 0 && $wc[1][1] === $vp8l );
check_meta( 'WebP: EXIF has the business GPS', abs( read_tiff( $wc[2][1] )['gps']['lng'] + 79.9958864 ) < 1e-6 );
check_meta( 'WebP: idempotent', Ratesight_Image_Metadata::rewrite_bytes( $wout, $business ) === $wout );
check_meta( 'WebP (simple), nothing to add: unchanged', Ratesight_Image_Metadata::rewrite_bytes( $simple, null ) === $simple );

$vp8   = "\x00\x00\x00\x9D\x01\x2A" . pack( 'v', 640 ) . pack( 'v', 480 ) . 'lossy';
$lossy = Ratesight_Image_Metadata::rewrite_bytes( webp( webp_chunk( 'VP8 ', $vp8 ) ), $business );
$lc    = webp_chunks( (string) $lossy );
check_meta( 'WebP (simple, lossy): canvas 640x480 read from the VP8 frame header', 1 + unpack( 'V', substr( $lc[0][1], 4, 3 ) . "\0" )[1] === 640 && 1 + unpack( 'V', substr( $lc[0][1], 7, 3 ) . "\0" )[1] === 480 && ord( $lc[0][1][0] ) === 0x0C );

$vp8x     = chr( 0x20 | 0x08 | 0x04 ) . "\0\0\0" . substr( pack( 'V', 639 ), 0, 3 ) . substr( pack( 'V', 479 ), 0, 3 );
$extended = webp( webp_chunk( 'VP8X', $vp8x ), webp_chunk( 'ICCP', 'profile' ), webp_chunk( 'VP8 ', $vp8 ), webp_chunk( 'EXIF', "Exif\0\0" . phone_exif( 8 ) ), webp_chunk( 'XMP ', '<x>Jane Owner</x>' ) );
$es       = Ratesight_Image_Metadata::rewrite_bytes( $extended, null );
$ec       = webp_chunks( (string) $es );
check_meta( 'WebP (extended), no location: EXIF/XMP removed, ICC kept', array_column( $ec, 0 ) === array( 'VP8X', 'ICCP', 'VP8 ', 'EXIF' ) && $leaks( $es ) === array() );
check_meta( 'WebP (extended): flags keep ICC, gain EXIF only for the carried orientation', ord( $ec[0][1][0] ) === ( 0x20 | 0x08 ) && read_tiff( $ec[3][1] )['orientation'] === 8 && read_tiff( $ec[3][1] )['gps'] === null );
check_meta( 'WebP orientation read through an "Exif\0\0"-prefixed chunk', Ratesight_Image_Metadata::orientation( $extended ) === 8 );
check_meta( 'WebP with a chunk running past the RIFF end is refused', Ratesight_Image_Metadata::rewrite_bytes( substr( $simple, 0, 12 ) . 'VP8L' . pack( 'V', 9999 ) . 'x', null ) === null );

// ── Files ────────────────────────────────────────────────────────────────────
$tmp = tempnam( sys_get_temp_dir(), 'rsimg' );
file_put_contents( $tmp, $src );
$res = Ratesight_Image_Metadata::rewrite_file( $tmp, $business );
check_meta( 'rewrite_file: rewritten in place, reports GPS written', $res['ok'] && $res['changed'] && $res['gps'] && file_get_contents( $tmp ) === $out );
$res = Ratesight_Image_Metadata::rewrite_file( $tmp, $business );
check_meta( 'rewrite_file: second run is a no-op', $res['ok'] && ! $res['changed'] );
check_meta( 'file_orientation reads a file', Ratesight_Image_Metadata::file_orientation( $tmp ) === 6 );
file_put_contents( $tmp, 'not an image' );
$res = Ratesight_Image_Metadata::rewrite_file( $tmp, $business );
check_meta( 'rewrite_file: unsupported file left untouched', ! $res['ok'] && $res['reason'] === 'unsupported' && file_get_contents( $tmp ) === 'not an image' );
unlink( $tmp );
check_meta( 'no temp files left behind', glob( $tmp . '.rs-meta-*' ) === array() );

// ── Size and memory guards (an out-of-memory error cannot be caught) ─────────
check_meta( 'whole-file reads are capped at 12 MB (about 3x buffered stays inside a 128M limit)', Ratesight_Image_Metadata::MAX_BYTES === 12582912 && Ratesight_Image_Metadata::MEMORY_FACTOR === 4 );
check_meta( 'memory_limit parsing: 128M, 1G, 512K, plain bytes; -1 and empty = unlimited', Ratesight_Image_Metadata::memory_limit_bytes( '128M' ) === 134217728 && Ratesight_Image_Metadata::memory_limit_bytes( '1G' ) === 1073741824 && Ratesight_Image_Metadata::memory_limit_bytes( '512K' ) === 524288 && Ratesight_Image_Metadata::memory_limit_bytes( '65536' ) === 65536 && Ratesight_Image_Metadata::memory_limit_bytes( '-1' ) === -1 && Ratesight_Image_Metadata::memory_limit_bytes( '' ) === -1 );
$mb = 1048576;
check_meta( 'memory pre-check: 12 MB x4 + 60 MB in use fits 128M', Ratesight_Image_Metadata::memory_allows( 12 * $mb, 128 * $mb, 60 * $mb ) );
check_meta( 'memory pre-check: 12 MB x4 + 90 MB in use does not fit 128M', ! Ratesight_Image_Metadata::memory_allows( 12 * $mb, 128 * $mb, 90 * $mb ) );
check_meta( 'memory pre-check: unlimited (-1) always fits', Ratesight_Image_Metadata::memory_allows( 500 * $mb, -1, 90 * $mb ) );
$big = tempnam( sys_get_temp_dir(), 'rsimg' );
$h   = fopen( $big, 'r+' );
ftruncate( $h, Ratesight_Image_Metadata::MAX_BYTES + 1 ); // Sparse: nothing is written or read.
fclose( $h );
clearstatcache();
$res = Ratesight_Image_Metadata::rewrite_file( $big, $business );
check_meta( 'rewrite_file refuses a file over MAX_BYTES without reading it', ! $res['ok'] && $res['reason'] === 'size' && filesize( $big ) === Ratesight_Image_Metadata::MAX_BYTES + 1 );
check_meta( 'file_orientation does not read a file over MAX_BYTES', Ratesight_Image_Metadata::file_orientation( $big ) === 1 );
unlink( $big );
$small = tempnam( sys_get_temp_dir(), 'rsimg' );
file_put_contents( $small, $src );
check_meta( 'sniff_file reads only the magic bytes', Ratesight_Image_Metadata::sniff_file( $small ) === 'image/jpeg' && Ratesight_Image_Metadata::sniff_file( $small . '-missing' ) === '' );
unlink( $small );

// ── Real encoder output (GD) ─────────────────────────────────────────────────
if ( ! extension_loaded( 'gd' ) ) {
	skip_meta( 'GD round-trip: real JPEG / PNG / WebP stay decodable after the rewrite', 'GD extension not loaded on this PHP' );
} else {
	$img = imagecreatetruecolor( 40, 20 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 200, 30, 30 ) );
	$encoders = array( 'jpeg' => 'imagejpeg', 'png' => 'imagepng' );
	if ( function_exists( 'imagewebp' ) ) $encoders['webp'] = 'imagewebp';
	foreach ( $encoders as $type => $fn ) {
		ob_start(); $fn( $img ); $raw = ob_get_clean();
		$re  = Ratesight_Image_Metadata::rewrite_bytes( $raw, $business );
		$dec = is_string( $re ) ? @imagecreatefromstring( $re ) : false;
		check_meta( "GD {$type}: still decodes, same size, location written", $dec !== false && imagesx( $dec ) === 40 && imagesy( $dec ) === 20 && str_contains( $re, 'WGS-84' ) );
		if ( $type === 'jpeg' ) {
			if ( function_exists( 'exif_read_data' ) ) {
				$f = tempnam( sys_get_temp_dir(), 'rsimg' );
				file_put_contents( $f, $re );
				$e = @exif_read_data( $f );
				unlink( $f );
				check_meta( 'exif extension reads back the business GPS', is_array( $e ) && ( $e['GPSLatitudeRef'] ?? '' ) === 'N' && ( $e['GPSLongitudeRef'] ?? '' ) === 'W' );
			} else {
				skip_meta( 'exif extension reads back the business GPS', 'exif extension not loaded' );
			}
		}
	}
	if ( ! function_exists( 'imagewebp' ) ) skip_meta( 'GD webp', 'GD built without WebP' );
}

echo PHP_EOL . ( $failures === 0 ? "ALL {$checks} CHECKS PASSED" : "{$failures} of {$checks} CHECKS FAILED" ) . ( $skipped ? " ({$skipped} skipped)" : '' ) . PHP_EOL;
exit( $failures === 0 ? 0 : 1 );
