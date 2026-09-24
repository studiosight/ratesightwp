<?php
/** Read-only connection truth for the Publishing tab; an OID alone proves nothing. */
defined( 'ABSPATH' ) || die;

$installation = Ratesight_Installation::status( plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' ) );
$pairing = Ratesight_Pairing::status();
$auth = Ratesight_Request_Auth::capability_auth();
$enrollment = Ratesight_Enrollment::status();
$connected = ! empty( $installation['active'] ) && Ratesight_Pairing::is_connected();
$blocked = ! empty( $enrollment['blocked'] );
$needs_repair = ! empty( $pairing['pairedAt'] ) || in_array( $enrollment['outcome'] ?? '', array( 'retry_exhausted', 'expired' ), true );
?>
<div data-ratesight-connection="<?php echo esc_attr( $blocked ? 'blocked' : ( $connected ? 'connected' : ( $needs_repair ? 'needs-repair' : 'pending' ) ) ); ?>">
<?php if ( $blocked ) : ?>
	<strong>Blocked</strong>
	<p class="description">Enrollment is blocked. Your Ratesight team must review and explicitly reconnect this installation in the dashboard.</p>
<?php elseif ( $connected ) : ?>
	<strong style="color:#00a32a;">Connected</strong>
	<p class="description">Dashboard pairing and signed authentication are configured. This does not verify an actual CRM blog delivery.</p>
	<?php if ( empty( $auth['readiness_current'] ) ) : ?>
		<p class="description">Publishing readiness needs a fresh check. The connection remains paired; successful publishing is not yet confirmed.</p>
	<?php else : ?>
		<p class="description">Signed readiness check is current. Each publishing delivery still requires its own result and verification.</p>
	<?php endif; ?>
<?php elseif ( $needs_repair ) : ?>
	<strong>Needs repair</strong>
	<p class="description">A saved pairing or enrollment needs attention, but active signed authentication is not confirmed. Your Ratesight team can review it in the dashboard.</p>
<?php else : ?>
	<strong><?php echo ! empty( $enrollment['accepted'] ) ? 'Pending approval' : 'Setup in progress'; ?></strong>
	<p class="description">This installation is not yet connected with signed dashboard authentication. Your Ratesight team can review enrollment and finish pairing in the dashboard.</p>
<?php endif; ?>
</div>
<p class="description">No secret or connection settings need to be entered here.</p>
