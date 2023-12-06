<?php

namespace SDU;

use GenericParameterJob;
use Job;
use SMW\Options;
use SMW\Services\ServicesFactory as ApplicationFactory;

use CommentStoreComment;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use RequestContext;
use WikiPage;

class SemanticDependencyUpdaterJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'SemanticDependencyUpdaterJob', $params );
	}

	/**
	 * Run the job
	 * @return bool success
	 */
	public function run() {


		$store = smwfGetStore();
        $pageString = $this->params['page']->getTitle()->prefixedText;
		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [SemanticDependencyUpdaterJob job] $pageString" );
		$maintenanceFactory = ApplicationFactory::getInstance()->newMaintenanceFactory();
        
		$dataRebuilder = $maintenanceFactory->newDataRebuilder( $store );
		$dataRebuilder->setOptions(
            new Options( [ 'page' => $this->params['page'] ] )
		);
		$dataRebuilder->rebuild();
        
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
