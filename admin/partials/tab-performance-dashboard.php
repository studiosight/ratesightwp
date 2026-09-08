<?php
/**
 * Dashboard-owned performance surface.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$ratesight_id = trim( (string) Ratesight_Options::get( 'code_id' ) );
$snapshot = get_option( Ratesight_Performance_Snapshot::OPTION, null );
$organic = is_array( $snapshot ) && is_array( $snapshot['organic'] ?? null ) ? $snapshot['organic'] : null;
$local = is_array( $snapshot ) && is_array( $snapshot['local'] ?? null ) ? $snapshot['local'] : null;
$rankings = is_array( $snapshot ) && is_array( $snapshot['rankings'] ?? null ) ? $snapshot['rankings'] : null;
$work = is_array( $snapshot ) && is_array( $snapshot['work'] ?? null ) ? $snapshot['work'] : null;
$metrics = is_array( $organic['metrics'] ?? null ) ? $organic['metrics'] : array();
$daily = is_array( $organic['daily'] ?? null ) ? array_slice( $organic['daily'], -14 ) : array();
$queries = is_array( $organic['queries'] ?? null ) ? $organic['queries'] : array();
$ranking_rows = is_array( $rankings['keywords'] ?? null ) ? $rankings['keywords'] : array();
$ranking_targets = array();
$all_ranking_scans_unavailable = ! empty( $ranking_rows );
foreach ( $ranking_rows as $ranking_row ) {
	$target = trim( (string) ( $ranking_row['target'] ?? '' ) );
	if ( '' !== $target && ! in_array( $target, $ranking_targets, true ) ) $ranking_targets[] = $target;
	if ( 'untrusted' !== ( $ranking_row['trust'] ?? '' ) ) $all_ranking_scans_unavailable = false;
}
$single_ranking_target = 1 === count( $ranking_targets ) ? preg_replace( '/^Organic\s+[—-]\s+/u', '', $ranking_targets[0] ) : null;
$format_metric = static function ( string $key, $value ): string {
	if ( null === $value ) return 'Unavailable';
	if ( 'ctr' === $key ) return number_format_i18n( (float) $value * 100, 1 ) . '%';
	if ( 'position' === $key ) return number_format_i18n( (float) $value, 1 );
	return number_format_i18n( (float) $value, 0 );
};
$daily_impressions = array_map( static fn( array $row ): float => (float) ( $row['impressions'] ?? 0 ), $daily );
$max_daily = $daily_impressions ? max( 1, ...$daily_impressions ) : 1;
?>
<div class="notice notice-info inline" data-ratesight-owner="dashboard" style="margin:0 0 16px;">
	<p><strong>Performance is managed in the Ratesight Dashboard.</strong> Search Console, Business Profile, and rank results use the provider connections already configured there. You do not need to connect those providers again in WordPress.</p>
</div>

<div class="rs-card" id="rs-dashboard-performance-card">
	<div class="rs-card-body">
		<h2 style="margin-top:0;">Performance overview</h2>
		<h3>Search performance</h3>
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

		<hr style="margin:24px 0;">
		<h3>Business Profile</h3>
		<?php if ( ! $local ) : ?>
			<p><strong>Waiting for Business Profile data.</strong> It will appear after the dashboard sends the next expanded snapshot.</p>
		<?php else : ?>
			<p>Status: <strong><?php echo esc_html( (string) $local['state'] ); ?></strong><?php if ( ! empty( $local['latestMetricDate'] ) ) : ?> &middot; source through <?php echo esc_html( (string) $local['latestMetricDate'] ); ?><?php endif; ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:18px 0;">
				<?php foreach ( array( 'impressions' => 'Profile views', 'calls' => 'Calls', 'directions' => 'Directions', 'websiteClicks' => 'Website clicks' ) as $key => $label ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:5px;padding:14px;background:#fff;">
						<div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $label ); ?></div>
						<div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( $format_metric( $key, $local['metrics'][ $key ]['current'] ?? null ) ); ?></div>
						<?php if ( false === ( $local['metrics'][ $key ]['complete'] ?? false ) ) : ?><div style="font-size:12px;color:#996800;margin-top:4px;">Partial data</div><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<hr style="margin:24px 0;">
		<h3>Tracked rankings</h3>
		<?php if ( ! $rankings ) : ?>
			<p><strong>Waiting for ranking data.</strong> It will appear after the dashboard sends the next expanded snapshot.</p>
		<?php else : ?>
			<p><strong><?php echo esc_html( number_format_i18n( (int) ( $rankings['tracked'] ?? 0 ) ) ); ?> searches tracked.</strong><?php if ( ! empty( $rankings['latestMetricDate'] ) ) : ?> Latest scan: <?php echo esc_html( (string) $rankings['latestMetricDate'] ); ?>.<?php endif; ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:18px 0;">
				<?php foreach ( array( 'tracked' => 'Tracked searches', 'ranking' => 'Ranking', 'top3' => 'Top 3', 'notRanking' => 'Not ranking' ) as $key => $label ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:5px;padding:14px;background:#fff;"><div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $label ); ?></div><div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $rankings[ $key ] ?? 0 ) ) ); ?></div></div>
				<?php endforeach; ?>
			</div>
			<?php if ( $single_ranking_target ) : ?>
				<p class="description"><strong>Tracking area:</strong> <?php echo esc_html( (string) $single_ranking_target ); ?></p>
			<?php endif; ?>
			<?php if ( $all_ranking_scans_unavailable ) : ?>
				<div style="border-left:4px solid #dba617;background:#fcf9e8;padding:12px 16px;margin-top:14px;">
					<strong>Ranking scans are waiting to run.</strong>
					<p style="margin:4px 0 10px;">These searches are tracked. Positions and visibility will appear after the next successful scan.</p>
					<ul style="columns:2;column-gap:28px;margin:0 0 0 18px;">
						<?php foreach ( $ranking_rows as $row ) : ?><li style="break-inside:avoid;margin-bottom:4px;"><?php echo esc_html( (string) $row['keyword'] ); ?></li><?php endforeach; ?>
					</ul>
				</div>
			<?php elseif ( $ranking_rows ) : ?>
				<?php $show_ranking_area = count( $ranking_targets ) > 1; ?>
				<div style="overflow-x:auto;"><table class="widefat striped"><thead><tr><th>Search</th><?php if ( $show_ranking_area ) : ?><th>Tracking area</th><?php endif; ?><th>Best rank</th><th>Visibility</th></tr></thead><tbody>
				<?php foreach ( $ranking_rows as $row ) : $scan_unavailable = 'untrusted' === ( $row['trust'] ?? '' ); ?>
					<tr>
						<td><?php echo esc_html( (string) $row['keyword'] ); ?></td>
						<?php if ( $show_ranking_area ) : ?><td><?php echo esc_html( (string) preg_replace( '/^Organic\s+[—-]\s+/u', '', (string) $row['target'] ) ); ?></td><?php endif; ?>
						<td><?php echo esc_html( $scan_unavailable ? 'Waiting for scan' : ( null === $row['bestRank'] ? 'Not ranking' : number_format_i18n( (float) $row['bestRank'], 0 ) ) ); ?></td>
						<td><?php echo esc_html( $scan_unavailable ? '—' : number_format_i18n( (float) $row['visibilityPct'], 0 ) . '%' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
		<?php endif; ?>

		<hr style="margin:24px 0;">
		<h3>Completed SEO work</h3>
		<?php if ( ! $work ) : ?>
			<p><strong>Waiting for completed-work data.</strong> It will appear after the dashboard sends the next expanded snapshot.</p>
		<?php else : ?>
			<p>Status: <strong><?php echo esc_html( (string) $work['state'] ); ?></strong></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:18px 0;">
				<?php foreach ( array( 'applied' => 'Changes applied', 'measured' => 'Measured', 'improved' => 'Improved', 'maturing' => 'Still measuring' ) as $key => $label ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:5px;padding:14px;background:#fff;"><div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $label ); ?></div><div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $work[ $key ] ?? 0 ) ) ); ?></div></div>
				<?php endforeach; ?>
			</div>
			<?php if ( null !== ( $work['moveRate'] ?? null ) ) : ?><p><strong><?php echo esc_html( number_format_i18n( (float) $work['moveRate'] * 100, 0 ) . '%' ); ?></strong> of measured changes improved.</p><?php endif; ?>
		<?php endif; ?>

		<p>The dashboard remains the source of truth. WordPress stores this bounded display snapshot only.</p>
		<?php if ( $ratesight_id === '' ) : ?>
			<p class="description" style="color:#b32d2e;">Add the Ratesight ID on the Connections tab so this site can receive dashboard performance snapshots.</p>
		<?php else : ?>
			<p class="description">Connected site ID: <?php echo esc_html( $ratesight_id ); ?></p>
		<?php endif; ?>
	</div>
</div>

<p class="description">No Google, Bing, or Business Profile credentials are copied into this WordPress plugin.</p>
