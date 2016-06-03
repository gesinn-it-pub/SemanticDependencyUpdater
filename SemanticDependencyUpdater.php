<?php

/*
 * SemanticDependencyUpdater is a MediaWiki extension that monitors changes in the semantic data of a wiki page.
 * The affected page can the Semantic Dependency Updater property to define which pages should also be updated.
 * This can be defined through a list of pages or for more advanced use cases, a query string.
 *
 * This extension requires Semantic MediaWiki >= 2.3 (http://www.semantic-mediawiki.org)
 *
 * This extension is slightly based on Remco C. de Boers https://www.mediawiki.org/wiki/Extension:SemanticDummyEditor
 *
 * @author Simon Heimler, gesinn.it GmbH & Co
 */

if (!defined('MEDIAWIKI')) {
	die();
}

if ( !defined( 'SMW_VERSION' ) ) {
	die( "ERROR: Semantic MediaWiki must be installed for Semantic Dummy Editor to run!" );
}

define( 'SDU_VERSION', '1.0.0' );

$wgExtensionCredits[defined( 'SEMANTIC_EXTENSION_TYPE' ) ? 'semantic' : 'other'][] = array(
		'name' => 'SemanticDependencyUpdater',
		'author' => array(
			'[https://www.mediawiki.org/wiki/User:Fannon Simon Heimler]',
			'[https://www.mediawiki.org/wiki/User:Rcdeboer Remco C. de Boer]',
		),
		'url' => 'http://www.mediawiki.org/wiki/Extension:SemanticDependencyUpdater',
		'description' => 'Monitors semantic data changes and updates dependend pages',
		'version' => SDU_VERSION,
);

global $wgExtensionFunctions;
$wgExtensionFunctions[] = 'SemanticDependencyUpdater::setup';
$wgJobClasses[ 'DummyEditJob' ] = 'DummyEditJob';

$wgSDUProperty = 'Semantic Dependency';
$wgSDUUseJobQueue = false;

class SemanticDependencyUpdater {

	public static function setup () {
		global $wgHooks;
		$wgHooks['SMW::SQLStore::AfterDataUpdateComplete'][] = 'SemanticDependencyUpdater::onAfterDataUpdateComplete';
	}

	public static function onAfterDataUpdateComplete( SMWStore $store, SMWSemanticData $newData, $compositePropertyTableDiffIterator ) {

		global $wgSDUProperty;

		$wgSDUProperty = str_replace(' ', '_', $wgSDUProperty);
		$subject = $newData->getSubject();
		$title = $subject->getTitle();

		wfDebugLog('SemanticDependencyUpdater', "[SDU] --> " . $title);


		// FIRST CHECK: Does the page data contain a $wgSUTPropertyName semantic property ?

		$properties = $newData->getProperties();
		$diffTable = $compositePropertyTableDiffIterator->getOrderedDiffByTable();

		if (!isset($properties[$wgSDUProperty])) {
			wfDebugLog('SemanticDependencyUpdater', "[SDU] <-- No SDU property found");
			return true;
		}

		// SECOND CHECK: Have there been actual changes in the data? (Ignore internal SMW data!)
		// TODO: Introduce an explicit list of Semantic Properties to watch ?

		unset($diffTable['smw_fpt_mdat']); // Ignore SMW's internal properties "smw_fpt_mdat"

		if (count($diffTable) > 0) {
			wfDebugLog('SemanticDependencyUpdater', "[SDU] -----> Data changes detected");
		} else {
			wfDebugLog('SemanticDependencyUpdater', "[SDU] <-- No semantic data changes detected");
			return true;
		}


		// QUERY AND UPDATE DEPENDENCIES

		$dataItem = $newData->getPropertyValues($properties[$wgSDUProperty]);

		foreach($dataItem as $valueItem) {
			SemanticDependencyUpdater::updatePagesMatchingQuery($valueItem->getString());
		}

		return true;
	}

	/**
	 * @param string $queryString Query string, excluding [[ and ]] brackets
	 */
	private static function updatePagesMatchingQuery( $queryString ) {

		$queryString = str_replace('AND', ']] [[', $queryString);
		$queryString = str_replace('OR', ']] OR [[', $queryString);

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [[$queryString]]");

		$store = smwfGetStore();

		$params = array(
			'limit' => 10000,
		);
		$processedParams = SMWQueryProcessor::getProcessedParams( $params );
		$query = SMWQueryProcessor::createQuery( "[[$queryString]]", $processedParams, SMWQueryProcessor::SPECIAL_PAGE );
		$result = $store->getQueryResult( $query ); // SMWQueryResult
		$wikiPageValues = $result->getResults(); // array of SMWWikiPageValues

		// TODO: This can be optimized by collecting a list of all pages first, make them unique
		// and do the dummy edit afterwards
		// TODO: A threshold when to switch to Queue Jobs might be smarter
		foreach($wikiPageValues as $page) {
			SemanticDependencyUpdater::dummyEdit( $page->getTitle() );
		}

		return;
	}

	/**
	 * Save a null revision in the page's history to propagate the update
	 *
	 * @param Title $title
	 */
	public static function dummyEdit( $title ) {
		global $wgSDUUseJobQueue;

		if( $wgSDUUseJobQueue ) {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [Edit Job] $title");
			$job = new DummyEditJob( $title );
			$job->insert();
		} else {
			wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [Edit] $title");
			$page = WikiPage::newFromID( $title->getArticleId() );
			if ( $page ) { // prevent NPE when page not found
				$text = $page->getText( Revision::RAW );
				$page->doEdit( $text, "[SemanticDependencyUpdater] Null edit." ); // since this is a null edit, the edit summary will be ignored.
			}
		}
	}

}

class DummyEditJob extends Job {

	function __construct( $title, $params = '', $id = 0 ) {
		parent::__construct( 'DummyEditJob', $title, $params, $id );
	}

	/**
	 * Run the job
	 * @return boolean success
	 */
	function run() {
		wfProfileIn( __METHOD__ );

		if ( is_null( $this->title ) ) {
			$this->error = "DummyEditJob: Invalid title";
			wfProfileOut( __METHOD__ );
			return false;
		}

		$page = WikiPage::newFromID( $this->title->getArticleId() );
		if ( $page ) { // prevent NPE when page not found
			$text = $page->getText( Revision::RAW );
			$page->doEdit($text, "[SemanticDependencyUpdater] Null edit."); // since this is a null edit, the edit summary will be ignored.
		}

		wfProfileOut( __METHOD__ );
		return true;
	}
}
