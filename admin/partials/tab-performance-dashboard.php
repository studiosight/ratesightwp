<?php
/**
 * Dashboard-owned performance surface.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$ratesight_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
$dashboard_url = $ratesight_id !== ''
	? 'https://dash.ratesight.com/seo/' . rawurlencode( $ratesight_id ) . '/rank'
	: 'https://dash.ratesight.com/seo';
?>
<div class="notice notice-info inline" data-ratesight-owner="dashboard" style="margin:0 0 16px;">
	<p><strong>Performance is managed in the Ratesight Dashboard.</strong> Search Console, Business Profile, and rank results use the provider connections already configured there. You do not need to connect those providers again in WordPress.</p>
</div>

<div class="rs-card" id="rs-dashboard-performance-card">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">SEO Performance</h2>
		<p>The dashboard is the source of truth for organic search, local visibility, rankings, outcomes, and completed work. WordPress remains the signed publishing and site-execution plane.</p>
		<p><a class="button button-primary" href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener">Open Performance in Ratesight</a></p>
		<?php if ( $ratesight_id === '' ) : ?>
			<p class="description" style="color:#b32d2e;">Add the Ratesight ID on the Connections tab so this link can open the correct site automatically.</p>
		<?php else : ?>
			<p class="description">Connected site ID: <?php echo esc_html( $ratesight_id ); ?></p>
		<?php endif; ?>
	</div>
</div>

<p class="description">No Google, Bing, or Business Profile credentials or performance records are copied into this WordPress plugin.</p>
