/*!
 * Client-side reload for a self-referencing page whose editor's own
 * post-edit request would otherwise show stale query output - see
 * SDU\Hooks::onOutputPageParserOutput()'s docblock for why SMW's own
 * PostProcHandler/`.smw-postproc` cannot be relied on for this case.
 *
 * Originally modeled on SemanticMediaWiki's ext.smw.util.purge.js
 * (`.page-purge` auto-reload): purge via `action=purge` rather than an
 * unconditional immediate reload, because the store write this page depends
 * on (its own forced self-UpdateJob, still in flight when this script first
 * runs) may not have propagated yet - an immediate reload can show the same
 * stale output the purge is meant to fix.
 *
 * Reload timing itself no longer follows ext.smw.util.purge.js's model of a
 * fixed growing backoff before reloading unconditionally: live measurement
 * (2026-08-10) proved that a page whose derived value takes several genuine
 * (non-empty-diff) UpdateJob passes to stabilize can still be mid-cycle when
 * even the longest fixed delay elapses, reloading onto a stale intermediate
 * value. Instead, after each purge this script asks the SDU\Api\
 * ApiSduSelfUpdateStatus API "is a reload for this exact revision still
 * pending?" and only reloads once the server reports the cycle has actually
 * ended - see checkPending() and run() below. Retry state lives in session
 * storage per title, exactly like ext.smw.util.purge.js, so a page reload
 * during the retry window doesn't reset it.
 *
 * Cycle identity is the page's `data-revision-id`, NOT a server-provided
 * expiry timestamp: an earlier version compared expiry timestamps to detect
 * "is this still the same cycle", which broke silently for any client
 * without a stable session between requests (the server re-derived a fresh
 * expiry on every such request, which this script then misread as "a new
 * save happened" and reset its own attempt count for - see git history on
 * this file for the incident). A revision ID cannot drift like that: it is
 * the same value across however many requests/sessions poll for it, and
 * only a genuinely new save (producing a genuinely new revision) changes it.
 * This is also exactly the identity the sduselfupdatestatus API compares
 * against server-side (SDU\Hooks::isSelfUpdateReloadPending()), so the
 * client and server are always asking about the same cycle.
 */
( function ( $, mw ) {

	'use strict';

	mw.sdu = mw.sdu || {};

	// Ceiling on total time spent retrying a single cycle (ms). Unlike the
	// mechanism this replaces, there is no server-provided expiry to size
	// this from - the server's own bound is SELF_UPDATE_MAX_ATTEMPTS retries
	// at increasing delays (see SDU\Hooks::SELF_UPDATE_RETRY_DELAY_SECONDS),
	// which this ceiling only needs to comfortably outlast, not match
	// exactly: checkPending() reporting "pending: false" (see poll() below)
	// is what actually stops the loop promptly once the server-side cycle
	// resolves; this is only the fallback for a cycle that, for whatever
	// reason, never reports "ended" at all.
	var MAX_RETRY_MS = 60000;

	// How often to ask the server "is this cycle done yet?" via the
	// sduselfupdatestatus API (see run() below). This USED TO be a growing
	// BACKOFF_SEQUENCE that governed the wait before an actual reload - live
	// measurement (2026-08-10) proved that approach unsafe: a page whose
	// derived value takes several genuine (non-empty-diff) UpdateJob passes
	// to stabilize can still be mid-cycle when even the LONGEST fixed delay
	// elapses, so the client reloaded onto a stale intermediate value and
	// only a second, later reload eventually caught the final one - the
	// fixed delay had no way to know how long the server actually needed,
	// because (see this file's own git history) it was deliberately never
	// coupled to the server's own timing in the first place.
	//
	// A short FIXED interval, not a growing sequence, is the right shape
	// once the thing being scheduled is a cheap read-only status check
	// instead of an expensive full-page reload: reloading too often was the
	// real cost BACKOFF_SEQUENCE was rationing against (see this file's own
	// former docblock on it), but polling a small JSON endpoint has no
	// comparable cost to ration - polling more slowly than necessary only
	// adds latency between "the server is actually done" and "the editor
	// finds out", for no corresponding benefit.
	var POLL_INTERVAL_MS = 1000;

	var reload = {
		storageKey: function ( title ) {
			return 'mw-sdu-reload-retry-' + title;
		},

		/**
		 * @param {string} title
		 * @param {string} revisionId this render's cycle identity - see this
		 *  file's own docblock for why this, not a timestamp
		 */
		getRetryState: function ( title, revisionId ) {
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
				// A DIFFERENT revisionId than the one this stored state was
				// last seen with means a NEW save happened while a previous
				// cycle's retry loop was still in flight - see this file's
				// own docblock for why revisionId (not a timestamp) is what
				// reliably signals that, independent of session stability.
				state.revisionId !== revisionId
			) {
				state = { attempts: 0, revisionId: revisionId };
			}

			return state;
		},

		setRetryState: function ( title, state ) {
			mw.storage.session.set( reload.storageKey( title ), JSON.stringify( state ) );
		},

		clearRetryState: function ( title ) {
			mw.storage.session.remove( reload.storageKey( title ) );
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
		 *  shown yet for this poll loop (see run()'s and poll()'s own
		 *  state.attempts/dialog handling for when one is created)
		 */
		closeOverlay: function ( dialog ) {
			if ( !dialog ) {
				return;
			}

			dialog.close();
			dialog.remove();
		},

		/**
		 * Queries the sduselfupdatestatus API (see
		 * SDU\Api\ApiSduSelfUpdateStatus) for whether a reload is still
		 * pending for this exact title+revision - the server-side source of
		 * truth this whole poll loop exists to consult, instead of guessing
		 * from a fixed timer.
		 *
		 * @param {string} title
		 * @param {string} revisionId
		 * @return {jQuery.Promise} resolves with a boolean (true = still
		 *  pending); rejects on a request failure (network error, API
		 *  error response, etc.) - see run()'s own handling of that
		 *  rejection for why a failure here is NOT treated as "assume
		 *  still pending" or "fall back to a fixed delay".
		 */
		checkPending: function ( title, revisionId ) {
			return new mw.Api().get( {
				action: 'sduselfupdatestatus',
				title: title,
				// The API's `revid` parameter is a MediaWiki integer param;
				// revisionId is carried as a string throughout this file
				// (see this file's own docblock on why cycle identity is
				// string-compared throughout), but jQuery serializes a
				// numeric-looking string identically to a number in the
				// query string, so no explicit Number() cast is needed here
				// - MediaWiki's ParamValidator coerces it server-side.
				revid: revisionId
			} ).then( function ( result ) {
				return result.sduselfupdatestatus.pending;
			} );
		},

		/**
		 * Repeatedly checks checkPending() every POLL_INTERVAL_MS until it
		 * reports the cycle has ended, then reloads - purging once more
		 * immediately beforehand so the reload cannot render a parser
		 * output cached from a moment just before the cycle's own last
		 * UpdateJob finished (SMW's own UpdateJob already purges the parser
		 * cache as part of its normal run - see
		 * SMW\MediaWiki\Jobs\UpdateJob::doUpdate() - but that purge and this
		 * poll observing "pending: false" are two independent signals with
		 * no ordering guarantee between them, so this purge closes that gap
		 * rather than assuming the job's own purge already landed).
		 *
		 * The status check itself is cheap and read-only, unlike a purge or
		 * a reload - see POLL_INTERVAL_MS's own docblock for why it is
		 * safe, and intentionally beneficial, to poll it on a short fixed
		 * interval instead of a growing backoff.
		 *
		 * @param {string} title
		 * @param {string} revisionId
		 * @param {number} startTime when THIS cycle's polling began (for
		 *  MAX_RETRY_MS bookkeeping) - fixed for the lifetime of one poll
		 *  loop, unlike attempts below
		 * @param {number} attempts how many still-pending results this
		 *  cycle has already seen - persisted to storage (see
		 *  getRetryState()'s own docblock on why a stored attempts count is
		 *  needed at all) but threaded through as a parameter here rather
		 *  than re-read from storage on every tick, so this loop's own
		 *  count cannot desync from what it just wrote
		 * @param {HTMLDialogElement|null} dialog the overlay for this poll
		 *  loop, once shown - null until the first still-pending result,
		 *  mirroring the previous behaviour of only showing it from the
		 *  second check onward (see run()'s own former condition, now
		 *  applied here since run() itself no longer loops)
		 */
		poll: function ( title, revisionId, startTime, attempts, dialog ) {
			if ( Date.now() - startTime >= MAX_RETRY_MS ) {
				reload.closeOverlay( dialog );
				reload.clearRetryState( title );
				mw.notify( mw.msg( 'sdu-reload-timeout' ), { type: 'info', autoHide: false } );
				return;
			}

			reload.checkPending( title, revisionId ).then( function ( pending ) {
				if ( pending ) {
					var nextAttempts = attempts + 1;

					reload.setRetryState( title, {
						attempts: nextAttempts,
						revisionId: revisionId,
						startTime: startTime
					} );

					setTimeout( function () {
						// Only shown once the cycle is confirmed to still be
						// running: the very first check after saving has no
						// evidence yet that a wait is even needed - most
						// saves resolve before that first check even
						// completes - so showing a blocking modal before any
						// actual waiting has happened would be disruptive
						// rather than informative.
						reload.poll( title, revisionId, startTime, nextAttempts, dialog || reload.showOverlay() );
					}, POLL_INTERVAL_MS );
					return;
				}

				reload.clearRetryState( title );

				new mw.Api().post( { action: 'purge', titles: title } ).then(
					reload.doReload,
					// A failed final purge is treated the same as a failed
					// status check below - see that branch's own comment.
					function () {
						reload.closeOverlay( dialog );
					}
				);
			}, function () {
				// The status check itself failed (network error, API error
				// response, etc.). This is deliberately NOT treated as
				// "assume the cycle is still pending and keep polling" nor
				// as "fall back to reloading after a fixed guess" - both
				// would risk exactly the stale-content bug this poll loop
				// replaces a fixed timer to fix, silently reintroduced in
				// precisely the situation (the server already having
				// trouble) where it is least acceptable. Giving up and
				// letting the editor reload manually once things recover is
				// the safer degradation.
				reload.closeOverlay( dialog );
				reload.clearRetryState( title );
			} );
		},

		/**
		 * @param {jQuery} context the `.sdu-reload-pending` element
		 */
		run: function ( context ) {
			var title = context.data( 'title' ) || mw.config.get( 'wgPageName' );
			var revisionId = String( context.data( 'revision-id' ) || mw.config.get( 'wgCurRevisionId' ) );
			var state = reload.getRetryState( title, revisionId );
			var startTime = state.startTime || Date.now();

			// Shown immediately, without waiting for a fresh poll to
			// confirm the cycle is still running, when state.attempts > 0:
			// that count can only be > 0 here if an EARLIER page load
			// already polled at least once and found the cycle still
			// pending (see poll()'s own setRetryState() call) - e.g. the
			// editor manually reloaded mid-cycle, or the poll loop's own
			// eventual reload landed back on a page still showing
			// .sdu-reload-pending for the same revision. Either way the
			// editor is already known to be waiting on a pending reload, so
			// showing the overlay from THIS render onward (not one more
			// poll later) is the correct continuation, not a new decision.
			var dialog = state.attempts > 0 ? reload.showOverlay() : null;

			reload.setRetryState( title, {
				attempts: state.attempts,
				revisionId: revisionId,
				startTime: startTime
			} );

			// An initial purge before the first status check, exactly like
			// the mechanism this replaces - the store write this page
			// depends on (its own forced self-UpdateJob) may already have
			// landed by the time this script first runs, and this purge
			// gives the earliest possible status check a freshly-rendered
			// page to reflect once it does reload, rather than only
			// purging right before the final reload at the end of poll().
			new mw.Api().post( { action: 'purge', titles: title } ).then( function () {
				reload.poll( title, revisionId, startTime, state.attempts, dialog );
			}, function () {
				// A failed initial purge is treated the same as any other
				// failure in this mechanism - see poll()'s own rejection
				// handler for why giving up, not guessing, is the safer
				// degradation.
				reload.closeOverlay( dialog );
				reload.clearRetryState( title );
			} );
		},

		init: function ( $content ) {
			var $pending = $content.find( '.sdu-reload-pending' );

			if ( !$pending.length ) {
				// The server no longer considers a reload pending for this
				// page (the self-update-pending marker was cleared or
				// expired, or the cycle ended) - drop any stale retry state
				// so a later, unrelated cycle for the same title starts with
				// a fresh retry window.
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
