<?php
/**
 * Dashboard-owned provider connection surface.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$ratesight_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
$installation = Ratesight_Installation::status( plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' ) );
$connected = $ratesight_id !== '' && $installation['active'];
?>
<div class="rs-card" id="rs-dashboard-connection-card" data-ratesight-owner="dashboard">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">Ratesight connection</h2>
		<?php if ( $connected ) : ?>
			<p><strong style="color:#00a32a;">Connected</strong></p>
			<p>Ratesight is managing this site's reporting and SEO services. There is nothing you need to connect in WordPress.</p>
		<?php else : ?>
			<p><strong>Setup in progress</strong></p>
			<p>Your Ratesight team can finish connecting this site. No action is needed here.</p>
		<?php endif; ?>
	</div>
</div>
