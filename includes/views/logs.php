<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cleared = false;

if (
	isset( $_POST['action'], $_POST['rest_in_sync_clear_logs_nonce'] )
	&& 'clear_logs' === $_POST['action']
	&& wp_verify_nonce( $_POST['rest_in_sync_clear_logs_nonce'], 'rest_in_sync_clear_logs' )
) {
	Rest_In_Sync_Logs::clear_logs();
	$cleared = true;
}

$log_files     = Rest_In_Sync_Logs::get_log_files(); // Newest first.
$selected_file = null;
$selected_date = null;
$log_lines     = array();

if ( ! empty( $log_files ) ) {
	$requested_date = isset( $_GET['log_date'] ) ? sanitize_text_field( wp_unslash( $_GET['log_date'] ) ) : null;

	foreach ( $log_files as $file ) {
		if ( $requested_date && $file['date'] === $requested_date ) {
			$selected_file = $file['file'];
			$selected_date = $file['date'];
			break;
		}
	}

	if ( ! $selected_file ) {
		$selected_file = $log_files[0]['file'];
		$selected_date = $log_files[0]['date'];
	}

	if ( file_exists( $selected_file ) ) {
		$log_lines = file( $selected_file, FILE_IGNORE_NEW_LINES ) ?: array();
	}
}
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Logs', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( $cleared ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All log files deleted.', 'rest-in-sync' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! Rest_In_Sync_Logs::enabled() ) : ?>
		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p>
				<?php esc_html_e( 'Logging is currently disabled. Enable it under', 'rest-in-sync' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Rest_In_Sync_Admin_Menu::MENU_SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Settings → Enable logging', 'rest-in-sync' ); ?></a>.
			</p>
		</div>
	<?php endif; ?>

	<div class="metabox-holder">

	<div class="postbox" style="margin-top:4px;">
		<div class="inside" style="padding-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">

			<?php if ( ! empty( $log_files ) ) : ?>
				<form method="get" style="display:flex;align-items:center;gap:8px;margin:0;">
					<input type="hidden" name="page" value="<?php echo esc_attr( Rest_In_Sync_Admin_Menu::MENU_SLUG . '-logs' ); ?>">
					<label for="rest-in-sync-log-select" style="font-weight:600;">
						<?php esc_html_e( 'Log file:', 'rest-in-sync' ); ?>
					</label>
					<select id="rest-in-sync-log-select" name="log_date" onchange="this.form.submit()">
						<?php foreach ( $log_files as $file ) : ?>
							<option value="<?php echo esc_attr( $file['date'] ); ?>"
								<?php selected( $file['date'], $selected_date ); ?>>
								<?php echo esc_html( $file['date'] ); ?>
								(<?php echo esc_html( $file['count'] ); ?> <?php esc_html_e( 'entries', 'rest-in-sync' ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</form>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'No log files found.', 'rest-in-sync' ); ?></span>
			<?php endif; ?>

			<form method="post" style="margin:0 0 0 auto;"
				onsubmit="return confirm('<?php echo esc_js( __( 'Delete all log files? This cannot be undone.', 'rest-in-sync' ) ); ?>')">
				<?php wp_nonce_field( 'rest_in_sync_clear_logs', 'rest_in_sync_clear_logs_nonce' ); ?>
				<input type="hidden" name="action" value="clear_logs">
				<button type="submit" class="button button-secondary" <?php echo empty( $log_files ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Clear all logs', 'rest-in-sync' ); ?>
				</button>
			</form>

		</div>
	</div>

	<?php if ( ! empty( $log_lines ) ) : ?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<span><?php echo esc_html( $selected_date ); ?></span>
				</h2>
			</div>
			<div class="inside" style="padding:0;">
				<pre style="
					margin:0;
					padding:12px 16px;
					background:#1d2327;
					color:#e0e0e0;
					font-size:12px;
					line-height:1.7;
					overflow-x:auto;
					max-height:70vh;
					overflow-y:auto;
					border-radius:0 0 3px 3px;
				"><?php
					foreach ( array_reverse( $log_lines ) as $line ) {
						echo esc_html( $line ) . "\n";
					}
				?></pre>
			</div>
		</div>
	<?php elseif ( ! empty( $log_files ) ) : ?>
		<div class="notice notice-info inline" style="margin-top:0;">
			<p><?php esc_html_e( 'This log file is empty.', 'rest-in-sync' ); ?></p>
		</div>
	<?php endif; ?>

	</div><!-- /.metabox-holder -->
</div>
