<?php

namespace SDU;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\MediaWiki\Jobs\UpdateJob;
use SMW\SemanticData;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMW\Store;
use SMWDIBlob;
use SMWQueryProcessor;

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
	 * Trigger dependency updates when a page is deleted.
	 * SMW semantic properties are already gone in AfterDataUpdateComplete.
	 */
	public static function onPageDelete( $wikiPage, $user, $reason, $pageId ) {
		self::debugLog(
			"[SDU] PageDeleteComplete detected, loading semantic data before removal"
		);

		$title = $wikiPage->getTitle();

		if ( $title == null ) {
			return true;
		}

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
			$id = $store->getObjectIds()->getSMWPropertyID(
				DIProperty::newFromUserLabel( $propertyName )
			);

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Seconds to delay a retried self-UpdateJob by, so it runs after (not
	 * immediately following) the UpdateJob whose empty re-parse triggered
	 * the retry - giving a lagging store write (e.g. replication lag) time
	 * to actually catch up before the next attempt reads it.
	 */
	private const SELF_UPDATE_RETRY_DELAY_SECONDS = 10;

	/**
	 * Maximum number of retry attempts for a single self-update cycle.
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
	private const SELF_UPDATE_MAX_ATTEMPTS = 3;

	/**
	 * TTL for the self-update-pending marker set in markSelfUpdatePending().
	 * Must comfortably outlast SELF_UPDATE_RETRY_DELAY_SECONDS plus however
	 * long the job queue takes to pick the job back up, or a slow queue could
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

		return $attempt;
	}

	private static function getSelfUpdateAttempt( string $id ): int {
		return (int)self::getSelfUpdateMarkerCache()->get(
			smwfCacheKey( self::SELF_UPDATE_PENDING_CACHE_NAMESPACE, $id )
		);
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
	 * catch up) and is independent of $wgSDUUseJobQueue - a disabled job
	 * queue means SDU cannot usefully retry at all, so this is a no-op then.
	 */
	private static function retrySelfUpdateIfWithinTraversalLimit( string $id, DIWikiPage $subject ): void {
		global $wgSDUUseJobQueue;

		if ( self::getSelfUpdateAttempt( $id ) === 0 ) {
			// No self-UpdateJob is in flight for this page - this empty diff
			// is from an unrelated store update (e.g. another extension's
			// job touching the same page) and has nothing to do with SDU's
			// "Update Self" store-timing gap, so it must not be retried.
			return;
		}

		if ( !$wgSDUUseJobQueue ) {
			self::clearSelfUpdatePending( $id );
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
		// rather than after SELF_UPDATE_RETRY_DELAY_SECONDS), which is a
		// smaller improvement, not a failure.
		if ( $jobQueueGroup->get( 'smw.update' )->delayedJobsEnabled() ) {
			$jobParams['jobReleaseTimestamp'] = time() + self::SELF_UPDATE_RETRY_DELAY_SECONDS;
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

			// A genuine change landed on a page that may still have a
			// self-update-pending marker from an earlier attempt (e.g. this
			// very change IS the store catching up that the retry was
			// waiting for). Clear it so a later unrelated empty diff on this
			// page isn't mistaken for still-pending self-update work.
			self::clearSelfUpdatePending( $id );

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

		$wikiPageValues = [];

		if ( $triggerSemanticDependencies ) {

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

		} else {

			$wikiPageValues = [ $subject ];
		}

		// If this page is among its own dependency targets (the documented
		// "Update Self" case), mark it so that a later empty-diff re-parse
		// on this same page can be recognized as this job's own follow-up
		// and retried if needed - see retrySelfUpdateIfWithinTraversalLimit().
		foreach ( $wikiPageValues as $wikiPageValue ) {
			if ( $wikiPageValue->equals( $subject ) ) {
				self::markSelfUpdatePending( $id );
				break;
			}
		}

		self::rebuildData( $triggerSemanticDependencies, $wikiPageValues, $subject );

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
		global $wgSDUUseJobQueue;

		if ( $wgSDUUseJobQueue ) {

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

}
