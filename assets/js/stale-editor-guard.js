/**
 * WP AI Gatekeeper — Stale editor guard
 *
 * Sends the merge count this editor was loaded with, so the server can refuse
 * saves that would write an older copy of the article over a merged AI
 * suggestion, and warns as soon as Heartbeat reports a newer merge.
 */
( function ( $ ) {
	'use strict';

	const config = window.wpAIGatekeeperStaleGuard;
	if ( ! config || ! config.postId ) {
		return;
	}

	const postId = Number( config.postId );
	const base = String( Number( config.mergeCount ) || 0 );
	const i18n = config.i18n || {};
	let warned = false;

	// Block editor: tag every REST request for this post (saves and autosaves).
	const wpGlobal = window.wp || {};
	if ( wpGlobal.apiFetch && typeof wpGlobal.apiFetch.use === 'function' ) {
		const postRoute = new RegExp( '/wp/v2/posts/' + postId + '(?!\\d)' );
		wpGlobal.apiFetch.use( function ( options, next ) {
			let target = options.path || options.url || '';
			try {
				target = decodeURIComponent( target );
			} catch ( e ) {
				// Keep the raw target.
			}
			if ( postRoute.test( target ) ) {
				const headers = Object.assign( {}, options.headers );
				headers[ config.header ] = base;
				options = Object.assign( {}, options, { headers: headers } );
			}
			return next( options );
		} );
	}

	/**
	 * Show the "article changed" warning once.
	 */
	function warn() {
		if ( warned ) {
			return;
		}
		warned = true;
		const message = i18n.stale || 'This article was changed by a merged AI suggestion after you opened it.';
		const reloadLabel = i18n.reload || 'Reload editor';

		const notices = document.body.classList.contains( 'block-editor-page' ) &&
			wpGlobal.data && wpGlobal.data.dispatch && wpGlobal.data.dispatch( 'core/notices' );
		if ( notices ) {
			notices.createWarningNotice( message, {
				id: 'wpai-stale-editor',
				isDismissible: false,
				actions: [ { label: reloadLabel, onClick: function () { window.location.reload(); } } ],
			} );
			return;
		}

		const notice = $( '<div class="notice notice-warning wpai-stale-editor-notice"><p></p></div>' );
		notice.find( 'p' ).text( message + ' ' ).append(
			$( '<button type="button" class="button button-small"></button>' ).text( reloadLabel ).on( 'click', function () {
				window.location.reload();
			} )
		);
		const anchor = $( '.wp-header-end' ).first();
		if ( anchor.length ) {
			anchor.after( notice );
		} else {
			$( '#post' ).before( notice );
		}
	}

	// Heartbeat: ask the server whether a merge happened since this page loaded.
	$( document ).on( 'heartbeat-send', function ( event, data ) {
		data[ config.heartbeatKey ] = { post_id: postId, merge_count: base };
	} );
	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( data && data[ config.heartbeatKey ] && data[ config.heartbeatKey ].stale ) {
			warn();
		}
	} );

	// Check right away when the user comes back to this tab.
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && ! warned && wpGlobal.heartbeat && typeof wpGlobal.heartbeat.connectNow === 'function' ) {
			wpGlobal.heartbeat.connectNow();
		}
	} );
}( window.jQuery ) );
