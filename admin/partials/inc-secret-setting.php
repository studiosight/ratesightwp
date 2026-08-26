<?php
/**
 * Write-only secret replacement control.
 *
 * Required variables: $secret_setting_key, $secret_setting_input_id,
 * $secret_setting_label, and $secret_setting_placeholder.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$secret_setting_status = Ratesight_Options::secret_setting_status( $secret_setting_key );
?>
<input type="password" id="<?php echo esc_attr( $secret_setting_input_id ); ?>" class="regular-text"
	autocomplete="new-password" value="" placeholder="<?php echo esc_attr( $secret_setting_placeholder ); ?>">
<button type="button" class="button rs-save-secret" data-setting="<?php echo esc_attr( $secret_setting_key ); ?>"
	data-input="#<?php echo esc_attr( $secret_setting_input_id ); ?>" style="margin-left:6px;">
	<?php echo esc_html( $secret_setting_status['configured'] ? 'Replace Key' : 'Save Key' ); ?>
</button>
<?php if ( $secret_setting_status['removeAllowed'] ) : ?>
	<button type="button" class="button-link-delete rs-remove-secret"
		data-setting="<?php echo esc_attr( $secret_setting_key ); ?>"
		data-label="<?php echo esc_attr( $secret_setting_label ); ?>" style="margin-left:10px;">Remove Saved Key</button>
<?php endif; ?>
<span class="rs-secret-feedback" style="margin-left:8px;font-size:13px;color:#646970;display:none;"></span>
<p class="description">
	<?php echo esc_html( $secret_setting_status['configured']
		? 'A key is configured. The saved value is never displayed.'
		: 'No key is configured.' ); ?>
</p>
