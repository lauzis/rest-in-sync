<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global per-field settings, applied across all post types, that control how
 * a field/meta key behaves in the Details view and the cron sync check.
 *
 * Two layers: exact per-field-name overrides (this file's original purpose),
 * and wildcard pattern rules (e.g. "view_count*") for excluding a whole
 * family of meta keys — like per-day view-count trackers — without toggling
 * each one individually. An exact override always wins over a pattern match,
 * so a single field can still be excepted from an otherwise-matching pattern.
 */
class Rest_In_Sync_Field_Settings {

	const OPTION          = 'rest_in_sync_field_settings';
	const OPTION_PATTERNS = 'rest_in_sync_field_pattern_settings';

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
		$all      = self::get_all();
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
		return self::resolve_exclusion( $field, self::SETTING_EXCLUDE_FROM_SYNC );
	}

	public static function is_excluded_from_diff( $field ) {
		return self::resolve_exclusion( $field, self::SETTING_EXCLUDE_FROM_DIFF );
	}

	/**
	 * An explicit per-field entry always wins; only when there isn't one does
	 * a matching wildcard pattern rule apply.
	 */
	private static function resolve_exclusion( $field, $setting ) {
		$all = self::get_all();

		if ( isset( $all[ $field ][ $setting ] ) ) {
			return (bool) $all[ $field ][ $setting ];
		}

		foreach ( self::get_pattern_rules() as $pattern => $rule ) {
			if ( ! empty( $rule[ $setting ] ) && self::pattern_matches( $pattern, $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Updates a single setting for a field and persists the whole store. The
	 * other setting is seeded from its current fully-resolved value (exact
	 * override or pattern match) rather than a blank default, so toggling one
	 * setting never silently overrides a pattern rule's effect on the other.
	 *
	 * @return array{exclude_from_sync:bool, exclude_from_diff:bool} The field's settings after the update.
	 */
	public static function update_field( $field, $setting, $value ) {
		$all = self::get_all();

		$all[ $field ] = array(
			self::SETTING_EXCLUDE_FROM_SYNC => self::is_excluded_from_sync( $field ),
			self::SETTING_EXCLUDE_FROM_DIFF => self::is_excluded_from_diff( $field ),
		);
		$all[ $field ][ $setting ] = (bool) $value;

		update_option( self::OPTION, $all );

		return $all[ $field ];
	}

	/**
	 * @return array<string, array{exclude_from_sync:bool, exclude_from_diff:bool}> keyed by pattern.
	 */
	public static function get_pattern_rules() {
		$patterns = get_option( self::OPTION_PATTERNS, array() );

		return is_array( $patterns ) ? $patterns : array();
	}

	/**
	 * Adds or updates a wildcard pattern rule. '*' matches any run of
	 * characters; everything else is matched literally, case-insensitively.
	 * E.g. "view_count*" matches "view_count", "view_count_2026",
	 * "view_count_2026-07-05", etc.
	 *
	 * @return array{exclude_from_sync:bool, exclude_from_diff:bool}
	 */
	public static function save_pattern_rule( $pattern, $exclude_from_sync, $exclude_from_diff ) {
		$patterns = self::get_pattern_rules();

		$patterns[ $pattern ] = array(
			self::SETTING_EXCLUDE_FROM_SYNC => (bool) $exclude_from_sync,
			self::SETTING_EXCLUDE_FROM_DIFF => (bool) $exclude_from_diff,
		);

		update_option( self::OPTION_PATTERNS, $patterns );

		return $patterns[ $pattern ];
	}

	public static function delete_pattern_rule( $pattern ) {
		$patterns = self::get_pattern_rules();

		unset( $patterns[ $pattern ] );

		update_option( self::OPTION_PATTERNS, $patterns );
	}

	private static function pattern_matches( $pattern, $field ) {
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

		return (bool) preg_match( $regex, $field );
	}
}
