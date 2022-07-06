<?php

namespace SDU;

use ContentHandler;
use Revision;
use SMWDIBlob;
use SMWQueryProcessor;
use SMWSemanticData;
use SMWStore;
use Title;
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

	public static function onAfterDataUpdateComplete( SMWStore $store, SMWSemanticData $newData,
													  $compositePropertyTableDiffIterator ) {
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

		if ( $dataItem != null ) {
			foreach ( $dataItem as $valueItem ) {
				if ( $valueItem instanceof SMWDIBlob ) {
					self::updatePagesMatchingQuery( $valueItem->getSerialization() );
				}
			}
		}

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

		// TODO: This can be optimized by collecting a list of all pages first, make them unique
		// and do the dummy edit afterwards
		// TODO: A threshold when to switch to Queue Jobs might be smarter
		foreach ( $wikiPageValues as $page ) {
			self::dummyEdit( $page->getTitle() );
		}
	}

	/**
	 * Save a null revision in the page's history to propagate the update
	 *
	 * @param Title $title
	 */
	public static function dummyEdit( $title ) {
		global $wgSDUUseJobQueue;

		if ( $wgSDUUseJobQueue ) {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [Edit Job] $title" );
			$job = new DummyEditJob( $title );
			$job->insert();
		} else {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [Edit] $title" );
			$page = WikiPage::newFromID( $title->getArticleId() );
			if ( $page ) { // prevent NPE when page not found
				$content = $page->getContent( Revision::RAW );

				if ( $content ) {
					$text = ContentHandler::getContentText( $content );
					$page->doEditContent( ContentHandler::makeContent( $text, $page->getTitle() ),
						"[SemanticDependencyUpdater] Null edit." ); // since this is a null edit, the edit summary will be ignored.
					$page->doPurge(); // required since SMW 2.5.1

					# Consider calling doSecondaryDataUpdates() for MW 1.32+
					# https://doc.wikimedia.org/mediawiki-core/master/php/classWikiPage.html#ac761e927ec2e7d95c9bb48aac60ff7c8
				}
			}
		}
	}

}
