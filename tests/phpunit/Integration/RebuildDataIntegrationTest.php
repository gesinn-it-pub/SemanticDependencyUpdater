<?php

namespace SDU\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Title\Title;
use SDU\Hooks;
use SMW\DIWikiPage;

/**
 * Hooks::rebuildData() has two modes. With the dependency trigger enabled it
 * queues forced UpdateJobs (covered by the other integration tests). Without
 * it, it forces a single immediate update of the first given page as a
 * deferred update - covered here.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class RebuildDataIntegrationTest extends SduIntegrationTestCase {

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
	 * @covers \SDU\Hooks
	 */
	public function testWithoutDependencyTriggerTheFirstPageIsUpdatedImmediately() {
		$sourceTitle = Title::newFromText( 'SDURebuildSourcePage', NS_MAIN );
		$targetTitle = Title::newFromText( 'SDURebuildTargetPage', NS_MAIN );

		// The target derives a value from the source page, which does not
		// exist yet - so Derived is empty after the target's own save.
		$this->editPage(
			$targetTitle,
			'{{#set:SDUTestDerived={{#show:SDURebuildSourcePage|?SDUTestSource}}}}'
		);
		$this->editPage( $sourceTitle, '{{#set:SDUTestSource=RebuiltValue}}' );
		$this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertSame(
			[],
			$this->getPropertyStringValues( $targetTitle, 'SDUTestDerived' ),
			'Sanity check: nothing has updated the target since the source appeared.'
		);

		$this->resetSmwCaches();

		Hooks::rebuildData(
			false,
			[ DIWikiPage::newFromTitle( $targetTitle ) ],
			DIWikiPage::newFromTitle( $sourceTitle )
		);
		DeferredUpdates::doUpdates();

		$this->assertSame(
			[ 'RebuiltValue' ],
			$this->getPropertyStringValues( $targetTitle, 'SDUTestDerived' ),
			'rebuildData() without dependency trigger must re-parse the first given page right away.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testWithDependencyTriggerTheUpdateIsQueuedInsteadOfRunImmediately() {
		$sourceTitle = Title::newFromText( 'SDURebuildQueuedSourcePage', NS_MAIN );
		$targetTitle = Title::newFromText( 'SDURebuildQueuedTargetPage', NS_MAIN );

		$this->editPage(
			$targetTitle,
			'{{#set:SDUTestDerived={{#show:SDURebuildQueuedSourcePage|?SDUTestSource}}}}'
		);
		$this->editPage( $sourceTitle, '{{#set:SDUTestSource=QueuedValue}}' );
		$this->getServiceContainer()->getJobRunner()->run( [] );

		Hooks::rebuildData(
			true,
			[ DIWikiPage::newFromTitle( $targetTitle ) ],
			DIWikiPage::newFromTitle( $sourceTitle )
		);
		DeferredUpdates::doUpdates();

		$this->assertSame(
			[],
			$this->getPropertyStringValues( $targetTitle, 'SDUTestDerived' ),
			'With the dependency trigger, the update is only queued, not run immediately.'
		);

		$this->runAllJobsWithFreshCaches();

		$this->assertSame(
			[ 'QueuedValue' ],
			$this->getPropertyStringValues( $targetTitle, 'SDUTestDerived' )
		);
	}
}
