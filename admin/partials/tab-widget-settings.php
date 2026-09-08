<?php
/**
 * Direct support route for legacy widget identity recovery.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

settings_errors();
$options = Ratesight_Options::get_all();
?>
<h2 class="rs-section">Support-Only Widget Identity</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p>These identifiers support legacy review widgets. Change them only when directed by Ratesight support.</p>
		<p class="description">Installed plugin version: <?php echo esc_html( RATESIGHT_RELEASE_VERSION ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'ratesight_options_widget_identity' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rs-code-id">Ratesight ID</label></th>
					<td><input type="text" id="rs-code-id" name="wp_ratesight_code_id" class="regular-text" value="<?php echo esc_attr( $options['code_id'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rs-campaign-id">Campaign ID</label></th>
					<td><input type="text" id="rs-campaign-id" name="wp_ratesight_campaign_id" class="regular-text" value="<?php echo esc_attr( $options['campaign_id'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rs-domain-id">Domain ID</label></th>
					<td><input type="text" id="rs-domain-id" name="wp_ratesight_domain_id" class="regular-text" value="<?php echo esc_attr( $options['domain_id'] ); ?>"></td>
				</tr>
			</table>
			<div class="rs-submit"><?php submit_button( 'Save Widget Identity' ); ?></div>
		</form>
	</div>
</div>
