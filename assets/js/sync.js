( function ( $ ) {
	'use strict';

	$( function () {
		var $table = $( '#ris-sync-table' );

		if ( ! $table.length || typeof risSync === 'undefined' ) {
			return;
		}

		function showToast( message, type ) {
			if ( window.RestInSyncToast ) {
				window.RestInSyncToast.show( message, type );
			}
		}

		function showEmptyNotice() {
			$table.after(
				$( '<div class="notice notice-success inline" style="margin-top:20px;"><p></p></div>' )
					.find( 'p' ).text( risSync.i18n.noneLeft ).end()
			);
			$table.remove();
		}

		$table.on( 'click', '.ris-check-now', function () {
			var $btn     = $( this );
			var $row     = $btn.closest( 'tr' );
			var $spinner = $row.find( '.ris-check-now-spinner' );
			var postId   = $btn.data( 'post-id' );

			$btn.prop( 'disabled', true ).text( risSync.i18n.checking );
			$spinner.addClass( 'is-active' );

			$.post( risSync.ajaxUrl, {
				action: 'rest_in_sync_check_now',
				nonce: risSync.nonce,
				post_id: postId
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risSync.i18n.error, 'error' );
					return;
				}

				var data = response.data;

				showToast( data.message, data.inSync ? 'success' : 'warning' );

				if ( data.inSync ) {
					$row.fadeOut( 200, function () {
						$row.remove();

						if ( ! $table.find( 'tbody tr' ).length ) {
							showEmptyNotice();
						}
					} );

					return;
				}

				$row.find( '.ris-last-checked' ).text( data.lastChecked );

				var $diffLink = $row.find( '.ris-diff-link' );

				if ( data.diffUrl ) {
					$diffLink.html(
						$( '<a target="_blank" rel="noopener noreferrer" class="button button-secondary"></a>' )
							.attr( 'href', data.diffUrl )
							.text( risSync.i18n.details )
					);
				} else {
					$diffLink.html( $( '<span class="description"></span>' ).text( risSync.i18n.noDiff ) );
				}
			} ).fail( function () {
				showToast( risSync.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( risSync.i18n.checkNow );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		$table.on( 'click', '.ris-ignore', function () {
			var $btn     = $( this );
			var $row     = $btn.closest( 'tr' );
			var $spinner = $row.find( '.ris-ignore-spinner' );
			var postId   = $btn.data( 'post-id' );

			$btn.prop( 'disabled', true ).text( risSync.i18n.ignoring );
			$spinner.addClass( 'is-active' );

			$.post( risSync.ajaxUrl, {
				action: 'rest_in_sync_ignore_until_next_check',
				nonce: risSync.nonce,
				post_id: postId
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					showToast( ( response && response.data && response.data.message ) || risSync.i18n.error, 'error' );
					$btn.prop( 'disabled', false ).text( risSync.i18n.ignore );
					$spinner.removeClass( 'is-active' );
					return;
				}

				showToast( response.data.message, 'success' );

				$row.fadeOut( 200, function () {
					$row.remove();

					if ( ! $table.find( 'tbody tr' ).length ) {
						showEmptyNotice();
					}
				} );
			} ).fail( function () {
				showToast( risSync.i18n.error, 'error' );
				$btn.prop( 'disabled', false ).text( risSync.i18n.ignore );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	} );
} )( jQuery );
