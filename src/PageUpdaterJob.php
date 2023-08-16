<?php

namespace SDU;

use CommentStoreComment;
use GenericParameterJob;
use Job;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use RequestContext;
use WikiPage;

class PageUpdaterJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'PageUpdaterJob', $params );
	}

	/**
	 * Run the job
	 * @return bool success
	 */
	public function run() {
		$pageParam = $this->params['page'];
		$page = WikiPage::newFromID( $this->params['page']->getTitle()->getArticleId() );
		$content = $page->getContent( RevisionRecord::RAW );
		$title = $page->getTitle();

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [rebuildData job] $title" );

		$performer = RequestContext::getMain()->getUser();
		$updater = $page->newPageUpdater( $performer );

		$updater->setContent( SlotRecord::MAIN, $content );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( __CLASS__ . ' [SemanticDependencyUpdater] Null edit. ' . $title ) );

		return true;
	}
}
