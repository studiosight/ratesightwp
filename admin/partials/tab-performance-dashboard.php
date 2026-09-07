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
$snapshot = get_option( Ratesight_Performance_Snapshot::OPTION, null );
$organic = is_array( $snapshot ) && is_array( $snapshot['organic'] ?? null ) ? $snapshot['organic'] : null;
$metrics = is_array( $organic['metrics'] ?? null ) ? $organic['metrics'] : array();
$daily = is_array( $organic['daily'] ?? null ) ? array_slice( $organic['daily'], -14 ) : array();
$queries = is_array( $organic['queries'] ?? null ) ? $organic['queries'] : array();
$format_metric = static function ( string $key, $value ): string {
	if ( null === $value ) return 'Unavailable';
	if ( 'ctr' === $key ) return number_format_i18n( (float) $value * 100, 1 ) . '%';
	if ( 'position' === $key ) return number_format_i18n( (float) $value, 1 );
	return number_format_i18n( (float) $value, 0 );
};
$max_daily = max( 1, ...array_map( static fn( array $row ): float => (float) ( $row['impressions'] ?? 0 ), $daily ) );
?>
<div class="notice notice-info inline" data-ratesight-owner="dashboard" style="margin:0 0 16px;">
	<p><strong>Performance is managed in the Ratesight Dashboard.</strong> Search Console, Business Profile, and rank results use the provider connections already configured there. You do not need to connect those providers again in WordPress.</p>
</div>

<div class="rs-card" id="rs-dashboard-performance-card">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">SEO Performance</h2>
		<?php if ( ! $organic ) : ?>
			<p><strong>Waiting for the first dashboard snapshot.</strong> The next dashboard Search Console refresh will send performance here automatically.</p>
		<?php else : ?>
			<p>Last dashboard snapshot: <strong><?php echo esc_html( (string) $snapshot['generatedAt'] ); ?></strong> &middot; source through <?php echo esc_html( (string) ( $organic['latestMetricDate'] ?? 'unavailable' ) ); ?> &middot; <?php echo esc_html( (string) $organic['state'] ); ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:18px 0;">
				<?php foreach ( array( 'clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR', 'position' => 'Average position' ) as $key => $label ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:5px;padding:14px;background:#fff;">
						<div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $label ); ?></div>
						<div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( $format_metric( $key, $metrics[ $key ]['current'] ?? null ) ); ?></div>
						<?php if ( true === ( $metrics[ $key ]['directionEligible'] ?? false ) ) : ?>
							<div style="font-size:12px;color:#646970;margin-top:4px;">vs previous 28 days: <?php echo esc_html( (string) ( $metrics[ $key ]['direction'] ?? 'flat' ) ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $daily ) : ?>
				<h3>Recent impressions</h3>
				<div style="height:110px;display:flex;align-items:flex-end;gap:4px;border-bottom:1px solid #dcdcde;padding-top:8px;margin-bottom:18px;" aria-label="Recent daily Search Console impressions">
					<?php foreach ( $daily as $row ) : $height = max( 2, (int) round( ( (float) ( $row['impressions'] ?? 0 ) / $max_daily ) * 100 ) ); ?>
						<div title="<?php echo esc_attr( (string) $row['date'] . ': ' . number_format_i18n( (float) $row['impressions'], 0 ) . ' impressions' ); ?>" style="height:<?php echo esc_attr( (string) $height ); ?>%;background:#1877f2;flex:1;min-width:4px;border-radius:2px 2px 0 0;"></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $queries ) : ?>
				<h3>Top searches</h3>
				<div style="overflow-x:auto;">
					<table class="widefat striped">
						<thead><tr><th>Search</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Position</th></tr></thead>
						<tbody>
						<?php foreach ( $queries as $query ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $query['value'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $query['impressions'], 0 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $query['clicks'], 0 ) ); ?></td>
								<td><?php echo esc_html( $format_metric( 'ctr', $query['ctr'] ?? null ) ); ?></td>
								<td><?php echo esc_html( $format_metric( 'position', $query['position'] ?? null ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<p>The dashboard remains the source of truth for organic search, local visibility, rankings, outcomes, and completed work. WordPress stores this bounded display snapshot only.</p>
		<p><a class="button button-primary" href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener">Open Performance in Ratesight</a></p>
		<?php if ( $ratesight_id === '' ) : ?>
			<p class="description" style="color:#b32d2e;">Add the Ratesight ID on the Connections tab so this link can open the correct site automatically.</p>
		<?php else : ?>
			<p class="description">Connected site ID: <?php echo esc_html( $ratesight_id ); ?></p>
		<?php endif; ?>
	</div>
</div>

<p class="description">No Google, Bing, or Business Profile credentials are copied into this WordPress plugin.</p>
