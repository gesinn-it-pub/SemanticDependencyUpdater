<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use SMW\DIWikiPage;

/**
 * A page can list its own name AND another ("remote") page as Semantic
 * Dependency values. The remote page's forced UpdateJob must not race the
 * self-referencing page's still-running self-update cycle: in an unordered
 * job queue the remote re-parse could read the page's data before its own
 * derived value has settled. SDU therefore holds the remote targets back
 * (markPendingRemoteTargets()) and releases them exactly once, when the
 * self-update cycle ends (clearSelfUpdatePending()).
 *
 * The remote page here derives a value from the self-referencing page via a
 * live store query, so "released and updated afterwards" is observable as
 * that derived value finally resolving.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class RemoteTargetHoldBackIntegrationTest extends SduIntegrationTestCase {

	public function addDBData() {
		parent::addDBData();

		foreach ( [ 'SDUTestSource', 'SDUTestDerived' ] as $property ) {
			$this->editPage(
				Title::newFromText( $property, SMW_NS_PROPERTY ),
				'{{#set:Has type=Text}}'
			);
		}

		$this->getServiceContainer()->getJobRunner()->run( [] );
	}

	private function getPropertyStringValues( Title $title, string $propertyLabel ): array {
		// Reading a page's data leaves it in SMW's caches, where a later job
		// in this same process would see it instead of the stored state.
		$this->resetSmwCaches();

		$semanticData = smwfGetStore()->getSemanticData( DIWikiPage::newFromTitle( $title ) );
		$values = $semanticData->getPropertyValues( \SMW\DIProperty::newFromUserLabel( $propertyLabel ) );

		return array_map( static fn ( $v ) => $v->getString(), $values );
	}

	/**
	 * @return string[] prefixed DB keys of the pages with a queued smw.update job
	 */
	private function getQueuedUpdateJobTitles(): array {
		$titles = [];

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'smw.update' );

		foreach ( $queue->getAllQueuedJobs() as $job ) {
			$titles[] = $job->getTitle()->getPrefixedDBKey();
		}

		return $titles;
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testRemoteTargetIsHeldBackUntilSelfUpdateCycleEnds() {
		$selfTitle = Title::newFromText( 'SDUHoldBackSelfPage', NS_MAIN );
		$remoteTitle = Title::newFromText( 'SDUHoldBackRemotePage', NS_MAIN );

		// The remote page derives a value from the self-referencing page.
		// That page does not exist yet, so Derived stays empty for now.
		$this->editPage(
			$remoteTitle,
			'{{#set:SDUTestDerived={{#show:SDUHoldBackSelfPage|?SDUTestSource}}}}'
		);
		$this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertSame( [], $this->getPropertyStringValues( $remoteTitle, 'SDUTestDerived' ) );

		// One dependency value that matches two pages (SDU turns "OR" into an
		// SMW query disjunction): the page itself and the remote page.
		$this->editPage(
			$selfTitle,
			'{{#set:SDUTestSource=HeldBackValue}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDUHoldBackRemotePage}}'
		);

		$this->assertSelfUpdatePendingAttempt( 1, $selfTitle );

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertContains(
			$selfTitle->getPrefixedDBKey(),
			$queued,
			'The self-update job must be queued right after the save.'
		);
		$this->assertNotContains(
			$remoteTitle->getPrefixedDBKey(),
			$queued,
			'The remote target must be held back while the self-update cycle is still running.'
		);
		$this->assertSame(
			[],
			$this->getPropertyStringValues( $remoteTitle, 'SDUTestDerived' ),
			'The remote page must not have been updated yet.'
		);

		// Drain the queue: the self-update cycle runs to its end, which
		// releases the held-back remote target exactly once.
		$this->runAllJobsWithFreshCaches();

		$this->assertSelfUpdatePendingAttempt( 0, $selfTitle );
		$this->assertSame(
			[ 'HeldBackValue' ],
			$this->getPropertyStringValues( $remoteTitle, 'SDUTestDerived' ),
			'Once the self-update cycle has ended, the held-back remote target must be ' .
			'updated and see the settled data of the self-referencing page.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPageWithoutRemoteTargetHoldsNothingBack() {
		$selfTitle = Title::newFromText( 'SDUHoldBackOnlySelfPage', NS_MAIN );

		$this->editPage(
			$selfTitle,
			'{{#set:SDUTestSource=OnlySelf}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);

		$this->assertSame(
			[ $selfTitle->getPrefixedDBKey() ],
			array_values( array_unique( $this->getQueuedUpdateJobTitles() ) ),
			'A page that only references itself queues just its own update job.'
		);

		$this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertSelfUpdatePendingAttempt( 0, $selfTitle );
	}
}
