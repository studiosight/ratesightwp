<?php
/**
 * Widgets tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

settings_errors();
$o = Ratesight_Options::get_all();
?>
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_widgets' ); ?>

<h2 class="rs-section">Connection</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="rs-code-id">Ratesight ID</label></th>
		<td>
			<input type="text" id="rs-code-id" name="wp_ratesight_code_id" class="regular-text" value="<?php echo esc_attr( $o['code_id'] ); ?>">
			<p class="description">Your unique Ratesight account identifier (OID).</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-campaign-id">Campaign ID</label></th>
		<td>
			<input type="text" id="rs-campaign-id" name="wp_ratesight_campaign_id" class="regular-text" value="<?php echo esc_attr( $o['campaign_id'] ); ?>">
			<p class="description">Optional — appended to widget requests as <code>&amp;cid=</code></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-domain-id">Domain ID</label></th>
		<td>
			<input type="text" id="rs-domain-id" name="wp_ratesight_domain_id" class="regular-text" value="<?php echo esc_attr( $o['domain_id'] ); ?>">
			<p class="description">Optional — appended as <code>&amp;DID=</code></p>
		</td>
	</tr>
	<tr>
		<th scope="row">Reviews Page</th>
		<td>
			<?php wp_dropdown_pages( array(
				'name'             => 'wp_ratesight_rv_page',
				'selected'         => (int) $o['review_page'], // phpcs:ignore WordPress.Security.EscapeOutput
				'show_option_none' => '— Select a page —',
			) ); ?>
			<p class="description">Used for the "See All Reviews" link in <code>[rs_leave_reviews]</code></p>
		</td>
	</tr>
</table>
</div>
</div>

<h2 class="rs-section">Shortcodes</h2>
<div class="rs-card">
<div class="rs-card-body">
	<div class="rs-sc-row">
		<span class="rs-sc-name">Leave a Review</span>
		<span class="rs-sc-code">[rs_leave_reviews]</span>
		<button type="button" class="button rs-btn-copy" data-copy="[rs_leave_reviews]">Copy</button>
	</div>
	<div class="rs-sc-row">
		<span class="rs-sc-name">All Reviews</span>
		<span class="rs-sc-code">[rs_all_reviews]</span>
		<button type="button" class="button rs-btn-copy" data-copy="[rs_all_reviews]">Copy</button>
	</div>
	<div class="rs-sc-row">
		<span class="rs-sc-name">Jobs</span>
		<span class="rs-sc-code">[rs_jobs]</span>
		<button type="button" class="button rs-btn-copy" data-copy="[rs_jobs]">Copy</button>
	</div>
	<p class="description" style="margin-top:10px;">Paste into any page or post.</p>
</div>
</div>

<h2 class="rs-section">Appearance</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Stars Color</th>
		<td>
			<div class="rs-color-row">
				<input type="color" name="wp_ratesight_stars_clr" value="<?php echo esc_attr( $o['stars_clr'] ); ?>">
				<span class="description">Color of the ★★★★★ in the leave-a-review widget</span>
			</div>
		</td>
	</tr>
	<tr>
		<th scope="row">Dark Text</th>
		<td>
			<label>
				<input type="checkbox" name="wp_ratesight_dark_text" value="1" <?php checked( 1, $o['dark_text'] ); ?>>
				Enable dark text on review widgets
			</label>
			<div class="rs-color-row">
				<input type="color" name="wp_ratesight_dark_clr" value="<?php echo esc_attr( $o['dark_text_color'] ); ?>">
				<span class="description">Dark text color</span>
			</div>
		</td>
	</tr>
</table>
</div>
</div>

<div class="rs-submit"><?php submit_button( 'Save Widget Settings' ); ?></div>
</form>
