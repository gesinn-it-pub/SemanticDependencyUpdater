<?php

namespace SDU;

use DeferredUpdates;
use JobQueueGroup;
use SMW\MediaWiki\Jobs\UpdateJob;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMWDIBlob;
use SMWQueryProcessor;
use SMWSemanticData;
use SMWStore;

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
		SMWStore $store, SMWSemanticData $newData,
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

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --> " . $title );

		// FIRST CHECK: Does the page data contain a $wgSDUProperty semantic property ?
		$properties = $newData->getProperties();
		if ( !isset( $properties[$wgSDUProperty] ) ) {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] <-- No SDU property found" );
			return true;
		}

		$diffTable = $compositePropertyTableDiffIterator->getOrderedDiffByTable();
		$smwSID = $compositePropertyTableDiffIterator->getSubject()->getId();
		// SECOND CHECK: Have there been actual changes in the data? (Ignore internal SMW data!)
		// TODO: Introduce an explicit list of Semantic Properties to watch ?
		unset( $diffTable['smw_fpt_mdat'] ); // Ignore SMW's internal properties "smw_fpt_mdat"

		// lets try first to check the data tables: https://www.semantic-mediawiki.org/wiki/Help:Database_schema
		// if change, on pageID from Issue, that is not REvision ID, then trigger all changes
		$triggerSemanticDependencies = false;

		if ( count( $diffTable ) > 0 ) {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] -----> Data changes detected" );

			foreach ( $diffTable as $key => $value ) {
				if ( strpos( $key, 'smw_di' ) === 0 && is_array( $value ) ) {
					foreach ( $value["insert"] as $insert ) {
						if ( $insert["s_id"] == $smwSID ) {
							if ( $insert["p_id"] != 506 ) {
								$triggerSemanticDependencies = true;
								break 2;
							} // revision ID change is good, but must not trigger UpdateJob for semantic dependencies
						}
					}
				}
			}
		} else {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] <-- No semantic data changes detected" );
			return true;
		}

		// THIRD CHECK: Has this page been already traversed more than twice?
		// This should only be the case when SMW errors occur.
		// In that case, the diffTable contains everything and SDU can't know if changes happened
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

		// SMW\SemanticData $newData
		// SMWDataItem[] $dataItem
		$wikiPageValues = [];
		if ( $triggerSemanticDependencies ) {
			$dataItem = $newData->getPropertyValues( $properties[$wgSDUProperty] );
			if ( $dataItem != null ) {
				foreach ( $dataItem as $valueItem ) {
					if ( $valueItem instanceof SMWDIBlob && $valueItem->getString() != $id ) {
						$wikiPageValues = array_merge( $wikiPageValues, self::updatePagesMatchingQuery( $valueItem->getSerialization() ) );
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
		$query =
			SMWQueryProcessor::createQuery( "[[$queryString]]", $processedParams, SMWQueryProcessor::SPECIAL_PAGE );
		$result = $store->getQueryResult( $query );
		$wikiPageValues = $result->getResults();

		return $wikiPageValues;
	}

	/**
	 * Rebuilds data of the given wikipages to regenerate semantic attrubutes and re-run queries
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
					JobQueueGroup::singleton()->lazyPush( $jobs );
				}
			} else {
				DeferredUpdates::addCallableUpdate( static function () use ( $jobFactory, $wikiPageValues ) {
					$job = $jobFactory->newUpdateJob(
						$wikiPageValues[0]->getTitle(),
						[
							UpdateJob::FORCED_UPDATE => true,
							'shallowUpdate' => false
						]
					);
					$job->run();
				} );
			}

		}
	}

}
