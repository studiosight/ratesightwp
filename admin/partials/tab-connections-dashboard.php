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
$rs_notice = Ratesight_Pairing::notice();
$rs_recovery = Ratesight_Pairing::status()['recovery'] ?? false;
?>
<div class="rs-card" id="rs-dashboard-connection-card" data-ratesight-owner="dashboard">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">Ratesight connection</h2>
		<?php if ( $connected ) : ?>
			<p><strong style="color:#137333;">Connected</strong></p>
			<p>Ratesight is managing this site's reporting and SEO services. There is nothing you need to connect in WordPress.</p>
			<p><strong>New blog posts:</strong> <?php echo $publication_events['pending'] > 0 ? esc_html( number_format_i18n( $publication_events['pending'] ) . ' waiting to sync' ) : 'Synced'; ?></p>
		<?php else : ?>
			<p><strong data-rs-connection-state="<?php echo esc_attr( $rs_notice['state'] ); ?>"><?php echo esc_html( 'paired' === $rs_notice['state'] ? 'Setup in progress' : $rs_notice['heading'] ); ?></strong></p>
			<p><?php echo esc_html( 'paired' === $rs_notice['state'] ? 'Your Ratesight team can finish connecting this site. No action is needed here.' : $rs_notice['line'] ); ?></p>
		<?php endif; ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<details id="rs-pairing-recovery" style="margin-top:14px;">
			<summary style="cursor:pointer;">Recovery and reset <span style="color:#646970;">(administrators only)</span></summary>
			<div style="margin-top:10px;max-width:640px;">
				<?php if ( $rs_recovery ) : ?>
					<p class="description">A recovery secret was generated here and the dashboard has not paired yet. Paste it into the dashboard's webhook secret field for this site.</p>
				<?php endif; ?>
				<h3 style="margin:10px 0 4px;">Generate a recovery secret</h3>
				<p class="description">Makes a new secret here to paste into the Ratesight dashboard. The current secret keeps working for 7 days. The new secret is shown once.</p>
				<p>
					<label for="rs-recovery-generate-word">Type <code>GENERATE</code> to confirm</label>
					<input type="text" id="rs-recovery-generate-word" autocomplete="off" style="width:140px;">
					<button type="button" class="button" id="rs-recovery-generate">Generate recovery secret</button>
				</p>
				<h3 style="margin:14px 0 4px;">Disconnect / reset pairing</h3>
				<p class="description">Clears this site's Ratesight secrets and pairing record. Ratesight cannot act on this site until it is paired again from the dashboard. Your Ratesight ID, Google connections and post settings are not touched.</p>
				<p>
					<label for="rs-recovery-reset-word">Type <code>RESET</code> to confirm</label>
					<input type="text" id="rs-recovery-reset-word" autocomplete="off" style="width:140px;">
					<button type="button" class="button" id="rs-recovery-reset">Disconnect / reset pairing</button>
				</p>
				<div id="rs-recovery-result" role="status" aria-live="polite"></div>
			</div>
		</details>
		<?php endif; ?>
	</div>
</div>
