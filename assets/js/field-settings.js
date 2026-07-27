( function ( $ ) {
	'use strict';

	$( function () {
		if ( typeof risFieldSettings === 'undefined' ) {
			return;
		}

		var $table        = $( '#ris-field-settings-table' );
		var $patternTable = $( '#ris-pattern-rules-table' );

		if ( ! $table.length && ! $patternTable.length ) {
			return;
		}

		function showToast( message, type ) {
			if ( window.RestInSyncToast ) {
				window.RestInSyncToast.show( message, type );
			}
		}

		function showEmptyNotice() {
			$table.after(
				$( '<div class="notice notice-info inline" style="margin-top:20px;"><p></p></div>' )
					.find( 'p' ).text( risFieldSettings.i18n.noneLeft ).end()
			);
			$table.remove();
		}

		$table.on( 'click', '.ris-toggle', function () {
			var $btn     = $( this );
			var $row     = $btn.closest( 'tr' );
			var field    = $btn.data( 'field' );
			var setting  = $btn.data( 'setting' );
			var newValue = $btn.attr( 'aria-pressed' ) !== 'true';

			$btn.prop( 'disabled', true );

			$.post( risFieldSettings.ajaxUrl, {
				action: 'rest_in_sync_update_field_setting',
				nonce: risFieldSettings.nonce,
				field: field,
				setting: setting,
				value: newValue ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risFieldSettings.i18n.error, 'error' );
					return;
				}

				$btn.attr( 'aria-pressed', newValue ? 'true' : 'false' );
				$btn.toggleClass( 'button-primary', newValue );

				showToast( risFieldSettings.i18n.settingSaved, 'success' );

				var stillFlagged = $row.find( '.ris-toggle[aria-pressed="true"]' ).length > 0;

				if ( ! stillFlagged ) {
					$row.fadeOut( 200, function () {
						$row.remove();

						if ( ! $table.find( 'tbody tr' ).length ) {
							showEmptyNotice();
						}
					} );
				}
			} ).fail( function () {
				showToast( risFieldSettings.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		function currentPatternValue( $row, setting, fallback ) {
			var $btn = $row.find( '.ris-pattern-toggle[data-setting="' + setting + '"]' );

			return $btn.length ? $btn.attr( 'aria-pressed' ) === 'true' : fallback;
		}

		$patternTable.on( 'click', '.ris-pattern-toggle', function () {
			var $btn     = $( this );
			var $row     = $btn.closest( 'tr' );
			var pattern  = $row.data( 'pattern' );
			var setting  = $btn.data( 'setting' );
			var newValue = $btn.attr( 'aria-pressed' ) !== 'true';

			var excludeSync = 'exclude_from_sync' === setting ? newValue : currentPatternValue( $row, 'exclude_from_sync', false );
			var excludeDiff = 'exclude_from_diff' === setting ? newValue : currentPatternValue( $row, 'exclude_from_diff', false );

			$btn.prop( 'disabled', true );

			$.post( risFieldSettings.ajaxUrl, {
				action: 'rest_in_sync_update_field_pattern',
				nonce: risFieldSettings.nonce,
				pattern: pattern,
				exclude_from_sync: excludeSync ? 1 : 0,
				exclude_from_diff: excludeDiff ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risFieldSettings.i18n.error, 'error' );
					return;
				}

				$btn.attr( 'aria-pressed', newValue ? 'true' : 'false' );
				$btn.toggleClass( 'button-primary', newValue );

				showToast( risFieldSettings.i18n.settingSaved, 'success' );
			} ).fail( function () {
				showToast( risFieldSettings.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		function maybeShowNoPatternsRow() {
			if ( $patternTable.find( 'tbody tr' ).not( '.ris-no-patterns-row' ).length ) {
				return;
			}

			if ( $patternTable.find( '.ris-no-patterns-row' ).length ) {
				return;
			}

			$patternTable.find( 'tbody' ).append(
				$( '<tr class="ris-no-patterns-row"><td colspan="3"><span class="description"></span></td></tr>' )
					.find( 'span' ).text( risFieldSettings.i18n.noPatterns ).end()
			);
		}

		$patternTable.on( 'click', '.ris-pattern-remove', function () {
			var $btn     = $( this );
			var $row     = $btn.closest( 'tr' );
			var pattern  = $row.data( 'pattern' );

			$btn.prop( 'disabled', true );

			$.post( risFieldSettings.ajaxUrl, {
				action: 'rest_in_sync_delete_field_pattern',
				nonce: risFieldSettings.nonce,
				pattern: pattern
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risFieldSettings.i18n.error, 'error' );
					$btn.prop( 'disabled', false );
					return;
				}

				showToast( risFieldSettings.i18n.patternRemoved, 'success' );

				$row.fadeOut( 200, function () {
					$row.remove();
					maybeShowNoPatternsRow();
				} );
			} ).fail( function () {
				showToast( risFieldSettings.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );

		$( '#ris-add-pattern' ).on( 'click', function () {
			var $btn        = $( this );
			var pattern     = $.trim( $( '#ris-new-pattern' ).val() );
			var excludeSync = $( '#ris-new-pattern-sync' ).is( ':checked' );
			var excludeDiff = $( '#ris-new-pattern-diff' ).is( ':checked' );

			if ( ! pattern ) {
				showToast( risFieldSettings.i18n.enterPattern, 'warning' );
				return;
			}

			if ( ! excludeSync && ! excludeDiff ) {
				showToast( risFieldSettings.i18n.selectSetting, 'warning' );
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( risFieldSettings.ajaxUrl, {
				action: 'rest_in_sync_update_field_pattern',
				nonce: risFieldSettings.nonce,
				pattern: pattern,
				exclude_from_sync: excludeSync ? 1 : 0,
				exclude_from_diff: excludeDiff ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risFieldSettings.i18n.error, 'error' );
					$btn.prop( 'disabled', false );
					return;
				}

				showToast( risFieldSettings.i18n.patternAdded, 'success' );
				window.location.reload();
			} ).fail( function () {
				showToast( risFieldSettings.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
