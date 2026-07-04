<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$out_of_sync_posts = get_posts( array(
	'post_type'      => Rest_In_Sync_Settings::get_post_types_to_sync(),
	'post_status'    => Rest_In_Sync_Sync_Checker::ELIGIBLE_POST_STATUSES,
	'posts_per_page' => -1,
	'orderby'        => 'meta_value_num',
	'order'          => 'DESC',
	'meta_key'       => Rest_In_Sync_Sync_Checker::META_LAST_CHECKED,
	'meta_query'     => array(
		array(
			'key'   => Rest_In_Sync_Sync_Checker::META_STATUS,
			'value' => Rest_In_Sync_Sync_Checker::STATUS_OUT_OF_SYNC,
		),
	),
) );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Sync', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( empty( $out_of_sync_posts ) ) : ?>
		<div class="notice notice-success inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'Everything is in sync. No out-of-sync posts were found.', 'rest-in-sync' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'rest-in-sync' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'rest-in-sync' ); ?></th>
					<th><?php esc_html_e( 'Last Checked', 'rest-in-sync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'rest-in-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'rest-in-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $out_of_sync_posts as $post ) :
					$diff_id          = get_post_meta( $post->ID, Rest_In_Sync_Sync_Checker::META_DIFF_ID, true );
					$last_checked     = get_post_meta( $post->ID, Rest_In_Sync_Sync_Checker::META_LAST_CHECKED, true );
					$post_type_object = get_post_type_object( $post->post_type );
					$diff_url         = $diff_id ? admin_url(
						'admin.php?page=' . Rest_In_Sync_Admin_Menu::MENU_SLUG . '-diff'
						. '&diff_id=' . rawurlencode( $diff_id )
						. '&_wpnonce=' . wp_create_nonce( Rest_In_Sync_Admin_Menu::DIFF_NONCE_ACTION . '_' . $diff_id )
					) : '';
				?>
					<tr>
						<td><?php echo esc_html( get_the_title( $post ) ); ?></td>
						<td><?php echo esc_html( $post_type_object ? $post_type_object->labels->singular_name : $post->post_type ); ?></td>
						<td><?php echo esc_html( $last_checked ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) : '—' ); ?></td>
						<td><span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Out of sync', 'rest-in-sync' ); ?></span></td>
						<td>
							<?php if ( $diff_url ) : ?>
								<a href="<?php echo esc_url( $diff_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
									<?php esc_html_e( 'Details', 'rest-in-sync' ); ?>
								</a>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'No diff available', 'rest-in-sync' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
