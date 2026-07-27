<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$all_settings = Rest_In_Sync_Field_Settings::get_all();
$flagged      = array();

foreach ( $all_settings as $field => $settings ) {
	$exclude_sync = ! empty( $settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ] );
	$exclude_diff = ! empty( $settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ] );

	if ( $exclude_sync || $exclude_diff ) {
		$flagged[ $field ] = $settings;
	}
}

ksort( $flagged );

$pattern_rules = Rest_In_Sync_Field_Settings::get_pattern_rules();
ksort( $pattern_rules );

wp_localize_script( 'rest-in-sync-field-settings', 'risFieldSettings', array(
	'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	'nonce'   => wp_create_nonce( Rest_In_Sync_Ajax::NONCE_ACTION_DETAILS ),
	'i18n'    => array(
		'error'         => __( 'Something went wrong. Please try again.', 'rest-in-sync' ),
		'settingSaved'  => __( 'Setting saved.', 'rest-in-sync' ),
		'noneLeft'      => __( 'No fields are currently excluded from sync or diff.', 'rest-in-sync' ),
		'noPatterns'    => __( 'No pattern rules yet.', 'rest-in-sync' ),
		'enterPattern'  => __( 'Enter a pattern first.', 'rest-in-sync' ),
		'selectSetting' => __( 'Check at least one setting for this pattern.', 'rest-in-sync' ),
		'patternAdded'  => __( 'Pattern rule added.', 'rest-in-sync' ),
		'patternRemoved' => __( 'Pattern rule removed.', 'rest-in-sync' ),
	),
) );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Field Settings', 'rest-in-sync' ); ?></h1>
	<hr class="wp-header-end">

	<p><?php esc_html_e( 'Fields and meta keys flagged "Don\'t sync by default" or "Don\'t use in diff" from the Sync Details page, listed here in one place. These settings are global — toggling one here has the exact same effect as toggling it from a post\'s Details page, since both apply the same setting to every post/post type sharing that field or meta name.', 'rest-in-sync' ); ?></p>

	<?php if ( empty( $flagged ) ) : ?>
		<div class="notice notice-info inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'No fields are currently excluded from sync or diff.', 'rest-in-sync' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped" id="ris-field-settings-table" style="margin-top:20px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field / Meta Key', 'rest-in-sync' ); ?></th>
					<th style="width:40%;"><?php esc_html_e( 'Settings', 'rest-in-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $flagged as $field => $settings ) :
					$exclude_sync = ! empty( $settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ] );
					$exclude_diff = ! empty( $settings[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ] );
				?>
					<tr>
						<td><code><?php echo esc_html( $field ); ?></code></td>
						<td>
							<button
								type="button"
								class="button ris-toggle<?php echo $exclude_sync ? ' button-primary' : ''; ?>"
								data-field="<?php echo esc_attr( $field ); ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ); ?>"
								aria-pressed="<?php echo $exclude_sync ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't sync by default", 'rest-in-sync' ); ?></button>
							<button
								type="button"
								class="button ris-toggle<?php echo $exclude_diff ? ' button-primary' : ''; ?>"
								data-field="<?php echo esc_attr( $field ); ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ); ?>"
								aria-pressed="<?php echo $exclude_diff ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't use in diff", 'rest-in-sync' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 style="margin-top:30px;"><?php esc_html_e( 'Pattern Rules', 'rest-in-sync' ); ?></h2>
	<p><?php esc_html_e( 'Exclude a whole family of meta keys at once with a wildcard pattern, where "*" matches any run of characters — e.g. "view_count*" matches "view_count", "view_count_2026", and "view_count_2026-07-05" alike. A field\'s own explicit toggle above always overrides a matching pattern, so you can still except individual fields from an otherwise-matching pattern.', 'rest-in-sync' ); ?></p>

	<table class="wp-list-table widefat fixed striped" id="ris-pattern-rules-table" style="margin-top:10px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Pattern', 'rest-in-sync' ); ?></th>
				<th style="width:40%;"><?php esc_html_e( 'Settings', 'rest-in-sync' ); ?></th>
				<th style="width:10%;"></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $pattern_rules ) ) : ?>
				<tr class="ris-no-patterns-row">
					<td colspan="3"><span class="description"><?php esc_html_e( 'No pattern rules yet.', 'rest-in-sync' ); ?></span></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $pattern_rules as $pattern => $rule ) :
					$exclude_sync = ! empty( $rule[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ] );
					$exclude_diff = ! empty( $rule[ Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ] );
				?>
					<tr data-pattern="<?php echo esc_attr( $pattern ); ?>">
						<td><code><?php echo esc_html( $pattern ); ?></code></td>
						<td>
							<button
								type="button"
								class="button ris-pattern-toggle<?php echo $exclude_sync ? ' button-primary' : ''; ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_SYNC ); ?>"
								aria-pressed="<?php echo $exclude_sync ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't sync by default", 'rest-in-sync' ); ?></button>
							<button
								type="button"
								class="button ris-pattern-toggle<?php echo $exclude_diff ? ' button-primary' : ''; ?>"
								data-setting="<?php echo esc_attr( Rest_In_Sync_Field_Settings::SETTING_EXCLUDE_FROM_DIFF ); ?>"
								aria-pressed="<?php echo $exclude_diff ? 'true' : 'false'; ?>"
							><?php esc_html_e( "Don't use in diff", 'rest-in-sync' ); ?></button>
						</td>
						<td><button type="button" class="button ris-pattern-remove"><?php esc_html_e( 'Remove', 'rest-in-sync' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<p style="margin-top:15px;">
		<input type="text" id="ris-new-pattern" placeholder="<?php esc_attr_e( 'e.g. view_count*', 'rest-in-sync' ); ?>" style="width:250px;">
		&nbsp;
		<label><input type="checkbox" id="ris-new-pattern-sync"> <?php esc_html_e( "Don't sync by default", 'rest-in-sync' ); ?></label>
		&nbsp;
		<label><input type="checkbox" id="ris-new-pattern-diff"> <?php esc_html_e( "Don't use in diff", 'rest-in-sync' ); ?></label>
		&nbsp;
		<button type="button" class="button button-primary" id="ris-add-pattern"><?php esc_html_e( 'Add Pattern', 'rest-in-sync' ); ?></button>
	</p>
</div>
