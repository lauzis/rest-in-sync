( function ( $ ) {
	'use strict';

	$( function () {
		var $table = $( '#ris-fields-table' );

		if ( ! $table.length || typeof risDetails === 'undefined' ) {
			return;
		}

		var $showEqual   = $( '#ris-show-equal' );
		var $fieldFilter = $( '#ris-field-filter' );

		function showToast( message, type ) {
			if ( window.RestInSyncToast ) {
				window.RestInSyncToast.show( message, type );
			}
		}

		function applyFilters() {
			var showEqual = $showEqual.is( ':checked' );
			var needle    = $.trim( $fieldFilter.val() ).toLowerCase();

			$table.find( 'tbody tr' ).each( function () {
				var $row = $( this );

				var matchesEqualFilter = showEqual || $row.data( 'differs' );
				var matchesNameFilter  = ! needle || String( $row.data( 'field-name' ) ).indexOf( needle ) !== -1;

				$row.toggle( matchesEqualFilter && matchesNameFilter );
			} );
		}

		$showEqual.on( 'change', applyFilters );
		$fieldFilter.on( 'input', applyFilters );
		applyFilters();

		$( '#ris-select-all' ).on( 'click', function () {
			$table.find( '.ris-field-checkbox' ).prop( 'checked', true );
		} );

		$( '#ris-select-none' ).on( 'click', function () {
			$table.find( '.ris-field-checkbox' ).prop( 'checked', false );
		} );

		$( '#ris-select-default' ).on( 'click', function () {
			$table.find( '.ris-field-checkbox' ).each( function () {
				var $checkbox = $( this );
				$checkbox.prop( 'checked', $checkbox.data( 'default-checked' ) === 1 );
			} );
		} );

		$table.on( 'click', '.ris-toggle', function () {
			var $btn     = $( this );
			var field    = $btn.data( 'field' );
			var setting  = $btn.data( 'setting' );
			var newValue = $btn.attr( 'aria-pressed' ) !== 'true';

			$btn.prop( 'disabled', true );

			$.post( risDetails.ajaxUrl, {
				action: 'rest_in_sync_update_field_setting',
				nonce: risDetails.nonce,
				field: field,
				setting: setting,
				value: newValue ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risDetails.i18n.error, 'error' );
					return;
				}

				$btn.attr( 'aria-pressed', newValue ? 'true' : 'false' );
				$btn.toggleClass( 'button-primary', newValue );

				if ( 'exclude_from_sync' === setting ) {
					$table.find( '.ris-field-checkbox[value="' + field + '"]' ).data( 'default-checked', newValue ? 0 : 1 );
				}

				showToast( risDetails.i18n.settingSaved, 'success' );
			} ).fail( function () {
				showToast( risDetails.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( '#ris-resync' ).on( 'click', function () {
			var $btn     = $( this );
			var $spinner = $( '#ris-resync-spinner' );

			$btn.prop( 'disabled', true ).text( risDetails.i18n.resyncing );
			$spinner.addClass( 'is-active' );

			$.post( risDetails.ajaxUrl, {
				action: 'rest_in_sync_check_now',
				nonce: risDetails.resyncNonce,
				post_id: risDetails.postId
			} ).done( function ( response ) {
				var success = response && response.success;
				var message = ( response && response.data && response.data.message ) || ( success ? '' : risDetails.i18n.error );

				if ( message ) {
					showToast( message, success ? 'success' : 'error' );
				}

				if ( success ) {
					window.location.reload();
					return;
				}

				$btn.prop( 'disabled', false ).text( risDetails.i18n.resync );
				$spinner.removeClass( 'is-active' );
			} ).fail( function () {
				showToast( risDetails.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( risDetails.i18n.resync );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		$( '#ris-ignore' ).on( 'click', function () {
			var $btn     = $( this );
			var $spinner = $( '#ris-ignore-spinner' );

			$btn.prop( 'disabled', true ).text( risDetails.i18n.ignoring );
			$spinner.addClass( 'is-active' );

			$.post( risDetails.ajaxUrl, {
				action: 'rest_in_sync_ignore_until_next_check',
				nonce: risDetails.resyncNonce,
				post_id: risDetails.postId
			} ).done( function ( response ) {
				var success = response && response.success;
				var message = ( response && response.data && response.data.message ) || risDetails.i18n.error;

				showToast( message, success ? 'success' : 'error' );

				if ( success ) {
					window.location.href = risDetails.syncUrl;
					return;
				}

				$btn.prop( 'disabled', false ).text( risDetails.i18n.ignore );
				$spinner.removeClass( 'is-active' );
			} ).fail( function () {
				showToast( risDetails.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( risDetails.i18n.ignore );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		function syncFields( options ) {
			var $btn     = options.$button || $( options.buttonSelector );
			var $spinner = options.spinnerSelector ? $( options.spinnerSelector ) : $();
			var fields   = options.fields || $table.find( '.ris-field-checkbox:checked' ).map( function () {
				return $( this ).val();
			} ).get();

			if ( ! fields.length ) {
				showToast( risDetails.i18n.noFieldsSelected, 'warning' );
				return;
			}

			if ( ! window.confirm( options.confirmMessage ) ) {
				return;
			}

			$btn.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );

			$.post( risDetails.ajaxUrl, {
				action: options.action,
				nonce: risDetails.nonce,
				post_id: risDetails.postId,
				fields: fields
			} ).done( function ( response ) {
				var success = response && response.success;
				var message = ( response && response.data && response.data.message )
					|| ( success ? options.successMessage : risDetails.i18n.error );

				showToast( message, success ? 'success' : 'error' );

				if ( success ) {
					window.location.reload();
					return;
				}

				$btn.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} ).fail( function () {
				showToast( risDetails.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		}

		// A version mismatch means the two sites may not store or accept fields
		// the same way, so every sync control is disabled. The server refuses too.
		if ( risDetails.versionBlocked ) {
			$( '#ris-push, #ris-pull, .ris-push-field, .ris-pull-field' )
				.prop( 'disabled', true )
				.attr( 'title', risDetails.versionMessage );
		}

		$( '#ris-push' ).on( 'click', function () {
			syncFields( {
				buttonSelector: '#ris-push',
				spinnerSelector: '#ris-push-spinner',
				action: 'rest_in_sync_push_fields',
				confirmMessage: risDetails.i18n.pushConfirm,
				successMessage: risDetails.i18n.pushSuccess
			} );
		} );

		$( '#ris-pull' ).on( 'click', function () {
			syncFields( {
				buttonSelector: '#ris-pull',
				spinnerSelector: '#ris-pull-spinner',
				action: 'rest_in_sync_pull_fields',
				confirmMessage: risDetails.i18n.pullConfirm,
				successMessage: risDetails.i18n.pullSuccess
			} );
		} );

		$table.on( 'click', '.ris-push-field', function () {
			var $btn  = $( this );
			var field = $btn.data( 'field' );
			var label = $btn.data( 'label' );

			syncFields( {
				$button: $btn,
				action: 'rest_in_sync_push_fields',
				confirmMessage: risDetails.i18n.pushFieldConfirm.replace( '%s', label ),
				successMessage: risDetails.i18n.pushSuccess,
				fields: [ field ]
			} );
		} );

		$table.on( 'click', '.ris-pull-field', function () {
			var $btn  = $( this );
			var field = $btn.data( 'field' );
			var label = $btn.data( 'label' );

			syncFields( {
				$button: $btn,
				action: 'rest_in_sync_pull_fields',
				confirmMessage: risDetails.i18n.pullFieldConfirm.replace( '%s', label ),
				successMessage: risDetails.i18n.pullSuccess,
				fields: [ field ]
			} );
		} );
	} );
} )( jQuery );
