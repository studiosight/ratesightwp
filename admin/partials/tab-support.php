<?php
/**
 * Client support page.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$connected = Ratesight_Pairing::is_connected();
?>

<div class="rs-widget-intro">
	<h2>Support</h2>
	<p>See what Ratesight manages and how to reach your account team.</p>
</div>

<h2 class="rs-section">How Ratesight Helps</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p>Ratesight helps your website attract and convert more local customers.</p>
		<ul style="list-style:disc;padding-left:20px;">
			<li>Publishes and improves search-focused website content.</li>
			<li>Tracks positive search and local visibility results.</li>
			<li>Powers your review and website widgets.</li>
			<li>Keeps search-supporting site features maintained.</li>
		</ul>
	</div>
</div>

<h2 class="rs-section">Connection</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<?php if ( $connected ) : ?>
			<p><strong style="color:#137333;">Ratesight is connected</strong></p>
			<p class="description">Your services are managed for you. There is nothing you need to connect in WordPress.</p>
		<?php else : ?>
			<p><strong>Setup in progress</strong></p>
			<p class="description">Your Ratesight team can finish connecting this site. No action is needed here.</p>
		<?php endif; ?>
	</div>
</div>

<h2 class="rs-section">Need help?</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p>Contact your account team or email <a href="mailto:support@ratesight.com">support@ratesight.com</a>.</p>
		<p class="description">Include your website address so we can help quickly.</p>
	</div>
</div>
