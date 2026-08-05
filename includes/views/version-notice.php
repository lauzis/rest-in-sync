<?php
/**
 * Warns when this site and the remote are not on the same plugin version.
 *
 * Rendered above any view offering a push or pull. The buttons are also
 * disabled from JavaScript, and the checker refuses regardless, so this is the
 * explanation rather than the enforcement.
 *
 * @var true|WP_Error $rest_in_sync_version_state
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( true === $rest_in_sync_version_state ) {
	return;
}
?>
<div class="notice notice-warning rest-in-sync-version-warning">
	<p>
		<strong><?php esc_html_e( 'Syncing is blocked.', 'rest-in-sync' ); ?></strong>
		<?php echo esc_html( $rest_in_sync_version_state->get_error_message() ); ?>
	</p>
</div>
