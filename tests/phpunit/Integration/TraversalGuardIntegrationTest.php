<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use SMW\DIProperty;
use SMW\DIWikiPage;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The per-process loop guard ($wgSDUTraversed): it stops a page whose
 * dependency updates keep producing genuine changes from being propagated
 * again and again by one PHP process - protection against two pages that
 * keep re-triggering each other.
 *
 * A job runner that works off several jobs in one process (runJobs.php
 * drains the whole queue in one; with --wait it never ends) shows that
 * "genuine passes of one page per process" is not the same as "a loop":
 *  - a self-referencing page whose derived values settle over several
 *    genuine passes is bounded by its own durable attempt limit and must
 *    not be cut short by the process counter, and
 *  - an old count must not stay on a page for the lifetime of a process.
 *
 * Every test here runs all its jobs inside the one PHPUnit process, which is
 * exactly the "one process works off many jobs" situation.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class TraversalGuardIntegrationTest extends SduIntegrationTestCase {

	public function addDBData() {
		parent::addDBData();

		foreach ( [ 'SDUTestSource', 'SDUTestDerived', 'SDUChain1', 'SDUChain2', 'SDUChain3' ] as $property ) {
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
		$values = $semanticData->getPropertyValues( DIProperty::newFromUserLabel( $propertyLabel ) );

		return array_map( static fn ( $v ) => $v->getString(), $values );
	}

	protected function tearDown(): void {
		ConvertibleTimestamp::setFakeTime( false );

		parent::tearDown();
	}

	/**
	 * Three derived values, each resolved from the one before: every genuine
	 * pass resolves exactly one more of them, so the cycle needs several
	 * genuine passes (more than the process counter allows) before the
	 * values - and therefore the held-back remote target - are final.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testCycleNeedingSeveralGenuinePassesEndsAndReleasesRemoteTargetsInOneProcess() {
		$selfTitle = Title::newFromText( 'SDUTraversalChainSelfPage', NS_MAIN );
		$remoteTitle = Title::newFromText( 'SDUTraversalChainRemotePage', NS_MAIN );

		$this->editPage(
			$remoteTitle,
			'{{#set:SDUTestDerived={{#show:SDUTraversalChainSelfPage|?SDUChain3}}}}'
		);
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$selfTitle,
			'{{#set:SDUTestSource=ChainValue}}'
			. '{{#set:SDUChain1={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:SDUChain2={{#show:{{FULLPAGENAME}}|?SDUChain1}}}}'
			. '{{#set:SDUChain3={{#show:{{FULLPAGENAME}}|?SDUChain2}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDUTraversalChainRemotePage}}'
		);

		$this->runAllJobsWithFreshCaches();

		$this->assertSame(
			[ 'ChainValue' ],
			$this->getPropertyStringValues( $selfTitle, 'SDUChain3' ),
			'The chain must have settled completely.'
		);
		$this->assertSelfUpdatePendingAttempt(
			0,
			$selfTitle,
			'The cycle must have ended; a process-wide counter must not leave it hanging.'
		);
		$this->assertSame(
			[ 'ChainValue' ],
			$this->getPropertyStringValues( $remoteTitle, 'SDUTestDerived' ),
			'The remote target held back during the cycle must have been released and seen the final value.'
		);
	}

	private function editPageWithPresetTraversalCount( string $text, string $name, int $count, ?int $secondsAgo ) {
		global $wgSDUTraversed, $wgSDUTraversedAt;

		$title = Title::newFromText( $name, NS_MAIN );
		$id = $title->getPrefixedDBKey();

		$wgSDUTraversed = [ $id => $count ];
		$wgSDUTraversedAt = $secondsAgo === null ? [] : [ $id => ConvertibleTimestamp::time() - $secondsAgo ];

		$this->editPage( $title, $text );

		return $this->getQueuedUpdateJobTitles();
	}

	/**
	 * The guard itself must keep working: a page that already had its genuine
	 * passes in this process moments ago is not propagated again.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testRecentCountStillStopsPropagation() {
		$this->editPage( Title::newFromText( 'SDUTraversalGuardTarget', NS_MAIN ), 'Target.' );
		$this->runAllJobsWithFreshCaches();

		$queued = $this->editPageWithPresetTraversalCount(
			'{{#set:SDUTestSource=GuardValue}}{{#set:Semantic Dependency=SDUTraversalGuardTarget}}',
			'SDUTraversalGuardSource',
			3,
			5
		);

		$this->assertNotContains( 'SDUTraversalGuardTarget', $queued );
	}

	/**
	 * A count from long ago is not a loop. Without this a process that never
	 * ends (runJobs.php --wait) would stop propagating a page for good once
	 * it had had its third genuine change over its whole lifetime.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testOldCountDoesNotStopPropagation() {
		$this->editPage( Title::newFromText( 'SDUTraversalOldTarget', NS_MAIN ), 'Target.' );
		$this->runAllJobsWithFreshCaches();

		$queued = $this->editPageWithPresetTraversalCount(
			'{{#set:SDUTestSource=OldValue}}{{#set:Semantic Dependency=SDUTraversalOldTarget}}',
			'SDUTraversalOldSource',
			3,
			3600
		);

		$this->assertContains( 'SDUTraversalOldTarget', $queued );
	}

}
