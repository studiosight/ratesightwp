<?php
/**
 * Admin partial: Connections tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix, not user input.


// Clear any revocation flags — the user is here to fix it, no need to keep
// showing the sitewide banner once they've arrived on this tab.
foreach ( array( 'gsc', 'gbp' ) as $_rs_service ) {
	if ( get_option( 'ratesight_' . $_rs_service . '_revoked' ) ) {
		delete_option( 'ratesight_' . $_rs_service . '_revoked' );
	}
}
unset( $_rs_service );

// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! empty( $_GET['rs_oauth_success'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p><strong>Account connected successfully.</strong> Select and lock your location or property below.</p></div>';
}
if ( ! empty( $_GET['rs_oauth_error'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<div class="notice notice-error is-dismissible"><p><strong>Connection failed:</strong> ' . esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['rs_oauth_error'] ) ) ) ) . '</p></div>';
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$o = Ratesight_Options::get_all();

$gbp_connected = Ratesight_OAuth_Client::is_connected( 'gbp' );
$gbp_locked    = Ratesight_GBP_Client::is_locked();
$gbp_data      = Ratesight_OAuth_Client::get_stored_data( 'gbp' );
$gbp_selection = Ratesight_GBP_Client::get_selection();

$gsc_connected = Ratesight_OAuth_Client::is_connected( 'gsc' );
$gsc_locked    = Ratesight_GSC_Client::is_locked();
$gsc_data      = Ratesight_OAuth_Client::get_stored_data( 'gsc' );
$gsc_selection = Ratesight_GSC_Client::get_selection();

if ( ! Ratesight_OAuth_Client::credentials_configured() ) :
?>
<div class="notice notice-error inline" style="margin:0 0 16px;">
	<p><strong>This site isn't authenticated yet.</strong> Make sure your <strong>Ratesight ID</strong> is set on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=widgets' ) ); ?>">Widgets tab</a> — the site key is provisioned automatically once it is (your license must be active). Until then, Search Console and Business Profile can't connect or refresh.</p>
</div>
<?php return; endif;

// Widget ID warning
if ( empty( Ratesight_Options::get( 'code_id' ) ) ) : ?>
<div class="notice notice-warning inline" style="margin:0 0 16px;">
	<p>⚠️ <strong>Ratesight ID not set.</strong> <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=widgets' ) ); ?>">Enter it on the Widgets tab</a> to enable the review widgets on this site.</p>
</div>
<?php endif; ?>

<!-- ── Search Console ────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Google Search Console</h2>
<div class="rs-card" id="rs-gsc-card">
<div class="rs-card-body">

<?php if ( ! $gsc_connected ) : ?>

	<p style="font-size:13px;color:#646970;margin:8px 0 16px;">
		Connect the Google account that has access to your Search Console properties. Performance data (impressions, clicks) will be pulled daily.
	</p>
	<a href="<?php echo esc_url( Ratesight_OAuth_Client::get_auth_url( 'gsc' ) ); ?>"
	   class="button button-primary">
		Connect Google Account (GSC)
	</a>

<?php elseif ( $gsc_locked ) : ?>

	<table class="form-table" role="presentation">
		<tr>
			<th>Connected Account</th>
			<td><span class="rs-connected-dot"></span> <?php echo esc_html( $gsc_data['email'] ?? 'Unknown' ); ?>&nbsp;&nbsp;<button type="button" class="button button-small rs-btn-danger rs-quick-disconnect" data-service="gsc" style="margin-left:8px;">Disconnect</button></td>
		</tr>
		<tr>
			<th>Active Property</th>
			<td><strong><?php echo esc_html( $gsc_selection['url'] ?? '—' ); ?></strong></td>
		</tr>
		<tr>
			<th>Last Sync</th>
			<td><?php
				wp_cache_delete( 'ratesight_gsc_last_sync', 'options' );
				$last_sync_val = get_option( 'ratesight_gsc_last_sync', '' );
				echo esc_html( $last_sync_val ?: 'Never' );
			?>
				&nbsp;<button type="button" class="button button-small" id="rs-sync-gsc-now">Sync Now</button>
				<span id="rs-sync-feedback" style="margin-left:8px;font-size:13px;display:none;"></span>
			</td>
		</tr>
		<tr>
			<th>Disconnect</th>
			<td>
				<div class="rs-disconnect-row" id="rs-gsc-disconnect-row">
					<input type="text" class="regular-text rs-disconnect-input" placeholder="Type DISCONNECT to confirm" id="rs-gsc-disconnect-input">
					<button type="button" class="button rs-btn-danger" id="rs-gsc-disconnect-btn" data-service="gsc">Disconnect</button>
				</div>
			</td>
		</tr>
	</table>

<?php else : ?>

	<table class="form-table" role="presentation">
		<tr>
			<th>Connected Account</th>
			<td><span class="rs-connected-dot"></span> <?php echo esc_html( $gsc_data['email'] ?? 'Unknown' ); ?>&nbsp;&nbsp;<button type="button" class="button button-small rs-btn-danger rs-quick-disconnect" data-service="gsc" style="margin-left:8px;">Disconnect</button></td>
		</tr>
		<tr>
			<th>Select Property</th>
			<td>
				<button type="button" class="button" id="rs-load-gsc-properties">Load Properties</button>
				<span id="rs-gsc-load-feedback" style="margin-left:8px;font-size:13px;color:#646970;display:none;">Loading…</span>
				<div id="rs-gsc-property-picker" style="margin-top:10px;display:none;">
					<input type="text" id="rs-gsc-filter" class="regular-text" placeholder="Filter properties…" style="margin-bottom:6px;width:100%;max-width:400px;">
					<div>
					<select id="rs-gsc-property-select" class="regular-text" style="min-width:350px;max-width:100%;">
						<option value="">— Select a property —</option>
					</select>
					<button type="button" class="button button-primary" id="rs-lock-gsc-btn" style="margin-left:6px;">
						Lock This Property
					</button>
					</div>
				</div>
				<p class="description">Once locked, this cannot be changed without disconnecting. Performance data will be pulled for posts on this property.</p>
			</td>
		</tr>
	</table>

<?php endif; ?>

</div>
</div>

<h2 class="rs-section">Google Business Profile</h2>
<div class="rs-card" id="rs-gbp-card">
<div class="rs-card-body">

<?php if ( ! $gbp_connected ) : ?>

	<p style="font-size:13px;color:#646970;margin:8px 0 16px;">
		Connect the Google account that manages your GBP locations. After connecting, select and lock the location for this site.
	</p>
	<a href="<?php echo esc_url( Ratesight_OAuth_Client::get_auth_url( 'gbp' ) ); ?>"
	   class="button button-primary">
		Connect Google Account (GBP)
	</a>

<?php elseif ( $gbp_locked ) : ?>

	<table class="form-table" role="presentation">
		<tr>
			<th>Connected Account</th>
			<td><span class="rs-connected-dot"></span> <?php echo esc_html( $gbp_data['email'] ?? 'Unknown' ); ?>&nbsp;&nbsp;<button type="button" class="button button-small rs-btn-danger rs-quick-disconnect" data-service="gbp" style="margin-left:8px;">Disconnect</button></td>
		</tr>
		<tr>
			<th>Active Location</th>
			<td>
				<strong><?php echo esc_html( $gbp_selection['label'] ?? $gbp_selection['id'] ?? '—' ); ?></strong>
				<?php
				$loc_id = isset( $gbp_selection['id'] ) ? basename( str_replace( 'locations/', '', $gbp_selection['id'] ) ) : '';
				if ( $loc_id ) : ?>
					<br><span style="font-size:12px;color:#787c82;">ID: <?php echo esc_html( $loc_id ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th>Last Sync</th>
			<td><?php
				$gbp_last_sync = get_option( 'ratesight_gbp_performance_last_sync', '' );
				echo esc_html( $gbp_last_sync ?: 'Never' );
			?>
				&nbsp;<button type="button" class="button button-small" id="rs-sync-gbp-conn-now">Sync Now</button>
				<span id="rs-sync-gbp-conn-feedback" style="margin-left:8px;font-size:13px;display:none;"></span>
			</td>
		</tr>
		<tr>
			<th>Disconnect</th>
			<td>
				<div class="rs-disconnect-row" id="rs-gbp-disconnect-row">
					<input type="text" class="regular-text rs-disconnect-input" placeholder="Type DISCONNECT to confirm" id="rs-gbp-disconnect-input">
					<button type="button" class="button rs-btn-danger" id="rs-gbp-disconnect-btn" data-service="gbp">Disconnect</button>
				</div>
				<p class="description">This cannot be undone without re-authenticating.</p>
			</td>
		</tr>
	</table>

<?php else : ?>

	<table class="form-table" role="presentation">
		<tr>
			<th>Connected Account</th>
			<td><span class="rs-connected-dot"></span> <?php echo esc_html( $gbp_data['email'] ?? 'Unknown' ); ?>&nbsp;&nbsp;<button type="button" class="button button-small rs-btn-danger rs-quick-disconnect" data-service="gbp" style="margin-left:8px;">Disconnect</button></td>
		</tr>
		<tr>
			<th>Disconnect</th>
			<td>
				<div class="rs-disconnect-row" id="rs-gbp-disconnect-row">
					<input type="text" class="regular-text rs-disconnect-input" placeholder="Type DISCONNECT to confirm" id="rs-gbp-disconnect-input">
					<button type="button" class="button rs-btn-danger" id="rs-gbp-disconnect-btn" data-service="gbp">Disconnect</button>
				</div>
				<p class="description">Switch to a different Google account by disconnecting first.</p>
			</td>
		</tr>
		<tr>
			<th>Select Location</th>
			<td>
				<button type="button" class="button" id="rs-load-gbp-locations">Load Locations</button>
				<span id="rs-gbp-load-feedback" style="margin-left:8px;font-size:13px;color:#646970;display:none;">Loading…</span>
				<div id="rs-gbp-location-picker" style="margin-top:10px;display:none;">
					<input type="text" id="rs-gbp-filter" class="regular-text" placeholder="Filter locations…" style="margin-bottom:6px;width:100%;max-width:400px;display:block;">
					<select id="rs-gbp-location-select" class="regular-text" style="min-width:350px;max-width:100%;">
						<option value="">— Select a location —</option>
					</select>
					<button type="button" class="button button-primary" id="rs-lock-gbp-btn" style="margin-left:6px;">
						Lock This Location
					</button>
				</div>
				<p class="description">Once locked, this cannot be changed without disconnecting.</p>
			</td>
		</tr>
	</table>

<?php endif; ?>

</div>
</div>

<!-- ── GBP Post Settings ─────────────────────────────────────────────────── -->
<h2 class="rs-section">GBP Post Settings</h2>
<div class="rs-card">
<div class="rs-card-body">
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_connections' ); ?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">CTA Button</th>
		<td>
			<select name="ratesight_gbp_cta_type">
				<?php
				$ctas = array( 'LEARN_MORE' => 'Learn More', 'BOOK' => 'Book', 'ORDER' => 'Order Online', 'SHOP' => 'Shop', 'SIGN_UP' => 'Sign Up', 'CALL' => 'Call Now' );
				foreach ( $ctas as $val => $lbl ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $o['gbp_cta_type'], $val, false ), esc_html( $lbl ) );
				endforeach;
				?>
			</select>
			<p class="description">Call-to-action button shown on GBP "What's New" posts.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Post to GBP</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_gbp_post_enabled" value="1" <?php checked( 1, $o['gbp_post_enabled'] ); ?>>
				Automatically create a GBP "What's New" post when a blog post is published
			</label>
			<p class="description">Requires a connected and locked GBP location. Uncheck to publish posts without pushing to Google Business Profile.</p>
		</td>
	</tr>
</table>
<div class="rs-submit"><?php submit_button( 'Save', 'primary', 'submit', false ); ?></div>
</form>
</div>
</div>

<h2 class="rs-section">Bing Webmaster Tools</h2>
<div class="rs-card">
<div class="rs-card-body">
<?php
$bing_key      = Ratesight_Bing_Client::get_api_key();
$bing_site     = Ratesight_Bing_Client::get_site_url();
$bing_last_sync = get_option( 'ratesight_bing_last_sync', '' );
?>
<p class="description" style="margin-bottom:16px;">
	Connect Bing Webmaster Tools to pull impressions, clicks, and keyword rankings from Bing search.
	Get your API key from <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a>
	→ Settings → API Access → Generate API Key.
</p>

<table class="form-table rs-connections-table" style="max-width:640px;">
	<tr>
		<th>API Key</th>
		<td>
			<input type="password" id="rs-bing-api-key" class="regular-text"
				placeholder="Paste your Bing Webmaster API key"
				value="<?php echo esc_attr( $bing_key ); ?>">
			<button type="button" class="button" id="rs-save-bing-key" style="margin-left:6px;">
				<?php echo $bing_key ? 'Update Key' : 'Save Key'; ?>
			</button>
			<span id="rs-bing-key-feedback" style="margin-left:8px;font-size:13px;color:#646970;display:none;"></span>
		</td>
	</tr>
	<?php if ( $bing_key ) : ?>
	<tr>
		<th>Site</th>
		<td>
			<?php if ( $bing_site ) : ?>
				<strong><?php echo esc_html( $bing_site ); ?></strong>
				<span style="color:#00a32a;margin-left:8px;">✓ Locked</span>
			<?php else : ?>
				<button type="button" class="button" id="rs-load-bing-sites">Load Sites</button>
				<span id="rs-bing-sites-feedback" style="margin-left:8px;font-size:13px;color:#646970;display:none;">Loading…</span>
				<div id="rs-bing-site-picker" style="margin-top:10px;display:none;">
					<select id="rs-bing-site-select" class="regular-text" style="min-width:350px;">
						<option value="">— Select a site —</option>
					</select>
					<button type="button" class="button button-primary" id="rs-lock-bing-btn" style="margin-left:6px;">
						Lock Site
					</button>
				</div>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ( $bing_site ) : ?>
	<tr>
		<th>Last Sync</th>
		<td>
			<?php echo esc_html( $bing_last_sync ?: 'Never' ); ?>
			&nbsp;<button type="button" class="button button-small" id="rs-sync-bing-now">Sync Now</button>
			<span id="rs-sync-bing-feedback" style="margin-left:8px;font-size:13px;display:none;"></span>
		</td>
	</tr>
	<?php endif; ?>
	<?php endif; ?>
</table>
</div>
</div>

<!-- ── DeepSeek AI ────────────────────────────────────────────────────────── -->
<h2 class="rs-section">DeepSeek AI</h2>
<div class="rs-card">
<div class="rs-card-body">
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_connections' ); ?>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="ratesight_deepseek_api_key">API Key</label></th>
		<td>
			<div class="rs-url-box">
				<input type="password" id="ratesight_deepseek_api_key" name="ratesight_deepseek_api_key"
					class="regular-text" autocomplete="new-password"
					value="<?php echo esc_attr( get_option( 'ratesight_deepseek_api_key', '' ) ); ?>"
					placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxx">
				<button type="button" class="button" id="rs-toggle-deepseek-key" title="Show/hide">👁</button>
			</div>
			<?php
			$has_key = trim( (string) get_option( 'ratesight_deepseek_api_key', '' ) ) !== '';
			if ( $has_key ) : ?>
				<p class="description" style="color:#00a32a;">&#10003; DeepSeek API key saved — AI features will call DeepSeek directly from the plugin.</p>
			<?php else : ?>
				<p class="description">AI calls route through the Ratesight Cloudflare Worker using the <code>DEEPSEEK_API</code> secret you added there. Leave this blank unless you want the plugin to call DeepSeek directly instead.</p>
			<?php endif; ?>
		</td>
	</tr>
</table>
<div class="rs-submit" style="padding-top:0;"><?php submit_button( 'Save', 'primary', 'submit', false ); ?></div>
</form>
</div>
</div>

<!-- ── Site Status (auto-loaded) ──────────────────────────────────────────── -->
<h2 class="rs-section">Site Status</h2>
<div class="rs-card">
<div class="rs-card-body">
	<div id="rs-site-status-wrap">
		<p style="font-size:13px;color:#646970;">
			<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>
			Checking status…
		</p>
	</div>
</div>

</div>

<!-- ── IndexNow ───────────────────────────────────────────────────────────── -->
<h2 class="rs-section">IndexNow</h2>
<div class="rs-card">
<div class="rs-card-body">
	<p class="description" style="margin:0 0 12px;">IndexNow instantly notifies Bing, Yandex, and other search engines when a page is published. Submitted automatically after every publish.</p>
	<div id="rs-indexnow-status-wrap" style="font-size:13px;color:#646970;">
		<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>
		Checking…
	</div>
</div>
</div>

<?php
$indexnow_log = Ratesight_IndexNow::get_log();
if ( ! empty( $indexnow_log ) ) : ?>
<h2 class="rs-section" style="margin-top:16px;">IndexNow Log
	<button type="button" id="rs-clear-indexnow-log" class="button button-small" style="margin-left:10px;font-size:12px;">Clear</button>
</h2>
<div class="rs-card">
<div class="rs-card-body" style="padding:0;">
<table class="wp-list-table widefat fixed striped" style="margin:0;">
	<thead>
		<tr>
			<th style="width:160px;">Time</th>
			<th style="width:70px;">Status</th>
			<th>URLs</th>
			<th style="width:160px;">Response</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $indexnow_log as $entry ) : ?>
		<tr>
			<td style="font-size:12px;color:#646970;"><?php echo esc_html( $entry['time'] ); ?></td>
			<td>
				<?php if ( $entry['success'] ) : ?>
					<span style="color:#00a32a;font-weight:600;">✓ OK</span>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600;">✗ Fail</span>
				<?php endif; ?>
			</td>
			<td style="font-size:12px;">
				<?php
				$urls = $entry['urls'] ?? array();
				if ( count( $urls ) === 1 ) {
					echo '<a href="' . esc_url( $urls[0] ) . '" target="_blank" style="word-break:break-all;">' . esc_html( $urls[0] ) . '</a>';
				} else {
					echo esc_html( count( $urls ) ) . ' URLs';
				}
				?>
			</td>
			<td style="font-size:12px;color:#646970;"><?php echo esc_html( $entry['note'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
</div>
<?php endif; ?>

<script>
jQuery( function( $ ) {
	$.post( ajaxurl, { action: 'ratesight_connections_status', nonce: '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>' } )
		.done( function ( r ) {
			if ( ! r.success ) return;
			var d   = r.data;
			var yes = '<span style="color:#00a32a;font-weight:600;">✅</span>';
			var no  = '<span style="color:#d63638;font-weight:600;">❌</span>';
			var warn = '<span style="color:#7a5800;font-weight:600;">⚠️</span>';

			// Site status panel
			var rows = [
				[ 'Sitemap accessible', d.sitemap_live ? yes + ' Yes' : no + ' Not found — make sure an SEO plugin is generating your sitemap.xml' ],
				[ 'Visible to search engines', d.blog_public ? yes + ' Yes' : warn + ' No — <a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>">update Reading settings</a>' ],
				[ 'Ratesight ID', d.widget_id_set ? yes + ' Configured' : no + ' Missing — <a href="<?php echo esc_url( admin_url( 'admin.php?page=ratesight&tab=widgets' ) ); ?>">enter on Widgets tab</a>' ],
			];

			var table = '<table class="wp-list-table widefat fixed" style="max-width:560px;">' +
				'<tbody>' +
				rows.map( function(row) {
					return '<tr><td style="font-weight:600;width:200px;">' + row[0] + '</td><td>' + row[1] + '</td></tr>';
				} ).join('') +
				'</tbody></table>';

			$( '#rs-site-status-wrap' ).html( table );

			// IndexNow status
			var inStatus = d.indexnow_ok
				? yes + ' Key verified — <code style="font-size:11px;">' + $( '<div>' ).text( d.indexnow_url || '' ).html() + '</code>'
				: no + ' Key not reachable — the plugin serves it automatically, try reloading this page.';

			$( '#rs-indexnow-status-wrap' ).html( inStatus );
		} );
} );
</script>


<?php
// ── System Health ──────────────────────────────────────────────────────────
$health_cron_hooks = array(
	'ratesight_sync_gsc'             => 'GSC daily sync',
	'ratesight_sync_gbp_performance' => 'GBP daily sync',
	'ratesight_sync_bing'            => 'Bing daily sync',
	'ratesight_prune_logs'           => 'Log pruning',
	'ratesight_retry_pending'        => 'Retry pending',
	'ratesight_check_broken_links'   => 'Broken link checker',
	'ratesight_daily_digest'         => 'Email digest',
);
$cron_issues = array();
foreach ( $health_cron_hooks as $hook => $label ) {
	$next = wp_next_scheduled( $hook );
	if ( ! $next ) {
		$cron_issues[] = $label . ' (not scheduled)';
	} elseif ( $next < time() - HOUR_IN_SECONDS ) {
		$cron_issues[] = $label . ' (overdue by ' . human_time_diff( $next ) . ')';
	}
}
$sync_status = array();
foreach ( array( 'gsc' => 'GSC', 'gbp' => 'GBP', 'bing' => 'Bing' ) as $k => $label ) {
	$last = get_option( "ratesight_{$k}_last_sync", '' );
	$age  = $last ? time() - strtotime( $last ) : null;
	$sync_status[ $label ] = array( 'last' => $last ?: 'Never', 'ok' => $age !== null ? $age < 2 * DAY_IN_SECONDS : null );
}
$last_link_scan = get_option( 'ratesight_link_last_scan', '' );
$scan_age_ok    = $last_link_scan ? ( time() - strtotime( $last_link_scan ) < 7 * DAY_IN_SECONDS ) : null;
$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
global $wpdb;
$log_table      = $wpdb->prefix . RATESIGHT_LOG_TABLE;
$recent_fails   = (int) $wpdb->get_var(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	"SELECT COUNT(*) FROM `{$log_table}` WHERE status = 'failed' AND received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
if ( isset( $_GET['rs_reschedule'] ) && current_user_can( 'manage_options' ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	Ratesight_Activator::maybe_upgrade();
	echo '<div class="notice notice-success is-dismissible inline"><p>Cron events rescheduled.</p></div>';
}
?>
<h2 class="rs-section" style="margin-top:16px;">System Health</h2>
<div class="rs-card"><div class="rs-card-body">
<table class="form-table" role="presentation" style="margin:0;">
<tr>
	<th style="width:180px;">AI Worker</th>
	<td>
		<button type="button" class="button button-small" id="rs-test-ai-worker">Test Connection</button>
		<span id="rs-ai-worker-result" style="margin-left:10px;font-size:13px;display:none;"></span>
		<p class="description" style="margin-top:4px;">Pings the Ratesight worker to confirm DeepSeek is reachable. Run this if link suggestions show "AI unavailable".</p>
	</td>
</tr>
<?php
$health         = Ratesight_Redirect_Health::get_last_result();
$health_checked = $health['checked_at'] ?? null;
$health_flagged = (int) ( $health['flagged'] ?? 0 );
?>
<tr>
	<th>Redirect Health</th>
	<td>
		<?php if ( $health_checked ) : ?>
			<?php if ( $health_flagged > 0 ) : ?>
				<span style="color:#d63638;">&#9888; <?php echo esc_html( $health_flagged ); ?> ranking page<?php echo $health_flagged === 1 ? '' : 's'; ?> soft-404ing — check your email.</span>
			<?php else : ?>
				<span style="color:#00a32a;">&#10003; All ranking pages returning correctly.</span>
			<?php endif; ?>
			<small style="color:#787c82;display:block;margin-top:2px;">Last checked: <?php echo esc_html( $health_checked ); ?> &middot; <?php echo esc_html( $health['total'] ?? 0 ); ?> pages checked</small>
		<?php else : ?>
			<span style="color:#646970;">Not yet run — fires daily.</span>
		<?php endif; ?>
		<p class="description" style="margin-top:4px;">Checks all pages with GSC impressions daily. Emails if any are soft-404ing or redirecting to catch-all targets.</p>
	</td>
</tr>
<tr>
	<th style="width:180px;">WP-Cron</th>
	<td><?php
	if ( $cron_disabled ) {
		echo '<span style="color:#856404;">⚠ DISABLE_WP_CRON is set — cron tasks won\'t fire without an external trigger (WP-CLI or server cron).</span>';
	} elseif ( empty( $cron_issues ) ) {
		echo '<span style="color:#00a32a;">✓ All cron events scheduled</span>';
	} else {
		echo '<span style="color:#d63638;">✗ Issues: ' . esc_html( implode( '; ', $cron_issues ) ) . '</span> &nbsp;';
		echo '<a href="' . esc_url( add_query_arg( 'rs_reschedule', '1' ) ) . '" class="button button-small">Reschedule All</a>';
	}
	?></td>
</tr>
<tr>
	<th>Last Syncs</th>
	<td><?php foreach ( $sync_status as $label => $info ) :
		$icon = $info['ok'] === true ? '<span style="color:#00a32a;">✓</span>' : ( $info['ok'] === false ? '<span style="color:#d63638;">✗</span>' : '<span style="color:#aaa;">—</span>' );
		echo wp_kses_post( $icon ) . ' <strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $info['last'] ) . ' &nbsp;&nbsp; ';
	endforeach; ?></td>
</tr>
<tr>
	<th>Link Scan</th>
	<td><?php
	if ( $scan_age_ok === true )       echo '<span style="color:#00a32a;">✓</span> Last scanned: ' . esc_html( $last_link_scan );
	elseif ( $scan_age_ok === false )  echo '<span style="color:#856404;">⚠</span> Last scanned: ' . esc_html( $last_link_scan ) . ' — consider re-scanning';
	else                               echo '<span style="color:#646970;">Never scanned — go to the Links tab and click <strong>Scan All Pages</strong></span>';
	?></td>
</tr>
<tr>
	<th>Webhook Failures (24h)</th>
	<td><?php
	if ( $recent_fails === 0 ) {
		echo '<span style="color:#00a32a;">✓ None</span>';
	} else {
		echo '<span style="color:#d63638;">✗ ' . (int) $recent_fails . ' failed</span> &nbsp;';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=ratesight&tab=logs&rs_status=failed' ) ) . '">View in Activity Log →</a>';
	}
	?></td>
</tr>
</table>
</div></div>
