<?php
/**
 * Client-facing review widget settings.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

settings_errors();
$options = Ratesight_Options::get_all();
$preview_text_color = $options['dark_text'] ? $options['dark_text_color'] : '#3c434a';
?>
<div class="rs-widget-intro">
	<h2>Reviews &amp; Widgets</h2>
	<p>Choose where visitors see your reviews and match the widget to your website.</p>
</div>

<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_widgets' ); ?>

<div class="rs-widget-layout">
	<div>
		<h2 class="rs-section">Where Reviews Lead</h2>
		<div class="rs-card">
			<div class="rs-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wp-ratesight-review-page">Reviews page</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'id'               => 'wp-ratesight-review-page',
								'name'             => 'wp_ratesight_rv_page',
								'selected'         => (int) $options['review_page'], // phpcs:ignore WordPress.Security.EscapeOutput
								'show_option_none' => '— Select a page —',
							) );
							?>
							<p class="description">The page visitors open when they choose to see all reviews.</p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<h2 class="rs-section">Appearance</h2>
		<div class="rs-card">
			<div class="rs-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rs-star-color">Star color</label></th>
						<td>
							<div class="rs-color-row">
								<input type="color" id="rs-star-color" name="wp_ratesight_stars_clr" value="<?php echo esc_attr( $options['stars_clr'] ); ?>">
								<span class="description">Used for the five review stars.</span>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">Review text</th>
						<td>
							<label for="rs-custom-text-color">
								<input type="checkbox" id="rs-custom-text-color" name="wp_ratesight_dark_text" value="1" <?php checked( 1, $options['dark_text'] ); ?>>
								Use a custom text color
							</label>
							<div class="rs-color-row">
								<input type="color" id="rs-review-text-color" name="wp_ratesight_dark_clr" value="<?php echo esc_attr( $options['dark_text_color'] ); ?>">
								<span class="description">Choose a readable color for review text.</span>
							</div>
						</td>
					</tr>
				</table>
			</div>
		</div>
	</div>

	<div>
		<h2 class="rs-section">Preview</h2>
		<div class="rs-widget-preview" id="rs-widget-preview" style="--rs-preview-stars:<?php echo esc_attr( $options['stars_clr'] ); ?>;--rs-preview-text:<?php echo esc_attr( $preview_text_color ); ?>;">
			<div class="rs-widget-preview-stars" aria-label="Five stars">★★★★★</div>
			<blockquote>“The team made everything easy and the results were excellent.”</blockquote>
			<p>— A happy customer</p>
			<span class="rs-widget-preview-link">See all reviews</span>
		</div>
		<p class="description rs-preview-note">This preview shows the colors visitors will see. Your live reviews remain unchanged.</p>
	</div>
</div>

<h2 class="rs-section">Add Reviews to a Page</h2>
<div class="rs-card">
	<div class="rs-card-body rs-shortcode-list">
		<div class="rs-sc-row">
			<span class="rs-sc-name">Review invitation</span>
			<span class="rs-sc-code">[rs_leave_reviews]</span>
			<button type="button" class="button rs-btn-copy" data-copy="[rs_leave_reviews]">Copy</button>
		</div>
		<p class="description">Adds five stars, a review invitation, and recent customer reviews.</p>
		<div class="rs-sc-row">
			<span class="rs-sc-name">All reviews</span>
			<span class="rs-sc-code">[rs_all_reviews]</span>
			<button type="button" class="button rs-btn-copy" data-copy="[rs_all_reviews]">Copy</button>
		</div>
		<p class="description">Adds the complete reviews experience to a dedicated page.</p>
	</div>
</div>

<div class="rs-submit"><?php submit_button( 'Save Review Widget' ); ?></div>
</form>
