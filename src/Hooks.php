<?php

namespace SDU;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
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

						if ( $change["p_id"] != 506 ) {
							$triggerSemanticDependencies = true;
							break 3;
						}
					}
				}
			}

			self::debugLog(
				"[SDU] triggerSemanticDependencies=" . ( $triggerSemanticDependencies ? "true" : "false" )
			);

		} else {

			self::debugLog( "[SDU] <-- No semantic data changes detected" );
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

		$wikiPageValues = [];

		if ( $triggerSemanticDependencies ) {

			$dataItem = $newData->getPropertyValues( $properties[$wgSDUProperty] );

			if ( $dataItem != null ) {

				self::debugLog(
					"[SDU] Dependency values count=" . count( $dataItem )
				);

				foreach ( $dataItem as $valueItem ) {

					if ( $valueItem instanceof SMWDIBlob && $valueItem->getString() != $id ) {

						self::debugLog(
							"[SDU] Dependency raw value=" . $valueItem->getSerialization()
						);

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
		global $wgSDUUseJobQueue;

		if ( $wgSDUUseJobQueue ) {

			$jobFactory = ApplicationFactory::getInstance()->newJobFactory();

			if ( $triggerSemanticDependencies ) {

				$jobs = [];

				foreach ( $wikiPageValues as $wikiPageValue ) {

					$jobs[] = $jobFactory->newUpdateJob(
						$wikiPageValue->getTitle(),
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
						$job = $jobFactory->newUpdateJob(
							$wikiPageValues[0]->getTitle(),
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
