<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$diff_id     = isset( $_GET['diff_id'] ) ? sanitize_text_field( wp_unslash( $_GET['diff_id'] ) ) : '';
$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$nonce_valid = $diff_id && wp_verify_nonce( $nonce, Rest_In_Sync_Admin_Menu::DIFF_NONCE_ACTION . '_' . $diff_id );

$post       = null;
$comparison = null;
$error      = '';

if ( $nonce_valid ) {
	$matches = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_key'       => Rest_In_Sync_Sync_Checker::META_DIFF_ID,
		'meta_value'     => $diff_id,
	) );

	$post = $matches ? $matches[0] : null;

	if ( $post ) {
		$result = ( new Rest_In_Sync_Sync_Checker() )->get_comparison( $post );

		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
		} else {
			$comparison = $result;
		}
	}
}

if ( $post ) {
	wp_localize_script( 'rest-in-sync-details', 'risDetails', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION_DETAILS ),
		'postId'  => $post->ID,
		'i18n'    => array(
			'error'            => __( 'Something went wrong. Please try again.', 'rest-in-sync' ),
			'noFieldsSelected' => __( 'Select at least one field to push.', 'rest-in-sync' ),
			'pushSuccess'      => __( 'Selected fields were pushed to the remote site.', 'rest-in-sync' ),
			'settingSaved'     => __( 'Setting saved.', 'rest-in-sync' ),
		),
	) );
}
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Sync Details', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! $nonce_valid || ! $post ) : ?>
		<div class="notice notice-error inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'This details link is invalid or has expired.', 'rest-in-sync' ); ?></p>
		</div>
	<?php elseif ( $error ) : ?>
		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p><?php echo esc_html( $error ); ?></p>
		</div>
	<?php else : ?>
		<p>
			<strong><?php esc_html_e( 'Post:', 'rest-in-sync' ); ?></strong>
			<?php echo esc_html( get_the_title( $post ) ); ?>
			(<?php echo esc_html( $post->post_type ); ?>)
		</p>

		<p class="ris-controls">
			<button type="button" id="ris-select-all" class="button"><?php esc_html_e( 'Select All', 'rest-in-sync' ); ?></button>
			<button type="button" id="ris-select-none" class="button"><?php esc_html_e( 'Select None', 'rest-in-sync' ); ?></button>
			<button type="button" id="ris-select-default" class="button"><?php esc_html_e( 'Select Default', 'rest-in-sync' ); ?></button>
			&nbsp;&nbsp;
			<label>
				<input type="checkbox" id="ris-show-equal">
				<?php esc_html_e( 'Show fields with equal values', 'rest-in-sync' ); ?>
			</label>
		</p>

		<table class="wp-list-table widefat fixed striped" id="ris-fields-table" style="margin-top:10px;">
			<thead>
				<tr>
					<th style="width:4%;"><?php esc_html_e( 'Sync', 'rest-in-sync' ); ?></th>
					<th style="width:16%;"><?php esc_html_e( 'Field', 'rest-in-sync' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Local', 'rest-in-sync' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Remote', 'rest-in-sync' ); ?></th>
					<th style="width:20%;"><?php esc_html_e( 'Field Settings', 'rest-in-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $comparison['rows'] as $row ) :
					$field_settings  = Rest_In_Sync_Field_Settings::get_for_field( $row['key'] );
					$exclude_sync    = ! empty( $field_settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ] );
					$exclude_diff    = ! empty( $field_settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ] );
					?>
					<tr data-differs="<?php echo $row['differs'] ? '1' : '0'; ?>">
						<td>
							<input
								type="checkbox"
								class="ris-field-checkbox"
								value="<?php echo esc_attr( $row['key'] ); ?>"
								data-default-checked="<?php echo $exclude_sync ? '0' : '1'; ?>"
								<?php checked( ! $exclude_sync ); ?>
							>
						</td>
						<td>
							<strong><?php echo esc_html( $row['label'] ); ?></strong>
							<?php if ( 'meta' === $row['type'] ) : ?>
								<br><code><?php esc_html_e( 'meta', 'rest-in-sync' ); ?></code>
							<?php endif; ?>
						</td>
						<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $row['local'] ); ?></pre></td>
						<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $row['remote'] ); ?></pre></td>
						<td>
							<button
								type="button"
								class="button ris-toggle<?php echo $exclude_sync ? ' button-primary' : ''; ?>"
								data-field="<?php echo esc_attr( $row['key'] ); ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ); ?>"
								aria-pressed="<?php echo $exclude_sync ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't sync by default", 'rest-in-sync' ); ?></button>
							<button
								type="button"
								class="button ris-toggle<?php echo $exclude_diff ? ' button-primary' : ''; ?>"
								data-field="<?php echo esc_attr( $row['key'] ); ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ); ?>"
								aria-pressed="<?php echo $exclude_diff ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't use in diff", 'rest-in-sync' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:15px;">
			<button type="button" id="ris-push" class="button button-primary"><?php esc_html_e( 'Push to Remote', 'rest-in-sync' ); ?></button>
			<span id="ris-push-spinner" class="spinner" style="float:none;"></span>
		</p>
	<?php endif; ?>
</div>
