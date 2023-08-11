<?php

namespace SDU;

use GenericParameterJob;
use Job;
use SMW\Options;
use SMW\Services\ServicesFactory as ApplicationFactory;

class RebuildDataJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'RebuildDataJob', $params );
	}

	/**
	 * Run the job
	 * @return bool success
	 */
	public function run() {
		$store = smwfGetStore();
		$pageString = $this->params['pageString'];
		wfDebugLog( 'SemanticDependencyUpdater', "[SDU] --------> [rebuildData job] $pageString" );
		$maintenanceFactory = ApplicationFactory::getInstance()->newMaintenanceFactory();

		$dataRebuilder = $maintenanceFactory->newDataRebuilder( $store );
		$dataRebuilder->setOptions(
			new Options( [ 'page' => $pageString ] )
		);
		$dataRebuilder->rebuild();
		return true;
	}
}
