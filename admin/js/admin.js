/**
 * Update Pilot — admin behaviour.
 *
 * Only one job: dim the fields that belong to an option that is currently
 * switched off, so the form says what it will actually do. Nothing here is
 * required for the settings to work — the server validates every value
 * regardless of what the browser did.
 */
( function () {
	'use strict';

	/**
	 * Tie a group of fields to a master checkbox.
	 *
	 * @param {string} masterName Name attribute of the controlling checkbox.
	 * @param {string[]} fieldNames Name attributes of the fields it controls.
	 */
	function bind( masterName, fieldNames ) {
		var master = document.querySelector( '[name="' + masterName + '"]' );

		if ( ! master ) {
			return;
		}

		var fields = [];

		fieldNames.forEach( function ( name ) {
			var nodes = document.querySelectorAll( '[name="' + name + '"]' );

			Array.prototype.forEach.call( nodes, function ( node ) {
				fields.push( node );
			} );
		} );

		function sync() {
			fields.forEach( function ( field ) {
				var container = field.closest( 'label' ) || field.parentNode;

				if ( container && container.classList ) {
					container.classList.toggle( 'upilot-dependent-off', ! master.checked );
				}
			} );
		}

		master.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Settings screen tabs, in the style of Settings > Privacy.
	 *
	 * Every field lives in the same <form> regardless of which tab shows it, so
	 * switching tabs is nothing but hiding and showing panels — there is no
	 * per-tab submit, and a hidden field still posts when the button is
	 * pressed. Without this script every panel stays visible and the page is
	 * the same long list it always was.
	 */
	function initTabs() {
		/*
		 * The sections row specifically. The row above it moves between the
		 * plugin's screens and is made of ordinary links: it wears the same
		 * class to look the same, and must be left alone.
		 */
		var nav = document.querySelector( '.upilot-sections-nav' );

		if ( ! nav ) {
			return;
		}

		var tabs = Array.prototype.slice.call( nav.querySelectorAll( '.upilot-tab' ) );

		if ( ! tabs.length ) {
			return;
		}

		function panelFor( tab ) {
			return document.getElementById( tab.getAttribute( 'aria-controls' ) );
		}

		function activate( tab, focus ) {
			tabs.forEach( function ( other ) {
				var isActive = other === tab;

				other.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				other.setAttribute( 'tabindex', isActive ? '0' : '-1' );

				var panel = panelFor( other );

				if ( panel ) {
					panel.hidden = ! isActive;
				}
			} );

			if ( focus ) {
				tab.focus();
			}

			var slug = tab.id.replace( 'upilot-tab-', '' );

			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + slug );
			} else {
				window.location.hash = slug;
			}
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				activate( tab, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var target = null;

				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					target = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					target = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === event.key ) {
					target = tabs[ 0 ];
				} else if ( 'End' === event.key ) {
					target = tabs[ tabs.length - 1 ];
				}

				if ( target ) {
					event.preventDefault();
					activate( target, true );
				}
			} );
		} );

		var initial = tabs[ 0 ];
		var wanted  = window.location.hash.replace( '#', '' );

		if ( wanted ) {
			var match = document.getElementById( 'upilot-tab-' + wanted );

			if ( match ) {
				initial = match;
			}
		}

		activate( initial, false );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bind( 'window_enabled', [ 'window_start_hour', 'window_end_hour', 'window_weekdays[]' ] );
		bind( 'schedule_enabled', [ 'schedule_hour', 'schedule_minute', 'schedule_interval' ] );
		bind( 'delay_enabled', [ 'delay_days', 'delay_applies_plugins', 'delay_applies_themes', 'delay_applies_core' ] );

		initTabs();
	} );
}() );
