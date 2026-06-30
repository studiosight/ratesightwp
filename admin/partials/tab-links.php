<?php
/**
 * Admin partial: Links tab
 *
 * Three sections:
 *   1. Link Report  — per-page inbound / outbound / broken counts
 *   2. Suggestions  — AI-scored internal link suggestions for a selected page
 *   3. Manual links — list of manually-inserted links (from meta) per page
 */
defined( 'ABSPATH' ) || die;

$last_scan = get_option( 'ratesight_link_last_scan', '' );
$report    = Ratesight_Link_Manager::get_report();
$total     = count( $report );
$orphans   = count( array_filter( $report, fn( $r ) => (int) ( $r['inbound_count'] ?? 1 ) === 0 && $r['inbound_count'] !== null ) );
$broken    = count( array_filter( $report, fn( $r ) => (int) ( $r['broken_count'] ?? 0 ) > 0 ) );

// ── Filter ────────────────────────────────────────────────────────────────────
$filter = sanitize_key( wp_unslash( $_GET['lf'] ?? 'all' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( $filter === 'orphaned' ) {
	$report = array_values( array_filter( $report, fn( $r ) => (int) ( $r['inbound_count'] ?? 1 ) === 0 && $r['inbound_count'] !== null ) );
} elseif ( $filter === 'broken' ) {
	$report = array_values( array_filter( $report, fn( $r ) => (int) ( $r['broken_count'] ?? 0 ) > 0 ) );
}

// ── Sort ──────────────────────────────────────────────────────────────────────
$sort      = sanitize_key( wp_unslash( $_GET['ls'] ?? 'inbound' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort_dir  = sanitize_key( wp_unslash( $_GET['ld'] ?? 'asc' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort_flip = $sort_dir === 'asc' ? 'desc' : 'asc';

usort( $report, function( $a, $b ) use ( $sort ) {
	switch ( $sort ) {
		case 'broken':
			return (int) ( $b['broken_count'] ?? 0 ) <=> (int) ( $a['broken_count'] ?? 0 );
		case 'outbound':
			return (int) ( $b['outbound_count'] ?? 0 ) <=> (int) ( $a['outbound_count'] ?? 0 );
		case 'title':
			return strcmp( $a['post_title'] ?? '', $b['post_title'] ?? '' );
		case 'inbound':
		default:
			// Null (not scanned) = treat as -1 so they sort to bottom when ascending
			$av = $a['inbound_count'] !== null ? (int) $a['inbound_count'] : -1;
			$bv = $b['inbound_count'] !== null ? (int) $b['inbound_count'] : -1;
			return $av <=> $bv;
	}
} );
if ( $sort_dir === 'desc' && $sort !== 'broken' && $sort !== 'outbound' ) {
	$report = array_reverse( $report );
} elseif ( $sort_dir === 'asc' && ( $sort === 'broken' || $sort === 'outbound' ) ) {
	$report = array_reverse( $report );
}

// ── Pagination ────────────────────────────────────────────────────────────────
$filtered_total = count( $report );
$per_page       = 25;
$current_page   = max( 1, absint( wp_unslash( $_GET['lp'] ?? 1 ) ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$total_pages    = max( 1, (int) ceil( $filtered_total / $per_page ) );
$current_page   = min( $current_page, $total_pages );
$paged_report   = array_slice( $report, ( $current_page - 1 ) * $per_page, $per_page );

// Base URL that always keeps page=ratesight&tab=links
$_rs_base = admin_url( 'admin.php?page=ratesight&tab=links' );

// Helper closures: sort URL and arrow for column headers
$rs_sort_url = function( string $col ) use ( $sort, $sort_dir, $_rs_base ): string {
	$dir = ( $sort === $col && $sort_dir === 'asc' ) ? 'desc' : 'asc';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return add_query_arg( array( 'ls' => $col, 'ld' => $dir, 'lp' => 1, 'lf' => sanitize_key( wp_unslash( $_GET['lf'] ?? 'all' ) ) ), $_rs_base );
};
$rs_sort_arrow = function( string $col ) use ( $sort, $sort_dir ): string {
	if ( $sort !== $col ) return ' <span style="color:#ccc;">↕</span>';
	return ' ' . ( $sort_dir === 'asc' ? '↑' : '↓' );
};
?>

<style>
.rs-links-toolbar { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px; }
.rs-links-stats   { display:flex;gap:6px;font-size:13px;flex-wrap:wrap; }
.rs-links-stats a,.rs-links-stats span { display:inline-block;padding:4px 12px;border-radius:20px;border:1px solid #ddd;background:#f6f7f7;color:#2c3338;text-decoration:none;cursor:pointer;transition:all .15s; }
.rs-links-stats a:hover { background:#e2e4e7; }
.rs-links-stats a.active,.rs-links-stats span.active { background:#1877F2;color:#fff;border-color:#1877F2; }
.rs-links-stats a.warn-tab { border-color:#f0b8b8;background:#fff5f5;color:#d63638; }
.rs-links-stats a.warn-tab:hover,.rs-links-stats a.warn-tab.active { background:#d63638;color:#fff;border-color:#d63638; }
.rs-link-orphan td { background:#fff8f8; }
.rs-link-broken-badge { display:inline-block;background:#d63638;color:#fff;border-radius:20px;font-size:11px;padding:1px 7px;margin-left:4px; }
.rs-link-ok-badge     { display:inline-block;background:#00a32a;color:#fff;border-radius:20px;font-size:11px;padding:1px 7px;margin-left:4px; }
.rs-link-stale td     { opacity:.6; }
#rs-suggestions-wrap  { margin-top:20px; }
.rs-suggestion-row    { display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid #f0f0f0; }
.rs-suggestion-row:last-child { border-bottom:none; }
.rs-suggestion-score  { min-width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0; }
.rs-score-high { background:#e6f4ea;color:#1e7e34; }
.rs-score-mid  { background:#fff3cd;color:#856404; }
.rs-suggestion-meta { flex:1;min-width:0; }
.rs-suggestion-anchor { font-weight:600;color:#1877F2; }
.rs-suggestion-reason { font-size:12px;color:#787c82;margin-top:2px; }
.rs-suggestion-diversity { font-size:11px;color:#d63638;margin-top:2px; }
.rs-suggestion-actions { flex-shrink:0; }
.rs-insert-link { }
.rs-anchor-missing { font-size:11px;color:#d63638;display:block;margin-top:2px; }
th.rs-sortable { cursor:pointer;white-space:nowrap;user-select:none; }
th.rs-sortable:hover { background:#f0f0f1; }
</style>

<div class="rs-links-toolbar">
	<div class="rs-links-stats">
		<?php
		$all_url      = add_query_arg( array( 'lf' => 'all',      'lp' => 1, 'ls' => $sort, 'ld' => $sort_dir ), $_rs_base );
		$orphan_url   = add_query_arg( array( 'lf' => 'orphaned', 'lp' => 1, 'ls' => $sort, 'ld' => $sort_dir ), $_rs_base );
		$broken_url   = add_query_arg( array( 'lf' => 'broken',   'lp' => 1, 'ls' => $sort, 'ld' => $sort_dir ), $_rs_base );
		$base_url     = add_query_arg( array( 'lf' => $filter,    'ls' => $sort, 'ld' => $sort_dir ), $_rs_base );
		?>
		<a href="<?php echo esc_url( $all_url ); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>"><strong><?php echo esc_html( $total ); ?></strong> All Pages</a>
		<?php if ( $orphans > 0 ) : ?>
			<a href="<?php echo esc_url( $orphan_url ); ?>" class="warn-tab <?php echo $filter === 'orphaned' ? 'active' : ''; ?>"
				title="Pages with no other pages on this site linking to them. Use Suggest Links to build internal links.">
				<strong><?php echo esc_html( $orphans ); ?></strong> orphaned</a>
		<?php endif; ?>
		<?php if ( $broken > 0 ) : ?>
			<a href="<?php echo esc_url( $broken_url ); ?>" class="warn-tab <?php echo $filter === 'broken' ? 'active' : ''; ?>"><strong><?php echo esc_html( $broken ); ?></strong> broken links</a>
		<?php endif; ?>
		<?php if ( $last_scan ) : ?>
			<span style="color:#787c82;font-size:12px;padding:4px 8px;">Last scan: <?php echo esc_html( $last_scan ); ?></span>
		<?php endif; ?>
	</div>
	<div style="display:flex;gap:8px;align-items:center;">
		<button type="button" class="button" id="rs-scan-links">Scan All Pages</button>
		<button type="button" class="button" id="rs-bulk-check-broken">Check Broken (All)</button>
		<button type="button" class="button" id="rs-fix-targets" title="Add target=_blank to external links, remove from internal links">Fix Link Targets</button>
		<span id="rs-scan-feedback" style="font-size:13px;display:none;"></span>
	</div>
</div>

<!-- ── Link Report ──────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Link Report</h2>
<div class="rs-card">
<div class="rs-card-body" style="padding:0;">
<?php if ( $filter === 'broken' ) :
	// ── Global broken links view ──────────────────────────────────────────────
	$all_broken = Ratesight_Link_Manager::get_all_broken();
?>
<?php if ( empty( $all_broken ) ) : ?>
	<p style="padding:16px;color:#00a32a;">✓ No broken links found across any scanned page.</p>
<?php else : ?>
<p style="padding:12px 16px 0;font-size:13px;color:#646970;margin:0;"><?php echo count( $all_broken ); ?> broken links across <?php echo esc_html( $broken ); ?> pages.
<strong>Unlink</strong> removes the &lt;a&gt; tag but keeps the text. <strong>Ignore</strong> hides it permanently. <strong>Replace</strong> lets you enter a new URL.
<em style="color:#856404;">⚠ 403 codes may be bot-protection — the page may still work in a browser.</em></p>
<table class="wp-list-table widefat fixed striped">
	<thead><tr>
		<th style="width:28%;">Page</th>
		<th>Broken URL</th>
		<th style="width:130px;">Anchor Text</th>
		<th style="width:80px;">Code</th>
		<th style="width:220px;">Actions</th>
	</tr></thead>
	<tbody>
	<?php foreach ( $all_broken as $b ) :
		$edit       = get_edit_post_link( $b['post_id'] );
		$type_label = array( 'post' => 'Blog Post', 'page' => 'Page', 'ratesight_page' => 'RS Page' )[ $b['post_type'] ] ?? $b['post_type'];
		$is_blocked = ! empty( $b['possibly_blocked'] );
	?>
	<tr data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>" data-url="<?php echo esc_attr( $b['url'] ); ?>">
		<td style="font-size:12px;">
			<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $b['post_title'] ); ?></a>
			<small style="color:#787c82;display:block;"><?php echo esc_html( $type_label ); ?></small>
		</td>
		<td style="word-break:break-all;font-size:12px;"><?php echo esc_html( $b['url'] ); ?></td>
		<td style="font-size:12px;"><?php echo esc_html( $b['anchor'] ?: '—' ); ?></td>
		<td>
			<?php if ( $is_blocked ) : ?>
				<span style="color:#856404;font-size:11px;" title="403 — may be bot protection, not necessarily broken">403 ⚠</span>
			<?php else : ?>
				<span style="color:#d63638;"><?php echo esc_html( $b['code'] ?: '?' ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<button type="button" class="button button-small rs-action-unlink"
				data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>"
				data-url="<?php echo esc_attr( $b['url'] ); ?>">Unlink</button>
			<button type="button" class="button button-small rs-action-ignore"
				data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>"
				data-url="<?php echo esc_attr( $b['url'] ); ?>">Ignore</button>
			<button type="button" class="button button-small rs-broken-replace-btn"
				data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>"
				data-url="<?php echo esc_attr( $b['url'] ); ?>">Replace</button>
		</td>
	</tr>
	<tr class="rs-replace-row" data-for-post="<?php echo esc_attr( $b['post_id'] ); ?>" data-for-url="<?php echo esc_attr( $b['url'] ); ?>" style="display:none;">
		<td colspan="5" style="padding:10px 16px;background:#f6f7f7;">
			<div class="rs-replace-anchor" style="display:none;font-size:12px;color:#646970;margin-bottom:8px;">Anchor text: <em></em></div>
			<div class="rs-replace-suggestions" style="margin-bottom:8px;"></div>
			<div style="display:flex;gap:8px;align-items:center;">
				<input type="url" class="regular-text rs-replace-input"
					placeholder="Or enter a custom replacement URL…" style="flex:1;">
				<button type="button" class="button button-primary button-small rs-replace-apply"
					data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>"
					data-url="<?php echo esc_attr( $b['url'] ); ?>">Apply</button>
				<button type="button" class="button button-small rs-replace-cancel">Cancel</button>
			</div>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php
// ── Ignored links ─────────────────────────────────────────────────────────
$all_ignored = Ratesight_Link_Manager::get_all_ignored();
if ( ! empty( $all_ignored ) ) :
?>
<h3 style="margin:20px 0 8px;font-size:14px;color:#646970;">Ignored Links (<?php echo count( $all_ignored ); ?>)
	<span style="font-size:12px;font-weight:400;margin-left:4px;">— click Unignore to move back to broken</span>
</h3>
<table class="wp-list-table widefat fixed striped" style="opacity:.8;">
	<thead><tr>
		<th style="width:28%;">Page</th>
		<th>URL</th>
		<th style="width:130px;">Anchor</th>
		<th style="width:55px;">Code</th>
		<th style="width:110px;">Action</th>
	</tr></thead>
	<tbody>
	<?php foreach ( $all_ignored as $b ) : ?>
	<tr data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>" data-url="<?php echo esc_attr( $b['url'] ); ?>">
		<td style="font-size:12px;"><?php echo esc_html( $b['post_title'] ); ?>
			<small style="color:#787c82;display:block;"><?php echo esc_html( array( 'post' => 'Blog Post', 'page' => 'Page', 'ratesight_page' => 'RS Page' )[ $b['post_type'] ] ?? $b['post_type'] ); ?></small>
		</td>
		<td style="word-break:break-all;font-size:12px;color:#787c82;"><?php echo esc_html( $b['url'] ); ?></td>
		<td style="font-size:12px;color:#787c82;"><?php echo esc_html( $b['anchor'] ?: '—' ); ?></td>
		<td style="font-size:12px;color:#787c82;"><?php echo esc_html( $b['code'] ?: '?' ); ?></td>
		<td>
			<button type="button" class="button button-small rs-action-unignore"
				data-post-id="<?php echo esc_attr( $b['post_id'] ); ?>"
				data-url="<?php echo esc_attr( $b['url'] ); ?>">Unignore</button>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<?php endif; ?>

<?php else : ?>
<?php if ( empty( $report ) ) : ?>
	<p style="padding:16px;color:#646970;">No pages found. Click <strong>Scan All Pages</strong> to build the link map.</p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped" id="rs-link-report-table">
	<thead>
		<tr>
			<th><a class="rs-sortable" href="<?php echo esc_url( $rs_sort_url( 'title' ) ); ?>">Page<?php echo wp_kses_post( $rs_sort_arrow( 'title' ) ); ?></a></th>
			<th style="width:90px;">Type</th>
			<th style="width:120px;"><a class="rs-sortable" href="<?php echo esc_url( $rs_sort_url( 'inbound' ) ); ?>" title="Pages on your site linking TO this page">Inbound<?php echo wp_kses_post( $rs_sort_arrow( 'inbound' ) ); ?></a></th>
			<th style="width:120px;"><a class="rs-sortable" href="<?php echo esc_url( $rs_sort_url( 'outbound' ) ); ?>" title="Links going out from this page">Outbound<?php echo wp_kses_post( $rs_sort_arrow( 'outbound' ) ); ?></a></th>
			<th style="width:110px;"><a class="rs-sortable" href="<?php echo esc_url( $rs_sort_url( 'broken' ) ); ?>" title="Outbound links that returned an error">Broken<?php echo wp_kses_post( $rs_sort_arrow( 'broken' ) ); ?></a></th>
			<th style="width:230px;">
				Actions
				<span style="font-size:11px;font-weight:400;color:#787c82;display:block;margin-top:1px;">
					<em>Suggest Links</em> = AI finds pages to link to, you pick which to insert
				</span>
			</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $paged_report as $row ) :
		$post_id    = (int) $row['post_id'];
		$post_type  = $row['post_type'] ?? 'ratesight_page';
		$type_label = array( 'post' => 'Blog Post', 'page' => 'Page', 'ratesight_page' => 'RS Page' )[ $post_type ] ?? $post_type;
		$type_color = array( 'post' => '#2271b1', 'page' => '#787c82', 'ratesight_page' => '#1877F2' )[ $post_type ] ?? '#aaa';
		$is_orphan  = $row['inbound_count'] !== null && (int) $row['inbound_count'] === 0;
		$is_stale   = (int) ( $row['stale'] ?? 1 ) === 1;
		$is_broken  = (int) ( $row['broken_count'] ?? 0 ) > 0;
		$not_checked = (int) ( $row['broken_count'] ?? -1 ) === -1;
		$edit_url   = get_edit_post_link( $post_id );
		$view_url   = get_permalink( $post_id );
		$row_class  = $is_orphan ? 'rs-link-orphan' : ( $is_stale ? 'rs-link-stale' : '' );
	?>
		<tr class="<?php echo esc_attr( $row_class ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<td>
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a>
				<?php if ( $view_url ) : ?><a href="<?php echo esc_url( $view_url ); ?>" target="_blank" style="color:#646970;margin-left:4px;">↗</a><?php endif; ?>
				<?php if ( $is_stale ) : ?><br><small style="color:#d63638;">Not scanned yet</small><?php endif; ?>
			</td>
			<td><span style="font-size:11px;color:<?php echo esc_attr( $type_color ); ?>;font-weight:600;"><?php echo esc_html( $type_label ); ?></span></td>
			<td>
				<?php if ( $row['inbound_count'] === null ) : ?>
					<span style="color:#aaa;">—</span>
				<?php elseif ( $is_orphan ) : ?>
					<span style="color:#d63638;font-weight:600;">0 ⚠</span>
				<?php else : ?>
					<?php echo (int) $row['inbound_count']; ?>
				<?php endif; ?>
			</td>
			<td><?php echo $row['outbound_count'] !== null ? (int) $row['outbound_count'] : '<span style="color:#aaa;">—</span>'; ?></td>
			<td>
				<?php if ( $not_checked || $row['broken_count'] === null ) : ?>
					<span style="color:#aaa;">Not checked</span>
				<?php elseif ( $is_broken ) : ?>
					<span class="rs-link-broken-badge"><?php echo (int) $row['broken_count']; ?> broken</span>
				<?php else : ?>
					<span class="rs-link-ok-badge">✓ OK</span>
				<?php endif; ?>
			</td>
			<td>
				<button type="button" class="button button-small rs-get-suggestions" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-title="<?php echo esc_attr( $row['post_title'] ); ?>">Suggest Links</button>
				<?php if ( $not_checked || $is_broken ) : ?>
				&nbsp;<button type="button" class="button button-small rs-check-broken" data-post-id="<?php echo esc_attr( $post_id ); ?>">Check Broken</button>
				<?php endif; ?>
				<?php if ( $is_broken ) : ?>
				&nbsp;<button type="button" class="button button-small rs-broken-detail" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-title="<?php echo esc_attr( $row['post_title'] ); ?>">View &amp; Fix</button>
				<?php endif; ?>
				&nbsp;<button type="button" class="button button-small rs-view-manual" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-title="<?php echo esc_attr( $row['post_title'] ); ?>">Manual Links</button>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $total_pages > 1 ) : ?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;font-size:13px;color:#646970;">
	<span>Showing <?php echo esc_html( ( $current_page - 1 ) * $per_page + 1 ); ?>–<?php echo esc_html( min( $current_page * $per_page, $filtered_total ) ); ?> of <?php echo esc_html( $filtered_total ); ?><?php echo $filter !== 'all' ? ' (' . esc_html( $filter ) . ')' : ''; ?></span>
	<div style="display:flex;gap:4px;">
		<?php if ( $current_page > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'lp', $current_page - 1, $base_url ) ); ?>" class="button button-small">← Prev</a>
		<?php endif; ?>

		<?php
		// Show a window of page numbers around current page.
		$window_start = max( 1, $current_page - 2 );
		$window_end   = min( $total_pages, $current_page + 2 );
		if ( $window_start > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'lp', 1, $base_url ) ); ?>" class="button button-small">1</a>
			<?php if ( $window_start > 2 ) : ?><span style="padding:0 4px;">…</span><?php endif; ?>
		<?php endif; ?>

		<?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>
			<?php if ( $p === $current_page ) : ?>
				<span class="button button-small" style="background:#1877F2;color:#fff;border-color:#1877F2;"><?php echo esc_html( $p ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( add_query_arg( 'lp', $p, $base_url ) ); ?>" class="button button-small"><?php echo esc_html( $p ); ?></a>
			<?php endif; ?>
		<?php endfor; ?>

		<?php if ( $window_end < $total_pages ) : ?>
			<?php if ( $window_end < $total_pages - 1 ) : ?><span style="padding:0 4px;">…</span><?php endif; ?>
			<a href="<?php echo esc_url( add_query_arg( 'lp', $total_pages, $base_url ) ); ?>" class="button button-small"><?php echo esc_html( $total_pages ); ?></a>
		<?php endif; ?>

		<?php if ( $current_page < $total_pages ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'lp', $current_page + 1, $base_url ) ); ?>" class="button button-small">Next →</a>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<?php endif; // empty report ?>
<?php endif; // filter !== broken ?>
</div>
</div>

<!-- ── Suggestions panel ────────────────────────────────────────────────────── -->
<div id="rs-suggestions-wrap" style="display:none;">
	<h2 class="rs-section">Link Suggestions — <span id="rs-suggestions-title"></span>
		<button type="button" class="button button-small" id="rs-refresh-suggestions"
			style="font-size:11px;margin-left:10px;vertical-align:middle;" title="Clear cached suggestions and regenerate">↻ Refresh</button>
	</h2>
	<div class="rs-card">
	<div class="rs-card-body">
		<div id="rs-suggestions-loading" style="display:none;color:#646970;">
			<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>
			Analysing content and scoring with AI…
		</div>
		<div id="rs-suggestions-list"></div>
		<p id="rs-suggestions-empty" style="display:none;color:#646970;">No suggestions found — either this page already links to all relevant pages, or there aren't enough overlapping topics with other published RS Pages.</p>
	</div>
	</div>
</div>

<!-- ── Broken Links Detail panel ─────────────────────────────────────────── -->
<div id="rs-broken-detail-wrap" style="display:none;">
	<h2 class="rs-section">Broken Links — <span id="rs-broken-detail-title"></span></h2>
	<div class="rs-card"><div class="rs-card-body">
		<div id="rs-broken-detail-loading" style="display:none;color:#646970;margin-bottom:12px;">
			<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>Checking links…
		</div>
		<div id="rs-broken-detail-list"></div>
		<p id="rs-broken-detail-empty" style="display:none;color:#00a32a;">✓ No broken links found.</p>
	</div></div>
</div>

<!-- ── Auto-fix replacement picker ──────────────────────────────────────── -->
<div id="rs-autofix-wrap" style="display:none;">
	<h2 class="rs-section">Replace Broken Link</h2>
	<div class="rs-card"><div class="rs-card-body">
		<p>Broken URL: <code id="rs-autofix-url"></code><br>
		Anchor text: <strong id="rs-autofix-anchor"></strong></p>
		<div id="rs-autofix-loading" style="display:none;color:#646970;">
			<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>Finding replacements…
		</div>
		<div id="rs-autofix-options"></div>
		<p id="rs-autofix-empty" style="display:none;color:#646970;">No replacement suggestions found. Try unlinking or manually entering a URL below.</p>
		<div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
			<input type="url" id="rs-autofix-custom-url" class="regular-text" placeholder="Or enter a custom replacement URL…">
			<button type="button" class="button" id="rs-autofix-custom-apply">Apply</button>
		</div>
	</div></div>
</div>

<script>
jQuery( function( $ ) {
	var ajax     = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce    = '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>';
	var _fixPost = 0;
	var _fixUrl  = '';

	// ── Fix link targets ─────────────────────────────────────────────────────
	$( '#rs-fix-targets' ).on( 'click', function () {
		if ( ! confirm( 'This will update post content across all pages:\n• External links → add target="_blank" rel="noopener noreferrer"\n• Internal links → remove target="_blank"\n\nContinue?' ) ) return;
		var $btn = $( this ).prop( 'disabled', true ).text( 'Fixing…' );
		var $fb  = $( '#rs-scan-feedback' ).show().css( 'color', '' ).text( 'Updating link targets across all pages…' );
		$.post( ajax, { action: 'ratesight_link_fix_targets', nonce: nonce } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Fix Link Targets' );
				if ( r.success ) {
					if ( r.data.total_changes === 0 ) {
						$fb.css( 'color', '#00a32a' ).text( '✓ All link targets already correct — nothing to change.' );
					} else {
						$fb.css( 'color', '#00a32a' ).text( '✓ Fixed ' + r.data.total_changes + ' links across ' + r.data.pages_fixed + ' pages.' );
					}
				} else {
					$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Fix Link Targets' ); $fb.css( 'color', '#d63638' ).text( '✗ Request failed.' ); } );
	} );

	// ── Bulk check broken ─────────────────────────────────────────────────────
	$( '#rs-bulk-check-broken' ).on( 'click', function () {
		if ( ! confirm( 'This checks every page for broken outbound links. It runs in batches — leave this tab open until complete.\n\nContinue?' ) ) return;
		var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
		var $fb  = $( '#rs-scan-feedback' ).show().css( 'color', '' ).text( 'Starting…' );

		function runBatch( offset ) {
			$.post( ajax, { action: 'ratesight_link_bulk_check_broken', nonce: nonce, offset: offset } )
				.done( function ( r ) {
					if ( ! r.success ) {
						$btn.prop( 'disabled', false ).text( 'Check Broken (All)' );
						$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) );
						return;
					}
					var d   = r.data;
					var pct = d.total > 0 ? Math.round( d.checked / d.total * 100 ) : 100;
					$fb.text( 'Checking broken links… ' + d.checked + ' / ' + d.total + ' (' + pct + '%)' );

					if ( d.done ) {
						$btn.prop( 'disabled', false ).text( 'Check Broken (All)' );
						$fb.css( 'color', '#00a32a' ).text( '✓ Done — checked ' + d.total + ' pages. Reloading…' );
						setTimeout( function () { location.reload(); }, 1500 );
					} else {
						// Small pause then continue — avoids hammering the server.
						setTimeout( function () { runBatch( d.offset ); }, 200 );
					}
				} )
				.fail( function () {
					$btn.prop( 'disabled', false ).text( 'Check Broken (All)' );
					$fb.css( 'color', '#d63638' ).text( '✗ Request failed — try again.' );
				} );
		}

		runBatch( 0 );
	} );

	// ── Scan all ──────────────────────────────────────────────────────────────
	$( '#rs-scan-links' ).on( 'click', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Scanning…' );
		var $fb  = $( '#rs-scan-feedback' ).show().css( 'color', '' ).text( 'Building link map…' );
		$.post( ajax, { action: 'ratesight_link_scan', nonce: nonce } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Scan All Pages' );
				if ( r.success ) {
					$fb.css( 'color', '' ).text( '✓ Scanned ' + r.data.scanned + ' pages, ' + r.data.orphans + ' orphaned. Queueing broken link check…' );
					// Automatically kick off broken check after a successful scan.
					$.post( ajax, { action: 'ratesight_link_bulk_check_broken', nonce: nonce } )
						.done( function ( br ) {
							var total = br.success ? ( br.data.total || 0 ) : 0;
							$fb.css( 'color', '#00a32a' ).text( '✓ Scanned ' + r.data.scanned + ' pages. Broken link check running in background (' + total + ' pages queued).' );
							setTimeout( function () { location.reload(); }, 1500 );
						} )
						.fail( function () {
							$fb.css( 'color', '#00a32a' ).text( '✓ Scanned ' + r.data.scanned + ' pages.' );
							setTimeout( function () { location.reload(); }, 1500 );
						} );
				} else {
					$fb.css( 'color', '#d63638' ).text( '✗ ' + ( r.data && r.data.message ? r.data.message : 'Scan failed.' ) );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Scan All Pages' ); $fb.css( 'color', '#d63638' ).text( '✗ Request failed.' ); } );
	} );

	// ── Check broken (quick check + count update) ─────────────────────────────
	$( document ).on( 'click', '.rs-check-broken', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Checking…' );
		var post_id = $btn.data( 'post-id' );
		$.post( ajax, { action: 'ratesight_link_check_broken', nonce: nonce, post_id: post_id } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Check Broken' );
				if ( r.success ) {
					var count = ( r.data.broken || [] ).length;
					var $td   = $btn.closest( 'tr' ).find( 'td:nth-child(4)' );
					$td.html( count > 0
						? '<span class="rs-link-broken-badge">' + count + ' broken</span>'
						: '<span class="rs-link-ok-badge">✓ OK</span>'
					);
					if ( count > 0 ) {
						// Show or add a View & Fix button.
						if ( ! $btn.siblings( '.rs-broken-detail' ).length ) {
							$btn.after( '&nbsp;<button type="button" class="button button-small rs-broken-detail" data-post-id="' + post_id + '" data-title="' + $btn.closest( 'tr' ).find( 'td:first-child a' ).text() + '">View &amp; Fix</button>' );
						}
					}
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Check Broken' ); } );
	} );

	// ── View & Fix broken detail panel ────────────────────────────────────────
	$( document ).on( 'click', '.rs-broken-detail', function () {
		var post_id = $( this ).data( 'post-id' );
		var title   = $( this ).data( 'title' );
		$( '#rs-broken-detail-wrap' ).show();
		$( '#rs-autofix-wrap' ).hide(); // only shown when Auto-fix is clicked
		$( '#rs-broken-detail-title' ).text( title );
		$( '#rs-broken-detail-loading' ).show();
		$( '#rs-broken-detail-list, #rs-broken-detail-empty' ).hide().html( '' );
		$( 'html, body' ).animate( { scrollTop: $( '#rs-broken-detail-wrap' ).offset().top - 40 }, 300 );

		$.post( ajax, { action: 'ratesight_link_broken_detail', nonce: nonce, post_id: post_id } )
			.done( function ( r ) {
				$( '#rs-broken-detail-loading' ).hide();
				if ( ! r.success ) { $( '#rs-broken-detail-empty' ).show().text( r.data.message || 'Error.' ).css( 'color', '#d63638' ); return; }

				var broken   = r.data.broken   || [];
				var outbound = r.data.outbound  || [];
				var active_broken = broken.filter( function(b){ return b.status === 'broken'; } );

				if ( active_broken.length === 0 ) { $( '#rs-broken-detail-empty' ).show(); return; }

				var html = '<table class="wp-list-table widefat fixed striped"><thead><tr>' +
					'<th>Broken URL</th><th style="width:120px;">Anchor Text</th><th style="width:60px;">Code</th><th style="width:240px;">Actions</th>' +
					'</tr></thead><tbody>';

				$.each( active_broken, function( i, b ) {
					html += '<tr data-url="' + $( '<div>' ).text( b.url ).html() + '" data-post-id="' + post_id + '">' +
						'<td style="word-break:break-all;font-size:12px;">' + $( '<div>' ).text( b.url ).html() + '</td>' +
						'<td style="font-size:12px;">' + $( '<div>' ).text( b.anchor || '—' ).html() + '</td>' +
						'<td><span style="color:#d63638;">' + ( b.code || '—' ) + '</span></td>' +
						'<td>' +
							'<button type="button" class="button button-small rs-action-autofix" data-post-id="' + post_id + '" data-url="' + $( '<div>' ).text( b.url ).html() + '" data-anchor="' + $( '<div>' ).text( b.anchor || '' ).html() + '">Auto-fix</button> ' +
							'<button type="button" class="button button-small rs-action-unlink" data-post-id="' + post_id + '" data-url="' + $( '<div>' ).text( b.url ).html() + '">Unlink</button> ' +
							'<button type="button" class="button button-small rs-action-recheck" data-post-id="' + post_id + '" data-url="' + $( '<div>' ).text( b.url ).html() + '">Recheck</button> ' +
							'<button type="button" class="button button-small rs-action-ignore" data-post-id="' + post_id + '" data-url="' + $( '<div>' ).text( b.url ).html() + '">Ignore</button>' +
						'</td></tr>';
				} );
				html += '</tbody></table>';

				// External link audit below.
				var external  = outbound.filter( function(o){ return !o.is_internal; } );
				var caution   = external.filter( function(o){ return o.authority === 'caution'; } );
				if ( caution.length > 0 ) {
					html += '<h3 style="margin:16px 0 8px;">External Link Audit</h3>' +
						'<p style="font-size:13px;color:#646970;margin-bottom:8px;">These external links go to domains not in your authority list. Review that they aren\'t competitors or low-quality sites.</p>' +
						'<table class="wp-list-table widefat fixed striped"><thead><tr><th>URL</th><th style="width:120px;">Anchor</th><th style="width:100px;">Classification</th></tr></thead><tbody>';
					$.each( caution, function( i, o ) {
						html += '<tr><td style="word-break:break-all;font-size:12px;">' + $( '<div>' ).text( o.url ).html() + '</td>' +
							'<td style="font-size:12px;">' + $( '<div>' ).text( o.anchor || '—' ).html() + '</td>' +
							'<td><span style="color:#856404;">⚠ Caution</span></td></tr>';
					} );
					html += '</tbody></table>';
				}

				$( '#rs-broken-detail-list' ).show().html( html );
			} )
			.fail( function () { $( '#rs-broken-detail-loading' ).hide(); $( '#rs-broken-detail-empty' ).show().text( 'Request failed.' ).css( 'color', '#d63638' ); } );
	} );

	// ── Inline replace button (broken filter view) ───────────────────────────
	$( document ).on( 'click', '.rs-broken-replace-btn', function () {
		var $btn        = $( this ).prop( 'disabled', true ).text( 'Finding…' );
		var $dataRow    = $btn.closest( 'tr' );
		var $replaceRow = $dataRow.next( '.rs-replace-row' );
		var post_id     = $btn.data( 'post-id' );
		var url         = $btn.data( 'url' );

		// Hide other open replace rows.
		$( '.rs-replace-row' ).not( $replaceRow ).hide();

		// Show the row immediately with a loading state.
		$replaceRow.show();
		$replaceRow.find( '.rs-replace-suggestions' ).html(
			'<span style="color:#646970;font-size:12px;"><span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>Looking for a replacement…</span>'
		);
		$replaceRow.find( '.rs-replace-input' ).val( '' );

		// Fetch auto-suggestions (internal first, then Wayback).
		$.post( ajax, { action: 'ratesight_link_auto_fix', nonce: nonce, post_id: post_id, url: url } )
			.done( function( r ) {
				$btn.prop( 'disabled', false ).text( 'Replace' );
				var suggestions = r.success ? ( r.data.suggestions || [] ) : [];
				var anchor      = r.success ? ( r.data.anchor || '' ) : '';
				var html = '';

				if ( suggestions.length ) {
					html += '<div style="margin-bottom:8px;font-size:12px;font-weight:600;color:#1d2327;">Suggested replacements:</div>';
					$.each( suggestions, function( i, s ) {
						var pct     = s.confidence || 0;
						var pctColor = pct >= 80 ? '#00a32a' : pct >= 60 ? '#856404' : '#787c82';
						var pctBadge = '<span style="background:' + pctColor + ';color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:6px;">' + pct + '%</span>';
						var typeBadge = s.type === 'archive'
							? '<span style="background:#787c82;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;">Archive</span>'
							: '';
						html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f0;">'
							+ '<div style="flex:1;font-size:12px;">'
							+   '<strong>' + $( '<div>' ).text( s.label ).html() + '</strong>' + pctBadge + typeBadge
							+   '<br><span style="color:#787c82;word-break:break-all;font-size:11px;">' + $( '<div>' ).text( s.url ).html() + '</span>'
							+   '<br><span style="color:#646970;font-size:11px;">' + $( '<div>' ).text( s.reason ).html() + '</span>'
							+ '</div>'
							+ '<button type="button" class="button button-primary button-small rs-replace-use-suggestion"'
							+   ' data-post-id="' + post_id + '" data-old="' + $( '<div>' ).text( url ).html() + '" data-new="' + $( '<div>' ).text( s.url ).html() + '">'
							+   'Use This</button>'
							+ '</div>';
					} );
					html += '<div style="margin-top:8px;font-size:12px;color:#646970;">Or enter a custom URL:</div>';
				} else {
					html += '<div style="font-size:12px;color:#646970;margin-bottom:6px;">No automatic suggestions found. Enter a replacement URL:</div>';
				}

				$replaceRow.find( '.rs-replace-suggestions' ).html( html );
				if ( anchor ) {
					$replaceRow.find( '.rs-replace-anchor' ).text( '"' + anchor + '"' ).show();
				}
			} )
			.fail( function() {
				$btn.prop( 'disabled', false ).text( 'Replace' );
				$replaceRow.find( '.rs-replace-suggestions' ).html(
					'<div style="font-size:12px;color:#646970;margin-bottom:6px;">Enter a replacement URL:</div>'
				);
			} );
	} );

	$( document ).on( 'click', '.rs-replace-use-suggestion', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Applying…' );
		var post_id = $btn.data( 'post-id' );
		var old_url = $btn.data( 'old' );
		var new_url = $btn.data( 'new' );
		$.post( ajax, { action: 'ratesight_link_replace', nonce: nonce, post_id: post_id, old_url: old_url, new_url: new_url } )
			.done( function( r ) {
				if ( r.success ) {
					var $replaceRow = $btn.closest( '.rs-replace-row' );
					$replaceRow.prev( 'tr' ).fadeOut( 200, function() { $( this ).remove(); } );
					$replaceRow.fadeOut( 200, function() { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false ).text( 'Use This' );
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function() { $btn.prop( 'disabled', false ).text( 'Use This' ); } );
	} );

	$( document ).on( 'click', '.rs-replace-cancel', function () {
		$( this ).closest( '.rs-replace-row' ).hide();
	} );

	$( document ).on( 'click', '.rs-replace-apply', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Applying…' );
		var post_id = $btn.data( 'post-id' );
		var old_url = $btn.data( 'url' );
		var new_url = $btn.closest( 'td' ).find( '.rs-replace-input' ).val().trim();
		if ( ! new_url ) { alert( 'Enter a replacement URL first.' ); $btn.prop( 'disabled', false ).text( 'Apply' ); return; }
		$.post( ajax, { action: 'ratesight_link_replace', nonce: nonce, post_id: post_id, old_url: old_url, new_url: new_url } )
			.done( function( r ) {
				if ( r.success ) {
					var $replaceRow = $btn.closest( '.rs-replace-row' );
					$replaceRow.prev( 'tr' ).fadeOut( 200, function() { $( this ).remove(); } );
					$replaceRow.fadeOut( 200, function() { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false ).text( 'Apply' );
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function() { $btn.prop( 'disabled', false ).text( 'Apply' ); } );
	} );

	// ── Broken link actions ──────────────────────────────────────────────────
	function removeRow( url, postId ) {
		// Global broken view: rows have data-url attribute
		var $row = $( 'tr[data-url="' + url + '"][data-post-id="' + postId + '"]' );
		$row.next( '.rs-replace-row' ).remove();
		$row.fadeOut( 200, function() {
			$( this ).remove();
		} );
		// Detail panel view
		$( '#rs-broken-detail-list tr[data-url="' + url + '"]' ).fadeOut( 200, function() {
			$( this ).remove();
			if ( $( '#rs-broken-detail-list tbody tr' ).length === 0 ) {
				$( '#rs-broken-detail-list' ).hide();
				$( '#rs-broken-detail-empty' ).show();
			}
		} );
	}

	$( document ).on( 'click', '.rs-action-ignore', function () {
		var $btn = $( this ).prop( 'disabled', true );
		var post_id = $btn.data( 'post-id' ), url = $btn.data( 'url' );
		$.post( ajax, { action: 'ratesight_link_ignore_broken', nonce: nonce, post_id: post_id, url: url } )
			.done( function ( r ) { if ( r.success ) removeRow( url, post_id ); else { $btn.prop( 'disabled', false ); alert( r.data.message || 'Failed.' ); } } )
			.fail( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.rs-action-unignore', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Restoring…' );
		var post_id = $btn.data( 'post-id' ), url = $btn.data( 'url' );
		$.post( ajax, { action: 'ratesight_link_unignore_broken', nonce: nonce, post_id: post_id, url: url } )
			.done( function ( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 200, function() { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false ).text( 'Unignore' );
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Unignore' ); } );
	} );

	$( document ).on( 'click', '.rs-action-unlink', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Unlinking…' );
		var post_id = $btn.data( 'post-id' ), url = $btn.data( 'url' );
		if ( ! confirm( 'Remove this link? The anchor text will be kept as plain text.' ) ) { $btn.prop( 'disabled', false ).text( 'Unlink' ); return; }
		$.post( ajax, { action: 'ratesight_link_unlink', nonce: nonce, post_id: post_id, url: url } )
			.done( function ( r ) { if ( r.success ) { removeRow( url, post_id ); } else { $btn.prop( 'disabled', false ).text( 'Unlink' ); alert( r.data.message || 'Failed.' ); } } )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Unlink' ); } );
	} );

	$( document ).on( 'click', '.rs-action-recheck', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Checking…' );
		var post_id = $btn.data( 'post-id' ), url = $btn.data( 'url' );
		$.post( ajax, { action: 'ratesight_link_check_broken', nonce: nonce, post_id: post_id } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Recheck' );
				if ( r.success ) {
					var still = ( r.data.broken || [] ).some( function(b){ return b.url === url; } );
					if ( ! still ) {
						removeRow( url );
					} else {
						alert( 'Still broken (' + ( r.data.broken.find( function(b){ return b.url===url; } ) || {} ).code + ').' );
					}
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Recheck' ); } );
	} );

	// ── Auto-fix panel ────────────────────────────────────────────────────────
	$( document ).on( 'click', '.rs-action-autofix', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Finding…' );
		_fixPost    = $btn.data( 'post-id' );
		_fixUrl     = $btn.data( 'url' );
		var anchor  = $btn.data( 'anchor' );

		$( '#rs-autofix-wrap' ).show();
		$( '#rs-autofix-url' ).text( _fixUrl );
		$( '#rs-autofix-anchor' ).text( anchor || '(unknown)' );
		$( '#rs-autofix-loading' ).show();
		$( '#rs-autofix-options, #rs-autofix-empty' ).hide().html( '' );
		$( 'html, body' ).animate( { scrollTop: $( '#rs-autofix-wrap' ).offset().top - 40 }, 300 );

		$.post( ajax, { action: 'ratesight_link_auto_fix', nonce: nonce, post_id: _fixPost, url: _fixUrl } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Auto-fix' );
				$( '#rs-autofix-loading' ).hide();
				if ( ! r.success || ! r.data.suggestions || r.data.suggestions.length === 0 ) {
					$( '#rs-autofix-empty' ).show(); return;
				}
				var html = '';
				$.each( r.data.suggestions, function( i, s ) {
					var badge = s.preferred
						? '<span style="background:#1877F2;color:#fff;border-radius:4px;padding:1px 6px;font-size:11px;margin-left:6px;">Recommended</span>'
						: '';
					html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;">' +
						'<div>' +
							'<strong>' + $( '<div>' ).text( s.label ).html() + '</strong>' + badge + '<br>' +
							'<small style="color:#646970;word-break:break-all;">' + $( '<div>' ).text( s.url ).html() + '</small><br>' +
							'<small style="color:#787c82;">' + $( '<div>' ).text( s.reason ).html() + '</small>' +
						'</div>' +
						'<button type="button" class="button button-primary button-small rs-apply-fix" data-url="' + $( '<div>' ).text( s.url ).html() + '" style="margin-left:16px;flex-shrink:0;">Use This</button>' +
					'</div>';
				} );
				$( '#rs-autofix-options' ).show().html( html );
			} )
			.fail( function () {
				$btn.prop( 'disabled', false ).text( 'Auto-fix' );
				$( '#rs-autofix-loading' ).hide();
				$( '#rs-autofix-empty' ).show();
			} );
	} );

	$( document ).on( 'click', '.rs-apply-fix', function () {
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Applying…' );
		var new_url = $btn.data( 'url' );
		$.post( ajax, { action: 'ratesight_link_replace', nonce: nonce, post_id: _fixPost, old_url: _fixUrl, new_url: new_url } )
			.done( function ( r ) {
				if ( r.success ) {
					$( '#rs-autofix-wrap' ).hide();
					removeRow( _fixUrl );
					alert( '✓ Link replaced.' );
				} else {
					$btn.prop( 'disabled', false ).text( 'Use This' );
					alert( r.data.message || 'Failed.' );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Use This' ); } );
	} );

	$( '#rs-autofix-custom-apply' ).on( 'click', function () {
		var new_url = $( '#rs-autofix-custom-url' ).val().trim();
		if ( ! new_url ) { alert( 'Enter a URL first.' ); return; }
		var $btn = $( this ).prop( 'disabled', true ).text( 'Applying…' );
		$.post( ajax, { action: 'ratesight_link_replace', nonce: nonce, post_id: _fixPost, old_url: _fixUrl, new_url: new_url } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( 'Apply' );
				if ( r.success ) {
					$( '#rs-autofix-wrap' ).hide();
					removeRow( _fixUrl );
					alert( '✓ Link replaced.' );
				} else { alert( r.data.message || 'Failed.' ); }
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Apply' ); } );
	} );

	var rsCurrentSuggestionPostId = 0;

	$( document ).on( 'click', '#rs-refresh-suggestions', function () {
		if ( ! rsCurrentSuggestionPostId ) return;
		var $btn = $( this ).prop( 'disabled', true ).text( '↻ Refreshing…' );
		var $body = $( '#rs-suggestions-body' );
		rsCurrentSuggestionPostId = post_id;
		$body.html( '<p style="color:#646970;padding:12px 0;">Regenerating suggestions…</p>' );
		$.post( ajax, { action: 'ratesight_link_refresh_suggestions', nonce: nonce, post_id: rsCurrentSuggestionPostId } )
			.done( function ( r ) {
				$btn.prop( 'disabled', false ).text( '↻ Refresh' );
				if ( r.success ) {
					renderSuggestions( r.data.suggestions, $body );
				} else {
					$body.html( '<p style="color:#d63638;">' + ( r.data && r.data.message ? r.data.message : 'Failed.' ) + '</p>' );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( '↻ Refresh' ); } );
	} );

	// ── Get suggestions ───────────────────────────────────────────────────────
	$( document ).on( 'click', '.rs-get-suggestions', function () {
		var post_id = $( this ).data( 'post-id' );
		var title   = $( this ).data( 'title' );
		$( '#rs-suggestions-wrap' ).show();
		$( '#rs-suggestions-title' ).text( title );
		$( '#rs-suggestions-loading' ).show();
		$( '#rs-suggestions-list, #rs-suggestions-empty' ).hide().html('');
		$( 'html, body' ).animate( { scrollTop: $( '#rs-suggestions-wrap' ).offset().top - 40 }, 300 );

		$.post( ajax, { action: 'ratesight_link_suggestions', nonce: nonce, post_id: post_id } )
			.done( function ( r ) {
				$( '#rs-suggestions-loading' ).hide();
				if ( ! r.success ) {
					$( '#rs-suggestions-empty' ).show().text( r.data && r.data.message ? r.data.message : 'Could not load suggestions.' );
					return;
				}
				var suggestions = r.data.suggestions || [];
				if ( suggestions.length === 0 ) { $( '#rs-suggestions-empty' ).show(); return; }
				var html = '';
				$.each( suggestions, function ( i, s ) {
					var score_class = s.score >= 8 ? 'rs-score-high' : 'rs-score-mid';
					var missing_note = s.anchor_in_content ? '' : '<span class="rs-anchor-missing">⚠ Anchor text not found in content — link cannot be auto-inserted</span>';
					var diversity    = s.diversity_warning ? '<div class="rs-suggestion-diversity">⚠ "' + $( '<div>' ).text( s.anchor_text ).html() + '" used ' + s.diversity_count + '+ times sitewide — consider varying it</div>' : '';
					var insert_btn   = s.anchor_in_content
						? '<button type="button" class="button button-primary button-small rs-insert-link" data-source="' + post_id + '" data-anchor="' + $( '<div>' ).text( s.anchor_text ).html() + '" data-url="' + $( '<div>' ).text( s.target_url ).html() + '" data-target-title="' + $( '<div>' ).text( s.target_title ).html() + '">Insert Link</button>'
						: '<button type="button" class="button button-small" disabled title="Anchor text not found in content">Insert Link</button>';
					html += '<div class="rs-suggestion-row">' +
						'<div class="rs-suggestion-score ' + score_class + '">' + s.score + '</div>' +
						'<div class="rs-suggestion-meta">' +
							'<div>Link <span class="rs-suggestion-anchor">"' + $( '<div>' ).text( s.anchor_text ).html() + '"</span> → ' + $( '<div>' ).text( s.target_title ).html() + '</div>' +
							'<div class="rs-suggestion-reason">' + ( s.reason || '' ) + '</div>' +
							diversity + missing_note +
						'</div>' +
						'<div class="rs-suggestion-actions">' + insert_btn + '</div>' +
					'</div>';
				} );
				$( '#rs-suggestions-list' ).show().html( html );
			} )
			.fail( function () { $( '#rs-suggestions-loading' ).hide(); $( '#rs-suggestions-empty' ).show().text( 'Request failed.' ); } );
	} );

	// ── Insert link ───────────────────────────────────────────────────────────
	$( document ).on( 'click', '.rs-insert-link', function () {
		var $btn         = $( this );
		var source_id    = $btn.data( 'source' );
		var anchor       = $btn.data( 'anchor' );
		var url          = $btn.data( 'url' );
		var target_title = $btn.data( 'target-title' );

		if ( ! confirm( 'Insert link?\n\nAnchor: "' + anchor + '"\nTarget: ' + target_title + '\n\nThis will update the post content.' ) ) return;
		$btn.prop( 'disabled', true ).text( 'Inserting…' );

		$.post( ajax, { action: 'ratesight_link_insert', nonce: nonce, post_id: source_id, anchor: anchor, url: url } )
			.done( function ( r ) {
				if ( r.success ) {
					$btn.closest( '.rs-suggestion-row' ).fadeOut( 300, function () { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false ).text( 'Insert Link' );
					alert( 'Could not insert: ' + ( r.data && r.data.message ? r.data.message : 'Unknown error.' ) );
				}
			} )
			.fail( function () { $btn.prop( 'disabled', false ).text( 'Insert Link' ); } );
	} );

	// ── Manual links panel ────────────────────────────────────────────────────
	$( document ).on( 'click', '.rs-view-manual', function () {
		var post_id = $( this ).data( 'post-id' );
		var title   = $( this ).data( 'title' );
		var $wrap   = $( '#rs-manual-links-wrap' ).show();
		$( '#rs-manual-title' ).text( title );
		$( '#rs-manual-list' ).html( '<span style="color:#646970;">Loading…</span>' );
		$( 'html, body' ).animate( { scrollTop: $wrap.offset().top - 40 }, 300 );

		$.post( ajax, { action: 'ratesight_link_get_manual', nonce: nonce, post_id: post_id } )
			.done( function( r ) {
				if ( ! r.success || ! r.data.links.length ) {
					$( '#rs-manual-list' ).html( '<p style="color:#646970;">No manually-inserted links on this page.</p>' );
					return;
				}
				var html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Anchor Text</th><th>URL</th><th style="width:100px;">Added</th><th style="width:100px;">Action</th></tr></thead><tbody>';
				$.each( r.data.links, function( i, l ) {
					html += '<tr>' +
						'<td>' + $( '<div>' ).text( l.anchor || '—' ).html() + '</td>' +
						'<td style="word-break:break-all;font-size:12px;"><a href="' + $( '<div>' ).text( l.url ).html() + '" target="_blank">' + $( '<div>' ).text( l.url ).html() + '</a></td>' +
						'<td style="font-size:12px;color:#646970;">' + ( l.added || '' ).slice( 0, 10 ) + '</td>' +
						'<td><button type="button" class="button button-small rs-remove-manual" style="color:#d63638;" data-post-id="' + post_id + '" data-url="' + $( '<div>' ).text( l.url ).html() + '">Remove</button></td>' +
					'</tr>';
				} );
				html += '</tbody></table>';
				$( '#rs-manual-list' ).html( html );
			} )
			.fail( function() { $( '#rs-manual-list' ).html( '<p style="color:#d63638;">Request failed.</p>' ); } );
	} );

	$( document ).on( 'click', '.rs-remove-manual', function () {
		if ( ! confirm( 'Remove this manually-inserted link? The anchor text will stay as plain text, and it won\'t be re-inserted on future content updates.' ) ) return;
		var $btn    = $( this ).prop( 'disabled', true ).text( 'Removing…' );
		var post_id = $btn.data( 'post-id' );
		var url     = $btn.data( 'url' );
		$.post( ajax, { action: 'ratesight_link_remove_manual', nonce: nonce, post_id: post_id, url: url } )
			.done( function( r ) {
				if ( r.success ) {
					$btn.closest( 'tr' ).fadeOut( 200, function () {
						$( this ).remove();
						if ( $( '#rs-manual-list tbody tr' ).length === 0 ) {
							$( '#rs-manual-list' ).html( '<p style="color:#646970;">No manually-inserted links on this page.</p>' );
						}
					} );
				} else {
					$btn.prop( 'disabled', false ).text( 'Remove' );
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function() { $btn.prop( 'disabled', false ).text( 'Remove' ); } );
	} );

	// ── Redirects panel ───────────────────────────────────────────────────────
	$( document ).on( 'click', '.rs-redirect-update', function () {
		var $row  = $( this ).closest( 'tr' );
		var path  = $row.data( 'path' );
		var dest  = $row.find( '.rs-redirect-dest' ).val().trim();
		var $btn  = $( this ).prop( 'disabled', true ).text( 'Saving…' );
		$.post( ajax, { action: 'ratesight_redirect_update', nonce: nonce, path: path, destination: dest } )
			.done( function( r ) {
				$btn.prop( 'disabled', false ).text( 'Save' );
				if ( r.success ) {
					$btn.text( '✓ Saved' );
					setTimeout( function () { $btn.text( 'Save' ); }, 2000 );
				} else {
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function() { $btn.prop( 'disabled', false ).text( 'Save' ); } );
	} );

	$( document ).on( 'click', '.rs-redirect-delete', function () {
		if ( ! confirm( 'Remove this redirect? The old URL will return a 404 again.' ) ) return;
		var $row  = $( this ).closest( 'tr' );
		var path  = $row.data( 'path' );
		var $btn  = $( this ).prop( 'disabled', true );
		$.post( ajax, { action: 'ratesight_redirect_delete', nonce: nonce, path: path } )
			.done( function( r ) {
				if ( r.success ) {
					$row.fadeOut( 200, function () { $( this ).remove(); } );
				} else {
					$btn.prop( 'disabled', false );
					alert( r.data && r.data.message ? r.data.message : 'Failed.' );
				}
			} )
			.fail( function() { $btn.prop( 'disabled', false ); } );
	} );
} );
</script>

<?php
// ── Manual Links Panel ────────────────────────────────────────────────────
?>
<div id="rs-manual-links-wrap" style="display:none;margin-top:20px;">
	<h2 class="rs-section">Manual Links — <span id="rs-manual-title"></span></h2>
	<div class="rs-card"><div class="rs-card-body">
		<p style="font-size:13px;color:#646970;margin-top:0;">These links were inserted by Ratesight and will be automatically re-applied after every webhook content update. Remove any that are no longer wanted.</p>
		<div id="rs-manual-list"></div>
	</div></div>
</div>

<?php
// ── Redirects Panel ───────────────────────────────────────────────────────
$redirects = Ratesight_Link_Manager::get_redirects();
if ( ! empty( $redirects ) ) :
?>
<div style="margin-top:20px;">
<h2 class="rs-section">RS Page Redirects</h2>
<div class="rs-card"><div class="rs-card-body" style="padding:0;">
<p style="padding:12px 16px 0;font-size:13px;color:#646970;margin:0;">These RS pages were trashed or deleted. Each URL is automatically 301-redirected to the destination below. Leave destination blank to redirect to the homepage.</p>
<table class="wp-list-table widefat fixed striped" style="margin:0;">
<thead><tr>
	<th>Old URL (was RS Page)</th>
	<th style="width:120px;">Page Title</th>
	<th style="width:120px;">Removed</th>
	<th>Redirect To</th>
	<th style="width:120px;">Actions</th>
</tr></thead>
<tbody>
<?php foreach ( $redirects as $path => $entry ) : ?>
<tr data-path="<?php echo esc_attr( $path ); ?>">
	<td style="font-size:12px;word-break:break-all;"><code>/<?php echo esc_html( $path ); ?>/</code></td>
	<td style="font-size:12px;"><?php echo esc_html( $entry['title'] ?? '—' ); ?></td>
	<td style="font-size:12px;color:#646970;"><?php echo esc_html( substr( $entry['removed_at'] ?? '', 0, 10 ) ); ?></td>
	<td>
		<input type="url" class="regular-text rs-redirect-dest" style="width:100%;"
			value="<?php echo esc_attr( $entry['redirect_to'] ?? '' ); ?>"
			placeholder="Leave blank to redirect to homepage">
	</td>
	<td>
		<button type="button" class="button button-small rs-redirect-update">Save</button>
		<button type="button" class="button button-small rs-redirect-delete" style="color:#d63638;" title="Remove redirect (URL will 404 again)">✕</button>
	</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>
</div>
<?php endif; ?>

<!-- ── Link Domain Rules ──────────────────────────────────────────────────── -->
<div style="margin-top:20px;">
<h2 class="rs-section">Link Domain Rules</h2>
<div class="rs-card"><div class="rs-card-body">
<p style="font-size:13px;color:#646970;margin-top:0;">
	These lists tell the AI which external domains to favour or avoid when suggesting and scoring links. One domain per line.
</p>
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_seo_pages' ); ?>
<table class="form-table" role="presentation" style="margin:0;">
	<tr>
		<th style="width:180px;"><label for="rs-approved-domains">Approved Sources</label></th>
		<td>
			<textarea id="rs-approved-domains" name="ratesight_link_approved_domains"
				rows="5" class="large-text" style="font-family:monospace;font-size:12px;"
				placeholder="wikipedia.org&#10;webmd.com&#10;yourpartner.com"><?php echo esc_textarea( get_option( 'ratesight_link_approved_domains', '' ) ); ?></textarea>
			<p class="description">AI <strong>will</strong> suggest linking to these when topically relevant. Good for authoritative references, trusted partners, industry bodies.</p>
		</td>
	</tr>
	<tr>
		<th><label for="rs-excluded-domains">Never Link To</label></th>
		<td>
			<textarea id="rs-excluded-domains" name="ratesight_link_excluded_domains"
				rows="5" class="large-text" style="font-family:monospace;font-size:12px;"
				placeholder="competitor.com&#10;low-quality-site.com"><?php echo esc_textarea( get_option( 'ratesight_link_excluded_domains', '' ) ); ?></textarea>
			<p class="description">AI <strong>will not</strong> suggest linking to these. Use for competitors or sites you don't want to endorse. Also skipped by the broken link checker.</p>
		</td>
	</tr>
</table>
<p style="margin-top:12px;"><?php submit_button( 'Save Domain Rules', 'primary', 'submit', false ); ?></p>
</form>
</div></div>
</div>
