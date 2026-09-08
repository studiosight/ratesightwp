<?php
/**
 * Client-facing performance overview backed by the dashboard snapshot.
 *
 * @package Ratesight
 */

defined( 'ABSPATH' ) || die;

$snapshot = get_option( Ratesight_Performance_Snapshot::OPTION, null );
$organic = is_array( $snapshot ) && is_array( $snapshot['organic'] ?? null ) ? $snapshot['organic'] : null;
$local = is_array( $snapshot ) && is_array( $snapshot['local'] ?? null ) ? $snapshot['local'] : null;
$rankings = is_array( $snapshot ) && is_array( $snapshot['rankings'] ?? null ) ? $snapshot['rankings'] : null;
$work = is_array( $snapshot ) && is_array( $snapshot['work'] ?? null ) ? $snapshot['work'] : null;
$metrics = is_array( $organic['metrics'] ?? null ) ? $organic['metrics'] : array();
$highlights = is_array( $organic['highlights'] ?? null ) ? $organic['highlights'] : array();

$is_improved = static fn( $metric ): bool => is_array( $metric ) && true === ( $metric['directionEligible'] ?? false ) && 'improved' === ( $metric['direction'] ?? null );
$format_position = static function ( $position ): string {
	$value = (float) $position;
	return '#' . number_format_i18n( $value, floor( $value ) === $value ? 0 : 1 );
};
$format_gain = static fn( $gain ): string => 'Up ' . number_format_i18n( (float) $gain, floor( (float) $gain ) === (float) $gain ? 0 : 1 ) . ' places';
$format_target = static function ( string $target ): string {
	$target = trim( (string) preg_replace( '/^Organic\s+[—-]\s+/u', '', $target ) );
	return preg_match( '/\(([^()]+)\)\s*$/', $target, $matches ) ? trim( $matches[1] ) : $target;
};

$outcome_cards = array();
foreach ( array(
	'clicks' => array( 'Website visits from Google', 'From search results' ),
	'impressions' => array( 'Search appearances', 'Times your business was seen' ),
) as $key => $copy ) {
	$current = $metrics[ $key ]['current'] ?? null;
	if ( null === $current || (float) $current <= 0 ) continue;
	$change = null;
	$percent = $metrics[ $key ]['percentDelta'] ?? null;
	if ( $is_improved( $metrics[ $key ] ?? null ) && null !== $percent && (float) $percent > 0 ) {
		$change = 'Up ' . number_format_i18n( (float) $percent * 100, 0 ) . '%';
	}
	$outcome_cards[] = array( 'label' => $copy[0], 'value' => number_format_i18n( (float) $current, 0 ), 'detail' => $copy[1], 'change' => $change );
}

if ( $local ) {
	foreach ( array(
		'calls' => array( 'Calls', 'From your Business Profile' ),
		'websiteClicks' => array( 'Website visits', 'From your Business Profile' ),
		'directions' => array( 'Direction requests', 'From your Business Profile' ),
	) as $key => $copy ) {
		$metric = is_array( $local['metrics'][ $key ] ?? null ) ? $local['metrics'][ $key ] : array();
		$current = $metric['current'] ?? null;
		if ( true !== ( $metric['complete'] ?? false ) || null === $current || (float) $current <= 0 ) continue;
		$outcome_cards[] = array( 'label' => $copy[0], 'value' => number_format_i18n( (float) $current, 0 ), 'detail' => $copy[1], 'change' => null );
	}
}

$headline = 'Your latest visibility results';
$headline_detail = 'Here are the strongest results from the latest 28-day reporting period.';
if ( $is_improved( $metrics['clicks'] ?? null ) && (float) ( $metrics['clicks']['percentDelta'] ?? 0 ) > 0 ) {
	$headline = 'Google search visits increased ' . number_format_i18n( (float) $metrics['clicks']['percentDelta'] * 100, 0 ) . '%';
	$headline_detail = 'Compared with the previous 28 days.';
} elseif ( $is_improved( $metrics['impressions'] ?? null ) && (float) ( $metrics['impressions']['percentDelta'] ?? 0 ) > 0 ) {
	$headline = 'Your business appeared in search ' . number_format_i18n( (float) $metrics['impressions']['percentDelta'] * 100, 0 ) . '% more often';
	$headline_detail = 'Compared with the previous 28 days.';
} elseif ( $is_improved( $metrics['position'] ?? null ) && (float) ( $metrics['position']['delta'] ?? 0 ) < 0 ) {
	$headline = 'Average search position improved by ' . number_format_i18n( abs( (float) $metrics['position']['delta'] ), 1 ) . ' places';
	$headline_detail = 'Compared with the previous 28 days.';
}

$organic_groups = array(
	'top3' => array( 'Top 3 searches', array_values( array_filter( (array) ( $highlights['wins'] ?? array() ), static fn( array $row ): bool => (float) ( $row['position'] ?? 101 ) <= 3 ) ) ),
	'page_one' => array( 'Page-one searches', array_values( array_filter( (array) ( $highlights['wins'] ?? array() ), static fn( array $row ): bool => (float) ( $row['position'] ?? 101 ) > 3 && (float) ( $row['position'] ?? 101 ) <= 10 ) ) ),
	'close' => array( 'Close to page one', array_values( array_filter( (array) ( $highlights['close'] ?? array() ), static fn( array $row ): bool => (float) ( $row['position'] ?? 101 ) > 10 && (float) ( $row['position'] ?? 101 ) <= 20 ) ) ),
	'improving' => array( 'Biggest movers', (array) ( $highlights['improving'] ?? array() ) ),
);

$seen_searches = array();
foreach ( $organic_groups as $group ) {
	foreach ( $group[1] as $row ) $seen_searches[] = strtolower( trim( (string) ( $row['value'] ?? '' ) ) );
}

$ranking_rows = is_array( $rankings['keywords'] ?? null ) ? array_values( array_filter(
	$rankings['keywords'],
	static fn( array $row ): bool => null !== ( $row['bestRank'] ?? null )
		&& (float) $row['bestRank'] <= 20
		&& 'untrusted' !== ( $row['trust'] ?? '' )
		&& ! in_array( strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) ), $seen_searches, true )
) ) : array();
$ranking_targets = array_values( array_unique( array_filter( array_map( static fn( array $row ): string => trim( (string) ( $row['target'] ?? '' ) ), $ranking_rows ) ) ) );
$ranking_title = 1 === count( $ranking_targets ) ? 'Local rankings in ' . $format_target( $ranking_targets[0] ) : 'Local ranking highlights';

if ( 'Your latest visibility results' === $headline ) {
	$page_one_count = count( (array) ( $highlights['wins'] ?? array() ) ) + count( array_filter( $ranking_rows, static fn( array $row ): bool => (float) $row['bestRank'] <= 10 ) );
	if ( $page_one_count > 0 ) {
		$headline = 'You are appearing on page one for ' . number_format_i18n( $page_one_count ) . ( 1 === $page_one_count ? ' search' : ' searches' );
		$headline_detail = 'These are verified search results from the latest reporting period.';
	}
}

$improved_work = is_array( $work ) ? (int) ( $work['improved'] ?? 0 ) : 0;
$maturing_work = is_array( $work ) ? (int) ( $work['maturing'] ?? 0 ) : 0;
$has_highlights = (bool) array_filter( $organic_groups, static fn( array $group ): bool => ! empty( $group[1] ) );
$has_results = ! empty( $outcome_cards ) || $has_highlights || ! empty( $ranking_rows ) || $improved_work > 0 || $maturing_work > 0;
?>

<style>
.rs-performance-hero { padding:22px!important; background:linear-gradient(135deg,#f0f6ff,#fff)!important; border-color:#c8dcfa!important; }
.rs-performance-hero h2 { margin:0 0 8px!important; font-size:26px!important; line-height:1.2!important; }
.rs-performance-hero p { margin:0!important; color:#50575e!important; font-size:14px!important; }
.rs-outcome-grid { display:grid!important; grid-template-columns:repeat(auto-fit,minmax(180px,1fr))!important; gap:12px!important; }
.rs-outcome-card { border:1px solid #dcdcde!important; border-radius:8px!important; padding:16px!important; background:#fff!important; }
.rs-outcome-label { color:#50575e!important; font-size:13px!important; font-weight:600!important; }
.rs-outcome-value { color:#1d2327!important; font-size:28px!important; font-weight:700!important; line-height:1.15!important; margin-top:6px!important; }
.rs-outcome-detail { color:#646970!important; font-size:12px!important; margin-top:5px!important; }
.rs-positive-badge { display:inline-block!important; margin-top:8px!important; border-radius:999px!important; background:#e7f7ec!important; color:#137333!important; font-size:12px!important; font-weight:700!important; padding:3px 8px!important; }
.rs-win-list { display:grid!important; grid-template-columns:repeat(auto-fit,minmax(230px,1fr))!important; gap:10px!important; margin:10px 0 18px!important; }
.rs-win-item { border:1px solid #dcdcde!important; border-left:4px solid #35a853!important; border-radius:6px!important; background:#fff!important; padding:13px 14px!important; }
.rs-win-term { display:block!important; color:#1d2327!important; font-size:14px!important; font-weight:700!important; }
.rs-win-meta { display:flex!important; flex-wrap:wrap!important; gap:8px!important; align-items:center!important; color:#50575e!important; font-size:12px!important; margin-top:7px!important; }
</style>

<div class="rs-card">
	<div class="rs-card-body rs-performance-hero">
		<h2><?php echo esc_html( $headline ); ?></h2>
		<p><?php echo esc_html( $headline_detail ); ?></p>
	</div>
</div>

<?php if ( ! $snapshot || ! $has_results ) : ?>
	<div class="rs-card" style="margin-top:16px;">
		<div class="rs-card-body">
			<h2>Your results are being prepared</h2>
			<p>Ratesight will add verified wins here as they become available. No action is needed.</p>
		</div>
	</div>
<?php else : ?>
	<?php if ( $outcome_cards ) : ?>
		<h2 class="rs-section">How Customers Found You</h2>
		<div class="rs-outcome-grid">
			<?php foreach ( $outcome_cards as $card ) : ?>
				<div class="rs-outcome-card">
					<div class="rs-outcome-label"><?php echo esc_html( $card['label'] ); ?></div>
					<div class="rs-outcome-value"><?php echo esc_html( $card['value'] ); ?></div>
					<div class="rs-outcome-detail"><?php echo esc_html( $card['detail'] ); ?></div>
					<?php if ( $card['change'] ) : ?><span class="rs-positive-badge"><?php echo esc_html( $card['change'] ); ?></span><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php foreach ( $organic_groups as $group_key => $group ) : if ( ! $group[1] ) continue; ?>
		<h2 class="rs-section"><?php echo esc_html( $group[0] ); ?></h2>
		<div class="rs-win-list">
			<?php foreach ( $group[1] as $row ) : ?>
				<div class="rs-win-item">
					<span class="rs-win-term"><?php echo esc_html( (string) $row['value'] ); ?></span>
					<div class="rs-win-meta">
						<?php if ( 'improving' !== $group_key ) : ?><strong><?php echo esc_html( $format_position( $row['position'] ) ); ?></strong><?php endif; ?>
						<span class="rs-positive-badge" style="margin-top:0!important;"><?php echo esc_html( null !== ( $row['positionGain'] ?? null ) ? $format_gain( $row['positionGain'] ) : ( 'close' === $group_key ? 'Within reach' : 'Strong visibility' ) ); ?></span>
						<?php if ( (float) ( $row['clicks'] ?? 0 ) > 0 ) : ?><span><?php echo esc_html( number_format_i18n( (float) $row['clicks'], 0 ) . ' visits' ); ?></span><?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( $ranking_rows ) : ?>
		<h2 class="rs-section"><?php echo esc_html( $ranking_title ); ?></h2>
		<div class="rs-win-list">
			<?php foreach ( $ranking_rows as $row ) : ?>
				<div class="rs-win-item">
					<span class="rs-win-term"><?php echo esc_html( (string) $row['keyword'] ); ?></span>
					<div class="rs-win-meta">
						<strong><?php echo esc_html( $format_position( $row['bestRank'] ) ); ?></strong>
						<span class="rs-positive-badge" style="margin-top:0!important;"><?php echo esc_html( (float) $row['bestRank'] <= 3 ? 'Top 3' : ( (float) $row['bestRank'] <= 10 ? 'Page one' : 'Close to page one' ) ); ?></span>
						<?php if ( count( $ranking_targets ) > 1 ) : ?><span><?php echo esc_html( $format_target( (string) $row['target'] ) ); ?></span><?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $improved_work > 0 || $maturing_work > 0 ) : ?>
		<h2 class="rs-section">SEO Improvements</h2>
		<div class="rs-outcome-grid">
			<?php if ( $improved_work > 0 ) : ?>
				<div class="rs-outcome-card"><div class="rs-outcome-label">Early gains confirmed</div><div class="rs-outcome-value"><?php echo esc_html( number_format_i18n( $improved_work ) ); ?></div><div class="rs-outcome-detail">Measured improvements moving in the right direction</div></div>
			<?php endif; ?>
			<?php if ( $maturing_work > 0 ) : ?>
				<div class="rs-outcome-card"><div class="rs-outcome-label">Results still developing</div><div class="rs-outcome-value"><?php echo esc_html( number_format_i18n( $maturing_work ) ); ?></div><div class="rs-outcome-detail">Recent improvements gathering enough data</div></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
<?php endif; ?>

<p class="description" style="margin-top:18px;">Results refresh automatically as verified data becomes available.</p>
