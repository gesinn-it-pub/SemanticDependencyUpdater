<?php

namespace SDU;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\MediaWiki\Jobs\UpdateJob;
use SMW\SemanticData;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMW\Store;
use SMWDIBlob;
use SMWQueryProcessor;
use WikiPage;

class Hooks {

	/**
	 * Wrapper for debug logging.
	 * Only logs if the SemanticDependencyUpdater debug group is enabled.
	 */
	private static function debugLog( string $message ): void {
		global $wgDebugLogGroups;

		if ( !isset( $wgDebugLogGroups['SemanticDependencyUpdater'] ) ) {
			return;
		}

		wfDebugLog( 'SemanticDependencyUpdater', $message );
	}

	public static function setup() {
		if ( !defined( 'MEDIAWIKI' ) ) {
			die();
		}

		if ( !defined( 'SMW_VERSION' ) ) {
			die( "ERROR: Semantic MediaWiki must be installed for Semantic Dependency Updater to run!" );
		}
	}

	/**
	 * Trigger dependency updates when a page is about to be deleted.
	 *
	 * Hooked on ArticleDelete (fires before the page and its SMW data are
	 * actually removed), not PageDeleteComplete (fires after): SMW's own
	 * ArticleDelete handler deletes the page's semantic data via a
	 * DeferredUpdate whose execution timing depends on request context
	 * (Site::isCommandLineMode()) - genuinely deferred to POSTSEND in a web
	 * request, but run synchronously and immediately in CLI/job-queue
	 * context. Since SDU requires SemanticMediaWiki and therefore loads
	 * after it, SMW's own ArticleDelete handler is registered - and thus
	 * runs - before this one regardless of context, so hooking the same
	 * event (rather than PageDeleteComplete) reliably sees the semantic data
	 * while it still exists, independent of that timing difference.
	 */
	public static function onPageDelete(
		WikiPage $wikiPage,
		User $user,
		string &$reason,
		string &$error,
		Status $status,
		bool $suppress
	) {
		self::debugLog(
			"[SDU] ArticleDelete detected, loading semantic data before removal"
		);

		$title = $wikiPage->getTitle();

		$store = smwfGetStore();

		$diWikiPage = DIWikiPage::newFromTitle( $title );

		$semanticData = $store->getSemanticData( $diWikiPage );

		if ( $semanticData == null ) {
			self::debugLog(
				"[SDU] <-- No semantic data available during delete"
			);
			return true;
		}

		// Trigger dependency rebuild without diff iterator
		self::runDependencyUpdateOnDelete( $semanticData );

		return true;
	}

	/**
	 * Runs dependency updates for deleted pages.
	 * Always triggers because the page is being removed.
	 */
	private static function runDependencyUpdateOnDelete( SemanticData $semanticData ): void {
		global $wgSDUProperty;

		$wgSDUProperty = str_replace( ' ', '_', $wgSDUProperty );

		$subject = $semanticData->getSubject();
		$title = $subject->getTitle();

		if ( $title == null ) {
			return;
		}

		self::debugLog(
			"[SDU] <-- Triggering dependency updates, page was deleted: " . $title
		);

		$properties = $semanticData->getProperties();

		if ( !isset( $properties[$wgSDUProperty] ) ) {
			self::debugLog(
				"[SDU] <-- Deleted page had no SDU property '{$wgSDUProperty}'"
			);
			return;
		}

		$dataItem = $semanticData->getPropertyValues( $properties[$wgSDUProperty] );

		if ( $dataItem == null ) {
			return;
		}

		self::debugLog(
			"[SDU] Dependency values count=" . count( $dataItem )
		);

		$wikiPageValues = [];

		foreach ( $dataItem as $valueItem ) {

			if ( $valueItem instanceof SMWDIBlob ) {

				self::debugLog(
					"[SDU] Dependency raw value=" . $valueItem->getSerialization()
				);

				$wikiPageValues = array_merge(
					$wikiPageValues,
					self::updatePagesMatchingQuery( $valueItem->getSerialization() )
				);
			}
		}

		self::rebuildData( true, $wikiPageValues, $subject );
	}

	/**
	 * Resolves the property names configured via $wgSDUIgnoredProperties to
	 * their numeric SMW object IDs, so that changes limited to those
	 * properties (e.g. a Revision ID property provided by an extension like
	 * SemanticExtraSpecialProperties, which changes on every edit regardless
	 * of semantic content) don't trigger dependency updates.
	 *
	 * Property IDs are assigned per-installation (except for SMW's own fixed
	 * IDs), so they cannot be hardcoded; they must be resolved via the store
	 * at runtime instead. Only SQLStore exposes the object ID lookup this
	 * requires - other Store implementations skip the filter entirely rather
	 * than fail, matching this hook's registration on
	 * SMW::SQLStore::AfterDataUpdateComplete specifically.
	 *
	 * @return int[]
	 */
	private static function getIgnoredPropertyIds( Store $store ): array {
		global $wgSDUIgnoredProperties;

		if ( !$store instanceof \SMW\SQLStore\SQLStore || $wgSDUIgnoredProperties === [] ) {
			return [];
		}

		$ids = [];

		foreach ( $wgSDUIgnoredProperties as $propertyName ) {
			try {
				$property = DIProperty::newFromUserLabel( $propertyName );
			} catch ( \SMW\Exception\PropertyLabelNotResolvedException ) {
				// A "_"-prefixed name (e.g. the default ___REVID) that is not
				// registered as a predefined property, because the extension
				// providing it (e.g. SemanticExtraSpecialProperties) is not
				// installed - DIProperty's constructor throws for this instead
				// of returning a falsy ID, unlike ordinary unknown labels.
				continue;
			}

			$id = $store->getObjectIds()->getSMWPropertyID( $property );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Seconds to delay each successive retried self-UpdateJob by, so it runs
	 * after (not immediately following) the UpdateJob whose empty re-parse
	 * triggered the retry - giving a lagging store write (e.g. replication
	 * lag) time to actually catch up before the next attempt reads it.
	 *
	 * This is a safety net for setups with REAL replication lag (e.g.
	 * multi-DB, replica reads), not a rate-limiting or throttling
	 * mechanism - retries should otherwise run as soon as possible. Kept
	 * deliberately short: the case this exists for is transient
	 * replication lag, typically resolved within a couple of seconds. A
	 * job queue backend without delayed-job support (see this constant's
	 * one call site) skips this delay entirely and retries immediately,
	 * which the code has always treated as an acceptable smaller
	 * improvement, not a degraded/incorrect state - this sequence being
	 * short does not change that, it only shrinks the gap between the two
	 * paths.
	 *
	 * An increasing sequence, not a fixed delay: the common case (a short
	 * replication lag) resolves on the first or second attempt, so keeping
	 * the EARLY delays short matters more than a longer fixed delay ever
	 * helping the common case - while a page that genuinely needs several
	 * attempts still benefits from later attempts spacing out a bit
	 * further, rather than hammering the store at the same short interval
	 * every time. Indexed by (attempt - 1); an attempt beyond the
	 * sequence's length is never reached, since SELF_UPDATE_MAX_ATTEMPTS is
	 * derived FROM this sequence's length (see that constant) rather than
	 * being tuned independently of it.
	 */
	private const SELF_UPDATE_RETRY_DELAY_SECONDS = [ 1, 1, 2, 3 ];

	/**
	 * Maximum number of retry attempts for a single self-update cycle -
	 * derived from SELF_UPDATE_RETRY_DELAY_SECONDS's own length rather than
	 * a separately-tuned number, so the two can never drift out of sync
	 * (e.g. a delay sequence with 4 entries but a limit of 3 would mean the
	 * 4th, longest-delayed retry is scheduled but then immediately hits the
	 * attempt-limit branch instead of ever running).
	 *
	 * Enforced via the attempt count stored in the self-update-pending
	 * marker itself (see markSelfUpdatePending()), NOT via $wgSDUTraversed:
	 * that global is process-local, but every retry normally runs as its own
	 * job-runner invocation (a fresh PHP process with $wgSDUTraversed reset
	 * to empty), so it cannot actually bound anything across real job queue
	 * runs. Only a value that survives between processes - the ObjectCache
	 * marker - can enforce a limit that holds in production, not just within
	 * a single request that happens to drain several jobs synchronously
	 * (e.g. in tests).
	 */
	private const SELF_UPDATE_MAX_ATTEMPTS = 4; // count( self::SELF_UPDATE_RETRY_DELAY_SECONDS ) - PHP forbids a non-literal const expression here; kept in sync by hand, see that constant's own docblock.

	/**
	 * TTL for the self-update-pending marker set in markSelfUpdatePending().
	 * Must comfortably outlast the LARGEST single step in
	 * SELF_UPDATE_RETRY_DELAY_SECONDS plus however long the job queue takes
	 * to pick the job back up, or a slow queue could
	 * see the marker expire before the delayed retry job even runs, wrongly
	 * treating that retry's own empty re-parse as unrelated to SDU again.
	 * Refreshed on every retry (see markSelfUpdatePending()), so this only
	 * needs to cover the gap between consecutive attempts, not the whole
	 * cycle.
	 */
	private const SELF_UPDATE_PENDING_TTL_SECONDS = 300;

	private const SELF_UPDATE_PENDING_CACHE_NAMESPACE = 'sdu:self-update-pending';

	/**
	 * Same TTL rationale as SELF_UPDATE_PENDING_TTL_SECONDS: pending remote
	 * targets are set once (on the save that first discovers them) and must
	 * survive until the self-update cycle they are held back for actually
	 * ends, which can take several job-queue-runner round trips.
	 */
	private const PENDING_REMOTE_TARGETS_TTL_SECONDS = 300;

	private const PENDING_REMOTE_TARGETS_CACHE_NAMESPACE = 'sdu:pending-remote-targets';

	/**
	 * Returns MediaWiki's own main object stash - deliberately not SMW's
	 * ApplicationFactory::getObjectCache(), which resolves to $smwgMainCacheType
	 * (a setting SDU has no reason to be coupled to; it's SMW-internal
	 * cache configuration, not a general-purpose store). getMainObjectStash()
	 * is MediaWiki's own dedicated service for exactly this kind of small,
	 * short-lived, cross-request marker.
	 *
	 * If it resolves to an EmptyBagOStuff (i.e. no real cache configured -
	 * $wgMainStash left at its no-op default), every set()/delete() call
	 * silently succeeds (returns true) while get() always returns false, so
	 * the self-update-pending marker can never actually be read back. That
	 * would make retrySelfUpdateIfWithinTraversalLimit() (see below) treat
	 * every self-UpdateJob's empty re-parse as unrelated store activity and
	 * silently never retry - no error, just quietly non-functional. A
	 * warning is logged once per request the first time this is detected,
	 * so the reason "Update Self" isn't retrying is at least discoverable.
	 */
	private static function getSelfUpdateMarkerCache(): \Wikimedia\ObjectCache\BagOStuff {
		static $warned = false;

		$cache = MediaWikiServices::getInstance()->getMainObjectStash();

		if (
			!$warned &&
			$cache->getQoS( $cache::ATTR_DURABILITY ) === $cache::QOS_DURABILITY_NONE
		) {
			$warned = true;
			wfLogWarning(
				'SemanticDependencyUpdater: no durable cache is configured ' .
				'(MediaWikiServices::getMainObjectStash() resolved to a no-op ' .
				'store). The "Update Self" retry mechanism requires a marker ' .
				'that survives between job queue runs and will silently never ' .
				'retry until $wgMainStash (or the default cache) is configured ' .
				'with a real backend.'
			);
		}

		return $cache;
	}

	/**
	 * Marks a page as having a forced self-UpdateJob in flight and returns
	 * the attempt number this call represents (1 for the initial push, 2+
	 * for each subsequent retry), so that a later AfterDataUpdateComplete
	 * call finding no semantic change on that same page can tell "this is
	 * that job's re-parse, possibly needing a retry" apart from an
	 * unrelated, independent store update on the same page that also
	 * happens to find nothing changed (e.g. from some other extension's
	 * job) - which must NOT be retried, since it has nothing to do with
	 * SDU's "Update Self" store-timing gap.
	 *
	 * Without the marker itself, retrySelfUpdateIfWithinTraversalLimit()
	 * would fire on every empty diff for any page carrying the SDU
	 * property, not just ones actually waiting on a self-triggered
	 * re-parse. Without the attempt count living in the marker (rather than
	 * in $wgSDUTraversed), the retry limit would not hold across real job
	 * queue runs - see SELF_UPDATE_MAX_ATTEMPTS.
	 */
	private static function markSelfUpdatePending( string $id ): int {
		$cache = self::getSelfUpdateMarkerCache();
		$key = smwfCacheKey( self::SELF_UPDATE_PENDING_CACHE_NAMESPACE, $id );

		$attempt = (int)$cache->get( $key ) + 1;

		$cache->set( $key, $attempt, self::SELF_UPDATE_PENDING_TTL_SECONDS );

		self::debugLog( "[SDU] markSelfUpdatePending: {$id} attempt={$attempt}" );

		return $attempt;
	}

	private static function getSelfUpdateAttempt( string $id ): int {
		return (int)self::getSelfUpdateMarkerCache()->get(
			smwfCacheKey( self::SELF_UPDATE_PENDING_CACHE_NAMESPACE, $id )
		);
	}

	private const CONSECUTIVE_EMPTY_DIFFS_CACHE_NAMESPACE = 'sdu:consecutive-empty-diffs';

	/**
	 * How many CONSECUTIVE empty diffs in a row end a self-update cycle
	 * early, before SELF_UPDATE_MAX_ATTEMPTS is reached.
	 *
	 * A single empty diff is genuinely ambiguous (see
	 * retrySelfUpdateIfWithinTraversalLimit()'s own docblock): it could mean
	 * the derived value has already stabilized, OR that the store write it
	 * depends on hasn't propagated yet (real replication lag), in which case
	 * the VERY NEXT attempt would find a real change. Bailing out on the
	 * FIRST empty diff would reintroduce exactly the bug "Update Self" exists
	 * to prevent - a page whose derived value never gets the chance to
	 * resolve.
	 *
	 * TWO consecutive empty diffs are a much stronger signal: replication lag
	 * that hasn't resolved after one full retry round (delay + re-parse) is
	 * far more likely to mean the value is simply stable now, not that it
	 * needs a THIRD attempt - verified live: pages with several genuine,
	 * non-empty passes in a row (multiple dependent subobjects settling one
	 * after another) reliably stop producing new diffs within one or two
	 * retries once they're actually done, not somewhere later in
	 * SELF_UPDATE_MAX_ATTEMPTS's full budget. Ending the cycle here, rather
	 * than always running out the full attempt budget regardless of whether
	 * anything is still changing, is what actually fixes the "page keeps
	 * reloading long after the visible content is already correct" symptom -
	 * verified live against a real multi-answer PageForms save that
	 * previously reloaded 5 times before this constant existed, with the
	 * last 3 of those attempts each finding nothing.
	 */
	private const MAX_CONSECUTIVE_EMPTY_DIFFS = 2;

	/**
	 * @return int the CURRENT consecutive-empty-diff count, i.e. the count
	 *  BEFORE this call's own increment - so the caller can compare it
	 *  against MAX_CONSECUTIVE_EMPTY_DIFFS to decide whether THIS diff is the
	 *  one that ends the cycle
	 */
	private static function incrementConsecutiveEmptyDiffs( string $id ): int {
		$cache = self::getSelfUpdateMarkerCache();
		$key = smwfCacheKey( self::CONSECUTIVE_EMPTY_DIFFS_CACHE_NAMESPACE, $id );

		$count = (int)$cache->get( $key ) + 1;
		$cache->set( $key, $count, self::SELF_UPDATE_PENDING_TTL_SECONDS );

		return $count;
	}

	private static function clearConsecutiveEmptyDiffs( string $id ): void {
		self::getSelfUpdateMarkerCache()->delete(
			smwfCacheKey( self::CONSECUTIVE_EMPTY_DIFFS_CACHE_NAMESPACE, $id )
		);
	}

	/**
	 * Test seam exposing the self-update-pending marker's current attempt
	 * count (0 if no marker is set), so tests can assert on this internal
	 * state directly rather than only inferring it from downstream job
	 * counts - job-count-only assertions previously let a page get falsely
	 * marked as self-update-pending (see onAfterDataUpdateComplete()'s
	 * $triggerSemanticDependencies guard around markSelfUpdatePending())
	 * without any test catching it, since the false marker had no
	 * observable effect on job counts in most tested scenarios.
	 *
	 * @internal for tests only
	 */
	public static function getSelfUpdatePendingAttemptForTesting( string $id ): int {
		return self::getSelfUpdateAttempt( $id );
	}

	/**
	 * Holds a self-referencing page's OTHER (non-self) "Semantic Dependency"
	 * targets back from being pushed as UpdateJobs immediately, so they
	 * don't race the self-update cycle - see clearSelfUpdatePending()'s own
	 * docblock for why running them together, unordered, in the same job
	 * queue let a remote target's UpdateJob read stale self data (a random
	 * job_random queue-pop order means push order alone can't fix this - the
	 * remote push has to happen ONLY once self is actually done, not merely
	 * "pushed at the same time or earlier").
	 *
	 * Serializes each target via DIWikiPage::getSerialization() (mirroring
	 * how markReloadPending() et al. already serialize SMW dataitems for
	 * cache storage) rather than storing Title objects directly - Title is
	 * not guaranteed serializable across cache backends/processes the way a
	 * plain string is.
	 *
	 * @param string $id
	 * @param DIWikiPage[] $remoteTargets
	 */
	private static function markPendingRemoteTargets( string $id, array $remoteTargets ): void {
		if ( !$remoteTargets ) {
			return;
		}

		$cache = self::getSelfUpdateMarkerCache();
		$key = smwfCacheKey( self::PENDING_REMOTE_TARGETS_CACHE_NAMESPACE, $id );

		$serialized = array_map(
			static fn ( DIWikiPage $target ) => $target->getSerialization(),
			$remoteTargets
		);

		self::debugLog(
			"[SDU] markPendingRemoteTargets: {$id} holding back " . count( $serialized ) . " remote target(s)"
		);

		$cache->set( $key, $serialized, self::PENDING_REMOTE_TARGETS_TTL_SECONDS );
	}

	/**
	 * Reads back and clears whatever remote targets markPendingRemoteTargets()
	 * held back for this page, so clearSelfUpdatePending() can push them
	 * exactly once, at the point the self-update cycle they were waiting on
	 * actually ends - see that method's own docblock.
	 *
	 * @return DIWikiPage[] empty if none were held back (the common case -
	 *  most self-referencing pages have no OTHER dependency targets at all)
	 */
	private static function takeAndClearPendingRemoteTargets( string $id ): array {
		$cache = self::getSelfUpdateMarkerCache();
		$key = smwfCacheKey( self::PENDING_REMOTE_TARGETS_CACHE_NAMESPACE, $id );

		$serialized = $cache->get( $key );

		if ( !$serialized || !is_array( $serialized ) ) {
			return [];
		}

		$cache->delete( $key );

		return array_values( array_filter( array_map(
			static function ( $item ) {
				try {
					return DIWikiPage::doUnserialize( $item );
				} catch ( \Exception ) {
					// A malformed cache entry (e.g. from a version mismatch
					// after a deploy) must not fatal the self-update cycle
					// this runs at the end of - dropping just that one
					// target is a far smaller blast radius than losing the
					// whole cycle-end handling.
					return null;
				}
			},
			$serialized
		) ) );
	}

	private static function clearSelfUpdatePending( string $id ): void {
		self::getSelfUpdateMarkerCache()->delete(
			smwfCacheKey( self::SELF_UPDATE_PENDING_CACHE_NAMESPACE, $id )
		);

		// The cycle this attempt count belonged to is now over (either
		// resolved or abandoned) - clear the reload-pending marker directly
		// rather than tracking "ended" as a separate state alongside it: an
		// earlier version of this method kept the two as independent cache
		// entries with independent TTLs, which could (and, verified live,
		// did) leave a stale "cycle ended" marker from a PREVIOUS revision's
		// cycle still active when a NEW revision's cycle started moments
		// later, wrongly suppressing that brand new cycle's own reload
		// prompt. Deleting the one marker that getReloadPendingRevId() itself
		// reads removes the second state entirely, so there is nothing left
		// that can go stale independently of it.
		self::getSelfUpdateMarkerCache()->delete(
			smwfCacheKey( self::RELOAD_PENDING_CACHE_NAMESPACE, $id )
		);

		// This page's own self-update cycle just ended (by whichever of
		// this method's several callers - resolved, attempt-limit reached,
		// or no longer self-referencing) - ANY remote dependency targets
		// held back by markPendingRemoteTargets() while that cycle was
		// running can now safely be pushed. Doing this here, in the one
		// method every cycle-end path already funnels through, rather than
		// duplicating this call at each of those call sites individually,
		// means no future new cycle-end path can forget it.
		$remoteTargets = self::takeAndClearPendingRemoteTargets( $id );

		if ( $remoteTargets ) {
			self::debugLog(
				"[SDU] clearSelfUpdatePending: {$id} self-update cycle ended, " .
				"releasing " . count( $remoteTargets ) . " held-back remote target(s)"
			);

			self::rebuildData( true, $remoteTargets, null );
		}

		// The empty-diff streak counted toward MAX_CONSECUTIVE_EMPTY_DIFFS
		// belonged to this now-ended cycle too - a LATER, unrelated cycle for
		// this same page must start its own streak from zero, not inherit
		// one left over from a cycle that already finished.
		self::clearConsecutiveEmptyDiffs( $id );
	}

	/**
	 * TTL for the reload-pending marker set in markReloadPending(). Only
	 * needs to outlast the self-update cycle itself (bounded by
	 * SELF_UPDATE_MAX_ATTEMPTS retries, each delayed by at most the largest
	 * step in SELF_UPDATE_RETRY_DELAY_SECONDS) plus however long the
	 * editor's browser takes to poll again - not an open-ended session
	 * window, unlike the mechanism this replaces.
	 */
	private const RELOAD_PENDING_TTL_SECONDS = 60;

	private const RELOAD_PENDING_CACHE_NAMESPACE = 'sdu:reload-pending';

	/**
	 * Marks a self-referencing page ($isSelfReferencing in
	 * onAfterDataUpdateComplete()) as needing a client-side reload for the
	 * given revision, the next time that revision's page is displayed.
	 *
	 * Why this is needed: SMW's own PostProcHandler decides whether to show
	 * its reload prompt by re-deriving the save's ChangeDiff from a cache
	 * keyed only by page (SMW\SQLStore\ChangeOp\ChangeDiff::CACHE_NAMESPACE +
	 * subject hash - one slot per page, not per revision or request). The
	 * forced self-UpdateJob this same onAfterDataUpdateComplete() call is
	 * about to queue (see rebuildData() below) re-parses this very page
	 * again - by design, per "Update Self" - and its own AfterDataUpdateComplete
	 * call overwrites that single cache slot with an empty diff (nothing
	 * changed on that second pass), before the editor's browser ever gets to
	 * request the page again. PostProcHandler::checkDiff() then reads that
	 * empty diff instead of this save's real one and reports "no user
	 * change", so SMW's own postproc div - and the reload prompt it would
	 * have shown - never renders, even though a real, non-ignored property
	 * did change. This marker preserves that "a real change just happened"
	 * fact through the self-UpdateJob's overwrite, entirely independent of
	 * ChangeDiff's cache.
	 *
	 * Keyed by revision ID, not by an absolute expiry timestamp: an earlier
	 * version of this mechanism kept a session-scoped authorization
	 * alongside a time-based marker, and re-derived a fresh expiry on every
	 * request from a client with no stable session between requests
	 * (observed live, not just in tests) - which the client read as "a new
	 * save cycle started" and reset its own retry-attempt count for,
	 * silently defeating the backoff dialog while the page kept reloading
	 * anyway. The revision ID that triggered this exact cycle is stable
	 * across however many requests/sessions poll for it - it cannot change
	 * except by a genuinely new save producing a genuinely new revision -
	 * so it is what the client and server actually need to agree on "is
	 * this still the same cycle", not a value derived from request timing.
	 */
	private static function markReloadPending( string $id, int $revId ): void {
		$cache = self::getSelfUpdateMarkerCache();
		$key = smwfCacheKey( self::RELOAD_PENDING_CACHE_NAMESPACE, $id );

		self::debugLog( "[SDU] markReloadPending: {$id} revId={$revId}" );

		$cache->set( $key, $revId, self::RELOAD_PENDING_TTL_SECONDS );
	}

	/**
	 * @return int|false the revision ID a reload is pending for, or false if
	 *  none is pending
	 */
	private static function getReloadPendingRevId( string $id ) {
		return self::getSelfUpdateMarkerCache()->get(
			smwfCacheKey( self::RELOAD_PENDING_CACHE_NAMESPACE, $id )
		);
	}

	/**
	 * Test seam mirroring getSelfUpdatePendingAttemptForTesting().
	 *
	 * @internal for tests only
	 */
	public static function isReloadPendingForTesting( string $id, int $revId ): bool {
		return self::getReloadPendingRevId( $id ) === $revId;
	}

	/**
	 * @return bool whether a reload is currently pending for the EXACT
	 *  given revision of the given page - i.e. whether ext.sdu.reload.js
	 *  (via SDU\Api\ApiSduSelfUpdateStatus) is still safe to keep polling,
	 *  or the self-update cycle it is waiting on has ended (resolved, or
	 *  exhausted SELF_UPDATE_MAX_ATTEMPTS) and it is finally safe to reload.
	 *
	 * Public production entry point for the same underlying check
	 * isReloadPendingForTesting() exposes for tests - kept as a separate
	 * method (both simply delegate to getReloadPendingRevId()) rather than
	 * widening that method's visibility or reusing the test seam directly
	 * from production code, so the test-only seam's name and contract can
	 * keep evolving independently of what production callers rely on.
	 */
	public static function isSelfUpdateReloadPending( string $id, int $revId ): bool {
		return self::getReloadPendingRevId( $id ) === $revId;
	}

	/**
	 * Re-pushes a forced self-UpdateJob for a page whose own re-parse (from
	 * an earlier SDU-triggered UpdateJob) found no semantic change at all,
	 * as long as the self-update-pending marker hasn't reached
	 * SELF_UPDATE_MAX_ATTEMPTS for this page.
	 *
	 * This is the retry half of the "Update Self" feature: without it, a
	 * page relying on a live store query against its own just-written data
	 * (e.g. `{{Self|X}}`) could see the follow-up UpdateJob's re-parse land
	 * before the underlying store write it depends on has fully propagated,
	 * and give up with the derived value still unresolved. See
	 * onAfterDataUpdateComplete()'s "No semantic data changes detected"
	 * branch for why this deliberately doesn't try to distinguish that case
	 * from "the derived value already resolved, nothing left to do" - both
	 * look identical here (an empty diff), and retrying the harmless case
	 * just costs one bounded extra UpdateJob.
	 *
	 * Always goes through the real job queue with a delay (never
	 * rebuildData()'s synchronous $job->run() path, which would re-run
	 * immediately in the same request and give a lagging store no time to
	 * catch up).
	 */
	private static function retrySelfUpdateIfWithinTraversalLimit( string $id, DIWikiPage $subject ): void {
		// See SELF_UPDATE_MAX_ATTEMPTS's own docblock: it is meant to always
		// equal count( SELF_UPDATE_RETRY_DELAY_SECONDS ), but PHP does not
		// allow that to be expressed as the const's own initializer, so it
		// is kept in sync by hand - this assertion catches the two constants
		// drifting apart (e.g. someone adding a 5th delay step without also
		// bumping the attempt limit) immediately and loudly, rather than
		// silently scheduling a retry job whose own delay index doesn't
		// exist. Phan can see both constants are currently literally equal
		// and flags this comparison as always false - true today, but the
		// whole point is to keep catching it if that ever stops being true.
		// @phan-suppress-next-line PhanImpossibleValueComparison
		if ( self::SELF_UPDATE_MAX_ATTEMPTS !== count( self::SELF_UPDATE_RETRY_DELAY_SECONDS ) ) {
			throw new \LogicException(
				'SELF_UPDATE_MAX_ATTEMPTS (' . self::SELF_UPDATE_MAX_ATTEMPTS . ') must equal ' .
				'count( SELF_UPDATE_RETRY_DELAY_SECONDS ) (' . count( self::SELF_UPDATE_RETRY_DELAY_SECONDS ) . ')'
			);
		}

		if ( self::getSelfUpdateAttempt( $id ) === 0 ) {
			// No self-UpdateJob is in flight for this page - this empty diff
			// is from an unrelated store update (e.g. another extension's
			// job touching the same page) and has nothing to do with SDU's
			// "Update Self" store-timing gap, so it must not be retried.
			return;
		}

		$jobTitle = $subject->getTitle();

		if ( $jobTitle === null ) {
			self::clearSelfUpdatePending( $id );
			return;
		}

		$attempt = self::markSelfUpdatePending( $id );

		if ( $attempt > self::SELF_UPDATE_MAX_ATTEMPTS ) {
			self::debugLog( "[SDU] <-- Already traversed, not retrying self-update" );
			self::clearSelfUpdatePending( $id );
			return;
		}

		// This IS an empty diff (that's why this method was called at all -
		// see onAfterDataUpdateComplete()'s "No semantic data changes
		// detected" branch, its only caller). See
		// MAX_CONSECUTIVE_EMPTY_DIFFS's own docblock for why two of these IN
		// A ROW - not the attempt limit alone - end the cycle early: still
		// bounded (this can never run longer than SELF_UPDATE_MAX_ATTEMPTS
		// would anyway, since $attempt already can't exceed it past this
		// point), but stops as soon as the value has demonstrably settled,
		// rather than always waiting out the full budget.
		$consecutiveEmptyDiffs = self::incrementConsecutiveEmptyDiffs( $id );

		if ( $consecutiveEmptyDiffs >= self::MAX_CONSECUTIVE_EMPTY_DIFFS ) {
			self::debugLog(
				"[SDU] <-- {$consecutiveEmptyDiffs} consecutive empty diffs, " .
				"value has settled - ending self-update cycle early"
			);
			self::clearSelfUpdatePending( $id );
			return;
		}

		self::debugLog( "[SDU] <-- Retrying self-update (attempt {$attempt})" );

		$jobParams = [
			UpdateJob::FORCED_UPDATE => true,
			'shallowUpdate' => false,
		];

		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();

		// Not every job queue backend supports delayed jobs (e.g. the plain
		// DB-backed queue does not) - pushing a delayed job to one throws a
		// hard JobQueueError, so only add the delay where it's actually
		// supported. Without it, the retry still runs (just immediately
		// rather than after this attempt's own delay step), which is a
		// smaller improvement, not a failure.
		if ( $jobQueueGroup->get( 'smw.update' )->delayedJobsEnabled() ) {
			// $attempt is 1-indexed (see markSelfUpdatePending()); the delay
			// sequence is indexed by (attempt - 1).
			$jobParams['jobReleaseTimestamp'] = time() + self::SELF_UPDATE_RETRY_DELAY_SECONDS[ $attempt - 1 ];
		}

		$job = ApplicationFactory::getInstance()->newJobFactory()->newUpdateJob( $jobTitle, $jobParams );

		$jobQueueGroup->lazyPush( $job );
	}

	public static function onAfterDataUpdateComplete(
		Store $store,
		SemanticData $newData,
		$compositePropertyTableDiffIterator
	) {
		global $wgSDUProperty;
		global $wgSDUTraversed;

		// $wgSDUTraversed is a process-local cache, not a declared extension.json config
		// variable, so it is genuinely unset on first use.
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( !isset( $wgSDUTraversed ) ) {
			$wgSDUTraversed = [];
		}

		$wgSDUProperty = str_replace( ' ', '_', $wgSDUProperty );

		$subject = $newData->getSubject();
		$title = $subject->getTitle();

		if ( $title == null ) {
			return true;
		}

		$id = $title->getPrefixedDBKey();

		self::debugLog(
			"[SDU] Subject={$id} SMW-SID=" . $subject->getId()
		);

		self::debugLog( "[SDU] --> " . $title );

		$properties = $newData->getProperties();

		self::debugLog(
			"[SDU] Properties found: " . implode( ", ", array_keys( $properties ) )
		);

		if ( !isset( $properties[$wgSDUProperty] ) ) {
			self::debugLog(
				"[SDU] <-- No SDU property '{$wgSDUProperty}' found"
			);
			return true;
		}

		$diffTable = $compositePropertyTableDiffIterator->getOrderedDiffByTable();

		self::debugLog(
			"[SDU] Diff tables: " . implode( ", ", array_keys( $diffTable ) )
		);

		unset( $diffTable['smw_fpt_mdat'] );

		// SMW's own query-management bookkeeping (the "_ASK*" special
		// properties documented on TypesRegistry: query string/format/size/
		// depth/duration/source/parameters/status code for embedded
		// {{#ask:}} queries on the page) can produce its own diff, completely
		// independent of any user-authored content, whenever a page with an
		// embedded query is (re-)parsed as a side effect of an UNRELATED
		// page's forced self-UpdateJob (e.g. a remote dependency target's own
		// UpdateJob touching a Site page's query-dependency bookkeeping while
		// re-parsing itself). Left unfiltered, this diff never sets
		// $triggerSemanticDependencies (none of its tables start with
		// "smw_di", so the scan below never even inspects it - see that
		// loop's own `strpos($key,'smw_di')` check) and so trips the "only
		// ignored properties changed" branch without actually going through
		// it, silently leaving a still-set reload-pending marker (see
		// markReloadPending()) unresolved instead of extending or clearing
		// it - the marker then simply times out client-side (see
		// ext.sdu.reload.js's MAX_RETRY_MS) rather than the reload ever
		// firing, even though the page's real content had already
		// stabilized. Same category of noise as smw_fpt_mdat above, filtered
		// the same way.
		foreach ( [ 'smw_fpt_ask', 'smw_fpt_askst', 'smw_fpt_askfo', 'smw_fpt_asksi', 'smw_fpt_askde',
			'smw_fpt_askdu', 'smw_fpt_asksc', 'smw_fpt_askpa', 'smw_fpt_askco' ] as $queryBookkeepingTable ) {
			unset( $diffTable[$queryBookkeepingTable] );
		}

		self::debugLog(
			"[SDU] Diff tables after filtering: " . implode( ", ", array_keys( $diffTable ) )
		);

		$ignoredPropertyIds = self::getIgnoredPropertyIds( $store );

		$triggerSemanticDependencies = false;

		if ( count( $diffTable ) > 0 ) {

			self::debugLog( "[SDU] -----> Data changes detected" );

			self::debugLog(
				"[SDU] Scanning diffTable for semantic changes..."
			);

			foreach ( $diffTable as $key => $value ) {

				if ( strpos( $key, 'smw_di' ) !== 0 || !is_array( $value ) ) {
					continue;
				}

				foreach ( [ 'insert', 'delete' ] as $op ) {

					if ( !isset( $value[$op] ) || !is_array( $value[$op] ) ) {
						continue;
					}

					foreach ( $value[$op] as $change ) {

						self::debugLog(
							"[SDU] " . strtoupper( $op ) .
							" detected: table={$key} s_id={$change["s_id"]} p_id={$change["p_id"]}"
						);

						if ( !in_array( $change["p_id"], $ignoredPropertyIds, true ) ) {
							$triggerSemanticDependencies = true;
							break 3;
						}
					}
				}
			}

			self::debugLog(
				"[SDU] triggerSemanticDependencies=" . ( $triggerSemanticDependencies ? "true" : "false" )
			);

			if ( !$triggerSemanticDependencies ) {
				// Every changed property is on $wgSDUIgnoredProperties (e.g.
				// SESP's ___REVID, which changes on every edit regardless of
				// semantic content) - per that setting's documented purpose,
				// such a change must not trigger SDU at all. Returning here
				// deliberately skips rebuildData() entirely: that call's
				// "no dependency trigger" branch forces an immediate
				// synchronous re-parse of this very page, which would only
				// find an empty diff (nothing meaningful changed) and, via
				// onAfterDataUpdateComplete()'s own "no semantic data
				// changes detected" branch below, either falsely mark this
				// ordinary page as self-update-pending or - if a genuine
				// self-update-pending marker already exists from an earlier,
				// unrelated "Update Self" cycle - falsely advance its bounded
				// attempt count for a reason that has nothing to do with
				// that cycle.
				self::debugLog(
					"[SDU] <-- Only ignored properties changed, skipping rebuild"
				);
				return true;
			}

		} else {

			self::debugLog( "[SDU] <-- No semantic data changes detected" );

			// A page whose own Semantic Dependency self-references (the
			// documented "Update Self" case) may have just been re-parsed by
			// a forced UpdateJob SDU itself pushed, expecting a derived value
			// to now resolve from data the first pass wrote to the store. If
			// that store write had not fully propagated yet (e.g. replication
			// lag) when this job ran, the re-parse legitimately finds no
			// change at all - indistinguishable here from the case where the
			// derived value already resolved correctly and no further update
			// is needed. Retrying is cheap (one more UpdateJob) and the
			// self-update-pending marker's own attempt count already bounds
			// it, so this deliberately does not try to tell the two cases
			// apart.
			if ( isset( $properties[$wgSDUProperty] ) ) {
				self::retrySelfUpdateIfWithinTraversalLimit( $id, $subject );
			}

			return true;
		}

		if ( array_key_exists( $id, $wgSDUTraversed ) ) {
			$wgSDUTraversed[$id] += 1;
		} else {
			$wgSDUTraversed[$id] = 1;
		}

		if ( $wgSDUTraversed[$id] > 2 ) {
			self::debugLog( "[SDU] <-- Already traversed" );
			return true;
		}

		// Reaching this point always means $triggerSemanticDependencies is
		// true: the only other path through the branch above (ignored-only
		// changes) already returned early.
		$wikiPageValues = [];

		$dataItem = $newData->getPropertyValues( $properties[$wgSDUProperty] );

		if ( $dataItem != null ) {

			self::debugLog(
				"[SDU] Dependency values count=" . count( $dataItem )
			);

			foreach ( $dataItem as $valueItem ) {

				if ( $valueItem instanceof SMWDIBlob ) {

					self::debugLog(
						"[SDU] Dependency raw value=" . $valueItem->getSerialization()
					);

					// Self-referencing values (e.g. Semantic Dependency={{FULLPAGENAME}})
					// are intentionally not excluded here: the documented "Update Self"
					// use case relies on the page re-queuing itself for a forced update,
					// e.g. when a property is derived from another property of the same
					// page via a live store query. Recursion is bounded by $wgSDUTraversed
					// below, not by excluding self-matches from the query here.
					$wikiPageValues = array_merge(
						$wikiPageValues,
						self::updatePagesMatchingQuery( $valueItem->getSerialization() )
					);
				}
			}
		}

		$isSelfReferencing = false;

		foreach ( $wikiPageValues as $wikiPageValue ) {
			if ( $wikiPageValue->equals( $subject ) ) {
				$isSelfReferencing = true;
				break;
			}
		}

		if ( $isSelfReferencing ) {
			// This page is among its own dependency targets (the documented
			// "Update Self" case). markSelfUpdatePending() itself increments
			// any existing attempt count (see its own docblock) rather than
			// resetting it, so a self-referencing page whose derived value
			// takes several genuine (non-empty-diff) passes to stabilize -
			// or, pathologically, never stabilizes at all - still accumulates
			// toward SELF_UPDATE_MAX_ATTEMPTS instead of being reset to
			// attempt 1 on every pass. Without this, the cross-process-durable
			// marker could never actually bound such a page, leaving only
			// $wgSDUTraversed to do so - which, per its own docblock above,
			// does not survive across real job-queue-runner process
			// boundaries, only within a single one.
			$attempt = self::markSelfUpdatePending( $id );

			// A REAL diff just landed - the value is still actively changing,
			// so any consecutive-empty-diff streak counted so far no longer
			// reflects "settled", it reflects a gap BEFORE this real change
			// arrived. Reset it: MAX_CONSECUTIVE_EMPTY_DIFFS should count
			// empty diffs following the LATEST real change, not accumulate
			// across one that came in between.
			self::clearConsecutiveEmptyDiffs( $id );

			// A page whose "Update Self" query keeps producing genuine,
			// non-empty diffs pass after pass (e.g. a simple field edit on a
			// page cascading through several dependent subobjects) must still
			// be bounded by SELF_UPDATE_MAX_ATTEMPTS here, not only on the
			// empty-diff path handled by retrySelfUpdateIfWithinTraversalLimit() -
			// otherwise such a page could re-queue itself indefinitely.
			if ( $attempt > self::SELF_UPDATE_MAX_ATTEMPTS ) {
				self::debugLog( "[SDU] <-- Already traversed (real-diff path), not re-queuing self-update" );
				self::clearSelfUpdatePending( $id );

				// A page can list more than one Semantic Dependency value at
				// once (e.g. both a self-reference AND a query matching
				// other, unrelated pages - see $wgSDUProperty's own
				// multi-value support). Dropping the attempt-limited
				// self-reference here must not also drop those OTHER,
				// perfectly legitimate dependency targets - only the
				// entry(ies) equal to this exact page are excluded, exactly
				// mirroring the equality check that set $isSelfReferencing
				// above.
				$wikiPageValues = array_values( array_filter(
					$wikiPageValues,
					static function ( $wikiPageValue ) use ( $subject ) {
						return !$wikiPageValue->equals( $subject );
					}
				) );

				self::rebuildData( true, $wikiPageValues, $subject );
				return true;
			}

			// This is also the one point where we still know a real,
			// non-ignored change happened on this exact save - see
			// markReloadPending()'s docblock for why the self-UpdateJob
			// about to be queued below would otherwise erase that fact
			// before the editor's browser can act on it. Anchored to
			// $title's OWN latest revision ID (the one this exact save just
			// created), not e.g. time() - see markReloadPending() for why
			// that distinction is the whole point of this design.
			self::markReloadPending( $id, $title->getLatestRevID() );

			// The self-update cycle just started/continued (this method
			// hasn't cleared it above), so it is NOT yet safe to push any
			// OTHER ("remote") dependency targets this same property value
			// also lists - doing so immediately races their own UpdateJobs
			// against this page's still-in-flight self-update cycle in an
			// unordered job queue (a job_random pop order means push order
			// alone cannot fix this), letting a remote target's forced
			// re-parse read this page's data before its own derived value
			// had actually settled. Hold them back instead;
			// clearSelfUpdatePending() releases them at the exact point
			// this page's own cycle genuinely ends, however many attempts
			// that takes - see that method's own docblock. Self's own
			// target is excluded here exactly like the attempt-limit branch
			// above does, so it isn't queued twice (once via its own
			// self-update-pending cycle, once again as a "remote" target).
			self::markPendingRemoteTargets(
				$id,
				array_values( array_filter(
					$wikiPageValues,
					static function ( $wikiPageValue ) use ( $subject ) {
						return !$wikiPageValue->equals( $subject );
					}
				) )
			);

			self::rebuildData( true, [ $subject ], $subject );

			return true;
		}

		// A genuine, non-ignored change landed on a page that is not (or no
		// longer) self-referencing, but may still have a self-update-pending
		// marker from an earlier attempt (e.g. the dependency was just
		// removed, or this was never really part of that cycle to begin
		// with). Clear it so a later unrelated empty diff on this page isn't
		// mistaken for still-pending self-update work - and so any remote
		// targets a PREVIOUS cycle held back for this page are released now
		// rather than staying stuck behind a cycle that will never end
		// again (see clearSelfUpdatePending()'s own docblock).
		self::clearSelfUpdatePending( $id );

		self::rebuildData( true, $wikiPageValues, $subject );

		return true;
	}

	/**
	 * @param string $queryString Query string, excluding [[ and ]] brackets
	 */
	private static function updatePagesMatchingQuery( $queryString ) {
		global $wgPageFormsListSeparator;

		$queryString = str_replace( 'AND', ']] [[', $queryString );
		$queryString = str_replace( 'OR', ']] OR [[', $queryString );

		// If PageForms is installed, get the separator character and change it into ||
		// Otherwise SDU won't work with multi-value properties.
		// $wgPageFormsListSeparator is declared by the optional PageForms extension, not SDU,
		// so it is genuinely unset when PageForms is not installed.
		// @phan-suppress-next-line MediaWikiNoIssetIfDefined
		if ( isset( $wgPageFormsListSeparator ) ) {
			$queryString = rtrim( $queryString, $wgPageFormsListSeparator );
			$queryString = str_replace( $wgPageFormsListSeparator, ' || ', $queryString );
		}

		self::debugLog( "[SDU] --------> [[$queryString]]" );

		$store = smwfGetStore();

		$params = [
			'limit' => 10000,
		];

		$processedParams = SMWQueryProcessor::getProcessedParams( $params );

		$query = SMWQueryProcessor::createQuery(
			"[[$queryString]]",
			$processedParams,
			SMWQueryProcessor::SPECIAL_PAGE
		);

		$result = $store->getQueryResult( $query );
		$wikiPageValues = $result->getResults();

		self::debugLog(
			"[SDU] Query matched " . count( $wikiPageValues ) . " pages"
		);

		return $wikiPageValues;
	}

	/**
	 * Rebuilds data of the given wikipages to regenerate semantic attributes and re-run queries
	 */
	public static function rebuildData( $triggerSemanticDependencies, $wikiPageValues, $subject ) {
		$jobFactory = ApplicationFactory::getInstance()->newJobFactory();

		if ( $triggerSemanticDependencies ) {

			$jobs = [];

			foreach ( $wikiPageValues as $wikiPageValue ) {

				$jobTitle = $wikiPageValue->getTitle();

				if ( $jobTitle === null ) {
					continue;
				}

				$jobs[] = $jobFactory->newUpdateJob(
					$jobTitle,
					[
						UpdateJob::FORCED_UPDATE => true,
						'shallowUpdate' => false
					]
				);
			}

			if ( $jobs ) {

				self::debugLog(
					"[SDU] Pushing " . count( $jobs ) . " UpdateJobs"
				);

				MediaWikiServices::getInstance()
					->getJobQueueGroup()
					->lazyPush( $jobs );
			}

		} else {

			self::debugLog(
				"[SDU] Running single UpdateJob immediately (no dependency trigger)"
			);

			DeferredUpdates::addCallableUpdate(
				static function () use ( $jobFactory, $wikiPageValues ) {
					$jobTitle = $wikiPageValues[0]->getTitle();

					if ( $jobTitle === null ) {
						return;
					}

					$job = $jobFactory->newUpdateJob(
						$jobTitle,
						[
							UpdateJob::FORCED_UPDATE => true,
							'shallowUpdate' => false
						]
					);

					$job->run();
				}
			);
		}
	}

	/**
	 * Renders a client-side reload prompt for a self-referencing page whose
	 * real, non-ignored change would otherwise be masked by its own forced
	 * self-UpdateJob before SMW's own PostProcHandler ever gets to show one -
	 * see markReloadPending()'s docblock for the mechanism this compensates
	 * for.
	 *
	 * Gated on MediaWiki's own post-edit cookie alone (Article::view()'s
	 * `wpPostEdit{revId}`, the same one PostProcHandler checks) - not on any
	 * SDU-managed session marker. That cookie is already exactly what this
	 * needs: single-use per revision (deleted after the first read, so a
	 * second unrelated visitor loading this page from a link never sees it),
	 * and stable across however many times ext.sdu.reload.js's own retries
	 * re-request the SAME revision's rendering - MediaWiki does not delete
	 * the cookie until the FULL response for that read has been sent, and
	 * every retry here is the browser's own `action=purge` + reload of that
	 * SAME already-rendered response's follow-up, not a fresh Article::view()
	 * read of a NEW revision. An earlier version of this mechanism kept a
	 * separate, session-scoped "authorization" marker to survive across
	 * retries instead - that marker re-derived its own expiry from "now" on
	 * every request from a client with no stable session between requests,
	 * which the client misread as "a new save cycle started" and silently
	 * broke the backoff dialog for. Reading the cookie directly on every
	 * render, rather than caching an "authorized" bit derived from it once,
	 * has no equivalent failure mode: the cookie's own presence/absence IS
	 * the fact this needs, not a derived approximation of it.
	 *
	 * Renders on EITHER of two conditions: the cookie (this render is the
	 * editor's own, for this exact revision), OR the reload-pending marker
	 * already being set for this exact revision (a retry's own
	 * `action=purge` reload of that same response, e.g. after the cookie's
	 * single-use window has technically closed but the cycle is still
	 * genuinely running) - see getReloadPendingRevId(). Once
	 * clearSelfUpdatePending() has cleared that marker (the cycle resolved or
	 * hit its attempt limit), this second condition alone already stops
	 * matching - there is no separate "cycle ended" state to check here.
	 */
	public static function onOutputPageParserOutput( OutputPage $outputPage, ParserOutput $parserOutput ) {
		$title = $outputPage->getTitle();

		if ( $title === null || !$title->exists() ) {
			return true;
		}

		$id = $title->getPrefixedDBKey();
		$revId = $title->getLatestRevID();

		$cookieKey = EditPage::POST_EDIT_COOKIE_KEY_PREFIX . $revId;
		$request = $outputPage->getContext()->getRequest();

		$authorized = $request->getCookie( $cookieKey ) !== null
			|| self::getReloadPendingRevId( $id ) === $revId;

		if ( !$authorized ) {
			return true;
		}

		self::debugLog( "[SDU] --> Emitting client retry prompt: {$id} revId={$revId}" );

		$outputPage->addModules( 'ext.sdu.reload' );

		$outputPage->addHtml(
			Html::rawElement(
				'div',
				[
					'class' => 'sdu-reload-pending',
					'data-title' => $id,
					'data-revision-id' => $revId,
				]
			)
		);

		return true;
	}

}
