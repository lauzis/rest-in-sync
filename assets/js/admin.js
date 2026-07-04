( function ( $ ) {
	'use strict';

	$( function () {
		var $button   = $( '#rest-in-sync-test-connection' );
		var $spinner  = $( '#rest-in-sync-test-connection-spinner' );
		var $result   = $( '#rest-in-sync-test-connection-result' );

		if ( ! $button.length ) {
			return;
		}

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.removeClass( 'notice-success notice-error' ).empty();

			$.post( restInSync.ajaxUrl, {
				action: 'rest_in_sync_test_connection',
				nonce: restInSync.nonce
			} ).done( function ( response ) {
				var message = response && response.data && response.data.message
					? response.data.message
					: restInSync.i18n.error;

				renderResult( response && response.success, message, response && response.data ? response.data.items : null );
			} ).fail( function () {
				renderResult( false, restInSync.i18n.error, null );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		function renderResult( success, message, items ) {
			var $notice = $( '<div>' )
				.addClass( 'notice inline' )
				.addClass( success ? 'notice-success' : 'notice-error' )
				.append( $( '<p>' ).text( message ) );

			if ( success && items && items.length ) {
				var $list = $( '<ul>' ).css( 'margin-left', '1.5em' );

				$.each( items, function ( index, item ) {
					var $link = $( '<a>' )
						.attr( 'href', item.link )
						.attr( 'target', '_blank' )
						.attr( 'rel', 'noopener noreferrer' )
						.text( item.title || item.link );

					$list.append( $( '<li>' ).append( $link ) );
				} );

				$notice.append( $list );
			}

			$result.empty().append( $notice );
		}
	} );
} )( jQuery );
