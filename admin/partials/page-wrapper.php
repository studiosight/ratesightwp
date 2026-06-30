<?php
/**
 * Admin page wrapper.
 *
 * Critical layout styles are inlined so they always apply regardless of
 * whether the external CSS file loads. Everything else is in ratesight-admin.css.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$valid_tabs = array( 'widgets', 'seo-pages', 'logs', 'connections', 'performance', 'links', 'help' );
if ( ! in_array( $tab, $valid_tabs, true ) ) {
	$tab = 'widgets';
}

$base = admin_url( 'admin.php?page=ratesight' );

$seo    = Ratesight_SEO_Writer::detected_plugins();
$layout = Ratesight_Layout_Writer::detected_themes();
$title  = Ratesight_Title_Writer::detected_themes();

$chips = array(
	array( 'ok' => ! empty( $seo ),    'label' => ! empty( $seo )    ? 'SEO: '           . implode( ', ', $seo )    : 'No SEO plugin detected'  ),
	array( 'ok' => ! empty( $layout ), 'label' => ! empty( $layout ) ? 'Layout: '        . implode( ', ', $layout ) : 'No layout theme detected' ),
	array( 'ok' => ! empty( $title ),  'label' => ! empty( $title )  ? 'Title control: ' . implode( ', ', $title )  : 'No title theme detected'  ),
);

$tabs = array(
	'widgets'     => 'Widgets',
	'connections' => 'Connections',
	'seo-pages'   => 'Settings',
	'performance' => 'Performance',
	'links'       => 'Links',
	'logs'        => 'Activity Log',
	'help'        => 'Reference',
);
?>
<style>
/* Inlined critical styles — guaranteed to apply regardless of CSS file */
.rs-page { margin-top:0!important; }

/* Header */
.rs-header { background:#1d2327!important; display:flex!important; align-items:center!important; justify-content:space-between!important; padding:16px 22px!important; border-radius:4px 4px 0 0!important; margin-bottom:0!important; }
.rs-header-brand { display:flex!important; align-items:center!important; gap:12px!important; }
.rs-header-brand img { width:26px!important; height:26px!important; opacity:.85!important; flex-shrink:0!important; }
.rs-header h1 { color:#fff!important; font-size:18px!important; font-weight:600!important; margin:0!important; padding:0!important; line-height:1!important; border:none!important; }
.rs-header-sub { font-size:10px!important; text-transform:uppercase!important; letter-spacing:.1em!important; color:rgba(255,255,255,.35)!important; padding-left:12px!important; margin-left:4px!important; border-left:1px solid rgba(255,255,255,.12)!important; }
.rs-header-ver { font-size:11px!important; color:rgba(255,255,255,.4)!important; background:rgba(255,255,255,.08)!important; padding:3px 10px!important; border-radius:20px!important; }

/* Detection chips */
.rs-chips { background:#23282d!important; padding:8px 22px!important; display:flex!important; flex-wrap:wrap!important; gap:6px!important; margin-bottom:0!important; }
.rs-chip { display:inline-flex!important; align-items:center!important; gap:5px!important; font-size:11px!important; font-weight:500!important; padding:3px 10px 3px 8px!important; border-radius:20px!important; }
.rs-chip-dot { width:6px!important; height:6px!important; border-radius:50%!important; flex-shrink:0!important; }
.rs-chip.ok   { background:rgba(0,163,42,.18)!important; color:#7ae89a!important; }
.rs-chip.ok .rs-chip-dot   { background:#5dd980!important; }
.rs-chip.none { background:rgba(255,255,255,.06)!important; color:rgba(255,255,255,.3)!important; }
.rs-chip.none .rs-chip-dot { background:rgba(255,255,255,.2)!important; }

/* Tabs */
#rs-tabs.nav-tab-wrapper { padding-left:22px!important; background:#fff!important; margin-bottom:0!important; border:1px solid #c3c4c7!important; border-top:none!important; }
#rs-tabs .nav-tab { border:none!important; border-bottom:3px solid transparent!important; background:transparent!important; margin-bottom:-1px!important; padding:11px 16px!important; font-size:13px!important; font-weight:500!important; color:#646970!important; }
#rs-tabs .nav-tab:hover { background:transparent!important; color:#2c3338!important; border-bottom-color:#c3c4c7!important; }
#rs-tabs .nav-tab-active,#rs-tabs .nav-tab-active:hover,#rs-tabs .nav-tab-active:focus { color:#1877F2!important; border-bottom-color:#1877F2!important; border-top:none!important; border-left:none!important; border-right:none!important; background:transparent!important; }

/* Body */
.rs-body { background:#f0f0f1!important; border:1px solid #c3c4c7!important; border-top:none!important; padding:20px!important; border-radius:0 0 4px 4px!important; }

/* Section headings */
.rs-section { font-size:11px!important; font-weight:700!important; text-transform:uppercase!important; letter-spacing:.08em!important; color:#646970!important; margin:20px 0 8px!important; padding:0 0 0 10px!important; border:none!important; border-left:3px solid #1877F2!important; line-height:1.4!important; }
.rs-section:first-child { margin-top:0!important; }

/* Cards */
.rs-card { background:#fff!important; border:1px solid #dcdcde!important; border-radius:4px!important; box-shadow:0 1px 3px rgba(0,0,0,.06)!important; width:100%!important; box-sizing:border-box!important; }
.rs-card-body { padding:4px 20px 16px!important; }

/* Form table inside cards */
.rs-card .form-table { margin-top:0!important; }
.rs-card .form-table th { width:180px!important; padding:12px 16px 12px 0!important; font-size:13px!important; font-weight:600!important; color:#1d2327!important; vertical-align:middle!important; border-bottom:1px solid #f0f0f1!important; }
.rs-card .form-table td { padding:12px 0!important; vertical-align:middle!important; border-bottom:1px solid #f0f0f1!important; }
.rs-card .form-table tr:last-child th,
.rs-card .form-table tr:last-child td { border-bottom:none!important; }

/* Shortcode rows */
.rs-sc-row { display:flex!important; align-items:center!important; gap:10px!important; padding:10px 14px!important; background:#f6f7f7!important; border:1px solid #e5e5e5!important; border-radius:4px!important; margin-bottom:8px!important; }
.rs-sc-row:last-of-type { margin-bottom:0!important; }
.rs-sc-name { width:130px!important; font-size:12px!important; font-weight:600!important; color:#646970!important; flex-shrink:0!important; }
.rs-sc-code { flex:1!important; font-family:ui-monospace,Consolas,monospace!important; font-size:13px!important; color:#1d2327!important; }

/* Color row */
.rs-color-row { display:flex!important; align-items:center!important; gap:8px!important; margin-top:6px!important; }

/* Endpoint URL box */
.rs-url-box { display:flex!important; align-items:center!important; gap:8px!important; background:#f6f7f7!important; border:1px solid #dcdcde!important; border-radius:3px!important; padding:7px 10px!important; }
.rs-url-box input[type="text"] { flex:1!important; border:none!important; background:transparent!important; box-shadow:none!important; font-family:ui-monospace,Consolas,monospace!important; font-size:12px!important; color:#646970!important; padding:0!important; min-width:0; }
.rs-url-box input[type="text"]:focus { border:none!important; box-shadow:none!important; outline:none!important; }

/* Submit area */
.rs-submit { margin-top:16px!important; padding-top:14px!important; border-top:1px solid #f0f0f1!important; }

/* Feedback */
.rs-feedback { font-size:13px!important; margin-left:8px!important; }
.rs-feedback.ok  { color:#00a32a!important; }
.rs-feedback.err { color:#d63638!important; }
</style>

<div class="wrap rs-page">

	<div class="rs-header">
		<div class="rs-header-brand">
			<img src="<?php echo esc_url( RATESIGHT_PLUGIN_URL . 'admin/images/rs-icon.png' ); ?>" alt="">
			<h1>Ratesight</h1>
			<span class="rs-header-sub">Agency</span>
		</div>
		<span class="rs-header-ver">v<?php echo esc_html( RATESIGHT_VERSION ); ?></span>
	</div>

	<div class="rs-chips">
		<?php foreach ( $chips as $c ) : ?>
			<span class="rs-chip <?php echo $c['ok'] ? 'ok' : 'none'; ?>">
				<span class="rs-chip-dot"></span>
				<?php echo esc_html( $c['label'] ); ?>
			</span>
		<?php endforeach; ?>
	</div>

	<div id="rs-tabs" class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a href="<?php echo esc_url( $base . '&tab=' . $slug ); ?>"
			   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="rs-body">
		<?php
		// ── Setup wizard ──────────────────────────────────────────────────────
		require __DIR__ . '/inc-setup-wizard.php';

		$map     = array(
			'widgets'     => 'tab-widgets.php',
			'seo-pages'   => 'tab-seo-pages.php',
			'logs'        => 'tab-logs.php',
			'connections' => 'tab-connections.php',
			'performance' => 'tab-performance.php',
			'links'       => 'tab-links.php',
			'help'        => 'tab-help.php',
		);
		$partial = __DIR__ . '/' . ( $map[ $tab ] ?? 'tab-widgets.php' );
		if ( file_exists( $partial ) ) {
			require $partial;
		}
		?>
	</div>

</div>
