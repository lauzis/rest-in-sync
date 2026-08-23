( function ( $ ) {
	'use strict';

	$( function () {
		bindDiffCache();

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
				var success = response && response.success;
				var message = response && response.data && response.data.message
					? response.data.message
					: restInSync.i18n.error;

				renderResult( success, message, response && response.data ? response.data.items : null );
				showToast( message, success ? 'success' : 'error' );
			} ).fail( function () {
				renderResult( false, restInSync.i18n.error, null );
				showToast( restInSync.i18n.error, 'error' );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		// Clearing cached diffs. The response carries the fresh figures, so the
		// summary is redrawn from it rather than by reloading the page.
		function bindDiffCache() {
			var $summary = $( '#rest-in-sync-diff-cache-summary' );
			var $stale   = $( '#rest-in-sync-clear-diff-cache-stale' );
			var $all     = $( '#rest-in-sync-clear-diff-cache-all' );
			var $wait    = $( '#rest-in-sync-clear-diff-cache-spinner' );

			if ( ! $summary.length ) {
				return;
			}

			$stale.on( 'click', function () {
				clear( 'stale' );
			} );

			$all.on( 'click', function () {
				if ( window.confirm( restInSync.i18n.confirmClearAll ) ) {
					clear( 'all' );
				}
			} );

			function clear( scope ) {
				$stale.prop( 'disabled', true );
				$all.prop( 'disabled', true );
				$wait.addClass( 'is-active' );

				$.post( restInSync.ajaxUrl, {
					action: 'rest_in_sync_clear_diff_cache',
					nonce: restInSync.diffCacheNonce,
					scope: scope
				} ).done( function ( response ) {
					var data    = response && response.data ? response.data : {};
					var success = response && response.success;

					if ( data.summary ) {
						$summary.html( data.summary );
					}

					// Driven by the figures rather than by reading the summary
					// back: nothing left to clear leaves nothing to click, in
					// any language.
					if ( data.stats ) {
						$stale.prop( 'disabled', ! data.stats.stale_files );
						$all.prop( 'disabled', ! data.stats.files );
					}

					showToast( data.message || restInSync.i18n.clearError, success ? 'success' : 'error' );
				} ).fail( function () {
					showToast( restInSync.i18n.clearError, 'error' );
				} ).always( function () {
					$wait.removeClass( 'is-active' );
				} );
			}
		}

		function showToast( message, type ) {
			if ( window.RestInSyncToast ) {
				window.RestInSyncToast.show( message, type );
			}
		}

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
