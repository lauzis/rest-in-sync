( function () {
	'use strict';

	var AUTO_DISMISS_MS = 5000;
	var TYPES           = [ 'success', 'error', 'warning', 'info' ];

	function getContainer() {
		var container = document.getElementById( 'rest-in-sync-toast-container' );

		if ( ! container ) {
			container = document.createElement( 'div' );
			container.id = 'rest-in-sync-toast-container';
			document.body.appendChild( container );
		}

		return container;
	}

	function removeToast( toast ) {
		if ( ! toast.parentNode ) {
			return;
		}

		toast.classList.add( 'rest-in-sync-toast--dismissing' );

		window.setTimeout( function () {
			if ( toast.parentNode ) {
				toast.parentNode.removeChild( toast );
			}
		}, 200 );
	}

	function show( message, type ) {
		type = TYPES.indexOf( type ) !== -1 ? type : 'info';

		var toast = document.createElement( 'div' );
		toast.className = 'rest-in-sync-toast rest-in-sync-toast--' + type;

		var text = document.createElement( 'span' );
		text.className = 'rest-in-sync-toast__message';
		text.textContent = message;
		toast.appendChild( text );

		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'rest-in-sync-toast__dismiss';
		dismiss.setAttribute( 'aria-label', 'Dismiss' );
		dismiss.innerHTML = '&times;';
		dismiss.addEventListener( 'click', function () {
			removeToast( toast );
		} );
		toast.appendChild( dismiss );

		getContainer().appendChild( toast );

		window.setTimeout( function () {
			removeToast( toast );
		}, AUTO_DISMISS_MS );
	}

	window.RestInSyncToast = { show: show };
} )();
