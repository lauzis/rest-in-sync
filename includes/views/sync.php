<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$search = isset( $_GET['ris_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ris_search'] ) ) : '';

$query_args = array(
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
);

if ( '' !== $search ) {
	$query_args['s'] = $search;
}

$out_of_sync_posts = get_posts( $query_args );

wp_localize_script( 'rest-in-sync-sync', 'risSync', array(
	'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	'nonce'   => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION_SYNC ),
	'i18n'    => array(
		'error'    => __( 'Something went wrong. Please try again.', 'rest-in-sync' ),
		'checking' => __( 'Checking…', 'rest-in-sync' ),
		'checkNow' => __( 'Check Now', 'rest-in-sync' ),
		'noneLeft' => __( 'Everything is in sync. No out-of-sync posts were found.', 'rest-in-sync' ),
		'noDiff'   => __( 'No diff available', 'rest-in-sync' ),
		'details'  => __( 'Details', 'rest-in-sync' ),
	),
) );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Sync', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<form method="get" style="margin:16px 0;">
		<input type="hidden" name="page" value="<?php echo esc_attr( Rest_In_Sync_Admin_Menu::MENU_SLUG ); ?>">
		<p class="search-box" style="margin:0;">
			<label class="screen-reader-text" for="ris-search-input"><?php esc_html_e( 'Search out-of-sync posts', 'rest-in-sync' ); ?></label>
			<input type="search" id="ris-search-input" name="ris_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', 'rest-in-sync' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'rest-in-sync' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Rest_In_Sync_Admin_Menu::MENU_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'rest-in-sync' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( empty( $out_of_sync_posts ) ) : ?>
		<div class="notice notice-<?php echo '' === $search ? 'success' : 'info'; ?> inline" style="margin-top:20px;">
			<p>
				<?php if ( '' !== $search ) : ?>
					<?php
					printf(
						/* translators: %s: the search term */
						esc_html__( 'No out-of-sync posts match "%s".', 'rest-in-sync' ),
						esc_html( $search )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Everything is in sync. No out-of-sync posts were found.', 'rest-in-sync' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped" id="ris-sync-table" style="margin-top:20px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'rest-in-sync' ); ?></th>
					<th><?php esc_html_e( 'Post Type', 'rest-in-sync' ); ?></th>
					<th class="ris-last-checked"><?php esc_html_e( 'Last Checked', 'rest-in-sync' ); ?></th>
					<th class="ris-status"><?php esc_html_e( 'Status', 'rest-in-sync' ); ?></th>
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
					<tr data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<td><?php echo esc_html( get_the_title( $post ) ); ?></td>
						<td><?php echo esc_html( $post_type_object ? $post_type_object->labels->singular_name : $post->post_type ); ?></td>
						<td class="ris-last-checked"><?php echo esc_html( $last_checked ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) : '—' ); ?></td>
						<td class="ris-status"><span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Out of sync', 'rest-in-sync' ); ?></span></td>
						<td class="ris-actions">
							<span class="ris-diff-link">
								<?php if ( $diff_url ) : ?>
									<a href="<?php echo esc_url( $diff_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
										<?php esc_html_e( 'Details', 'rest-in-sync' ); ?>
									</a>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'No diff available', 'rest-in-sync' ); ?></span>
								<?php endif; ?>
							</span>
							<button type="button" class="button ris-check-now" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
								<?php esc_html_e( 'Check Now', 'rest-in-sync' ); ?>
							</button>
							<span class="spinner ris-check-now-spinner" style="float:none;"></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
