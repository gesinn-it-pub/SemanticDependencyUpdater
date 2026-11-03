<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use SDU\Hooks;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\SemanticData;
use SMW\SQLStore\ChangeOp\ChangeOp;
use SMWDIBlob;

/**
 * SDU refreshes dependent pages with forced SMW UpdateJobs, which write the
 * SMW store only. A page whose display title is derived from other pages
 * would keep the stale page_props.displaytitle of its last real save, which
 * is what PageForms autocompletion and the DisplayTitle extension read. After
 * its own handling SDU therefore queues a RefreshLinksJob when the SMW
 * display title (_DTITLE) and page_props.displaytitle differ.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class PagePropsDisplayTitleIntegrationTest extends SduIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Core ignores a DISPLAYTITLE that differs from the page name by more
		// than case and underscores unless this is switched off.
		$this->overrideConfigValue( 'RestrictDisplayTitle', false );

		// SMW stores _DTITLE only with this flag; SDU has nothing to compare
		// otherwise.
		$this->setDisplayTitleFeature( true );
	}

	protected function tearDown(): void {
		$this->setDisplayTitleFeature( null );

		parent::tearDown();
	}

	/**
	 * @param bool|null $enabled null restores the original value
	 */
	private function setDisplayTitleFeature( ?bool $enabled ): void {
		static $original = null;

		$original ??= $GLOBALS['smwgDVFeatures'];

		if ( $enabled === null ) {
			$GLOBALS['smwgDVFeatures'] = $original;
		} elseif ( is_array( $original ) ) {
			// SMW 7 style: a list of flag names
			$flags = array_diff( $original, [ 'wpv-display-title' ] );
			$GLOBALS['smwgDVFeatures'] = $enabled ? array_merge( $flags, [ 'wpv-display-title' ] ) : $flags;
		} else {
			// legacy bit field
			$GLOBALS['smwgDVFeatures'] = $enabled ? $original | SMW_DV_WPV_DTITLE : $original & ~SMW_DV_WPV_DTITLE;
		}

		\SMW\Services\ServicesFactory::clear();
		$this->resetSmwCaches();
	}

	private function getPagePropsDisplayTitle( Title $title ): ?string {
		$value = $this->getDb()->newSelectQueryBuilder()
			->select( 'pp_value' )
			->from( 'page_props' )
			->where( [ 'pp_page' => $title->getArticleID(), 'pp_propname' => 'displaytitle' ] )
			->caller( __METHOD__ )
			->fetchField();

		return $value === false ? null : $value;
	}

	private function countQueuedJobs( string $type ): int {
		return count( iterator_to_array(
			$this->getServiceContainer()->getJobQueueGroup()->get( $type )->getAllQueuedJobs()
		) );
	}

	private function saveSource( string $name, string $dependentPage ): void {
		$this->editPage(
			Title::newFromText( 'SDUDtSource', NS_MAIN ),
			"{{#set:SDUDtName=$name|Semantic Dependency=$dependentPage}}"
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPagePropsDisplayTitleFollowsChangeOfDependency() {
		$dependent = Title::newFromText( 'SDUDtDependent', NS_MAIN );

		$this->saveSource( 'Alpha', 'SDUDtDependent' );
		$this->editPage( $dependent, '{{DISPLAYTITLE:{{#show:SDUDtSource|?SDUDtName|link=none}}}}' );
		$this->runAllJobsWithFreshCaches();

		$this->assertSame( 'Alpha', $this->getPagePropsDisplayTitle( $dependent ) );

		$this->saveSource( 'Beta', 'SDUDtDependent' );
		$this->runAllJobsWithFreshCaches();

		$this->assertSame(
			'Beta',
			$this->getPagePropsDisplayTitle( $dependent ),
			'page_props.displaytitle must follow the SMW display title once the queue is empty.'
		);
		$this->assertSame( 1, Hooks::getPagePropsRefreshAttemptsForTesting( 'SDUDtDependent', 'Beta' ) );
		$this->assertSame( 0, $this->countQueuedJobs( 'refreshLinksPrioritized' ) );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPagePropsDisplayTitleIsFixedAfterCreation() {
		$dependent = Title::newFromText( 'SDUDtCreated', NS_MAIN );

		// The dependent page is parsed before the store contains the data.
		$this->editPage( $dependent, '{{DISPLAYTITLE:{{#show:SDUDtSource|?SDUDtName|link=none}}}}' );
		$this->saveSource( 'Gamma', 'SDUDtCreated' );
		$this->runAllJobsWithFreshCaches();

		$this->assertSame( 'Gamma', $this->getPagePropsDisplayTitle( $dependent ) );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testNoRefreshWhenDisplayTitlesAlreadyMatch() {
		$page = Title::newFromText( 'SDUDtStable', NS_MAIN );

		$this->editPage( $page, '{{DISPLAYTITLE:Fixed title}}{{#set:Semantic Dependency=SDUDtStable}}' );
		$this->runAllJobsWithFreshCaches();

		$this->assertSame( 'Fixed title', $this->getPagePropsDisplayTitle( $page ) );
		$this->assertSame( 0, Hooks::getPagePropsRefreshAttemptsForTesting( 'SDUDtStable', 'Fixed title' ) );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testEntityDifferencesDoNotCauseRefreshes() {
		$page = Title::newFromText( 'SDUDtEntity', NS_MAIN );

		$this->editPage( $page, '{{DISPLAYTITLE:Tom &amp; Jerry}}' );
		$this->runAllJobsWithFreshCaches();

		$this->assertSame(
			0,
			Hooks::getPagePropsRefreshAttemptsForTesting( 'SDUDtEntity', 'Tom & Jerry' ),
			'A stored "&amp;" versus a plain "&" must compare equal.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testRefreshesAreBoundedForAnUnreachableTarget() {
		$page = Title::newFromText( 'SDUDtUnreachable', NS_MAIN );
		$this->editPage( $page, '{{DISPLAYTITLE:Old title}}' );
		$this->runAllJobsWithFreshCaches();

		// An SMW display title page_props can never reach: nothing here ever
		// writes it there, so every store update sees the same mismatch.
		$semanticData = new SemanticData( DIWikiPage::newFromTitle( $page ) );
		$semanticData->addPropertyObjectValue( new DIProperty( '_DTITLE' ), new SMWDIBlob( 'Unreachable' ) );

		$diff = $this->createMock( ChangeOp::class );
		$diff->method( 'getOrderedDiffByTable' )->willReturn( [] );

		for ( $i = 0; $i < 6; $i++ ) {
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $semanticData, $diff );
		}

		$this->assertSame( 3, Hooks::getPagePropsRefreshAttemptsForTesting( 'SDUDtUnreachable', 'Unreachable' ) );
		// The queue itself de-duplicates identical refresh jobs, so the
		// counter is what proves the bound.
		$this->assertGreaterThanOrEqual( 1, $this->countQueuedJobs( 'refreshLinksPrioritized' ) );

		// A genuinely new target starts again at zero.
		$semanticData = new SemanticData( DIWikiPage::newFromTitle( $page ) );
		$semanticData->addPropertyObjectValue( new DIProperty( '_DTITLE' ), new SMWDIBlob( 'Another target' ) );
		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $semanticData, $diff );

		$this->assertSame( 1, Hooks::getPagePropsRefreshAttemptsForTesting( 'SDUDtUnreachable', 'Another target' ) );
	}
}
