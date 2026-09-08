<?php
/**
 * Client support page.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$ratesight_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
?>

<h2 class="rs-section">How Ratesight Helps</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p>Ratesight helps your website attract and convert more local customers.</p>
		<ul style="list-style:disc;padding-left:20px;">
			<li>Publishes and improves search-focused website content.</li>
			<li>Tracks positive search and local visibility results.</li>
			<li>Powers your review and website widgets.</li>
			<li>Maintains supporting technical SEO features.</li>
		</ul>
	</div>
</div>

<h2 class="rs-section">Connection</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<?php if ( $ratesight_id !== '' ) : ?>
			<p><strong style="color:#00a32a;">Ratesight is connected</strong></p>
			<p class="description">Your services are managed for you. There is nothing you need to connect in WordPress.</p>
		<?php else : ?>
			<p><strong>Setup in progress</strong></p>
			<p class="description">Your Ratesight team can finish connecting this site. No action is needed here.</p>
		<?php endif; ?>
	</div>
</div>

<h2 class="rs-section">Need Help?</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p>Contact your account team or email <a href="mailto:support@ratesight.com">support@ratesight.com</a>.</p>
		<p class="description">Installed plugin version: <?php echo esc_html( RATESIGHT_RELEASE_VERSION ); ?></p>
	</div>
</div>
