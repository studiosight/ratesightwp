<?php
/**
 * Admin partial: Reference tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$endpoint = rest_url( 'ratesight/v1/create-page' );
?>

<!-- ── Request flow ──────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Request Flow</h2>
<div class="rs-card">
<div class="rs-card-body">
	<p class="description" style="margin:0 0 10px;">The webhook always returns within a second. Image download and publishing happen asynchronously:</p>
	<pre class="rs-code">POST <?php echo esc_html( $endpoint ); ?>

1. Request received  → post/page created as draft, 200 returned immediately
2. ~15 seconds later → image downloaded and attached as featured image
3. Image attached    → promoted to final status from settings
4. Activity Log      → updated: success / warnings / failed</pre>
</div>
</div>

<!-- ── Post fields ───────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Post Fields <span style="font-weight:400;font-size:11px;letter-spacing:0;text-transform:none;color:#787c82;">(post_type: "post" or omitted)</span></h2>
<div class="rs-card">
<div class="rs-card-body">
<pre class="rs-code">{
  "title":               "Best Plumbers in Austin",
  "article":             "&lt;h2&gt;Why Choose Us&lt;/h2&gt;&lt;p&gt;Content here...&lt;/p&gt;",
  "post_type":           "post",
  "slug":                "best-plumbers-austin",
  "child_category":      "Plumbing",
  "parent_category":     "Services",
  "summary":             "Austin's top-rated plumbers since 2005.",
  "meta_title":          "Best Plumbers in Austin, TX",
  "meta_description":    "Looking for a reliable Austin plumber?",
  "featured_image_url":  "https://example.com/photo.jpg",
  "layout":              "full-width",
  "show_title":          true,
  "status":              "publish"
}</pre>

<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
	<thead>
		<tr>
			<th style="width:170px">Field</th>
			<th style="width:80px">Required</th>
			<th>Description</th>
		</tr>
	</thead>
	<tbody>
		<?php
		$post_fields = array(
			array( 'title',              true,  'Post title. Theme renders as H1 — do not include an H1 in article.' ),
			array( 'article',            true,  'HTML content starting at H2. Sanitised with wp_kses_post().' ),
			array( 'post_type',          false, '"post" — this is the default if omitted.' ),
			array( 'slug',               false, 'URL slug. Auto-generated from title if omitted. Must be unique — duplicate slugs return the existing post.' ),
			array( 'child_category',     false, 'Category name. Created under parent_category (if sent) or the parent from plugin settings.' ),
			array( 'parent_category',    false, 'Parent category name. Created automatically if it doesn\'t exist. Overrides the parent category set in plugin settings.' ),
			array( 'summary',            false, 'Post excerpt. Also used as the GBP post body.' ),
			array( 'meta_title',         false, 'SEO title tag. Falls back to title if omitted.' ),
			array( 'meta_description',   false, 'SEO meta description. Falls back to summary if omitted.' ),
			array( 'featured_image_url', false, 'Remote image URL. Downloaded after the 200 is returned. Non-fatal if it fails.' ),
			array( 'layout',             false, 'Override the default layout set in plugin settings. "full-width", "right-sidebar", or "left-sidebar".' ),
			array( 'show_title',         false, 'Override the default show title set in plugin settings. true or false.' ),
			array( 'status',             false, 'Override the final post status for this request. "publish", "draft", "pending", or "private". Falls back to the value set in AI SEO Pages settings.' ),
		);
		foreach ( $post_fields as [ $field, $req, $desc ] ) : ?>
		<tr>
			<td><code><?php echo esc_html( $field ); ?></code></td>
			<td><?php echo $req ? '<strong style="color:#d63638;font-size:11px;">Required</strong>' : '<span style="color:#646970;font-size:11px;">Optional</span>'; ?></td>
			<td style="font-size:13px;"><?php echo esc_html( $desc ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p class="description" style="margin-top:10px;">
	⚠ <code>parent_slug</code> is ignored for posts. Categories handle hierarchy for posts.<br>
	⚠ <code>layout</code> and <code>show_title</code> default to the values set in AI SEO Pages settings — only send these to override per-request.
</p>
</div>
</div>

<!-- ── Page fields ───────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Page Fields <span style="font-weight:400;font-size:11px;letter-spacing:0;text-transform:none;color:#787c82;">(post_type: "page")</span></h2>
<div class="rs-card">
<div class="rs-card-body">
<pre class="rs-code">{
  "title":               "Best Plumbers in Austin",
  "article":             "&lt;h2&gt;Why Choose Us&lt;/h2&gt;&lt;p&gt;Content here...&lt;/p&gt;",
  "post_type":           "page",
  "slug":                "best-plumbers-austin",
  "parent_slug":         "plumbing",
  "summary":             "Austin's top-rated plumbers since 2005.",
  "meta_title":          "Best Plumbers in Austin, TX",
  "meta_description":    "Looking for a reliable Austin plumber?",
  "featured_image_url":  "https://example.com/photo.jpg",
  "layout":              "full-width",
  "show_title":          false,
  "status":              "draft"
}</pre>

<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
	<thead>
		<tr>
			<th style="width:170px">Field</th>
			<th style="width:80px">Required</th>
			<th>Description</th>
		</tr>
	</thead>
	<tbody>
		<?php
		$page_fields = array(
			array( 'title',              true,  'Page title. Theme renders as H1 — do not include an H1 in article.' ),
			array( 'article',            true,  'HTML content starting at H2. Sanitised with wp_kses_post().' ),
			array( 'post_type',          true,  '"page" — must be set explicitly to create a page.' ),
			array( 'slug',               false, 'URL slug. Auto-generated from title if omitted. Must be unique — duplicate slugs return the existing page.' ),
			array( 'parent_slug',        false, 'Slug of the parent RS page. Falls back to root level if not found (warns in log). Also checks native pages as fallback.' ),
			array( 'summary',            false, 'Page excerpt.' ),
			array( 'meta_title',         false, 'SEO title tag. Falls back to title if omitted.' ),
			array( 'meta_description',   false, 'SEO meta description. Falls back to summary if omitted.' ),
			array( 'featured_image_url', false, 'Remote image URL. Downloaded after the 200 is returned. Non-fatal if it fails.' ),
			array( 'layout',             false, 'Override the default layout set in plugin settings. "full-width", "right-sidebar", or "left-sidebar".' ),
			array( 'show_title',         false, 'Override the default show title set in plugin settings. true or false.' ),
			array( 'status',             false, 'Override the final page status for this request. "publish", "draft", "pending", or "private". Falls back to the value set in AI SEO Pages settings.' ),
		);
		foreach ( $page_fields as [ $field, $req, $desc ] ) : ?>
		<tr>
			<td><code><?php echo esc_html( $field ); ?></code></td>
			<td><?php echo $req ? '<strong style="color:#d63638;font-size:11px;">Required</strong>' : '<span style="color:#646970;font-size:11px;">Optional</span>'; ?></td>
			<td style="font-size:13px;"><?php echo esc_html( $desc ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p class="description" style="margin-top:10px;">
	⚠ <code>child_category</code> is ignored for pages. Categories only apply to posts.<br>
	⚠ <code>layout</code> and <code>show_title</code> default to the values set in AI SEO Pages settings — only send these to override per-request.
</p>
</div>
</div>

<!-- ── Responses ─────────────────────────────────────────────────────────────── -->
<h2 class="rs-section">Responses</h2>
<div class="rs-card">
<div class="rs-card-body">
<pre class="rs-code">// 200 — draft created, publishing in ~15 seconds
{ "success": true, "post_id": 123, "post_type": "page", "post_url": "https://site.com/.../", "status": "draft" }

// 409 — slug already exists (no duplicate created)
{ "success": false, "duplicate": true, "post_id": 99, "post_url": "https://site.com/..." }

// 403 — IP not permitted
{ "message": "Forbidden: your IP address is not permitted." }

// 422 — missing required field
{ "success": false, "message": "Required field \"title\" is missing or empty." }</pre>
</div>
</div>

<!-- ── GBP integration ───────────────────────────────────────────────────────── -->
<h2 class="rs-section">GBP Integration</h2>
<div class="rs-card">
<div class="rs-card-body">
	<p class="description" style="margin:0 0 10px;">When GBP is connected and locked, a "What's New" post is automatically created on your Google Business Profile after each <strong>blog post</strong> publishes. Only fires for <code>post_type: "post"</code> (not pages) with final status <code>publish</code>.</p>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th>GBP Post Field</th><th>Source</th></tr></thead>
		<tbody>
			<tr><td>Summary / Body</td><td><code>summary</code> from webhook → falls back to <code>title</code></td></tr>
			<tr><td>CTA URL</td><td>Published page permalink</td></tr>
			<tr><td>CTA Button</td><td>Configured in Connections tab (Learn More, Book, etc.)</td></tr>
			<tr><td>Image</td><td>Featured image if set</td></tr>
		</tbody>
	</table>
</div>
</div>

<!-- ── Custom theme hooks ─────────────────────────────────────────────────────── -->
<h2 class="rs-section">Custom Theme Hooks</h2>
<div class="rs-card">
<div class="rs-card-body">
	<p style="font-size:13px;font-weight:600;margin:0 0 8px;">Layout</p>
	<pre class="rs-code">add_filter( 'ratesight_layout_meta', function( $wrote, $layout, $post_id ) {
    $map = [ 'full-width' => 'no-sidebar', 'right-sidebar' => 'default', 'left-sidebar' => 'left' ];
    if ( isset( $map[ $layout ] ) ) update_post_meta( $post_id, '_my_theme_sidebar', $map[ $layout ] );
    return $wrote;
}, 10, 3 );</pre>
	<p style="font-size:13px;font-weight:600;margin:8px 0 8px;">Title Visibility</p>
	<pre class="rs-code" style="margin-bottom:0;">add_action( 'ratesight_title_visibility', function( $post_id, $show ) {
    update_post_meta( $post_id, '_my_theme_hide_title', $show ? '0' : '1' );
}, 10, 2 );</pre>
</div>
</div>
