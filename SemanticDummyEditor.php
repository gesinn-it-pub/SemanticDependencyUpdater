<?php

/*
 * SemanticDummyEditor - a MediaWiki extension that monitors changes in semantic dependencies and 
 * propagates them through null edits on the dependent pages.
 * 
 * This extension requires Semantic MediaWiki (http://www.semantic-mediawiki.org)
 * 
 * @author Remco C. de Boer, ArchiXL / XL&Knowledge <rdeboer@archixl.nl>
 * 
 * MIT License
 *
 * Copyright (c) 2016, ArchiXL / XL&Knowledge
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.  
 */

if (!defined('MEDIAWIKI')) {
	die();
}

if ( !defined( 'SMW_VERSION' ) ) {
	die( "ERROR: Semantic MediaWiki must be installed for Semantic Dummy Editor to run!" );
}

define( 'SDE_VERSION', '1.1.0' );

$wgExtensionCredits[defined( 'SEMANTIC_EXTENSION_TYPE' ) ? 'semantic' : 'other'][] = array(
		'name' => 'SemanticDummyEditor',
		'author' => array(
			'[https://www.mediawiki.org/wiki/User:Rcdeboer Remco C. de Boer]',
			'[https://www.mediawiki.org/wiki/User:Fannon Simon Heimler]',
		),
		'url' => 'http://www.mediawiki.org/wiki/Extension:SemanticDummyEditor',
		'description' => 'Monitors changes in semantic dependencies and propagates them through null edits on the dependent pages.',
		'version' => SDE_VERSION,
);

global $wgExtensionFunctions;
$wgExtensionFunctions[] = 'setupDummyEditor';
$wgJobClasses[ 'DummyEditJob' ] = 'DummyEditJob';

$wgSDEUseJobQueue = false;
$wgSDERelations = array();

function setupDummyEditor() {
	global $wgHooks;
	$wgHooks['SMW::SQLStore::AfterDataUpdateComplete'][] = 'SemanticDummyEditor::onAfterDataUpdateComplete';
}

class SemanticDummyEditor {

	public static function onAfterDataUpdateComplete( SMWStore $store, SMWSemanticData $newData ) {
		$subject = $newData->getSubject();
		$title = Title::makeTitle( $subject->getNamespace(), $subject->getDBkey() );

		wfDebugLog('SemanticDummyEditor', "SemanticDummyEditor::onAfterDataUpdateComplete on " . $title->getPrefixedText());

		$dependencies = SemanticDummyEditor::findDependencies( $title );

		for( $i = 0; $i < count($dependencies); $i++) {
			$dependency = $dependencies[$i];
			wfDebugLog( 'SemanticDummyEditor', "Testing dependency $dependency for additional dependencies.") ;
			$additionalDependencies = SemanticDummyEditor::findDependencies( Title::newFromText( $dependency ) );
			foreach( $additionalDependencies as $additionalDependency ) {
				if( !in_array( $additionalDependency, $dependencies ) ) { // prevent infinite loops
					// add additional dependency to the end of the array, so it too will be inspected for additional dependencies
					wfDebugLog( 'SemanticDummyEditor', "Additional dependency found: " . $additionalDependency );
					$dependencies[] = $additionalDependency;
				} else {
					wfDebugLog( 'SemanticDummyEditor', "Ignoring duplicate additional dependency: " . $additionalDependency );
				}
			}
		}

		wfDebugLog( 'SemanticDummyEditor', "SemanticDummyEditor::onAfterDataUpdateComplete dependencies: " . implode( '; ', $dependencies ) );

		// refresh the dependent pages, since their dependent values will have changed
		// refresh has to be done through a dummy edit
		foreach( $dependencies as $changed ) {
			$changedTitle = Title::newFromText( $changed );
			SemanticDummyEditor::dummyEdit( $changedTitle );
		}

		return true;
	}

	/**
	 * Finds all pages dependent on a particular page, by examining the relations from $wgSDERelations.
	 * @param unknown_type $title the title of the page.
	 */
	private static function findDependencies( $title) {
		global $wgSDERelations;

		// find all dependency relations on this page
		$dependencies = array();
		foreach($wgSDERelations as $relation) {
			wfDebugLog('SemanticDummyEditor', "SemanticDummyEditor::findDependencies for page " . $title->getPrefixedText() . " through relation $relation");
			$dependencies = array_merge( $dependencies, SemanticDummyEditor::dependsOn($title, $relation) );
		}

		return $dependencies;
	}

	/**
	 * Save a null revision in the page's history to propagate the update
	 *
	 * @param Title $title
	 */
	public static function dummyEdit( $title ) {
		global $wgSDEUseJobQueue;
		wfDebugLog( 'SemanticDummyEditor', "SemanticDummyEditor::dummyEdit performing dummy edit on $title" );
//		$dbw = wfGetDB( DB_MASTER );

		if( $wgSDEUseJobQueue ) {
			wfDebugLog( 'SemanticDummyEditor', "SemanticDummyEditor::dummyEdit adding job to queue: " . $title->getgetPrefixedText());
			$job = new DummyEditJob( $title );
			$job->insert();
		} else {
			wfDebugLog( 'SemanticDummyEditor', "SemanticDummyEditor::dummyEdit bypassing jobqueue" . $title->getgetPrefixedText() );
			$page = WikiPage::newFromID( $title->getArticleId() );
			if ( $page ) { // prevent NPE when page not found
				$text = $page->getText( Revision::RAW );
				$page->doEdit( $text, "[SemanticDummyEditor] Null edit." ); // since this is a null edit, the edit summary will be ignored.
			}
		}
	}

	/**
	 * Finds the pages that depend on a particular page through a particular relation.
	 * @param unknown_type $title the title of the page the results depend upon.
	 * @param unknown_type $relation the relation that the results have to the page.
	 */
	private static function dependsOn( $title, $relation ) {

		$titleText = $title->getPrefixedText();

		$store = smwfGetStore();

		$params = array();
		$params[ 'limit' ] = 10000; // $smwgQMaxLimit
		$processedParams = SMWQueryProcessor::getProcessedParams( $params );
		$query = SMWQueryProcessor::createQuery( "[[$relation::$titleText]]", $processedParams, SMWQueryProcessor::SPECIAL_PAGE );
		$result = $store->getQueryResult( $query ); // SMWQueryResult
		$pages = $result->getResults(); // array of SMWWikiPageValues

		$relatedElements = array();
		foreach($pages as $page) {
			$relatedElements[] = $page->getTitle()->getPrefixedText();
		}

		wfDebugLog( 'SemanticDummyEditor', "SemanticDummyEditor::dependsOn: " . implode( '; ', $relatedElements ) );

		return $relatedElements;
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
			$page->doEdit($text, "[SemanticDummyEditor] Null edit."); // since this is a null edit, the edit summary will be ignored.
		}

		wfProfileOut( __METHOD__ );
		return true;
	}
}
