<?php
/**
 * Image optimization tab (admin.php?page=ratesight&tab=images).
 *
 * One form saves the whole ratesight_options_images group, so every option of the group is a
 * field here (an option a form does not carry is written as empty on save).
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

settings_errors();
$rs_img     = Ratesight_Options::get_all();
$rs_env     = Ratesight_Image_Optimizer::environment();
$rs_set     = Ratesight_Image_Optimizer::settings();
$rs_has_geo = $rs_set['location'] !== null;
$rs_partial = ! $rs_has_geo && ( trim( (string) $rs_img['img_geo_lat'] ) !== '' || trim( (string) $rs_img['img_geo_lng'] ) !== '' );
$rs_yes_no  = static function ( bool $ok ): string {
	return $ok ? '<strong style="color:#137333;">Yes</strong>' : '<strong style="color:#b32d2e;">No</strong>';
};
?>
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_images' ); ?>

<h2 class="rs-section">Image optimization</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Optimize uploads</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_img_opt_enabled" value="1" <?php checked( (int) $rs_img['img_opt_enabled'], 1 ); ?>>
				Resize, compress and clean the metadata of new image uploads
			</label>
			<p class="description">Off by default. While off, uploads are stored exactly as WordPress stores them.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_opt_max_edge">Maximum long edge</label></th>
		<td>
			<input type="number" min="0" max="12000" step="1" id="ratesight_img_opt_max_edge" name="ratesight_img_opt_max_edge" value="<?php echo esc_attr( (string) $rs_img['img_opt_max_edge'] ); ?>" class="small-text"> px
			<p class="description">Larger images are scaled down to this width or height (never enlarged). 0 = do not resize. Allowed: 320 to 12000.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_opt_format">Format for new uploads</label></th>
		<td>
			<select id="ratesight_img_opt_format" name="ratesight_img_opt_format">
				<option value="webp" <?php selected( $rs_img['img_opt_format'], 'webp' ); ?>>WebP</option>
				<option value="avif" <?php selected( $rs_img['img_opt_format'], 'avif' ); ?>>AVIF when supported, otherwise WebP</option>
				<option value="original" <?php selected( $rs_img['img_opt_format'], 'original' ); ?>>Keep the uploaded format</option>
			</select>
			<p class="description">When the server cannot write the chosen format, the upload keeps its own format. A conversion that would make the file larger is not kept. Animated GIF, WebP and PNG files are never re-encoded. AVIF encoding is slow: sizes that do not fit in the upload's time limit are written in the uploaded format (WebP for TIFF and BMP) until "Optimize existing images" below finishes them.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_opt_quality">Quality</label></th>
		<td>
			<input type="number" min="40" max="100" step="1" id="ratesight_img_opt_quality" name="ratesight_img_opt_quality" value="<?php echo esc_attr( (string) $rs_img['img_opt_quality'] ); ?>" class="small-text">
			<p class="description">WebP, AVIF and JPEG quality for the image and every size WordPress generates (default 82).</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Keep originals</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_img_opt_keep_original" value="1" <?php checked( (int) $rs_img['img_opt_keep_original'], 1 ); ?>>
				Keep a backup copy of each original (metadata removed) in <code>uploads/<?php echo esc_html( Ratesight_Image_Optimizer::BACKUP_DIR ); ?></code>
			</label>
			<p class="description">Off by default: originals are not kept, and nothing stays in the public uploads folder. Backups use extra disk space and are what makes rollback of the bulk tool possible.</p>
		</td>
	</tr>
</table>
</div>
</div>

<h2 class="rs-section">Business location in photos</h2>
<div class="rs-card">
<div class="rs-card-body">
<p class="description" style="margin-top:12px;">While optimization is on, location data from phones, camera details and serial numbers, owner names, copyright notices, keywords and embedded titles are removed from JPEG, PNG and WebP images and every size WordPress makes of them (HEIC photos included once WordPress has converted them to JPEG). When both coordinates are set, the business location below is written into each image instead. It is never taken from a visitor or from the photo. With no coordinates, images are only cleaned.</p>
<p class="description">Not every file can be cleaned: a HEIC, TIFF, GIF or other original that WordPress keeps as uploaded still holds its own metadata, and files over <?php echo esc_html( (string) (int) round( Ratesight_Image_Metadata::MAX_BYTES / 1048576 ) ); ?> MB (or too large for this server's PHP memory) are left exactly as uploaded.</p>
<?php if ( $rs_partial ) : ?>
	<p style="color:#b32d2e;"><strong>Both latitude and longitude are needed.</strong> Until both are valid, images are only cleaned.</p>
<?php endif; ?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="ratesight_img_geo_lat">Latitude</label></th>
		<td><input type="text" inputmode="decimal" id="ratesight_img_geo_lat" name="ratesight_img_geo_lat" value="<?php echo esc_attr( (string) $rs_img['img_geo_lat'] ); ?>" class="regular-text" placeholder="e.g. 40.4406"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_geo_lng">Longitude</label></th>
		<td><input type="text" inputmode="decimal" id="ratesight_img_geo_lng" name="ratesight_img_geo_lng" value="<?php echo esc_attr( (string) $rs_img['img_geo_lng'] ); ?>" class="regular-text" placeholder="e.g. -79.9959"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_geo_city">City (optional)</label></th>
		<td><input type="text" maxlength="100" id="ratesight_img_geo_city" name="ratesight_img_geo_city" value="<?php echo esc_attr( (string) $rs_img['img_geo_city'] ); ?>" class="regular-text"></td>
	</tr>
	<tr>
		<th scope="row"><label for="ratesight_img_geo_state">State (optional)</label></th>
		<td><input type="text" maxlength="100" id="ratesight_img_geo_state" name="ratesight_img_geo_state" value="<?php echo esc_attr( (string) $rs_img['img_geo_state'] ); ?>" class="regular-text"></td>
	</tr>
</table>
<div class="rs-submit"><?php submit_button( 'Save image settings', 'primary', 'submit', false ); ?></div>
</div>
</div>
</form>

<h2 class="rs-section">This server</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr><th scope="row">GD / Imagick</th><td><?php echo wp_kses_post( $rs_yes_no( $rs_env['gd'] ) . ' / ' . $rs_yes_no( $rs_env['imagick'] ) ); ?></td></tr>
	<tr><th scope="row">Can write WebP / AVIF</th><td><?php echo wp_kses_post( $rs_yes_no( $rs_env['webp'] ) . ' / ' . $rs_yes_no( $rs_env['avif'] ) ); ?></td></tr>
	<tr><th scope="row">Location written</th><td><?php echo wp_kses_post( $rs_yes_no( $rs_has_geo ) ); ?></td></tr>
</table>
<?php if ( ! $rs_env['gd'] && ! $rs_env['imagick'] ) : ?>
	<p class="description">No image library: images are not resized, compressed or converted. Metadata is still cleaned for JPEG, PNG and WebP.</p>
<?php endif; ?>
</div>
</div>

<h2 class="rs-section">Optimize existing images</h2>
<div class="rs-card">
<div class="rs-card-body">
<p class="description" style="margin-top:12px;">Existing images are optimized in place: same file names, same format, same URLs, so pages that already show them keep working. Each image and all its sizes are resized to the maximum long edge, recompressed when that makes them smaller, and cleaned. Files over <?php echo esc_html( (string) (int) round( Ratesight_Image_Metadata::MAX_BYTES / 1048576 ) ); ?> MB are skipped. With AVIF chosen, sizes of new uploads that did not fit in the upload's time limit are kept in their original format and are encoded as AVIF here (the earlier size file is kept, because a post may link to it). Runs a few images per request while this page is open.</p>
<p>
	<button type="button" class="button" id="rs-img-count">Count images (dry run)</button>
	<button type="button" class="button button-primary" id="rs-img-apply" disabled>Optimize existing images</button>
	<button type="button" class="button" id="rs-img-stop" disabled>Stop</button>
	<button type="button" class="button" id="rs-img-rollback" disabled>Roll back to originals</button>
</p>
<p id="rs-img-summary" aria-live="polite"></p>
<ul id="rs-img-log" style="max-height:220px;overflow:auto;font-size:12px;margin:0;"></ul>
</div>
</div>

<script>
( function ( $ ) {
	var nonce = <?php echo wp_json_encode( wp_create_nonce( Ratesight_Image_Optimizer::NONCE ) ); ?>;
	var running = false;
	var $sum = $( '#rs-img-summary' ), $log = $( '#rs-img-log' );

	function text( s ) { return $( '<div>' ).text( s ).html(); }
	function line( s ) { $log.prepend( '<li>' + text( s ) + '</li>' ); }
	function kb( n ) { return Math.round( ( n || 0 ) / 1024 ) + ' KB'; }
	function setRunning( on ) {
		running = on;
		$( '#rs-img-stop' ).prop( 'disabled', ! on );
		$( '#rs-img-count' ).prop( 'disabled', on );
		if ( on ) { $( '#rs-img-apply, #rs-img-rollback' ).prop( 'disabled', true ); }
	}
	function post( action, extra ) {
		return $.post( ajaxurl, $.extend( { action: action, nonce: nonce }, extra || {} ) );
	}
	function count() {
		post( 'ratesight_img_opt_status' ).done( function ( r ) {
			if ( ! r || ! r.success ) { $sum.text( ( r && r.data && r.data.message ) || 'Request failed.' ); return; }
			var d = r.data;
			$sum.text( 'Dry run: ' + d.candidates + ' image(s) to optimize, ' + d.optimized + ' already optimized, ' + d.failed + ' skipped after an error, ' + d.rollbackable + ' with backups' + ( d.avif_pending ? ', ' + d.avif_pending + ' with sizes still to encode as AVIF' : '' ) + '. Nothing was written.' + ( d.enabled ? '' : ' Turn on Image optimization and save before optimizing.' ) );
			$( '#rs-img-apply' ).prop( 'disabled', ! d.enabled || ( d.candidates + ( d.avif_pending || 0 ) ) < 1 );
			$( '#rs-img-rollback' ).prop( 'disabled', d.rollbackable < 1 );
		} ).fail( function () { $sum.text( 'Request failed.' ); } );
	}
	function loop( action, confirmWord ) {
		if ( ! running ) { count(); return; }
		post( action, { confirm: confirmWord } ).done( function ( r ) {
			if ( ! r || ! r.success ) {
				line( ( r && r.data && r.data.message ) || 'Request failed.' );
				if ( r && r.data && r.data.busy ) { setTimeout( function () { loop( action, confirmWord ); }, 5000 ); return; }
				setRunning( false ); count(); return;
			}
			$.each( r.data.items || [], function ( i, it ) {
				line( '#' + it.id + ' ' + it.status + ( it.bytes_before ? ' (' + kb( it.bytes_before ) + ' to ' + kb( it.bytes_after ) + ')' : '' ) + ( it.message ? ': ' + it.message : '' ) );
			} );
			$sum.text( r.data.remaining + ' remaining.' );
			if ( r.data.done ) { setRunning( false ); count(); return; }
			loop( action, confirmWord );
		} ).fail( function () { line( 'Request failed; stopped.' ); setRunning( false ); count(); } );
	}

	$( '#rs-img-count' ).on( 'click', count );
	$( '#rs-img-stop' ).on( 'click', function () { running = false; $( this ).prop( 'disabled', true ); } );
	$( '#rs-img-apply' ).on( 'click', function () {
		if ( ! window.confirm( 'Optimize existing images in place now? Without "Keep originals" this cannot be undone.' ) ) { return; }
		setRunning( true ); loop( 'ratesight_img_opt_apply', 'apply' );
	} );
	$( '#rs-img-rollback' ).on( 'click', function () {
		if ( ! window.confirm( 'Restore the backed-up originals of every image the bulk tool optimized?' ) ) { return; }
		setRunning( true ); loop( 'ratesight_img_opt_rollback', 'rollback' );
	} );
} )( jQuery );
</script>
