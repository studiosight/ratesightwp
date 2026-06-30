<?php
/**
 * Activity Log tab.
 *
 * @package    Ratesight
 * @subpackage Ratesight/admin/partials
 */

defined( 'ABSPATH' ) || die;

$search  = sanitize_text_field( wp_unslash( $_GET['rs_search'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status  = sanitize_key( wp_unslash( $_GET['rs_status'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$logs    = Ratesight_Logger::get_recent_logs( 200, $search, $status );
$days    = (int) Ratesight_Options::get( 'log_retention_days' );
$current_url = admin_url( 'admin.php?page=ratesight&tab=logs' );

$pills = array(
	Ratesight_Logger::STATUS_PENDING          => array( 'Pending',  'pending'  ),
	Ratesight_Logger::STATUS_SUCCESS          => array( 'Success',  'success'  ),
	Ratesight_Logger::STATUS_SUCCESS_WARNINGS => array( 'Warnings', 'warnings' ),
	Ratesight_Logger::STATUS_FAILED           => array( 'Failed',   'failed'   ),
	Ratesight_Logger::STATUS_MODIFIED         => array( 'Modified', 'modified' ),
);
?>

<form method="get" action="<?php echo esc_url( $current_url ); ?>" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
	<input type="hidden" name="page" value="ratesight">
	<input type="hidden" name="tab"  value="logs">
	<input type="search" name="rs_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search title, category, error…" class="regular-text" style="width:260px;">
	<select name="rs_status">
		<option value="">All statuses</option>
		<?php foreach ( $pills as $s => [$label] ) : ?>
			<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="button">Filter</button>
	<?php if ( $search || $status ) : ?>
		<a href="<?php echo esc_url( $current_url ); ?>" class="button">Clear</a>
	<?php endif; ?>
</form>

<div class="rs-log-bar">
	<div>
		<h2>Activity Log</h2>
		<p class="rs-log-meta"><?php echo count( $logs ); ?> entries shown &middot; auto-pruned after <?php echo esc_html( $days ); ?> days</p>
	</div>
	<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
		<?php if ( ! empty( $logs ) && ! $search && ! $status ) : ?>
			<button type="button" id="rs-clear-logs" class="button" style="color:#d63638;border-color:#f0b8b8;">Clear All</button>
		<?php endif; ?>
		<button type="button" id="rs-publish-drafts" class="button button-primary">Publish All Drafts</button>
		<button type="button" id="rs-fix-log-status" class="button">Fix Log Status</button>
		<span id="rs-publish-drafts-feedback" style="display:none;font-size:13px;"></span>
	</div>
</div>

<?php if ( empty( $logs ) ) : ?>
	<div class="rs-empty"><p>No webhook activity yet. Use <strong>Send Test Request</strong> on the AI SEO Pages tab.</p></div>
<?php else : ?>
	<div id="rs-log-table-wrap">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:155px">Date / Time</th>
					<th style="width:95px">Status</th>
					<th>Title</th>
					<th style="width:145px">Category</th>
					<th style="width:65px">Post</th>
					<th>Notes</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) :
					[ $label, $cls ] = $pills[ $log['status'] ] ?? array( $log['status'], '' );
					$msg    = $log['notes'] ?: ( $log['error_message'] ?? '' );
					$is_err     = $log['status'] === Ratesight_Logger::STATUS_FAILED;
					$is_warning  = $log['status'] === Ratesight_Logger::STATUS_SUCCESS_WARNINGS;
					$is_pending  = $log['status'] === Ratesight_Logger::STATUS_PENDING;
					$gbp_failed  = $is_warning && str_contains( (string) $msg, 'GBP post failed' );
				?>
				<tr>
					<td style="font-size:12px;color:#646970;"><?php echo esc_html( $log['received_at'] ); ?></td>
					<td><span class="rs-pill <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $label ); ?></span></td>
					<td><?php echo esc_html( $log['title'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $log['child_category'] ?: '—' ); ?></td>
					<td>
						<?php if ( ! empty( $log['post_id'] ) ) :
							$edit       = get_edit_post_link( $log['post_id'] );
							$view       = get_permalink( $log['post_id'] );
							$is_rs_page = get_post_type( $log['post_id'] ) === 'ratesight_page';
						?>
							<?php if ( $is_rs_page ) : ?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="13" viewBox="0 0 24 30" style="vertical-align:middle;margin-right:3px;position:relative;top:-1px;" aria-label="Ratesight Page"><path d="M12 0C7.6 0 4 3.6 4 8c0 6 8 16 8 16s8-10 8-16c0-4.4-3.6-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="#1877F2"/></svg><?php endif; ?>
							<a class="rs-post-link" href="<?php echo esc_url( $edit ); ?>" target="_blank">#<?php echo esc_html( $log['post_id'] ); ?></a>
							<?php if ( $view ) : ?><a style="color:#646970;margin-left:2px;" href="<?php echo esc_url( $view ); ?>" target="_blank">↗</a><?php endif; ?>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<?php if ( $msg ) : ?>
							<span class="<?php echo $is_err ? 'rs-err' : 'rs-note'; ?>" title="<?php echo esc_attr( $msg ); ?>"><?php echo esc_html( $msg ); ?></span>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<?php if ( $is_err && ! empty( $log['raw_payload'] ) ) : ?>
							<button type="button"
								class="button button-small rs-retry-log"
								data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Retry</button>
						<?php elseif ( $is_err ) : ?>
							<span style="color:#999;font-size:11px;" title="Enable Store Raw Payload to allow retries">No payload</span>
						<?php elseif ( $gbp_failed ) : ?>
							<button type="button"
								class="button button-small rs-retry-gbp"
								data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Retry GBP</button>
						<?php elseif ( $is_pending ) : ?>
							<button type="button"
								class="button button-small rs-recheck-pending"
								data-log-id="<?php echo esc_attr( $log['id'] ); ?>">Recheck</button>
						<?php else : ?>—<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
