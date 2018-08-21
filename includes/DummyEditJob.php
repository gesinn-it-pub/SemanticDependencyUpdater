<?php

namespace SDU;

use Revision;
use Job;
use WikiPage;
use ContentHandler;

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
			$content = $page->getContent( Revision::RAW );
			$text = ContentHandler::getContentText( $content );
			$page->doEditContent( ContentHandler::makeContent( $text, $page->getTitle() ),
				"[SemanticDependencyUpdater] Null edit." ); // since this is a null edit, the edit summary will be ignored.
			$page->doPurge(); // required since SMW 2.5.1
		}

		wfProfileOut( __METHOD__ );
		return true;
	}
}