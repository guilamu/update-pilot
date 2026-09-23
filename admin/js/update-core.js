/**
 * Update Pilot — WordPress Updates screen.
 *
 * Core prints "Automatic update scheduled in …" under every plugin and theme
 * with an update, and has no filter for it on this screen. For an item Update
 * Pilot is holding back that is untrue, so the sentence is swapped for the
 * reason it is held. Text only, through textContent: nothing here is HTML.
 */
( function () {
	'use strict';

	var data = window.updatePilotUpdateCore;

	if ( ! data || ! data.core || ! data.items ) {
		return;
	}

	function replaceIn( row, message ) {
		var walker = document.createTreeWalker( row, NodeFilter.SHOW_TEXT );
		var node;

		while ( ( node = walker.nextNode() ) ) {
			if ( -1 !== node.nodeValue.indexOf( data.core ) ) {
				node.nodeValue = node.nodeValue.replace( data.core, message );
				return;
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Object.keys( data.items ).forEach( function ( form ) {
			Object.keys( data.items[ form ] ).forEach( function ( id ) {
				var boxes = document.querySelectorAll( 'form[name="' + form + '"] input[name="checked[]"]' );

				Array.prototype.forEach.call( boxes, function ( box ) {
					var row = box.value === id ? box.closest( 'tr' ) : null;

					if ( row ) {
						replaceIn( row, data.items[ form ][ id ] );
					}
				} );
			} );
		} );
	} );
}() );
