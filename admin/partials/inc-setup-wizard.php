<?php
/**
 * Setup wizard — dismissible checklist for WordPress-local setup only.
 *
 * Checks:
 *   1. Ratesight site connection configured
 *   2. Search engines allowed (blog_public)
 *
 * Dismissed per-user via a user meta flag.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$user_id   = get_current_user_id();
$dismissed = get_user_meta( $user_id, 'ratesight_wizard_dismissed', true );
if ( $dismissed ) return;

$ratesight_id_set = (bool) preg_match( '/^[0-9]{1,20}$/', trim( (string) Ratesight_Options::get( 'code_id' ) ) );

// Evaluate each step.
$steps = array(
	array(
		'id'      => 'widget_id',
		'label'   => 'Ratesight account identified',
		'done'    => $ratesight_id_set,
		'action'  => 'Enter the Ratesight ID supplied with your account.',
		'url'     => admin_url( 'admin.php?page=ratesight&tab=widgets' ),
	),
	array(
		'id'      => 'blog_public',
		'label'   => 'Site visible to search engines',
		'done'    => (bool) get_option( 'blog_public' ),
		'action'  => 'Go to Settings → Reading and uncheck "Discourage search engines".',
		'url'     => admin_url( 'options-reading.php' ),
	),
);

$incomplete = array_filter( $steps, static fn( $s ) => ! $s['done'] );

if ( empty( $incomplete ) ) return; // Everything done — hide wizard entirely.

$pct = round( ( count( array_filter( $steps, static fn( $s ) => $s['done'] ) ) / count( $steps ) ) * 100 );
?>

<div id="rs-setup-wizard" role="region" aria-labelledby="rs-setup-wizard-title" style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #1877F2;border-radius:0 4px 4px 0;padding:16px 20px;margin-bottom:20px;position:relative;">

	<button type="button" id="rs-wizard-dismiss" aria-label="Dismiss setup checklist" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;font-size:18px;color:#646970;line-height:1;">×</button>

	<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
		<div style="flex:1;">
			<strong id="rs-setup-wizard-title" style="font-size:14px;">Setup checklist</strong>
			<span style="font-size:13px;color:#646970;margin-left:8px;"><?php echo esc_html( count( $steps ) - count( $incomplete ) ); ?>/<?php echo esc_html( count( $steps ) ); ?> complete</span>
		</div>
		<div role="progressbar" aria-label="Setup progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $pct ); ?>" style="width:160px;height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden;">
			<div style="width:<?php echo esc_attr( $pct ); ?>%;height:100%;background:#1877F2;border-radius:3px;transition:width .4s;"></div>
		</div>
	</div>

	<ol style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;list-style:none;margin:0;padding:0;">
		<?php foreach ( $steps as $step ) :
			$icon   = $step['done'] ? '✅' : '○';
			$style  = $step['done'] ? 'opacity:.6;' : '';
		?>
		<li style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:#f6f7f7;border-radius:4px;<?php echo esc_attr( $style ); ?>">
			<span aria-hidden="true" style="font-size:14px;flex-shrink:0;margin-top:1px;"><?php echo wp_kses_post( $icon ); ?></span>
			<div style="font-size:13px;">
				<span class="screen-reader-text"><?php echo esc_html( $step['done'] ? 'Complete: ' : 'Incomplete: ' ); ?></span>
				<strong><?php echo esc_html( $step['label'] ); ?></strong>
				<?php if ( ! $step['done'] ) : ?>
					<div style="color:#646970;margin-top:2px;">
						<?php if ( $step['url'] ) : ?>
							<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo wp_kses( $step['action'], array( 'code' => array() ) ); ?></a>
						<?php else : ?>
							<?php echo wp_kses( $step['action'], array( 'code' => array() ) ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>

</div>

<script>
jQuery( function( $ ) {
	$( '#rs-wizard-dismiss' ).on( 'click', function () {
		var duration = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 0 : 200;
		$( '#rs-setup-wizard' ).fadeOut( duration, function () {
			var destination = document.querySelector( '.rs-body h2' );
			if ( destination ) {
				destination.setAttribute( 'tabindex', '-1' );
				destination.focus();
			}
		} );
		$.post( ajaxurl, {
			action: 'ratesight_dismiss_wizard',
			nonce:  '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>'
		} );
	} );
} );
</script>
