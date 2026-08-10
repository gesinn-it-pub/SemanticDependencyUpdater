<?php

namespace SDU;

use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\MediaWiki\Jobs\UpdateJob;
use SMW\SemanticData;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMW\Store;
use SMWDIBlob;
use SMWQueryProcessor;
use Status;
use User;
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
		self::runDependencyUpdateOnDelete( $store, $semanticData );

		return true;
	}

	/**
	 * Runs dependency updates for deleted pages.
	 * Always triggers because the page is being removed.
	 */
	private static function runDependencyUpdateOnDelete(
		Store $store,
		SemanticData $semanticData
	): void {
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
	private const SELF_UPDATE_RETRY_DELAY_SECONDS = [ 1, 2, 3, 5 ];

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
	 *
	 * No return type hint: BagOStuff lives in the global namespace up to
	 * MediaWiki 1.42 and moved to Wikimedia\ObjectCache\ in 1.43 (which
	 * class_alias()es the old global name back for compatibility, but only
	 * in that direction) - this branch declares support for MediaWiki
	 * >= 1.39, and PHP has no type alias mechanism to declare "whichever
	 * FQCN this MW version uses".
	 */
	private static function getSelfUpdateMarkerCache() {
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

	private static function clearSelfUpdatePending( string $id ): void {
		self::getSelfUpdateMarkerCache()->delete(
			smwfCacheKey( self::SELF_UPDATE_PENDING_CACHE_NAMESPACE, $id )
		);
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
		// exist.
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
			$wgSDUTraversed[$id] = $wgSDUTraversed[$id] + 1;
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

			// The above comment describes the INTENT correctly, but until
			// this check was added, nothing here actually ENFORCED it: the
			// attempt count accumulated exactly as described, but this
			// branch never compared it against SELF_UPDATE_MAX_ATTEMPTS -
			// only retrySelfUpdateIfWithinTraversalLimit() (the EMPTY-diff
			// path) did. A page whose "Update Self" query keeps producing
			// genuine, non-empty diffs pass after pass (verified live: a
			// simple field edit on a page cascading through several
			// dependent subobjects can trigger many real passes in a row)
			// therefore never hit "already traversed" here, and kept
			// re-queuing itself indefinitely.
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
		} else {
			// A genuine, non-ignored change landed on a page that is not (or
			// no longer) self-referencing, but may still have a
			// self-update-pending marker from an earlier attempt (e.g. the
			// dependency was just removed, or this was never really part of
			// that cycle to begin with). Clear it so a later unrelated empty
			// diff on this page isn't mistaken for still-pending self-update
			// work.
			self::clearSelfUpdatePending( $id );
		}

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
		// Otherwise SDU won't work with multi-value properties
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

}
