<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$diff_id     = isset( $_GET['diff_id'] ) ? sanitize_text_field( wp_unslash( $_GET['diff_id'] ) ) : '';
$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$nonce_valid = $diff_id && wp_verify_nonce( $nonce, Rest_In_Sync_Admin_Menu::DIFF_NONCE_ACTION . '_' . $diff_id );

$post      = null;
$diff_data = null;

if ( $nonce_valid ) {
	$matches = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_key'       => Rest_In_Sync_Sync_Checker::META_DIFF_ID,
		'meta_value'     => $diff_id,
	) );

	$post = $matches ? $matches[0] : null;

	$file_path = REST_IN_SYNC_DIFF_PATH . $diff_id . '.json';

	if ( $post && file_exists( $file_path ) ) {
		$decoded = json_decode( (string) file_get_contents( $file_path ), true );

		if ( is_array( $decoded ) && isset( $decoded['diff'] ) && is_array( $decoded['diff'] ) ) {
			$diff_data = $decoded;
		}
	}
}

$field_labels = array(
	'title'   => __( 'Title', 'rest-in-sync' ),
	'content' => __( 'Content', 'rest-in-sync' ),
	'excerpt' => __( 'Excerpt', 'rest-in-sync' ),
	'status'  => __( 'Status', 'rest-in-sync' ),
);
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Sync Diff', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! $nonce_valid ) : ?>
		<div class="notice notice-error inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'This diff link is invalid or has expired.', 'rest-in-sync' ); ?></p>
		</div>
	<?php elseif ( ! $diff_data ) : ?>
		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'This diff could not be found. The post may have been resynced or the diff file may have been deleted.', 'rest-in-sync' ); ?></p>
		</div>
	<?php else : ?>
		<p>
			<strong><?php esc_html_e( 'Post:', 'rest-in-sync' ); ?></strong>
			<?php echo esc_html( get_the_title( $post ) ); ?>
			(<?php echo esc_html( $post->post_type ); ?>)
			<?php if ( ! empty( $diff_data['checked_at'] ) ) : ?>
				&mdash;
				<?php
				printf(
					/* translators: %s: date the diff was recorded. */
					esc_html__( 'checked %s', 'rest-in-sync' ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $diff_data['checked_at'] ) ) )
				);
				?>
			<?php endif; ?>
		</p>

		<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
			<thead>
				<tr>
					<th style="width:20%;"><?php esc_html_e( 'Field', 'rest-in-sync' ); ?></th>
					<th style="width:40%;"><?php esc_html_e( 'Local', 'rest-in-sync' ); ?></th>
					<th style="width:40%;"><?php esc_html_e( 'Remote', 'rest-in-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $field_labels as $field => $label ) :
					if ( ! isset( $diff_data['diff'][ $field ] ) ) {
						continue;
					}
					$values = $diff_data['diff'][ $field ];
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $values['local'] ); ?></pre></td>
						<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $values['remote'] ); ?></pre></td>
					</tr>
				<?php endforeach; ?>

				<?php if ( ! empty( $diff_data['diff']['meta'] ) && is_array( $diff_data['diff']['meta'] ) ) : ?>
					<?php foreach ( $diff_data['diff']['meta'] as $meta_key => $values ) : ?>
						<tr>
							<td>
								<strong><?php esc_html_e( 'Meta:', 'rest-in-sync' ); ?></strong>
								<code><?php echo esc_html( $meta_key ); ?></code>
							</td>
							<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $values['local'] ); ?></pre></td>
							<td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo esc_html( (string) $values['remote'] ); ?></pre></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
