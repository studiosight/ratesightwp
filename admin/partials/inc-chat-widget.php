<?php
/**
 * Reusable AI chat widget.
 * Expects $chat_context ('organic'|'local') and $chat_prompts (array).
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$chat_context = $chat_context ?? 'organic';
$chat_prompts = $chat_prompts ?? array();
$widget_id    = 'rs-chat-' . $chat_context;
?>
<div class="rs-chat-wrap" id="<?php echo esc_attr( $widget_id ); ?>" data-context="<?php echo esc_attr( $chat_context ); ?>" style="margin-top:20px;">
	<div class="rs-chat-head">
		<h3>💬 Ask AI — <?php echo $chat_context === 'organic' ? 'Organic SEO' : 'Local SEO'; ?></h3>
	</div>
	<div class="rs-chat-messages" id="<?php echo esc_attr( $widget_id ); ?>-messages"></div>
	<?php if ( ! empty( $chat_prompts ) ) : ?>
	<div class="rs-suggested-prompts" id="<?php echo esc_attr( $widget_id ); ?>-prompts">
		<?php foreach ( $chat_prompts as $p ) : ?>
			<button type="button" class="rs-prompt-chip" data-chat="<?php echo esc_attr( $widget_id ); ?>" data-prompt="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></button>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<div class="rs-chat-input-row">
		<textarea id="<?php echo esc_attr( $widget_id ); ?>-input" placeholder="Ask anything about this data…" rows="2"></textarea>
		<button type="button" class="button button-primary rs-chat-send" data-chat="<?php echo esc_attr( $widget_id ); ?>">Ask</button>
	</div>
</div>
