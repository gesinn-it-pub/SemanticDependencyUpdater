<?php

namespace SDU;

use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use SMW\MediaWiki\Jobs\UpdateJob;
use SMW\SemanticData;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMW\Store;
use SMWDIBlob;
use SMWQueryProcessor;

class Hooks {

	public static function setup() {
		if ( !defined( 'MEDIAWIKI' ) ) {
			die();
		}

		if ( !defined( 'SMW_VERSION' ) ) {
			die( "ERROR: Semantic MediaWiki must be installed for Semantic Dependency Updater to run!" );
		}
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

		// DEBUG: Subject context
		wfDebugLog(
			'SemanticDependencyUpdater',
			"[SDU] Subject={$id} SMW-SID=" . $subject->getId()
		);

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --> " . $title );

		// FIRST CHECK: Does the page data contain a $wgSDUProperty semantic property?
		$properties = $newData->getProperties();

		// DEBUG: list all properties found
		wfDebugLog(
			'SemanticDependencyUpdater',
			"[SDU] Properties found: " . implode( ", ", array_keys( $properties ) )
		);

		if ( !isset( $properties[$wgSDUProperty] ) ) {
			wfDebugLog(
				'SemanticDependencyUpdater',
				"[SDU] <-- No SDU property '{$wgSDUProperty}' found"
			);
			return true;
		}

		$diffTable = $compositePropertyTableDiffIterator->getOrderedDiffByTable();
		$smwSID = $compositePropertyTableDiffIterator->getSubject()->getId();

		// DEBUG: diff table keys before filtering
		wfDebugLog(
			'SemanticDependencyUpdater',
			"[SDU] Diff tables: " . implode( ", ", array_keys( $diffTable ) )
		);

		// SECOND CHECK: Have there been actual changes in the data?
		// Ignore SMW's internal properties "smw_fpt_mdat"
		unset( $diffTable['smw_fpt_mdat'] );

		// DEBUG: diff table keys after filtering
		wfDebugLog(
			'SemanticDependencyUpdater',
			"[SDU] Diff tables after filtering: " . implode( ", ", array_keys( $diffTable ) )
		);

		$triggerSemanticDependencies = false;

		if ( count( $diffTable ) > 0 ) {

			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] -----> Data changes detected" );

			// DEBUG: start scanning inserts
			wfDebugLog(
				'SemanticDependencyUpdater',
				"[SDU] Scanning diffTable for inserts..."
			);

			foreach ( $diffTable as $key => $value ) {

				if ( strpos( $key, 'smw_di' ) === 0 && is_array( $value ) ) {

					// Defensive: not every diff entry has inserts
					if ( !isset( $value["insert"] ) || !is_array( $value["insert"] ) ) {
						continue;
					}

					foreach ( $value["insert"] as $insert ) {

						// DEBUG: log each insert detected
						wfDebugLog(
							'SemanticDependencyUpdater',
							"[SDU] INSERT detected: table={$key} s_id={$insert["s_id"]} p_id={$insert["p_id"]}"
						);

						if ( $insert["s_id"] == $smwSID ) {
							if ( $insert["p_id"] != 506 ) {
								$triggerSemanticDependencies = true;
								break 2;
							}
							// revision ID change is good, but must not trigger UpdateJob for semantic dependencies
						}
					}
				}
			}

			// DEBUG: final trigger status
			wfDebugLog(
				'SemanticDependencyUpdater',
				"[SDU] triggerSemanticDependencies=" . ( $triggerSemanticDependencies ? "true" : "false" )
			);

		} else {

			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] <-- No semantic data changes detected" );
			return true;
		}

		// THIRD CHECK: Has this page been already traversed more than twice?
		if ( array_key_exists( $id, $wgSDUTraversed ) ) {
			$wgSDUTraversed[$id] = $wgSDUTraversed[$id] + 1;
		} else {
			$wgSDUTraversed[$id] = 1;
		}

		if ( $wgSDUTraversed[$id] > 2 ) {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] <-- Already traversed" );
			return true;
		}

		// QUERY AND UPDATE DEPENDENCIES

		$wikiPageValues = [];

		if ( $triggerSemanticDependencies ) {

			$dataItem = $newData->getPropertyValues( $properties[$wgSDUProperty] );

			if ( $dataItem != null ) {

				// DEBUG: dependency value count
				wfDebugLog(
					'SemanticDependencyUpdater',
					"[SDU] Dependency values count=" . count( $dataItem )
				);

				foreach ( $dataItem as $valueItem ) {

					if ( $valueItem instanceof SMWDIBlob && $valueItem->getString() != $id ) {

						// DEBUG: raw dependency query fragment
						wfDebugLog(
							'SemanticDependencyUpdater',
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

			// No dependency trigger → only rebuild the current subject
			$wikiPageValues = [ $subject ];
		}

		self::rebuildData( $triggerSemanticDependencies, $wikiPageValues, $subject );

		return true;
	}

	/**
	 * @param string $queryString Query string, excluding [[ and ]] brackets
	 */
	private static function updatePagesMatchingQuery( $queryString ) {
		global $sfgListSeparator;

		$queryString = str_replace( 'AND', ']] [[', $queryString );
		$queryString = str_replace( 'OR', ']] OR [[', $queryString );

		// If SF is installed, get the separator character and change it into ||
		// Otherwise SDU won't work with multi-value properties
		if ( isset( $sfgListSeparator ) ) {
			$queryString = rtrim( $queryString, $sfgListSeparator );
			$queryString = str_replace( $sfgListSeparator, ' || ', $queryString );
		}

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [[$queryString]]" );

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

		// DEBUG: query match count
		wfDebugLog(
			'SemanticDependencyUpdater',
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

					// DEBUG: job push count
					wfDebugLog(
						'SemanticDependencyUpdater',
						"[SDU] Pushing " . count( $jobs ) . " UpdateJobs"
					);

					MediaWikiServices::getInstance()
						->getJobQueueGroup()
						->lazyPush( $jobs );
				}

			} else {

				// DEBUG: single job run
				wfDebugLog(
					'SemanticDependencyUpdater',
					"[SDU] Running single UpdateJob immediately (no dependency trigger)"
				);

				/** @phpstan-ignore class.notFound */
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
