<?php
/**
 * Dashboard-owned provider connection surface.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$installation = Ratesight_Installation::status( plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' ) );
$connected = $installation['active'] && Ratesight_Pairing::is_connected();
$publication_events = Ratesight_Publication_Events::status();
?>
<div class="rs-card" id="rs-dashboard-connection-card" data-ratesight-owner="dashboard">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">Ratesight connection</h2>
		<?php if ( $connected ) : ?>
			<p><strong style="color:#137333;">Connected</strong></p>
			<p>Ratesight is managing this site's reporting and SEO services. There is nothing you need to connect in WordPress.</p>
			<p><strong>New blog posts:</strong> <?php echo $publication_events['pending'] > 0 ? esc_html( number_format_i18n( $publication_events['pending'] ) . ' waiting to sync' ) : 'Synced'; ?></p>
		<?php else : ?>
			<p><strong>Setup in progress</strong></p>
			<p>Your Ratesight team can finish connecting this site. No action is needed here.</p>
		<?php endif; ?>
	</div>
</div>
