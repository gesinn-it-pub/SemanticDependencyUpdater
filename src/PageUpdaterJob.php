<?php

namespace SDU;

use CommentStoreComment;
use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;
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
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( $this->params['page']->getTitle()->getArticleId() );
		$content = $page->getContent( RevisionRecord::RAW );
		$title = $page->getTitle();

		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [rebuildData job] $title" );

		$performer = RequestContext::getMain()->getUser();
		$updater = $page->newPageUpdater( $performer );

		$updater->setContent( SlotRecord::MAIN, $content );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( __CLASS__ . ' [SemanticDependencyUpdater] Null edit. ' . $title ) );

		$page->doPurge();
		return true;
	}
}
