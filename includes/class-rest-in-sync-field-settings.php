<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global per-field settings, applied across all post types, that control how
 * a field/meta key behaves in the Details view and the cron sync check.
 */
class Rest_In_Sync_Field_Settings {

	const OPTION = 'rest_in_sync_field_settings';

	const SETTING_EXCLUDE_FROM_SYNC = 'exclude_from_sync';
	const SETTING_EXCLUDE_FROM_DIFF = 'exclude_from_diff';

	/**
	 * @return array<string, array{exclude_from_sync:bool, exclude_from_diff:bool}>
	 */
	public static function get_all() {
		$settings = get_option( self::OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * @return array{exclude_from_sync:bool, exclude_from_diff:bool}
	 */
	public static function get_for_field( $field ) {
		$all     = self::get_all();
		$defaults = array(
			self::SETTING_EXCLUDE_FROM_SYNC => false,
			self::SETTING_EXCLUDE_FROM_DIFF => false,
		);

		if ( ! isset( $all[ $field ] ) || ! is_array( $all[ $field ] ) ) {
			return $defaults;
		}

		return wp_parse_args( $all[ $field ], $defaults );
	}

	public static function is_excluded_from_sync( $field ) {
		return ! empty( self::get_for_field( $field )[ self::SETTING_EXCLUDE_FROM_SYNC ] );
	}

	public static function is_excluded_from_diff( $field ) {
		return ! empty( self::get_for_field( $field )[ self::SETTING_EXCLUDE_FROM_DIFF ] );
	}

	/**
	 * Updates a single setting for a field and persists the whole store.
	 *
	 * @return array{exclude_from_sync:bool, exclude_from_diff:bool} The field's settings after the update.
	 */
	public static function update_field( $field, $setting, $value ) {
		$all = self::get_all();

		$all[ $field ] = self::get_for_field( $field );
		$all[ $field ][ $setting ] = (bool) $value;

		update_option( self::OPTION, $all );

		return $all[ $field ];
	}
}
