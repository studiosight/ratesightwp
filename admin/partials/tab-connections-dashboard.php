<?php
/**
 * Dashboard-owned provider connection surface.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$ratesight_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
$dashboard_url = $ratesight_id !== ''
	? 'https://dash.ratesight.com/seo/' . rawurlencode( $ratesight_id ) . '/setup'
	: 'https://dash.ratesight.com/seo';
$installation = Ratesight_Installation::status( plugin_basename( RATESIGHT_PLUGIN_DIR . 'ratesight.php' ) );
$auth_mode = Ratesight_Request_Auth::mode();
$signed_secret_configured = trim( (string) get_option( 'ratesight_webhook_secret', '' ) ) !== '';
$readiness = get_option( 'ratesight_auth_v2_readiness', array() );
$readiness_at = is_array( $readiness ) ? (int) ( $readiness['completed_at'] ?? 0 ) : 0;
$readiness_current = $readiness_at > 0 && $readiness_at >= time() - Ratesight_Request_Auth::READINESS_TTL;
$legacy = array(
	'Google Search Console' => Ratesight_OAuth_Client::is_connected( 'gsc' ),
	'Google Business Profile' => Ratesight_OAuth_Client::is_connected( 'gbp' ),
	'Bing Webmaster Tools' => Ratesight_Bing_Client::is_connected(),
);
$legacy_count = count( array_filter( $legacy ) );
?>
<div class="notice notice-info inline" data-ratesight-owner="dashboard" style="margin:0 0 16px;">
	<p><strong>Provider connections are managed in the RateSight Dashboard.</strong> Connect Google Search Console and Business Profile, review Bing coverage, and choose the site destination there. Provider credentials are not entered in WordPress.</p>
</div>

<div class="rs-card" id="rs-dashboard-connection-card">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">RateSight App Connection</h2>
		<table class="form-table" role="presentation">
			<tr><th>Plugin</th><td><strong><?php echo esc_html( RATESIGHT_RELEASE_VERSION ); ?></strong> &middot; <?php echo $installation['active'] ? '<span style="color:#00a32a;">Active</span>' : '<span style="color:#b32d2e;">Inactive installation</span>'; ?></td></tr>
			<tr><th>RateSight ID</th><td><?php echo $ratesight_id !== '' ? esc_html( $ratesight_id ) : '<span style="color:#b32d2e;">Not configured</span>'; ?></td></tr>
			<tr><th>Signed API</th><td><?php echo $signed_secret_configured ? '<span style="color:#00a32a;">Configured</span>' : '<span style="color:#b32d2e;">Not configured</span>'; ?> &middot; mode <code><?php echo esc_html( $auth_mode ); ?></code><?php echo $readiness_current ? ' &middot; readiness current' : ''; ?></td></tr>
			<tr><th>Provider ownership</th><td>Dashboard only</td></tr>
		</table>
		<p><a class="button button-primary" href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener">Open Dashboard Connections</a></p>
	</div>
</div>

<h2 class="rs-section">Legacy Provider State</h2>
<div class="rs-card">
	<div class="rs-card-body">
		<p><strong><?php echo esc_html( (string) $legacy_count ); ?></strong> legacy provider connection<?php echo $legacy_count === 1 ? '' : 's'; ?> remain stored for rollback compatibility. They cannot be created, changed, or disconnected from this plugin.</p>
		<ul style="list-style:disc;padding-left:20px;">
			<?php foreach ( $legacy as $label => $configured ) : ?>
				<li><?php echo esc_html( $label ); ?>: <?php echo $configured ? 'legacy state retained' : 'not configured locally'; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="description">No credential values, account identities, properties, or locations are shown here. Existing state is retained until a separately verified fleet cleanup.</p>
	</div>
</div>

<p class="description">IndexNow, signed publication events, installation diagnostics, and WordPress-local controls remain below. Provider OAuth, API keys, destination mapping, approvals, retries, and delivery history are dashboard-owned.</p>
