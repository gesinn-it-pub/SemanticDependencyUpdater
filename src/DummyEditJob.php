<?php

namespace SDU;

use ContentHandler;
use GenericParameterJob;
use Job;
use SMW\Options;
use Title;
use MediaWiki\Revision\RevisionRecord;
use SMW\Services\ServicesFactory as ApplicationFactory;
use WikiPage;

class DummyEditJob extends Job implements GenericParameterJob {

	function __construct( array $params ) {
		parent::__construct( 'DummyEditJob', $params );
	}

	/**
	 * Run the job
	 * @return bool success
	 */
	public function run() {
		$store = smwfGetStore();

		$page = WikiPage::newFromID( $this->params['title']->getArticleId() );
		if ( $page ) { // prevent NPE when page not found
			$content = $page->getContent( RevisionRecord::RAW );

			if ( $content ) {
				$maintenanceFactory = ApplicationFactory::getInstance()->newMaintenanceFactory();

				$dataRebuilder = $maintenanceFactory->newDataRebuilder($store);
				$dataRebuilder->setOptions(
					new Options( ['page' => $this->params['title']->prefixedText] )
				);
				$dataRebuilder->rebuild();
			}

		}

		return true;
	}
}
