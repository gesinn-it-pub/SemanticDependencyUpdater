/*!
 * Client-side reload for a self-referencing page whose editor's own
 * post-edit request would otherwise show stale query output - see
 * SDU\Hooks::onOutputPageParserOutput()'s docblock for why SMW's own
 * PostProcHandler/`.smw-postproc` cannot be relied on for this case.
 *
 * Modeled directly on SemanticMediaWiki's ext.smw.util.purge.js
 * (`.page-purge` auto-reload): backoff-and-retry via `action=purge` rather
 * than an unconditional immediate reload, because the store write this page
 * depends on (its own forced self-UpdateJob, still in flight when this
 * script first runs) may not have propagated yet - an immediate reload can
 * show the same stale output the purge is meant to fix. Retry state lives
 * in session storage per title, exactly like ext.smw.util.purge.js, so a
 * page reload during the retry window doesn't reset the backoff clock.
 */
( function ( $, mw ) {

	'use strict';

	mw.sdu = mw.sdu || {};

	// Fallback ceiling on total time spent retrying (ms), used only if the
	// server-provided expiry (see below) is unavailable for some reason.
	// Otherwise the actual budget for each render is derived from
	// `data-expires-at` on the `.sdu-reload-pending` element itself - an
	// absolute server clock reading (Hooks::getReloadRetryExpiry()), not a
	// client-local guess: the server's own window (RELOAD_PENDING_TTL_SECONDS/
	// authorizeReloadRetries()) is anchored to when the triggering save
	// happened, strictly earlier than this script's own Date.now() on its
	// first run, so a client-local timer started fresh here would otherwise
	// always end up running past what the server still considers pending.
	var MAX_RETRY_MS = 60000;

	// Deliberately NOT coupled to SDU\Hooks::SELF_UPDATE_RETRY_DELAY_SECONDS -
	// that PHP-side sequence governs a separate concern (how long to wait
	// before re-running a self-UpdateJob whose empty re-parse may just be
	// racing replication lag) and is tuned to be as fast as safely possible
	// for that job, independent of how any client happens to be polling.
	// This sequence instead balances two client-side concerns: reloading
	// too early is guaranteed wasted work (nothing new to show yet on most
	// saves) and reloading too often adds `action=purge` load across
	// however many editors happen to be mid-save at once, so this starts at
	// a few seconds rather than immediately and grows from there, without
	// trying to line up with any particular server-side timing.
	var BACKOFF_SEQUENCE = [ 3000, 5000, 8000, 13000 ];

	var reload = {
		storageKey: function ( title ) {
			return 'mw-sdu-reload-retry-' + title;
		},

		/**
		 * @param {string} title
		 * @param {number} expiresAt absolute ms timestamp this render's
		 *  cycle is bounded by (see getExpiresAt()) - used to detect a
		 *  cycle change, see below
		 */
		getRetryState: function ( title, expiresAt ) {
			var raw = mw.storage.session.get( reload.storageKey( title ) );
			var state;

			try {
				state = raw ? JSON.parse( raw ) : null;
			} catch ( e ) {
				state = null;
			}

			if (
				!state ||
				typeof state.attempts !== 'number' ||
				typeof state.startTime !== 'number' ||
				// A DIFFERENT expiresAt than the one this stored state was
				// last seen with means a NEW save happened while a previous
				// cycle's retry loop was still in flight (init()'s own
				// clearRetryState() call only fires once .sdu-reload-pending
				// stops being rendered at all, which never happens here -
				// the new save's own onOutputPageParserOutput() re-renders
				// it immediately with a fresh expiry). Without this check,
				// the stale `attempts` count from the PREVIOUS cycle carries
				// over, so the new cycle starts mid-backoff (e.g. straight
				// at the 15s step) instead of at the beginning.
				state.expiresAt !== expiresAt
			) {
				state = { attempts: 0, startTime: Date.now(), expiresAt: expiresAt };
			}

			return state;
		},

		setRetryState: function ( title, state ) {
			mw.storage.session.set( reload.storageKey( title ), JSON.stringify( state ) );
		},

		clearRetryState: function ( title ) {
			mw.storage.session.remove( reload.storageKey( title ) );
		},

		computeBackoff: function ( attempts ) {
			var index = Math.min( attempts, BACKOFF_SEQUENCE.length - 1 );
			return BACKOFF_SEQUENCE[ index ];
		},

		doReload: function () {
			location.reload();
		},

		/**
		 * Dims the page behind a centered spinner while a retry is in
		 * flight, so the editor sees the same "please wait" affordance a
		 * blocking action (e.g. PageForms' popup-form loading state, which
		 * this mirrors conceptually) would give - without it, the retry
		 * loop's own delay looks identical to the page simply being done
		 * loading.
		 *
		 * Uses the native <dialog> element's showModal() rather than a
		 * hand-built fixed-position overlay div: the browser itself renders
		 * the dimmed backdrop (::backdrop) and guarantees top-most stacking,
		 * without this needing its own z-index/opacity handling the way
		 * PageForms' popupform-background does. Supported in every browser
		 * MediaWiki >= 1.35 already targets (no IE11), so no fallback path
		 * is needed here.
		 *
		 * @return {HTMLDialogElement} the dialog, for the caller to close()
		 */
		showOverlay: function () {
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'sdu-reload-dialog';

			var $spinnerWrapper = $( '<div>' ).addClass( 'sdu-reload-dialog-spinner' );
			$spinnerWrapper.append( $.createSpinner( { size: 'large', type: 'block' } ) );

			dialog.appendChild( $spinnerWrapper[ 0 ] );
			document.body.appendChild( dialog );
			dialog.showModal();

			return dialog;
		},

		/**
		 * @param {HTMLDialogElement|null} dialog null if no overlay was
		 *  shown for this attempt (see run()'s state.attempts > 0 check)
		 */
		closeOverlay: function ( dialog ) {
			if ( !dialog ) {
				return;
			}

			dialog.close();
			dialog.remove();
		},

		/**
		 * @param {jQuery} context the `.sdu-reload-pending` element
		 * @return {number} absolute ms timestamp (Date.now() scale) this
		 *  retry loop must stop by
		 */
		getExpiresAt: function ( context ) {
			var serverExpiresAt = parseInt( context.data( 'expires-at' ), 10 );

			if ( !isNaN( serverExpiresAt ) && serverExpiresAt > 0 ) {
				return serverExpiresAt;
			}

			// No server-provided expiry available on this render (see this
			// method's call site's docblock on data-expires-at) - fall back
			// to a fresh full window from now. Deliberately NOT derived from
			// any stored retry state: this is evaluated before
			// getRetryState() decides whether the stored state even still
			// applies to this cycle (see getRetryState()'s own expiresAt
			// comparison), so it must not depend on it.
			return Date.now() + MAX_RETRY_MS;
		},

		/**
		 * @param {jQuery} context the `.sdu-reload-pending` element
		 */
		run: function ( context ) {
			var title = context.data( 'title' ) || mw.config.get( 'wgPageName' );
			var expiresAt = reload.getExpiresAt( context );
			var state = reload.getRetryState( title, expiresAt );

			// Only shown from the second poll onward (state.attempts > 0):
			// the very first render after saving has no evidence yet that a
			// retry is even needed - most saves resolve without one - so
			// showing a blocking modal on every single save of a
			// self-referencing page, before any actual waiting has happened,
			// is disruptive rather than informative. Once a retry has
			// actually been scheduled, the editor IS waiting on a pending
			// reload, and the overlay reflects that.
			var dialog = state.attempts > 0 ? reload.showOverlay() : null;

			if ( Date.now() >= expiresAt ) {
				reload.closeOverlay( dialog );
				reload.clearRetryState( title );
				mw.notify( mw.msg( 'sdu-reload-timeout' ), { type: 'info', autoHide: false } );
				return;
			}

			var delay = reload.computeBackoff( state.attempts );

			new mw.Api().post( { action: 'purge', titles: title } ).then( function () {
				var remaining = expiresAt - Date.now();

				if ( remaining <= delay ) {
					reload.closeOverlay( dialog );
					reload.clearRetryState( title );
					mw.notify( mw.msg( 'sdu-reload-timeout' ), { type: 'info', autoHide: false } );
					return;
				}

				reload.setRetryState( title, {
					attempts: state.attempts + 1,
					startTime: state.startTime,
					expiresAt: expiresAt
				} );

				setTimeout( function () {
					reload.doReload();
				}, delay );
			}, function () {
				// A failed purge attempt is treated the same as running out of
				// retry budget - retrying against a server that is currently
				// erroring is unlikely to help, and could contribute to the
				// server-side load this backoff exists to bound in the first
				// place.
				reload.closeOverlay( dialog );
				reload.clearRetryState( title );
			} );
		},

		init: function ( $content ) {
			var $pending = $content.find( '.sdu-reload-pending' );

			if ( !$pending.length ) {
				// The server no longer considers a reload pending for this
				// page (the self-update-pending marker was cleared or
				// expired) - drop any stale retry state so a later,
				// unrelated cycle for the same title starts with a fresh
				// retry window.
				reload.clearRetryState( mw.config.get( 'wgPageName' ) );
				return;
			}

			$pending.each( function () {
				reload.run( $( this ) );
			} );
		}
	};

	mw.sdu.reload = reload;

	reload.init( $( document ) );

}( jQuery, mediaWiki ) );
