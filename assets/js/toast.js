/**
 * Alias for the shared toast component in lauzis/wp-plugin-packages.
 *
 * The implementation moved to the package; this keeps window.RestInSyncToast
 * working so admin.js, sync.js, details.js and field-settings.js are unchanged.
 * If the package is missing, calls become no-ops rather than TypeErrors.
 */
( function () {
	'use strict';

	window.RestInSyncToast = {
		show: function ( message, type ) {
			if ( ! window.wpNoticesToast ) {
				return;
			}

			return window.wpNoticesToast.show( message, type );
		},
	};
} )();
