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
		$page = WikiPage::newFromID( $this->title->getArticleId() );
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

		return true;
	}
}