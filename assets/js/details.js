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

		$( '#ris-push' ).on( 'click', function () {
			var $btn     = $( this );
			var $spinner = $( '#ris-push-spinner' );
			var fields   = $table.find( '.ris-field-checkbox:checked' ).map( function () {
				return $( this ).val();
			} ).get();

			if ( ! fields.length ) {
				showToast( risDetails.i18n.noFieldsSelected, 'warning' );
				return;
			}

			$btn.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );

			$.post( risDetails.ajaxUrl, {
				action: 'rest_in_sync_push_fields',
				nonce: risDetails.nonce,
				post_id: risDetails.postId,
				fields: fields
			} ).done( function ( response ) {
				var success = response && response.success;
				var message = ( response && response.data && response.data.message )
					|| ( success ? risDetails.i18n.pushSuccess : risDetails.i18n.error );

				showToast( message, success ? 'success' : 'error' );
			} ).fail( function () {
				showToast( risDetails.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	} );
} )( jQuery );
