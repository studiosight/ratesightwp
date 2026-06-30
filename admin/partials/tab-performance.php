<?php
/**
 * Admin partial: Performance Hub.
 *
 * Overview scorecard → Local tab (GBP) → Organic tab (GSC + rank tracking).
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


$gsc_locked     = Ratesight_GSC_Client::is_locked();
$gbp_locked     = Ratesight_GBP_Client::is_locked();
$last_sync      = get_option( 'ratesight_gsc_last_sync', null );
$gsc_selection  = Ratesight_GSC_Client::get_selection();
$gbp_selection  = Ratesight_GBP_Client::get_selection();

// ── Active sub-tab ─────────────────────────────────────────────────────────
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$perf_tab = sanitize_key( $_GET['ptab'] ?? 'overview' );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $perf_tab, array( 'overview', 'organic', 'local', 'bing' ), true ) ) {
	$perf_tab = 'overview';
}

$base_url = admin_url( 'admin.php?page=ratesight&tab=performance' );
?>

<style>
/* Performance Hub sub-tabs */
.rs-perf-tabs { display:flex; gap:0; border-bottom:2px solid #dcdcde; margin-bottom:20px; }
.rs-perf-tab { display:inline-block; padding:9px 18px; font-size:13px; font-weight:500; color:#646970; text-decoration:none!important; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .12s,border-color .12s; }
.rs-perf-tab:hover { color:#1d2327; }
.rs-perf-tab.active { color:#1877F2; border-bottom-color:#1877F2; }

/* Scorecard */
.rs-scorecard { display:flex; flex-direction:column; gap:16px; margin-bottom:20px; }
.rs-scorecard-group { }
.rs-scorecard-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#787c82; margin-bottom:6px; }
.rs-scorecard-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.rs-score-card { background:#fff; border:1px solid #dcdcde; border-radius:5px; padding:16px 18px; }
.rs-score-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#787c82; margin-bottom:6px; }
.rs-score-value { font-size:26px; font-weight:700; color:#1d2327; line-height:1; }
.rs-score-delta { font-size:12px; margin-top:5px; }
.rs-delta-up   { color:#00a32a; }
.rs-delta-down { color:#d63638; }
.rs-delta-flat { color:#787c82; }

/* Rank distribution bar */
.rs-rank-bar { display:flex; height:8px; border-radius:4px; overflow:hidden; margin-top:8px; }
.rs-rank-bar-top3  { background:#00a32a; }
.rs-rank-bar-top10 { background:#1877F2; }
.rs-rank-bar-rest  { background:#dcdcde; }

/* Rankings table extras */
.rs-kw-row { background:#f6f7f7; }
.rs-kw-table { width:100%; border-collapse:collapse; font-size:12px; }
.rs-kw-table td { padding:6px 10px; border-bottom:1px solid #f0f0f1; color:#1d2327; }
.rs-kw-table tr:last-child td { border-bottom:none; }

/* Trend arrows */
.rs-up   { color:#00a32a; font-weight:600; }
.rs-down { color:#d63638; font-weight:600; }
.rs-flat { color:#787c82; }

/* Health checks */
.rs-health-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.rs-health-item { display:flex; align-items:flex-start; gap:8px; padding:10px 12px; background:#f6f7f7; border-radius:4px; font-size:13px; }
.rs-health-ok   { color:#00a32a; font-size:16px; flex-shrink:0; }
.rs-health-warn { color:#7a5800; font-size:16px; flex-shrink:0; }
.rs-health-fail { color:#d63638; font-size:16px; flex-shrink:0; }
.rs-health-detail { font-size:12px; color:#787c82; margin-top:2px; }

/* Review cards */
.rs-review-card { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:14px 16px; margin-bottom:10px; }
.rs-review-meta { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.rs-review-author { font-weight:600; font-size:13px; }
.rs-review-date { font-size:12px; color:#787c82; }
.rs-review-stars { color:#f5a623; letter-spacing:1px; }
.rs-review-body { font-size:13px; color:#1d2327; line-height:1.5; margin-bottom:10px; }
.rs-reply-box { background:#f6f7f7; border-radius:4px; padding:10px; margin-top:8px; font-size:12px; color:#646970; }
.rs-reply-draft { width:100%; min-height:70px; font-size:13px; border:1px solid #dcdcde; border-radius:4px; padding:8px; margin-top:8px; resize:vertical; }

/* AI chat */
.rs-chat-wrap { background:#fff; border:1px solid #dcdcde; border-radius:5px; overflow:hidden; margin-top:16px; }
.rs-chat-head { padding:12px 18px; border-bottom:1px solid #f0f0f1; background:#fafafa; }
.rs-chat-head h3 { font-size:11px!important; font-weight:700!important; text-transform:uppercase; letter-spacing:.07em; color:#787c82!important; margin:0!important; padding:0!important; }
.rs-chat-messages { min-height:60px; max-height:320px; overflow-y:auto; padding:16px 18px; display:flex; flex-direction:column; gap:10px; }
.rs-msg { max-width:85%; padding:10px 14px; border-radius:8px; font-size:13px; line-height:1.55; }
.rs-msg-user { background:#1877F2; color:#fff; align-self:flex-end; border-radius:8px 8px 2px 8px; }
.rs-msg-ai   { background:#f0f0f1; color:#1d2327; align-self:flex-start; border-radius:8px 8px 8px 2px; }
.rs-chat-input-row { display:flex; gap:8px; padding:12px 18px; border-top:1px solid #f0f0f1; background:#fafafa; }
.rs-chat-input-row textarea { flex:1; min-height:38px; max-height:120px; resize:vertical; font-size:13px; border:1px solid #dcdcde; border-radius:4px; padding:8px 10px; }
.rs-suggested-prompts { padding:0 18px 12px; display:flex; flex-wrap:wrap; gap:6px; }
.rs-prompt-chip { font-size:11px; padding:4px 10px; border:1px solid #dcdcde; border-radius:20px; background:#fff; cursor:pointer; color:#646970; }
.rs-prompt-chip:hover { background:#f0f0f1; color:#1d2327; }

@media (max-width:700px) {
	.rs-scorecard { grid-template-columns:1fr 1fr; }
	.rs-health-grid { grid-template-columns:1fr; }
}
</style>

<!-- Sub-tabs -->
<div class="rs-perf-tabs">
	<a href="<?php echo esc_url( $base_url . '&ptab=overview' ); ?>" class="rs-perf-tab <?php echo $perf_tab === 'overview' ? 'active' : ''; ?>">Overview</a>
	<a href="<?php echo esc_url( $base_url . '&ptab=organic' ); ?>"  class="rs-perf-tab <?php echo $perf_tab === 'organic'  ? 'active' : ''; ?>">Organic (Google Search Console)</a>
	<a href="<?php echo esc_url( $base_url . '&ptab=local' ); ?>"    class="rs-perf-tab <?php echo $perf_tab === 'local'    ? 'active' : ''; ?>">Local (GBP)</a>
	<a href="<?php echo esc_url( $base_url . '&ptab=bing' ); ?>"     class="rs-perf-tab <?php echo $perf_tab === 'bing'     ? 'active' : ''; ?>">Organic (Bing)</a>
</div>

<?php if ( $perf_tab === 'overview' ) : ?>

	<?php
	$gsc_stats       = $gsc_locked ? Ratesight_GSC_Client::get_overview_stats() : array();
	$gbp_stats       = $gbp_locked ? Ratesight_GBP_Insights_Client::get_overview_stats() : array();
	$gbp_ever_synced = (bool) get_option( 'ratesight_gbp_performance_last_sync', false );

	// Bing overview — sum last 28 days from the performance table.
	$bing_stats      = array();
	if ( Ratesight_Bing_Client::is_connected() && Ratesight_Bing_Client::is_locked() ) {
		global $wpdb;
		$bing_perf_tbl = $wpdb->prefix . 'ratesight_bing_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$bing_row = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(position) AS position
			 FROM `{$bing_perf_tbl}`
			 WHERE date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			28
		), ARRAY_A );
		if ( $bing_row ) {
			$bing_stats = array(
				'impressions' => (int) ( $bing_row['impressions'] ?? 0 ),
				'clicks'      => (int) ( $bing_row['clicks'] ?? 0 ),
				'position'    => round( (float) ( $bing_row['position'] ?? 0 ), 1 ),
			);
		}
	}

	// If no stored RS post data, fall back to the live site overview (same data
	// the Organic (Google Search Console) tab shows — queries GSC API directly, cached 24h).
	$using_site_overview = false;
	if ( $gsc_locked && empty( $gsc_stats['total_impressions'] ) ) {
		$site_overview = Ratesight_GSC_Client::get_site_overview();
		if ( ! is_wp_error( $site_overview ) && ! empty( $site_overview['total_impressions'] ) ) {
			$gsc_stats = array(
				'total_impressions' => $site_overview['total_impressions'],
				'total_clicks'      => $site_overview['total_clicks'],
				'avg_position'      => $site_overview['avg_position'],
				'page_count'        => $site_overview['page_count'],
				'prev_impressions'  => 0,
				'prev_clicks'       => 0,
			);
			$using_site_overview = true;
		}
	}

	$imp_now   = (int) ( $gsc_stats['total_impressions'] ?? 0 );
	$imp_prev  = (int) ( $gsc_stats['prev_impressions']  ?? 0 );
	$imp_delta = $imp_prev > 0 ? round( ( ( $imp_now - $imp_prev ) / $imp_prev ) * 100 ) : null;

	$clk_now   = (int) ( $gsc_stats['total_clicks'] ?? 0 );
	$clk_prev  = (int) ( $gsc_stats['prev_clicks']   ?? 0 );
	$clk_delta = $clk_prev > 0 ? round( ( ( $clk_now - $clk_prev ) / $clk_prev ) * 100 ) : null;

	$local_now   = (int) ( $gbp_stats['total_impressions'] ?? 0 );
	$local_prev  = (int) ( $gbp_stats['prev_impressions']  ?? 0 );
	$local_delta = $local_prev > 0 ? round( ( ( $local_now - $local_prev ) / $local_prev ) * 100 ) : null;
	?>

	<?php if ( $using_site_overview ) : ?>
	<p style="font-size:12px;color:#787c82;margin:0 0 8px;">Showing site-wide GSC data — Ratesight post tracking will appear here once pages are published and indexed.</p>
	<?php endif; ?>

	<div class="rs-scorecard">

		<!-- Google Search Console -->
		<div class="rs-scorecard-group">
			<div class="rs-scorecard-label">Google Search Console</div>
			<div class="rs-scorecard-row">
				<div class="rs-score-card">
					<div class="rs-score-label">Impressions</div>
					<div class="rs-score-value"><?php echo $imp_now > 0 ? esc_html( number_format( $imp_now ) ) : '—'; ?></div>
					<?php if ( $imp_delta !== null ) : ?>
						<div class="rs-score-delta <?php echo esc_attr( $imp_delta >= 0 ? 'rs-delta-up' : 'rs-delta-down' ); ?>">
							<?php echo $imp_delta >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( abs( $imp_delta ) ); ?>% vs last month
						</div>
					<?php else : ?>
						<div class="rs-score-delta rs-delta-flat">Last 28 days</div>
					<?php endif; ?>
				</div>
				<div class="rs-score-card">
					<div class="rs-score-label">Clicks</div>
					<div class="rs-score-value"><?php echo $clk_now > 0 ? esc_html( number_format( $clk_now ) ) : '—'; ?></div>
					<?php if ( $clk_delta !== null ) : ?>
						<div class="rs-score-delta <?php echo esc_attr( $clk_delta >= 0 ? 'rs-delta-up' : 'rs-delta-down' ); ?>">
							<?php echo $clk_delta >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( abs( $clk_delta ) ); ?>% vs last month
						</div>
					<?php else : ?>
						<div class="rs-score-delta rs-delta-flat">Last 28 days</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Google Business Profile -->
		<div class="rs-scorecard-group">
			<div class="rs-scorecard-label">Google Business Profile</div>
			<div class="rs-scorecard-row">
				<div class="rs-score-card">
					<div class="rs-score-label">Local Impressions</div>
					<div class="rs-score-value"><?php echo ( $local_now > 0 ) ? esc_html( number_format( $local_now ) ) : ( $gbp_ever_synced ? '0' : '—' ); ?></div>
					<?php if ( $local_delta !== null && $local_now > 0 ) : ?>
						<div class="rs-score-delta <?php echo esc_attr( $local_delta >= 0 ? 'rs-delta-up' : 'rs-delta-down' ); ?>">
							<?php echo $local_delta >= 0 ? '↑' : '↓'; ?> <?php echo esc_html( abs( $local_delta ) ); ?>% vs last month
						</div>
					<?php elseif ( $gbp_ever_synced && $local_now === 0 ) : ?>
						<div class="rs-score-delta rs-delta-flat" style="font-size:11px;">Low local search visibility</div>
					<?php else : ?>
						<div class="rs-score-delta rs-delta-flat">Last 28 days</div>
					<?php endif; ?>
				</div>
				<div class="rs-score-card">
					<div class="rs-score-label">Actions</div>
					<?php
					$gbp_actions = ( $gbp_stats['website_clicks'] ?? 0 ) + ( $gbp_stats['call_clicks'] ?? 0 ) + ( $gbp_stats['direction_requests'] ?? 0 );
					?>
					<div class="rs-score-value"><?php echo ( $gbp_actions > 0 ) ? esc_html( number_format( $gbp_actions ) ) : ( $gbp_ever_synced ? '0' : '—' ); ?></div>
					<div class="rs-score-delta rs-delta-flat">
						<?php
						$wc = $gbp_stats['website_clicks'] ?? 0;
						$cc = $gbp_stats['call_clicks'] ?? 0;
						$dr = $gbp_stats['direction_requests'] ?? 0;
						echo ( $wc > 0 ? esc_html( number_format( $wc ) ) . ' web' : '— web' ) . ' &middot; ';
						echo ( $cc > 0 ? esc_html( number_format( $cc ) ) . ' calls' : '— calls' ) . ' &middot; ';
						echo ( $dr > 0 ? esc_html( number_format( $dr ) ) . ' directions' : '— directions' );
						?>
					</div>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $bing_stats ) ) : ?>
		<!-- Bing -->
		<div class="rs-scorecard-group">
			<div class="rs-scorecard-label">Bing</div>
			<div class="rs-scorecard-row">
				<div class="rs-score-card">
					<div class="rs-score-label">Impressions</div>
					<div class="rs-score-value"><?php echo $bing_stats['impressions'] > 0 ? esc_html( number_format( $bing_stats['impressions'] ) ) : '—'; ?></div>
					<div class="rs-score-delta rs-delta-flat">Last 28 days</div>
				</div>
				<div class="rs-score-card">
					<div class="rs-score-label">Clicks</div>
					<div class="rs-score-value"><?php echo $bing_stats['clicks'] > 0 ? esc_html( number_format( $bing_stats['clicks'] ) ) : '—'; ?></div>
					<div class="rs-score-delta rs-delta-flat"><?php echo $bing_stats['position'] ? 'Avg pos: ' . esc_html( $bing_stats['position'] ) : 'Last 28 days'; ?></div>
				</div>
			</div>
		</div>
		<?php endif; ?>

	</div>

	<?php if ( ! $gsc_locked && ! $gbp_locked ) : ?>
		<div class="rs-empty"><p>Connect <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=connections' ) ); ?>">Google Search Console and/or Google Business Profile</a> to see performance data here.</p></div>
	<?php endif; ?>

	<!-- Narrative summary -->
	<?php
	$narrative_parts = array();

	// GSC story
	$ov_imp = (int) ( $gsc_stats['total_impressions'] ?? 0 );
	$ov_top10 = 0; $ov_new = 0;
	if ( $gsc_locked ) {
		$ov_data = Ratesight_GSC_Client::get_performance_data( 30 );
		foreach ( $ov_data as $r ) {
			if ( (float) $r['position'] <= 10 && (float) $r['position'] > 0 ) $ov_top10++;
			if ( ! empty( $r['is_new'] ) ) $ov_new++;
		}
		if ( $ov_top10 > 0 ) {
			$narrative_parts[] = $ov_top10 . ' ' . ( $ov_top10 === 1 ? 'page' : 'pages' ) . ' ranking on page 1';
		}
		if ( $ov_new > 0 ) {
			$narrative_parts[] = $ov_new . ' new ' . ( $ov_new === 1 ? 'ranking' : 'rankings' ) . ' this month';
		}
		if ( $ov_imp > 0 ) {
			$narrative_parts[] = number_format( $ov_imp ) . ' search impressions';
		}
	}

	// GBP story
	$ov_calls = (int) ( $gbp_stats['call_clicks'] ?? 0 );
	$ov_web   = (int) ( $gbp_stats['website_clicks'] ?? 0 );
	if ( $ov_calls > 0 ) $narrative_parts[] = $ov_calls . ' calls from GBP';
	if ( $ov_web   > 0 ) $narrative_parts[] = $ov_web   . ' website clicks from GBP';

	if ( count( $narrative_parts ) >= 2 ) :
	?>
	<div style="background:linear-gradient(135deg,#f0f7ff 0%,#f5f0ff 100%);border:1px solid #dde5f5;border-radius:8px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;gap:10px;">
		<span style="font-size:18px;">📈</span>
		<p style="margin:0;font-size:13px;color:#1d2327;line-height:1.5;">
			<?php
			$last  = array_pop( $narrative_parts );
			$first = implode( ', ', $narrative_parts );
			echo esc_html( ucfirst( $first ) . ( $first ? ', and ' : '' ) . $last . '.' );
			?>
		</p>
	</div>
	<?php endif; ?>

<?php elseif ( $perf_tab === 'organic' ) : ?>

	<?php if ( ! $gsc_locked ) : ?>
		<div class="notice notice-warning inline" style="margin:0 0 16px;"><p>Connect Search Console on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=connections' ) ); ?>">Connections tab</a>.</p></div>
	<?php else :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_days_raw = isset( $_GET['gsc_days'] ) ? (int) $_GET['gsc_days'] : 30;
		$active_days     = in_array( $active_days_raw, array( 7, 30, 90 ), true ) ? $active_days_raw : 30;
		$data = Ratesight_GSC_Client::get_performance_data( $active_days );
		$cached_insights = get_transient( 'ratesight_ai_insights' );
	?>

	<!-- Toolbar -->
	<div class="rs-log-bar" style="margin-bottom:16px;">
		<div>
			<h2>Organic (Google Search Console)</h2>
			<p class="rs-log-meta">
				Property: <strong><?php echo esc_html( $gsc_selection['url'] ?? '—' ); ?></strong>
				&middot; <?php if ( $last_sync ) : ?>Last synced: <?php echo esc_html( $last_sync ); ?><?php endif; ?>
			</p>
		</div>
		<div style="display:flex;gap:8px;align-items:center;">
			<div class="rs-period-toggle" id="rs-period-toggle" style="display:flex;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
				<?php foreach ( array( 7 => '7d', 30 => '30d', 90 => '90d' ) as $btn_days => $btn_label ) :
					$btn_active = $active_days === $btn_days;
				?>
				<button type="button" class="rs-period-btn <?php echo $btn_active ? 'rs-period-active' : ''; ?>" data-days="<?php echo (int) $btn_days; ?>"
					style="padding:5px 12px;font-size:12px;border:none;<?php echo $btn_active ? 'background:#1877F2;color:#fff;font-weight:600;' : 'background:#fff;color:#646970;'; ?>cursor:pointer;">
					<?php echo esc_html( $btn_label ); ?>
				</button>
				<?php endforeach; ?>
			</div>
			<button type="button" id="rs-sync-gsc-now" class="button button-small">Sync Now</button>
			<span id="rs-sync-feedback" style="font-size:13px;display:none;"></span>
		</div>
	</div>

	<!-- Organic summary cards -->
	<?php if ( ! empty( $data ) ) :
		$oc_impressions = 0; $oc_prev_impressions = 0;
		$oc_clicks      = 0; $oc_prev_clicks      = 0;
		$oc_top10 = 0; $oc_top3 = 0; $oc_new = 0;
		foreach ( $data as $row ) {
			$oc_impressions      += (int) $row['impressions'];
			$oc_clicks           += (int) $row['clicks'];
			$oc_prev_impressions += (int) $row['prev_impressions'];
			$oc_prev_clicks      += (int) $row['prev_clicks'];
			$pos = (float) $row['position'];
			if ( $pos > 0 ) {
				if ( $pos <= 10 ) $oc_top10++;
				if ( $pos <= 3  ) $oc_top3++;
			}
			if ( ! empty( $row['is_new'] ) ) $oc_new++;
		}

		$oc_delta = static function( int $now, int $prev ): string {
			if ( $prev <= 0 ) return '';
			$pct = round( ( ( $now - $prev ) / $prev ) * 100 );
			// Only show positive deltas or small drops (< 20%) — large negatives are noise
			// in short windows and alarm clients unnecessarily.
			if ( $pct < -20 ) return '';
			return ( $pct >= 0 ? '+' : '' ) . $pct . '%';
		};
		$oc_delta_col = static function( int $now, int $prev ): string {
			if ( $prev <= 0 ) return '#787c82';
			return $now >= $prev ? '#15803d' : '#dc2626';
		};
	?>
	<div id="rs-organic-cards" style="display:grid;grid-template-columns:repeat(<?php echo (int) ( $oc_new > 0 ? 5 : 4 ); ?>,1fr);gap:12px;margin-bottom:20px;">
		<?php
		$oc_cards = array(
			array( 'icon' => '👁', 'key' => 'impressions', 'label' => 'Impressions',     'value' => number_format( $oc_impressions ), 'delta' => $oc_delta( $oc_impressions, $oc_prev_impressions ), 'dcol' => $oc_delta_col( $oc_impressions, $oc_prev_impressions ), 'desc' => 'Total search appearances' ),
			array( 'icon' => '🖱',  'key' => 'clicks',      'label' => 'Clicks',          'value' => number_format( $oc_clicks ),      'delta' => $oc_delta( $oc_clicks, $oc_prev_clicks ),           'dcol' => $oc_delta_col( $oc_clicks, $oc_prev_clicks ),           'desc' => 'Clicks to your pages' ),
			array( 'icon' => '🏆', 'key' => 'top3',        'label' => 'Top 3',           'value' => $oc_top3,                         'delta' => '',                                                  'dcol' => '#787c82',                                               'desc' => 'Pages ranking #1–3' ),
			array( 'icon' => '✅', 'key' => 'top10',       'label' => 'Page 1 Rankings', 'value' => $oc_top10,                        'delta' => '',                                                  'dcol' => '#787c82',                                               'desc' => 'Pages ranking in top 10' ),
			array( 'icon' => '🆕', 'key' => 'new',         'label' => 'New Rankings',    'value' => $oc_new,                          'delta' => '',                                                  'dcol' => '#787c82',                                               'desc' => 'First appeared this period', 'hide_if_zero' => true ),
		);
		foreach ( $oc_cards as $oc ) :
			if ( ! empty( $oc['hide_if_zero'] ) && (int) $oc['value'] === 0 ) continue;
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;">
			<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;">
				<span style="font-size:16px;line-height:1;"><?php echo $oc['icon']; // phpcs:ignore ?></span>
				<?php if ( $oc['delta'] ) : ?>
				<span data-oc-delta="<?php echo esc_attr( $oc['key'] ); ?>" style="font-size:10px;font-weight:600;color:<?php echo esc_attr( $oc['dcol'] ); ?>;background:<?php echo str_starts_with( $oc['delta'], '+' ) ? '#f0fdf4' : '#fef2f2'; ?>;padding:2px 6px;border-radius:10px;"><?php echo esc_html( $oc['delta'] ); ?></span>
				<?php else : ?><span></span><?php endif; ?>
			</div>
			<div data-oc="<?php echo esc_attr( $oc['key'] ); ?>" style="font-size:22px;font-weight:700;color:#1d2327;line-height:1;margin-bottom:3px;"><?php echo esc_html( $oc['value'] ); ?></div>
			<div style="font-size:12px;font-weight:600;color:#1d2327;margin-bottom:2px;"><?php echo esc_html( $oc['label'] ); ?></div>
			<div style="font-size:11px;color:#787c82;"><?php echo esc_html( $oc['desc'] ); ?></div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- AI Insights cards (reuse existing) -->
	<div id="rs-insights-wrap">
		<?php if ( $cached_insights ) :
			$wins      = $cached_insights['wins'] ?? array();
			$opps      = $cached_insights['opportunities'] ?? array();
			$post_map  = $cached_insights['posts'] ?? array();

			// Sort longer titles first so longer matches win over shorter prefixes.
			usort( $post_map, static function ( $a, $b ) {
				return strlen( $b['post_title'] ) - strlen( $a['post_title'] );
			} );

			$linkify = static function ( $text ) use ( $post_map ) {
				foreach ( $post_map as $p ) {
					if ( strpos( $text, $p['post_title'] ) !== false ) {
						$edit_url = admin_url( 'post.php?post=' . $p['post_id'] . '&action=edit' );
						$link     = '<a href="' . esc_url( $edit_url ) . '" target="_blank" style="color:inherit;text-decoration:underline;text-underline-offset:2px;">' . esc_html( $p['post_title'] ) . '</a>';
						// Replace only the first match.
						$pos = strpos( $text, $p['post_title'] );
						return esc_html( substr( $text, 0, $pos ) ) . $link . esc_html( substr( $text, $pos + strlen( $p['post_title'] ) ) );
					}
				}
				return esc_html( $text );
			};
		?>
		<div id="rs-insights-cards">
			<?php if ( ! empty( $wins ) || ! empty( $opps ) ) : ?>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
				<?php if ( ! empty( $wins ) ) : ?>
				<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">
					<div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
						<span style="font-size:15px;">🏆</span>
						<span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#15803d;">Wins</span>
					</div>
					<?php foreach ( $wins as $w ) : ?>
					<div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid #dcfce7;">
						<span style="color:#16a34a;font-size:14px;line-height:1;margin-top:2px;">✓</span>
						<span style="font-size:13px;color:#14532d;line-height:1.5;"><?php echo $linkify( $w ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php if ( ! empty( $opps ) ) : ?>
				<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;">
					<div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
						<span style="font-size:15px;">🎯</span>
						<span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#b45309;">Opportunities</span>
					</div>
					<?php foreach ( $opps as $o ) : ?>
					<div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid #fef3c7;">
						<span style="color:#d97706;font-size:14px;line-height:1;margin-top:2px;">→</span>
						<span style="font-size:13px;color:#78350f;line-height:1.5;"><?php echo $linkify( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<p style="font-size:11px;color:#787c82;margin:0 0 16px;">
				AI insights &middot; <a href="#" id="rs-refresh-insights">Refresh</a>
			</p>
		</div>
		<?php elseif ( ! empty( $data ) ) : ?>
		<div class="rs-card" style="margin-bottom:16px;">
			<div class="rs-card-body" style="display:flex;align-items:center;gap:16px;">
				<div style="flex:1;"><strong style="font-size:13px;">AI Wins &amp; Opportunities</strong><p style="font-size:12px;color:#646970;margin:3px 0 0;">Analyse your data to surface ranking wins and quick wins.</p></div>
				<button type="button" id="rs-generate-insights" class="button button-primary">Generate Insights</button>
			</div>
		</div>
		<?php endif; ?>
		<div id="rs-insights-loading" style="display:none;margin-bottom:16px;">
			<div class="rs-card"><div class="rs-card-body" style="display:flex;align-items:center;gap:12px;">
				<span class="spinner is-active" style="float:none;margin:0;"></span>
				<span style="font-size:13px;color:#646970;">Analysing with AI…</span>
			</div></div>
		</div>
		<div id="rs-insights-error" style="display:none;margin-bottom:16px;"></div>
	</div>

	<!-- Rankings table -->
	<?php if ( empty( $data ) ) : ?>
		<div id="rs-rankings-empty" style="margin-bottom:16px;">

			<!-- Site-level GSC snapshot — shows immediately, no Ratesight posts needed -->
			<div class="rs-card" id="rs-site-overview-card" style="margin-bottom:12px;">
				<div class="rs-card-body">
					<div id="rs-site-overview-loading" style="display:flex;align-items:center;gap:10px;color:#646970;font-size:13px;">
						<span class="spinner is-active" style="float:none;margin:0;"></span> Loading your site's organic performance from Google Search Console…
					</div>
					<div id="rs-site-overview-content" style="display:none;"></div>
				</div>
			</div>

			<!-- CTA card -->
			<div style="background:linear-gradient(135deg,#1877F2 0%,#0d5cbf 100%);border-radius:6px;padding:24px 28px;color:#fff;display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
				<div style="flex:1;min-width:200px;">
					<h3 style="margin:0 0 6px;font-size:16px;color:#fff;">Turn these rankings into revenue</h3>
					<p style="margin:0;font-size:13px;opacity:.9;line-height:1.5;">Ratesight creates SEO-optimised local pages that target the keywords your customers are already searching. See how it works for your business.</p>
				</div>
				<a href="https://ratesight.com/#demo" target="_blank" style="display:inline-block;background:#fff;color:#1877F2;font-weight:700;font-size:13px;padding:10px 22px;border-radius:5px;text-decoration:none;white-space:nowrap;flex-shrink:0;">Book a Free Audit →</a>
			</div>

		</div>

		<script>
		jQuery( function( $ ) {
			function loadSiteOverview() {
				$.post( ajaxurl, { action: 'ratesight_get_site_overview', nonce: '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>' } )
					.done( function ( r ) {
						$( '#rs-site-overview-loading' ).hide();
						if ( r.success ) {
							var d    = r.data;
							var opps = d.opportunities || [];

							var statsHtml =
								'<p style="font-size:12px;font-weight:600;color:#787c82;text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px;">Your site — last 28 days</p>' +
								'<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f0f0f1;">' +
								'<div><div style="font-size:24px;font-weight:700;color:#1d2327;">' + ( d.total_impressions > 0 ? parseInt(d.total_impressions).toLocaleString() : '—' ) + '</div><div style="font-size:12px;color:#787c82;">Total Impressions</div></div>' +
								'<div><div style="font-size:24px;font-weight:700;color:#1d2327;">' + ( d.total_clicks > 0 ? parseInt(d.total_clicks).toLocaleString() : '—' ) + '</div><div style="font-size:12px;color:#787c82;">Total Clicks</div></div>' +
								'<div><div style="font-size:24px;font-weight:700;color:#1d2327;">' + ( d.avg_position > 0 ? '#' + d.avg_position : '—' ) + '</div><div style="font-size:12px;color:#787c82;">Avg Position</div></div>' +
								'<div><div style="font-size:24px;font-weight:700;color:#1d2327;">' + ( d.page_count > 0 ? d.page_count : '—' ) + '</div><div style="font-size:12px;color:#787c82;">Pages Indexed</div></div>' +
								'</div>';

							var oppsHtml = '';
							if ( opps.length ) {
								oppsHtml = '<p style="font-size:12px;font-weight:600;color:#787c82;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px;">🎯 Quick win opportunities — pages ranked 6–20</p>' +
								'<table class="wp-list-table widefat striped" style="margin-bottom:0;">' +
								'<thead><tr><th>Page</th><th style="width:110px">Impressions</th><th style="width:95px">Position</th><th style="width:70px">CTR</th></tr></thead><tbody>' +
								opps.map( function(p) {
									var posColor = p.position <= 10 ? '#1877F2' : '#646970';
									return '<tr>' +
										'<td style="font-size:13px;">' + ( p.url ? '<a href="' + p.url + '" target="_blank" style="color:#1877F2;">' + ( p.title || p.url ) + '</a>' : ( p.title || '—' ) ) + '</td>' +
										'<td>' + ( p.impressions > 0 ? parseInt(p.impressions).toLocaleString() : '—' ) + '</td>' +
										'<td><span style="color:' + posColor + ';font-weight:600;">' + ( p.position > 0 ? '#' + parseFloat(p.position).toFixed(1) : '—' ) + '</span></td>' +
										'<td>' + ( p.ctr > 0 ? parseFloat(p.ctr).toFixed(1) + '%' : '—' ) + '</td>' +
									'</tr>';
								} ).join('') +
								'</tbody></table>' +
								'<p style="font-size:12px;color:#787c82;margin:10px 0 0;">These pages are close to page 1. Dedicated local service pages created by Ratesight could push them over the line and convert that traffic.</p>';
							} else if ( d.page_count > 0 ) {
								oppsHtml = '<p style="font-size:13px;color:#646970;">No pages in the 6–20 range with significant impressions yet — your site may already be ranking well, or is still building authority.</p>';
							}

							$( '#rs-site-overview-content' ).html( statsHtml + oppsHtml ).show();
						} else {
							var msg = r.data && r.data.message ? r.data.message : '';
							if ( msg.indexOf('48 hours') >= 0 || msg.indexOf('No data') >= 0 ) {
								$( '#rs-site-overview-content' ).html(
									'<p style="font-size:13px;color:#646970;">GSC data takes up to 48 hours to appear after a site is first connected. Check back tomorrow — once it\'s there this section will populate automatically.</p>'
								).show();
							} else if ( msg ) {
								$( '#rs-site-overview-content' ).html( '<p style="font-size:13px;color:#d63638;">' + msg + '</p>' ).show();
							}
						}
					} )
					.fail( function () {
						$( '#rs-site-overview-loading' ).hide();
						$( '#rs-site-overview-content' ).html( '<p style="font-size:13px;color:#646970;">Could not load site data — check your GSC connection.</p>' ).show();
					} );
			}
			loadSiteOverview();
		} );
		</script>

	<?php else : ?>
		<input type="hidden" id="rs-inline-rows" value="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
		<div style="overflow-x:auto;">
		<table class="wp-list-table widefat striped" id="rs-rankings-table" style="table-layout:auto;min-width:700px;">
			<thead>
				<tr>
					<th class="rs-sort-th" data-col="title"          style="min-width:200px;cursor:pointer;user-select:none;">Post Title</th>
					<th class="rs-sort-th" data-col="impressions"    style="width:100px;white-space:nowrap;cursor:pointer;user-select:none;color:#1877F2;">Impressions ↓</th>
					<th class="rs-sort-th" data-col="clicks"         style="width:70px;white-space:nowrap;cursor:pointer;user-select:none;">Clicks</th>
					<th class="rs-sort-th" data-col="position"       style="width:105px;white-space:nowrap;cursor:pointer;user-select:none;">Position</th>
					<th class="rs-sort-th rs-trend-th" data-col="position_start" style="width:90px;white-space:nowrap;cursor:pointer;user-select:none;" title="Position change over the period.">Trend</th>
					<th style="width:60px;white-space:nowrap;" title="30-day position history">30d</th>
					<th class="rs-sort-th" data-col="ctr"            style="width:80px;white-space:nowrap;cursor:pointer;user-select:none;">CTR</th>
					<th style="width:50px;">↗</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $data as $row ) :
				$pos         = (float) $row['position'];
				$pos_start   = isset( $row['position_start'] ) && $row['position_start'] !== null ? (float) $row['position_start'] : null;
				// Trend = how much position improved over the period (positive = climbed up the rankings).
				$trend = $pos_start !== null ? round( $pos_start - $pos, 1 ) : null;

				$pos_color = $pos <= 3 ? '#00a32a' : ( $pos <= 10 ? '#1877F2' : '#646970' );
				$edit = get_edit_post_link( $row['post_id'] );
				$view = get_permalink( $row['post_id'] );
			?>
			<tr class="rs-rank-row" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>">
				<td>
					<?php if ( ( $row['post_type'] ?? '' ) === 'ratesight_page' ) : ?><svg xmlns="http://www.w3.org/2000/svg" width="12" height="15" viewBox="0 0 24 30" style="vertical-align:middle;margin-right:5px;position:relative;top:-1px;flex-shrink:0;" aria-label="Ratesight Page"><path d="M12 0C7.6 0 4 3.6 4 8c0 6 8 16 8 16s8-10 8-16c0-4.4-3.6-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="#1877F2"/></svg><?php endif; ?>
					<?php if ( $edit ) : ?><a href="<?php echo esc_url( $edit ); ?>" target="_blank"><?php echo esc_html( $row['post_title'] ?: '(no title)' ); ?></a>
					<?php else : ?><?php echo esc_html( $row['post_title'] ?: '(no title)' ); ?><?php endif; ?>
					<button type="button" class="rs-kw-toggle button button-small" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>" style="margin-left:6px;font-size:10px;">Keywords</button>
				</td>
				<td><strong><?php echo esc_html( number_format( (int) $row['impressions'] ) ); ?></strong></td>
				<td><?php echo esc_html( number_format( (int) $row['clicks'] ) ); ?></td>
				<td><span style="color:<?php echo esc_attr( $pos_color ); ?>;font-weight:600;">#<?php echo esc_html( number_format( $pos, 1 ) ); ?></span></td>
				<td><?php
					if ( $trend !== null && abs( $trend ) <= 50 && abs( $trend ) >= 0.1 ) :
						$cls = $trend > 0 ? 'rs-up' : 'rs-down';
						echo '<span class="' . esc_attr( $cls ) . '">' . ( $trend > 0 ? '↑' : '↓' ) . esc_html( number_format( abs( $trend ), 1 ) ) . '</span>';
					elseif ( $trend !== null && abs( $trend ) < 0.1 ) :
						echo '<span class="rs-flat">→</span>';
					else :
						echo '<span class="rs-flat">—</span>';
					endif; ?>
				</td>
				<?php
				// Sparkline SVG from stored daily positions.
				$spk_raw = $row['sparkline'] ?? '';
				$spk_pts = array();
				foreach ( explode( ',', $spk_raw ) as $spk_v ) {
					$spk_f = (float) trim( $spk_v );
					if ( $spk_f > 0 ) $spk_pts[] = $spk_f;
				}
				$spk_count = count( $spk_pts );
				?>
				<td style="min-width:56px;vertical-align:middle;"><?php
				if ( $spk_count >= 1 ) :
					$spk_min   = min( $spk_pts );
					$spk_max   = max( $spk_pts );
					$spk_range = ( $spk_max - $spk_min ) ?: 1;
					$spk_w     = 52; $spk_h = 20; $spk_pad = 3;
					$spk_first = $spk_pts[0];
					$spk_last  = $spk_pts[ $spk_count - 1 ];
					$spk_color = ( $spk_last < $spk_first - 0.5 ) ? '#16a34a'
						: ( ( $spk_last > $spk_first + 0.5 ) ? '#dc2626' : '#9ca3af' );
					echo '<svg width="' . (int) $spk_w . '" height="' . (int) $spk_h . '" viewBox="0 0 ' . (int) $spk_w . ' ' . (int) $spk_h . '" style="display:block;overflow:visible;">';
					if ( $spk_count === 1 ) :
						$cy = round( $spk_pad + ( ( $spk_pts[0] - $spk_min ) / $spk_range ) * ( $spk_h - $spk_pad * 2 ), 1 );
						echo '<circle cx="' . esc_attr( $spk_w / 2 ) . '" cy="' . esc_attr( $cy ) . '" r="2.5" fill="' . esc_attr( $spk_color ) . '"/>';
					else :
						$coords = '';
						foreach ( $spk_pts as $si => $sv ) {
							$sx = round( ( $si / ( $spk_count - 1 ) ) * $spk_w, 1 );
							$sy = round( $spk_pad + ( ( $sv - $spk_min ) / $spk_range ) * ( $spk_h - $spk_pad * 2 ), 1 );
							$coords .= $sx . ',' . $sy . ' ';
						}
						echo '<polyline points="' . esc_attr( trim( $coords ) ) . '" fill="none" stroke="' . esc_attr( $spk_color ) . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
					endif;
					echo '</svg>';
				endif; ?>
				</td>
				<?php
				$ctr_pct = (float) $row['ctr'] * 100;
				$pos_num = (float) $row['position'];
				$exp_ctrs = array( 0, 28, 15, 11, 8, 6, 4, 3, 2.5, 2, 1.5 );
				$pos_round = (int) min( 10, max( 1, round( $pos_num ) ) );
				$exp_ctr  = $pos_num <= 0 ? 0 : ( $pos_num <= 10 ? ( $exp_ctrs[ $pos_round ] ?? 1.5 ) : 0.5 );
				$ratio    = $exp_ctr > 0 ? $ctr_pct / $exp_ctr : 1;
				$bar_col  = $ratio >= 0.8 ? '#16a34a' : ( $ratio >= 0.4 ? '#d97706' : '#dc2626' );
				$bar_w    = $exp_ctr > 0 ? min( 100, ( $ctr_pct / max( $exp_ctr, $ctr_pct, 1 ) ) * 100 ) : 0;
				?>
				<td title="Expected ~<?php echo esc_attr( number_format( $exp_ctr, 1 ) ); ?>% at this position">
				<?php if ( $ctr_pct > 0 ) : ?>
					<div style="display:flex;flex-direction:column;gap:2px;">
						<span style="font-size:12px;font-weight:600;color:<?php echo esc_attr( $bar_col ); ?>;"><?php echo esc_html( number_format( $ctr_pct, 1 ) ); ?>%</span>
						<div style="height:3px;background:#f0f0f1;border-radius:2px;width:50px;"><div style="height:3px;background:<?php echo esc_attr( $bar_col ); ?>;border-radius:2px;width:<?php echo esc_attr( number_format( $bar_w, 0 ) ); ?>%;"></div></div>
					</div>
				<?php else : ?><span style="color:#787c82;">—</span><?php endif; ?>
				</td>
				<td><?php if ( $view ) : ?><a href="<?php echo esc_url( $view ); ?>" target="_blank" class="rs-post-link">↗</a><?php else : ?>—<?php endif; ?></td>
			</tr>
			<tr class="rs-kw-row" id="rs-kw-<?php echo esc_attr( $row['post_id'] ); ?>" style="display:none;">
				<td colspan="8" style="padding:0 14px 14px;">
					<div class="rs-kw-content" style="color:#646970;font-size:12px;padding:8px 0;">Loading keywords…</div>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div><!-- /overflow-x:auto -->
	<?php endif; ?>

	<!-- Attention Pages (zero impressions) -->
	<div id="rs-attention-wrap" style="margin:8px 0 18px;">
		<a href="#" id="rs-attention-toggle" style="font-size:11px;color:#787c82;text-decoration:none;display:none;">
			<span id="rs-attention-label">⚠ Checking pages…</span>
		</a>
		<div id="rs-attention-panel" style="display:none;margin-top:10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;overflow:hidden;">
			<div style="padding:10px 14px;border-bottom:1px solid #f0f0f1;display:flex;justify-content:space-between;align-items:center;">
				<strong style="font-size:12px;color:#1d2327;">Pages needing attention</strong>
				<span style="font-size:11px;color:#787c82;">Zero impressions 30+ days after publish, or dropped off recently</span>
			</div>
			<div id="rs-attention-list" style="padding:4px 0;"></div>
		</div>
	</div>

	<!-- Schema Manager -->
	<h2 class="rs-section" style="margin-top:24px;">📋 Schema Markup</h2>
	<div class="rs-card">
	<div class="rs-card-body">
		<p class="description" style="margin:0 0 12px;">Schema is auto-generated on publish. Check here to review, edit, or add schema to pages that are missing it.</p>
		<button type="button" id="rs-load-schema-status" class="button">Check Schema Status</button>
		<div id="rs-schema-result" style="margin-top:14px;"></div>
	</div>
	</div>
	<?php
	$chat_context = 'organic';
	$chat_prompts = ! empty( $data ) ? array(
		'Which of my Ratesight pages should I update to improve rankings?',
		'Which pages are close to page 1 and worth a push?',
		'Why might my CTR be low on my top pages?',
		'Which pages have improved the most this month?',
	) : array(
		'What local service pages should I create first?',
		'Which topics on this site have the most ranking potential?',
		'How would Ratesight pages help this site rank better?',
		'What keywords are my competitors likely targeting?',
	);
	include dirname( __FILE__ ) . '/inc-chat-widget.php'; ?>

	<!-- Keyword Cannibalization -->
	<h2 class="rs-section" style="margin-top:24px;">🔄 Keyword Cannibalization</h2>
	<div class="rs-card">
	<div class="rs-card-body">
		<p class="description" style="margin:0 0 12px;">Detect pages competing for the same search queries. Having two pages fight for the same keyword splits your ranking authority.</p>
		<button type="button" id="rs-check-cannibalization" class="button">Check for Cannibalization</button>
		<div id="rs-cannibalization-result" style="margin-top:14px;"></div>
	</div>
	</div>

	<!-- Content Improvement Queue -->
	<h2 class="rs-section" style="margin-top:24px;">📈 Content Improvement Queue</h2>
	<div class="rs-card">
	<div class="rs-card-body">
		<p class="description" style="margin:0 0 12px;">Pages ranking 6–20 with 500+ impressions but low CTR. These are your biggest quick wins — a better title and meta description alone can double clicks.</p>
		<button type="button" id="rs-load-improvement-queue" class="button">Load Improvement Queue</button>
		<div id="rs-improvement-result" style="margin-top:14px;"></div>
	</div>
	</div>

	<?php endif; // gsc_locked ?>

<?php elseif ( $perf_tab === 'local' ) : ?>

	<?php if ( ! $gbp_locked ) : ?>
		<div class="notice notice-warning inline" style="margin:0 0 16px;"><p>Connect and lock a GBP location on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=connections' ) ); ?>">Connections tab</a>.</p></div>
	<?php else :
		$last_gbp_sync = get_option( 'ratesight_gbp_performance_last_sync', null );

		// Auto-trigger performance sync if it's never run.
		if ( ! $last_gbp_sync && ! wp_next_scheduled( 'ratesight_sync_gbp_performance' ) ) {
			wp_schedule_single_event( time() + 3, 'ratesight_sync_gbp_performance' );
		}
	?>

	<!-- Toolbar -->
	<div class="rs-log-bar" style="margin-bottom:16px;">
		<div>
			<h2>Local (GBP)</h2>
			<p class="rs-log-meta">
				Location: <strong><?php echo esc_html( $gbp_selection['label'] ?? '—' ); ?></strong>
				&middot; Last 28 days
				<?php if ( $last_gbp_sync ) : ?>&middot; Last synced: <?php echo esc_html( $last_gbp_sync ); ?><?php endif; ?>
			</p>
		</div>
		<div style="display:flex;gap:8px;align-items:center;">
			<button type="button" id="rs-sync-gbp-perf" class="button button-small">Sync Now</button>
		</div>
	</div>
	<script>
	JQuery( function($) {
		$( '#rs-sync-gbp-perf' ).on( 'click', function() {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Syncing…' );
			// GBP sync can take 10-20s — fire and reload after a short delay.
			$.post( ajaxurl, { action: 'ratesight_sync_gbp_now', nonce: '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>' } );
			setTimeout( function() { window.location.reload(); }, 8000 );
		} );
	} );
	</script>

	<!-- GBP Performance Cards with period toggle -->
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$gbp_period_raw = isset( $_GET['gbp_days'] ) ? (int) $_GET['gbp_days'] : 28;
	$gbp_period     = in_array( $gbp_period_raw, array( 7, 28, 90 ), true ) ? $gbp_period_raw : 28;

	// Current period from stored data (fast).
	$gbp_history = Ratesight_GBP_Insights_Client::get_performance_history( $gbp_period );
	$cutoff_cur  = gmdate( 'Y-m-d', strtotime( '-' . $gbp_period . ' days' ) );
	$cur = array( 'search' => 0, 'calls' => 0, 'website' => 0 );
	foreach ( $gbp_history as $row ) {
		if ( $row['date'] < $cutoff_cur ) continue;
		$cur['search']  += (int) $row['search_impressions'] + (int) $row['maps_impressions'];
		$cur['calls']   += (int) $row['call_clicks'];
		$cur['website'] += (int) $row['website_clicks'];
	}

	// Year-over-year comparison — same weekday-aligned window last year.
	// We find the same day of the week last year so Mon–Sun always compares to Mon–Sun.
	// Cached 24h per period.
	$yoy_key = 'ratesight_gbp_yoy_' . $gbp_period;
	$prv     = get_transient( $yoy_key );
	if ( false === $prv ) {
		// Current window end = 2 days ago (same as sync lag).
		$cur_end_ts   = strtotime( '-2 days' );
		$cur_start_ts = strtotime( '-' . ( $gbp_period + 2 ) . ' days' );

		// Step back exactly 52 weeks (364 days) to land on the same day of the week.
		$yoy_end   = gmdate( 'Y-m-d', $cur_end_ts   - ( 52 * 7 * DAY_IN_SECONDS ) );
		$yoy_start = gmdate( 'Y-m-d', $cur_start_ts - ( 52 * 7 * DAY_IN_SECONDS ) );

		$prv = Ratesight_GBP_Insights_Client::fetch_period_totals( $yoy_start, $yoy_end );
		set_transient( $yoy_key, $prv, DAY_IN_SECONDS );
	}
	// Invalidate YoY cache when user forces a manual sync.
	// (Handled in ajax_sync_gbp_now by deleting these transients.)

	$delta = static function ( int $now, int $prev ): ?string {
		// Don't show delta if prev is near-zero — listing likely didn't exist last year.
		if ( $prev < 5 ) return null;
		// Also hide if the delta would be misleadingly large (>500% = listing is new).
		$pct = round( ( ( $now - $prev ) / $prev ) * 100 );
		if ( abs( $pct ) > 500 ) return null;
		return ( $pct >= 0 ? '+' : '' ) . $pct . '%';
	};
	$delta_color = static function ( int $now, int $prev ): string {
		if ( $prev < 5 ) return '#787c82';
		return $now >= $prev ? '#15803d' : '#dc2626';
	};

	$base_url_gbp = admin_url( 'admin.php?page=ratesight&tab=performance&ptab=local' );
	if ( ! empty( $gbp_history ) ) :
	?>

	<!-- Period toggle -->
	<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
		<div style="display:flex;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
			<?php foreach ( array( 7 => '7d', 28 => '28d', 90 => '90d' ) as $d => $label ) :
				$active = $gbp_period === $d;
			?>
			<a href="<?php echo esc_url( $base_url_gbp . '&gbp_days=' . $d ); ?>"
				style="padding:5px 12px;font-size:12px;text-decoration:none;<?php echo $active ? 'background:#1877F2;color:#fff;font-weight:600;' : 'background:#fff;color:#646970;'; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
			<?php endforeach; ?>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:6px;">
		<?php
		$cards = array(
			array( 'icon' => '👁', 'label' => 'Profile Views',  'desc' => 'Impressions via Search & Maps', 'val' => $cur['search'],  'prev' => $prv['search'] ),
			array( 'icon' => '📞', 'label' => 'Phone Calls',    'desc' => 'Called directly from listing',  'val' => $cur['calls'],   'prev' => $prv['calls'] ),
			array( 'icon' => '🌐', 'label' => 'Website Clicks', 'desc' => 'Clicked through to website',    'val' => $cur['website'], 'prev' => $prv['website'] ),
		);
		foreach ( $cards as $c ) :
			$d    = $delta( $c['val'], $c['prev'] );
			$dcol = $delta_color( $c['val'], $c['prev'] );
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">
			<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;">
				<span style="font-size:20px;line-height:1;"><?php echo $c['icon']; // phpcs:ignore ?></span>
				<?php if ( $d !== null ) : ?>
				<span style="font-size:11px;font-weight:600;color:<?php echo esc_attr( $dcol ); ?>;background:<?php echo $c['val'] >= $c['prev'] ? '#f0fdf4' : '#fef2f2'; ?>;padding:2px 7px;border-radius:10px;"><?php echo esc_html( $d ); ?></span>
				<?php endif; ?>
			</div>
			<div style="font-size:26px;font-weight:700;color:#1d2327;line-height:1;margin-bottom:4px;"><?php echo esc_html( number_format( $c['val'] ) ); ?></div>
			<div style="font-size:12px;font-weight:600;color:#1d2327;margin-bottom:2px;"><?php echo esc_html( $c['label'] ); ?></div>
			<div style="font-size:11px;color:#787c82;"><?php echo esc_html( $c['desc'] ); ?></div>
		</div>
		<?php endforeach; ?>
	</div>
	<p style="font-size:11px;color:#787c82;margin:0 0 20px;">
		Last <?php echo esc_html( $gbp_period ); ?> days<?php if ( $prv['search'] >= 5 || $prv['calls'] >= 5 ) echo ' · vs same period last year'; ?>
	</p>
	<?php endif; ?>

	<!-- Profile health -->
	<h2 class="rs-section">Profile Health</h2>
	<div class="rs-card" id="rs-profile-health-card">
		<div class="rs-card-body">
			<div id="rs-profile-health-content">
				<div id="rs-profile-health-loading" style="display:flex;align-items:center;gap:8px;color:#646970;font-size:13px;">
					<span class="spinner is-active" style="float:none;margin:0;"></span> Loading profile health…
				</div>
				<button type="button" id="rs-load-profile-health" class="button" style="display:none;">Refresh</button>
			</div>
		</div>
	</div>
	<script>
	jQuery( function($) {
		// Auto-load profile health on tab open
		$.post( ajaxurl, { action: 'ratesight_get_profile_health', nonce: '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>' } )
			.done( function(r) { $( '#rs-load-profile-health' ).trigger( 'click-result', [r] ); } )
			.fail( function() { $( '#rs-profile-health-loading' ).hide(); $( '#rs-load-profile-health' ).show(); } );
	} );
	</script>

	<!-- Reviews -->
	<h2 class="rs-section">Reviews</h2>
	<div class="rs-card" id="rs-reviews-card">
		<div class="rs-card-body">
			<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
				<button type="button" id="rs-load-reviews" class="button button-primary">Load Reviews</button>
				<button type="button" id="rs-check-review-velocity" class="button">Check Review Velocity</button>
				<span id="rs-reviews-loading" style="display:none;font-size:13px;color:#646970;">Loading…</span>
			</div>
			<div id="rs-review-velocity-result" style="margin-bottom:12px;"></div>
			<div id="rs-reviews-content"></div>
		</div>
	</div>

	<!-- Local AI Chat -->
	<?php
	$chat_context = 'local';
	$chat_prompts = array(
		'How can I improve my local ranking?',
		'Draft a reply to my most recent negative review',
		'What GBP post ideas would work for my business?',
		'What categories should I add to my profile?',
	);
	include dirname( __FILE__ ) . '/inc-chat-widget.php'; ?>

	<?php endif; // gbp_locked ?>

<?php endif; // perf_tab ?>

<?php if ( $perf_tab === 'bing' ) : ?>

<?php
$bing_connected  = Ratesight_Bing_Client::is_connected();
$bing_locked     = Ratesight_Bing_Client::is_locked();
$bing_last_sync  = get_option( 'ratesight_bing_last_sync', '' );
$bing_days_raw   = isset( $_GET['bing_days'] ) ? absint( wp_unslash( $_GET['bing_days'] ) ) : 30;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bing_days       = in_array( $bing_days_raw, array( 7, 30, 90 ), true ) ? $bing_days_raw : 30;
$bing_base       = $base_url . '&ptab=bing';
?>

<?php if ( ! $bing_connected || ! $bing_locked ) : ?>
	<div class="rs-empty">
		<p>Connect Bing Webmaster Tools on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=connections' ) ); ?>">Connections tab</a> to see Bing search performance here.</p>
	</div>
<?php else : ?>

<?php
global $wpdb;
$bing_perf = $wpdb->prefix . 'ratesight_bing_performance';  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

$rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	"SELECT p.post_id, p.url,
	        COALESCE( wp.post_title, p.url ) AS title,
	        wp.post_type,
	        SUM(p.impressions) AS impressions,
	        SUM(p.clicks) AS clicks,
	        AVG(p.position) AS position
	 FROM `{$bing_perf}` p
	 LEFT JOIN {$wpdb->posts} wp ON wp.ID = p.post_id
	 WHERE p.date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
	 GROUP BY p.url
	 ORDER BY impressions DESC
	 LIMIT 200",
	$bing_days
), ARRAY_A );

$total_impressions = array_sum( array_column( $rows ?: array(), 'impressions' ) );
$total_clicks      = array_sum( array_column( $rows ?: array(), 'clicks' ) );
$avg_pos           = count( $rows ) > 0
	? round( array_sum( array_column( $rows, 'position' ) ) / count( $rows ), 1 )
	: 0;

$rs_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="13" viewBox="0 0 24 30" style="vertical-align:middle;margin-right:4px;position:relative;top:-1px;flex-shrink:0;" aria-label="Ratesight Page"><path d="M12 0C7.6 0 4 3.6 4 8c0 6 8 16 8 16s8-10 8-16c0-4.4-3.6-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="#1877F2"/></svg>';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
	<div>
		<h2 style="margin:0 0 2px;">Bing Search Performance</h2>
		<p class="rs-log-meta">
			Site: <strong><?php echo esc_html( Ratesight_Bing_Client::get_site_url() ); ?></strong>
			&middot; Last sync: <?php echo $bing_last_sync ? esc_html( $bing_last_sync ) : 'Never'; ?>
		</p>
	</div>
	<div style="display:flex;gap:8px;align-items:center;">
		<div style="display:flex;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
			<?php foreach ( array( 7 => '7d', 30 => '30d', 90 => '90d' ) as $btn_days => $btn_label ) :
				$btn_active = $bing_days === $btn_days; ?>
			<button type="button" class="rs-bing-period <?php echo $btn_active ? 'rs-period-active' : ''; ?>" data-days="<?php echo (int) $btn_days; ?>"
				style="padding:5px 12px;font-size:12px;border:none;<?php echo $btn_active ? 'background:#1877F2;color:#fff;font-weight:600;' : 'background:#fff;color:#646970;'; ?>cursor:pointer;">
				<?php echo esc_html( $btn_label ); ?>
			</button>
			<?php endforeach; ?>
		</div>
		<button type="button" id="rs-sync-bing-perf" class="button button-small">Sync Now</button>
		<span id="rs-sync-bing-perf-feedback" style="font-size:13px;display:none;"></span>
	</div>
</div>

<?php if ( empty( $rows ) ) : ?>
	<div class="rs-empty"><p>No Bing data for this period — try a wider range or click <strong>Sync Now</strong> above.</p></div>
<?php else : ?>

<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
	<div class="rs-score-card"><div class="rs-score-value"><?php echo number_format( $total_impressions ); ?></div><div class="rs-score-label">Bing Impressions</div></div>
	<div class="rs-score-card"><div class="rs-score-value"><?php echo number_format( $total_clicks ); ?></div><div class="rs-score-label">Bing Clicks</div></div>
	<div class="rs-score-card"><div class="rs-score-value"><?php echo $avg_pos ? esc_html( $avg_pos ) : '&#8212;'; ?></div><div class="rs-score-label">Avg Position</div></div>
</div>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th>Page</th>
			<th style="width:120px;">Impressions</th>
			<th style="width:80px;">Clicks</th>
			<th style="width:100px;">Avg Position</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $rows as $row ) :
		$is_rs  = ( $row['post_type'] ?? '' ) === 'ratesight_page';
		$edit   = $row['post_id'] ? get_edit_post_link( $row['post_id'] ) : null;
		$view   = $row['post_id'] ? get_permalink( $row['post_id'] ) : $row['url'];
	?>
		<tr>
			<td style="display:flex;align-items:center;gap:2px;">
				<?php if ( $is_rs ) echo wp_kses_post( $rs_icon ); ?>
				<?php if ( $edit ) : ?>
					<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
					<a href="<?php echo esc_url( $view ); ?>" target="_blank" style="color:#646970;margin-left:4px;">↗</a>
				<?php else : ?>
					<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank"><?php echo esc_html( $row['url'] ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo number_format( $row['impressions'] ); ?></td>
			<td><?php echo number_format( $row['clicks'] ); ?></td>
			<td><?php echo esc_html( round( (float) $row['position'], 1 ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php endif; ?>
<?php endif; ?>

<?php endif; // perf_tab === bing ?>
