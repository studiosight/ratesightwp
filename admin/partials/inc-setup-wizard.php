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

// Evaluate each step.
$steps = array(
	array(
		'id'      => 'widget_id',
		'label'   => 'Ratesight is connected',
		'done'    => ! empty( Ratesight_Options::get( 'code_id' ) ),
		'action'  => 'Your Ratesight team can finish connecting this site. No action is needed here.',
		'url'     => '',
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

<div id="rs-setup-wizard" style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #1877F2;border-radius:0 4px 4px 0;padding:16px 20px;margin-bottom:20px;position:relative;">

	<button type="button" id="rs-wizard-dismiss" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;font-size:18px;color:#787c82;line-height:1;" title="Dismiss">×</button>

	<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
		<div style="flex:1;">
			<strong style="font-size:14px;">Setup Checklist</strong>
			<span style="font-size:12px;color:#787c82;margin-left:8px;"><?php echo esc_html( count( $steps ) - count( $incomplete ) ); ?>/<?php echo esc_html( count( $steps ) ); ?> complete</span>
		</div>
		<div style="width:160px;height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden;">
			<div style="width:<?php echo esc_attr( $pct ); ?>%;height:100%;background:#1877F2;border-radius:3px;transition:width .4s;"></div>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;">
		<?php foreach ( $steps as $step ) :
			$icon   = $step['done'] ? '✅' : '○';
			$style  = $step['done'] ? 'opacity:.6;' : '';
		?>
		<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:#f6f7f7;border-radius:4px;<?php echo esc_attr( $style ); ?>">
			<span style="font-size:14px;flex-shrink:0;margin-top:1px;"><?php echo wp_kses_post( $icon ); ?></span>
			<div style="font-size:12px;">
				<strong><?php echo esc_html( $step['label'] ); ?></strong>
				<?php if ( ! $step['done'] ) : ?>
					<div style="color:#787c82;margin-top:2px;">
						<?php if ( $step['url'] ) : ?>
							<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo wp_kses( $step['action'], array( 'code' => array() ) ); ?></a>
						<?php else : ?>
							<?php echo wp_kses( $step['action'], array( 'code' => array() ) ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

</div>

<script>
jQuery( function( $ ) {
	$( '#rs-wizard-dismiss' ).on( 'click', function () {
		$( '#rs-setup-wizard' ).fadeOut( 200 );
		$.post( ajaxurl, {
			action: 'ratesight_dismiss_wizard',
			nonce:  '<?php echo esc_js( wp_create_nonce( 'ratesight_admin' ) ); ?>'
		} );
	} );
} );
</script>
