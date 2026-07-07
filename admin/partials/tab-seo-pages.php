<?php
/**
 * Settings tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

settings_errors();
$o   = Ratesight_Options::get_all();
$url        = rest_url( 'ratesight/v1/create-page' );
$update_url = rest_url( 'ratesight/v1/update-page' );

// Generate a secret on first view so it's always ready.
$webhook_secret = get_option( 'ratesight_webhook_secret', '' );
?>
<form method="post" action="options.php">
<?php settings_fields( 'ratesight_options_seo_pages' ); ?>

<h2 class="rs-section">Webhook</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Endpoint URLs</th>
		<td>
			<p style="margin:0 0 4px;font-size:12px;color:#646970;">Create / update by slug:</p>
			<div class="rs-url-box" style="margin-bottom:8px;">
				<input type="text" value="<?php echo esc_attr( $url ); ?>" readonly>
				<button type="button" class="button rs-btn-copy" data-copy="<?php echo esc_attr( $url ); ?>">Copy</button>
			</div>
			<p style="margin:0 0 4px;font-size:12px;color:#646970;">Replace content on existing page by URL — add <code>"url": "https://…"</code> to payload:</p>
			<div class="rs-url-box">
				<input type="text" value="<?php echo esc_attr( $update_url ); ?>" readonly>
				<button type="button" class="button rs-btn-copy" data-copy="<?php echo esc_attr( $update_url ); ?>">Copy</button>
			</div>
			<p class="description" style="margin-top:6px;">Both endpoints share the same auth. For update-page: <strong>GET first</strong> to read page state, page builder type, and content format — then POST with correctly-formatted replacement content.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Webhook Secret</th>
		<td>
			<?php if ( $webhook_secret ) : ?>
			<div class="rs-url-box">
				<input type="text" id="rs-webhook-secret" value="<?php echo esc_attr( $webhook_secret ); ?>" readonly style="font-family:monospace;">
				<button type="button" class="button rs-btn-copy" data-copy="<?php echo esc_attr( $webhook_secret ); ?>">Copy</button>
			</div>
			<button type="button" class="button button-small" id="rs-regen-secret" style="margin-top:6px;">Regenerate</button>
			<p class="description">
				Include header <code>X-Ratesight-Signature: sha256=&lt;hex&gt;</code> where the value is
				<code>HMAC-SHA256(raw_body, secret)</code>. Requests without this header are still accepted.
			</p>
			<?php else : ?>
			<button type="button" class="button" id="rs-regen-secret">Generate Secret</button>
			<span id="rs-webhook-secret-wrap" style="display:none;margin-top:6px;">
				<div class="rs-url-box">
					<input type="text" id="rs-webhook-secret" value="" readonly style="font-family:monospace;">
					<button type="button" class="button rs-btn-copy" data-copy="">Copy</button>
				</div>
			</span>
			<p class="description">Optional. Generate a secret to enable HMAC signature verification on incoming webhooks. Requests without the signature header are always accepted.</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row">Test Connection</th>
		<td>
			<button type="button" id="rs-send-test" class="button button-secondary">Send Test Request</button>
			<span id="rs-test-feedback" class="rs-feedback" style="display:none;"></span>
			<p class="description">Creates a draft test post via loopback. Check Activity Log for the result.</p>
		</td>
	</tr>
</table>
</div>
</div>

<h2 class="rs-section">Post Defaults</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Parent Category</th>
		<td>
			<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
				<?php wp_dropdown_categories( array(
					'id'                => 'ratesight_parent_category',
					'name'              => 'ratesight_parent_category',
					'selected'          => (int) $o['parent_category'],
					'show_option_none'  => '— None (use Uncategorized) —',
					'option_none_value' => '0',
					'hierarchical'      => true,
					'hide_empty'        => false,
				) ); ?>
				<button type="button" class="button button-small rs-add-cat-btn" data-target="ratesight_parent_category" data-taxonomy="category">+ Add Category</button>
			</div>
			<div class="rs-add-cat-form" data-for="ratesight_parent_category" style="display:none;margin-top:8px;display:none;">
				<input type="text" class="regular-text rs-new-cat-name" placeholder="New category name" style="width:200px;">
				<button type="button" class="button rs-save-cat-btn" data-target="ratesight_parent_category" data-taxonomy="category" style="margin-left:4px;">Add</button>
				<button type="button" class="button rs-cancel-cat-btn" data-target="ratesight_parent_category" style="margin-left:4px;">Cancel</button>
				<span class="rs-add-cat-feedback" style="margin-left:8px;font-size:13px;"></span>
			</div>
			<p class="description" style="margin-top:6px;">For <strong>blog posts</strong> — webhook child categories are nested under this parent. Example: set "Moving Services" here and send <code>child_category: "Long Distance"</code> → creates <em>Moving Services → Long Distance</em>.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">RS Page Parent Category</th>
		<td>
			<?php
			$rs_cats = get_terms( array( 'taxonomy' => 'rs_category', 'hide_empty' => false ) );
			?>
			<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
				<select id="ratesight_rs_page_parent_cat" name="ratesight_rs_page_parent_cat">
					<option value="0">— None —</option>
					<?php if ( ! is_wp_error( $rs_cats ) ) foreach ( $rs_cats as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>" <?php selected( (int) $o['rs_page_parent_category'], $t->term_id ); ?>>
							<?php echo esc_html( $t->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-small rs-add-cat-btn" data-target="ratesight_rs_page_parent_cat" data-taxonomy="rs_category">+ Add RS Category</button>
			</div>
			<div class="rs-add-cat-form" data-for="ratesight_rs_page_parent_cat" style="display:none;margin-top:8px;">
				<input type="text" class="regular-text rs-new-cat-name" placeholder="New RS category name" style="width:200px;">
				<button type="button" class="button rs-save-cat-btn" data-target="ratesight_rs_page_parent_cat" data-taxonomy="rs_category" style="margin-left:4px;">Add</button>
				<button type="button" class="button rs-cancel-cat-btn" data-target="ratesight_rs_page_parent_cat" style="margin-left:4px;">Cancel</button>
				<span class="rs-add-cat-feedback" style="margin-left:8px;font-size:13px;"></span>
			</div>
			<p class="description" style="margin-top:6px;">For <strong>RS Pages</strong> (AI SEO CPT) — completely separate from blog categories. Send <code>child_category</code> in the webhook to nest under this parent. Manage all RS Categories under <strong>RS Pages → RS Categories</strong>.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-page-base">RS Page Base Slug</label></th>
		<td>
			<?php
			$current_base = esc_attr( $o['rs_page_base'] ?? '' );
			$saved_base   = get_option( 'ratesight_rs_page_base', '' );
			?>
			<input type="text" id="rs-page-base" name="ratesight_rs_page_base"
				class="regular-text" placeholder="e.g. services (leave blank for root-level)"
				value="<?php echo esc_attr( $current_base ); ?>">
			<p class="description">
				RS Pages appear at <code>/<?php echo esc_html( $current_base ? $current_base . '/' : '' ); ?><em>slug</em>/</code>.
				Leave blank to keep pages at root level (e.g. <code>/office-movers-daly-city-ca/</code>).
			</p>
			<?php if ( $saved_base !== '' ) : ?>
			<p class="description" style="color:#d63638;margin-top:4px;">
				⚠️ <strong>Changing this renames all existing RS Page URLs</strong> — existing links and search rankings will break unless you set up redirects.
			</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-status">Final Post Status</label></th>
		<td>
			<select id="rs-status" name="ratesight_post_status">
				<?php foreach ( array( 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending Review', 'private' => 'Private' ) as $v => $l ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $o['post_status'], $v, false ), esc_html( $l ) );
				endforeach; ?>
			</select>
			<p class="description">Posts are created as draft first, promoted after image attaches (~15 seconds).</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-page-status">Reference Page Status</label></th>
		<td>
			<select id="rs-page-status" name="ratesight_page_status">
				<?php foreach ( array( 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending Review', 'private' => 'Private' ) as $v => $l ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $o['page_status'], $v, false ), esc_html( $l ) );
				endforeach; ?>
			</select>
			<p class="description">Final status for reference pages (RS Pages CPT) created via webhook. Defaults to Published.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-default-layout">Default Post Layout</label></th>
		<td>
			<select id="rs-default-layout" name="ratesight_default_layout">
				<?php foreach ( array( 'full-width' => 'Full Width', 'right-sidebar' => 'Right Sidebar', 'left-sidebar' => 'Left Sidebar' ) as $v => $l ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $o['default_layout'], $v, false ), esc_html( $l ) );
				endforeach; ?>
			</select>
			<p class="description">Default layout for blog posts. Can be overridden per-request with the <code>layout</code> field.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-default-page-layout">Default Page Layout</label></th>
		<td>
			<select id="rs-default-page-layout" name="ratesight_default_page_layout">
				<?php foreach ( array( 'full-width' => 'Full Width', 'right-sidebar' => 'Right Sidebar', 'left-sidebar' => 'Left Sidebar' ) as $v => $l ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $o['default_page_layout'], $v, false ), esc_html( $l ) );
				endforeach; ?>
			</select>
			<p class="description">Default layout for pages. Can be overridden per-request with the <code>layout</code> field.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Show Title</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_default_show_title" value="1" <?php checked( 1, $o['default_show_title'] ); ?>>
				Show the post/page title by default
			</label>
			<p class="description">Can be overridden per-request with the <code>show_title</code> field.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Post Author</th>
		<td>
			<?php wp_dropdown_users( array(
				'name'     => 'ratesight_post_author',
				'selected' => (int) $o['post_author'],
				'who'      => 'authors',
			) ); ?>
		</td>
	</tr>
</table>
</div>
</div>

<h2 class="rs-section">Activity Log</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="rs-retention">Log Retention</label></th>
		<td>
			<input type="number" id="rs-retention" name="ratesight_log_retention"
				class="small-text" value="<?php echo esc_attr( $o['log_retention_days'] ); ?>" min="1" max="365">
			days
			<p class="description">Activity log entries older than this are deleted automatically each day.</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="rs-perf-retention">Performance Data Retention</label></th>
		<td>
			<select id="rs-perf-retention" name="ratesight_performance_retention_days">
				<?php
				$options = array(
					90   => '3 months',
					180  => '6 months',
					365  => '1 year',
					548  => '18 months (recommended)',
					730  => '2 years',
					1095 => '3 years',
				);
				$current = (int) $o['performance_retention_days'];
				if ( ! array_key_exists( $current, $options ) ) {
					$current = 548; // fallback to default
				}
				foreach ( $options as $val => $label ) :
					printf(
						'<option value="%d" %s>%s</option>',
						(int) $val,
						esc_attr( selected( $current, $val, false ) ),
						esc_html( $label )
					);
				endforeach;
				?>
			</select>
			<p class="description">GSC rankings and keyword data older than this are pruned daily. Minimum is always 90 days to preserve trend data.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Store Raw Payload</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_store_payload" value="1" <?php checked( 1, $o['store_raw_payload'] ); ?>>
				Save the full JSON request body with each log entry
			</label>
			<p class="description">Off by default. Adds ~50–200 MB/day at your volume. Required for retry.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">404 Fuzzy Router Mode</th>
		<td>
			<select name="ratesight_fuzzy_mode">
				<option value="legacy" <?php selected( 'legacy', $o['fuzzy_mode'] ); ?>>Legacy — unconstrained slug similarity (default)</option>
				<option value="same-city-or-hub" <?php selected( 'same-city-or-hub', $o['fuzzy_mode'] ); ?>>Same-city or hub — never redirect one city's URL to another city's page</option>
				<option value="off" <?php selected( 'off', $o['fuzzy_mode'] ); ?>>Off — no fuzzy 404 redirects</option>
			</select>
			<p class="description">Constrains the runtime 404 smart-router. "Same-city or hub" blocks cross-city fuzzy matches (e.g. a San Bruno URL landing on a San Ramon page) and falls back to the base service hub for commercial/office city pages. Explicit redirects are never affected.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Error Logging</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_log_errors_to_wp" value="1" <?php checked( 1, $o['log_errors_to_wp'] ); ?>>
				Write failed webhook errors to the PHP / WP_DEBUG_LOG error log
			</label>
			<p class="description">Mirrors every <strong>Failed</strong> activity log entry to your server error log for external monitoring.</p>
		</td>
	</tr>
</table>
</div>
</div>

<h2 class="rs-section">Notifications</h2>
<div class="rs-card">
<div class="rs-card-body">
<table class="form-table" role="presentation">
	<tr>
		<th scope="row">Email Notifications</th>
		<td>
			<label>
				<input type="checkbox" name="ratesight_notify_enabled" value="1" <?php checked( 1, get_option( 'ratesight_notify_enabled', 0 ) ); ?>>
				Send a daily digest email
			</label>
			<p class="description">Covers: failed webhooks, stale syncs, OAuth disconnections, and broken links. Only sent when there's something to report.</p>
		</td>
	</tr>
	<tr>
		<th scope="row">Notification Email</th>
		<td>
			<input type="email" name="ratesight_notify_email" class="regular-text"
				value="<?php echo esc_attr( get_option( 'ratesight_notify_email', get_option( 'admin_email', '' ) ) ); ?>"
				placeholder="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>">
			<p class="description">Defaults to the WordPress admin email if left blank.</p>
		</td>
	</tr>
</table>
</div>
</div>

<div class="rs-submit"><?php submit_button( 'Save Settings' ); ?></div>
</form>
