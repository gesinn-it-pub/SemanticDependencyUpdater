<?php

namespace SDU;

use DeferredUpdates;
use JobQueueGroup;
use SMW\Options;
use SMW\Services\ServicesFactory as ApplicationFactory;
use SMWDIBlob;
use SMWQueryProcessor;
use SMWSemanticData;
use SMWStore;
use WikiPage;

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

		// SECOND CHECK: Have there been actual changes in the data? (Ignore internal SMW data!)
		// TODO: Introduce an explicit list of Semantic Properties to watch ?
		unset( $diffTable['smw_fpt_mdat'] ); // Ignore SMW's internal properties "smw_fpt_mdat"

		if ( count( $diffTable ) > 0 ) {
			// wfDebugLog('SemanticDependencyUpdater', "[SDU] diffTable: " . print_r($diffTable, true));
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] -----> Data changes detected" );
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
		$dataItem = $newData->getPropertyValues( $properties[$wgSDUProperty] );

		$wikiPageValues = [];
		if ( $dataItem != null ) {
			foreach ( $dataItem as $valueItem ) {
				if ( $valueItem instanceof SMWDIBlob ) {
					$wikiPageValues = array_merge( $wikiPageValues, self::updatePagesMatchingQuery( $valueItem->getSerialization() ) );
				}
			}
		}

		self::rebuildData( $wikiPageValues, $store );
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
		$result = $store->getQueryResult( $query ); // SMWQueryResult
		$wikiPageValues = $result->getResults(); // array of SMWWikiPageValues

		return $wikiPageValues;
	}

	/**
	 * Rebuilds data of the given wikipages to regenerate semantic attrubutes and re-run queries
	 *
	 * @param SMWWikiPageValues[] $wikiPageValues
	 * @param SMWStore $store
	 */
	public static function rebuildData( $wikiPageValues, $store ) {
		global $wgSDUUseJobQueue;

		$pageArray = [];
		foreach ( $wikiPageValues as $wikiPageValue ) {
			$page = WikiPage::newFromID( $wikiPageValue->getTitle()->getArticleId() );
			if ( $page ) {
				$pageArray[] = $page->getTitle()->prefixedText;
			}
		}
		$pageString = implode( $pageArray, "|" );

		// TODO: A threshold when to switch to Queue Jobs might be smarter
		if ( $wgSDUUseJobQueue ) {
			$jobs[] = new RebuildDataJob( [
				'pageString' => $pageString,
			] );
			foreach ( $wikiPageValues as $page ) {
				$jobs[] = new PageUpdaterJob( [
					'page' => $page
				] );
			}
			JobQueueGroup::singleton()->lazyPush( $jobs );
		} else {
			DeferredUpdates::addCallableUpdate( static function () use ( $store, $pageString ) {
				wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [rebuildData] $pageString" );
				$maintenanceFactory = ApplicationFactory::getInstance()->newMaintenanceFactory();

				$dataRebuilder = $maintenanceFactory->newDataRebuilder( $store );
				$dataRebuilder->setOptions(
					new Options( [ 'page' => $pageString ] )
				);
				$dataRebuilder->rebuild();
			} );
		}
	}

}
