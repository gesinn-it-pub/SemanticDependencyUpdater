<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use SDU\Hooks;

/**
 * A page's dependency query runs on the first pass of a save. Pages that the
 * same save brings into existence (e.g. through #createpageifnotex in a
 * template) are not in the store yet at that moment and would never be found.
 * SDU therefore evaluates the query again when the page's self-update cycle
 * ends (clearSelfUpdatePending()) and releases those pages too.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class RequeryAtCycleEndIntegrationTest extends SduIntegrationTestCase {

	private const TEAM_TEXT = '{{#set:SDUTestSource=Value}}'
		. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDUReqRelated::SDUReqTeam}}';

	public function addDBData() {
		parent::addDBData();

		foreach ( [ 'SDUTestSource', 'SDUReqRelated' ] as $property ) {
			$this->editPage(
				Title::newFromText( $property, SMW_NS_PROPERTY ),
				'{{#set:Has type=' . ( $property === 'SDUReqRelated' ? 'Page' : 'Text' ) . '}}'
			);
		}

		$this->getServiceContainer()->getJobRunner()->run( [] );
	}

	/**
	 * Drains jobs one at a time until the page's self-update cycle has ended
	 * and returns the pages with a queued smw.update job at that moment.
	 *
	 * @return string[]
	 */
	private function runUntilCycleEnded( Title $team ): array {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->resetSmwCaches();
			$status = $this->getServiceContainer()->getJobRunner()->run( [ 'maxJobs' => 1 ] );

			if ( $status['jobs'] === [] ) {
				$this->fail( 'Job queue ran dry before the self-update cycle ended.' );
			}

			if ( Hooks::getSelfUpdatePendingAttemptForTesting( $team->getPrefixedDBKey() ) === 0 ) {
				return $this->getQueuedUpdateJobTitles();
			}
		}

		$this->fail( 'The self-update cycle did not end within 50 jobs.' );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPageCreatedByTheSameSaveIsFoundAtCycleEnd() {
		$team = Title::newFromText( 'SDUReqTeam', NS_MAIN );
		$role = Title::newFromText( 'SDUReqRole', NS_MAIN );

		$this->editPage( $team, '{{#set:SDUTestSource=Old}}' );
		$this->runAllJobsWithFreshCaches();

		// Existing team page gets its dependency; the first pass finds no
		// related page yet.
		$this->editPage( $team, self::TEAM_TEXT );
		$this->assertSelfUpdatePendingAttempt( 1, $team );

		// The related page appears only after that first pass, as the
		// template's #createpageifnotex does within the same save.
		$this->editPage( $role, '{{#set:SDUReqRelated=SDUReqTeam}}' );
		$this->assertNotContains( $role->getPrefixedDBKey(), $this->getQueuedUpdateJobTitles() );

		$this->assertContains(
			$role->getPrefixedDBKey(),
			$this->runUntilCycleEnded( $team ),
			'The page that did not exist during the first pass must be found when the cycle ends.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testSelfIsNeverReleasedAsRemoteTarget() {
		$team = Title::newFromText( 'SDUReqTeamSelf', NS_MAIN );

		$this->editPage(
			$team,
			'{{#set:SDUTestSource=Value}}{{#set:SDUReqRelated=SDUReqTeamSelf}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDUReqRelated::SDUReqTeamSelf}}'
		);

		$queuedAtEnd = $this->runUntilCycleEnded( $team );

		$this->assertNotContains( $team->getPrefixedDBKey(), $queuedAtEnd );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testCycleEndWithoutCycleDoesNotRequery() {
		$team = Title::newFromText( 'SDUReqTeamNoCycle', NS_MAIN );
		$role = Title::newFromText( 'SDUReqRoleNoCycle', NS_MAIN );

		// Not self-referencing: no cycle, so nothing is held back or queried
		// again beyond the regular first pass.
		$this->editPage( $role, '{{#set:SDUReqRelated=SDUReqTeamNoCycle}}' );
		$this->editPage(
			$team,
			'{{#set:SDUTestSource=Value}}{{#set:Semantic Dependency=SDUReqRelated::SDUReqTeamNoCycle}}'
		);

		$this->assertSelfUpdatePendingAttempt( 0, $team );
	}
}
