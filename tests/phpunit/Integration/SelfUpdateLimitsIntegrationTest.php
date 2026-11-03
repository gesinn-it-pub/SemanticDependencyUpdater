<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use ReflectionMethod;
use SDU\Hooks;
use SMW\DIWikiPage;
use SMW\SemanticData;

/**
 * The bounds of the "Update Self" cycle: how many attempts a page gets, what
 * happens to its other dependency targets when the limit is hit, and how a
 * retry is scheduled.
 *
 * The attempt count lives in a marker that survives between job runner
 * processes. Reaching the limit organically would take several real
 * re-parses that each need a specific store state, so these tests advance
 * the marker to the state just before the limit through the same private
 * method production code uses (markSelfUpdatePending()).
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class SelfUpdateLimitsIntegrationTest extends SduIntegrationTestCase {

	/** Mirrors Hooks::SELF_UPDATE_MAX_ATTEMPTS and SELF_UPDATE_RETRY_DELAY_SECONDS. */
	private const MAX_ATTEMPTS = 4;

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

	/**
	 * Advances the self-update-pending marker of a page by the given number
	 * of attempts, exactly as the production code does on each (re-)push.
	 */
	private function advanceSelfUpdateMarker( Title $title, int $attempts ): void {
		$method = new ReflectionMethod( Hooks::class, 'markSelfUpdatePending' );

		for ( $i = 0; $i < $attempts; $i++ ) {
			$method->invoke( null, $title->getPrefixedDBKey() );
		}
	}

	/**
	 * A genuine (non-empty) change on a self-referencing page that has
	 * already used up its attempts must neither re-queue the page itself nor
	 * drop its other dependency targets.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testRealChangeAtTheAttemptLimitStopsTheCycleButKeepsRemoteTargets() {
		$selfTitle = Title::newFromText( 'SDULimitRealDiffSelfPage', NS_MAIN );
		$remoteTitle = Title::newFromText( 'SDULimitRealDiffRemotePage', NS_MAIN );

		$this->editPage( $remoteTitle, 'Remote content.' );
		$this->runAllJobsWithFreshCaches();

		$this->advanceSelfUpdateMarker( $selfTitle, self::MAX_ATTEMPTS );
		$this->assertSelfUpdatePendingAttempt( self::MAX_ATTEMPTS, $selfTitle );

		$this->editPage(
			$selfTitle,
			'{{#set:SDUTestSource=LimitValue}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDULimitRealDiffRemotePage}}'
		);

		$this->assertSelfUpdatePendingAttempt(
			0,
			$selfTitle,
			'Past the attempt limit the self-update cycle must be ended, not continued.'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertNotContains(
			$selfTitle->getPrefixedDBKey(),
			$queued,
			'The page must not re-queue itself once its attempts are used up.'
		);
		$this->assertContains(
			$remoteTitle->getPrefixedDBKey(),
			$queued,
			'Dropping the attempt-limited self-reference must not drop the other dependency targets.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testEmptyRetryAtTheAttemptLimitEndsTheCycleWithoutAnotherJob() {
		$title = Title::newFromText( 'SDULimitEmptyDiffPage', NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=SettledValue}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);
		$this->assertSelfUpdatePendingAttempt( 1, $title );

		// The page's own forced re-parse is about to find nothing new. With
		// every attempt but the last already used, that empty diff must end
		// the cycle instead of scheduling a further retry.
		$this->advanceSelfUpdateMarker( $title, self::MAX_ATTEMPTS - 1 );

		$this->runJobsUntilOneUpdateJobRan();

		$this->assertSelfUpdatePendingAttempt( 0, $title );
		$this->assertNotContains(
			$title->getPrefixedDBKey(),
			$this->getQueuedUpdateJobTitles(),
			'No retry may be queued once the attempt limit is exceeded.'
		);
	}

	/**
	 * The retry is delayed where the queue supports it, by the step that
	 * belongs to the attempt. The test environment's DB-backed queue does
	 * not support delayed jobs, so a queue that does is substituted for the
	 * one retry under test.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testRetryIsDelayedByTheStepOfItsAttemptWhereTheQueueSupportsDelays() {
		$title = Title::newFromText( 'SDULimitDelayedRetryPage', NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=DelayedValue}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);

		$this->resetSmwCaches();
		$job = $this->getServiceContainer()->getJobQueueGroup()->get( 'smw.update' )->pop();
		$this->assertNotFalse( $job, 'The self-update job must be waiting in the queue.' );

		$pushed = [];
		// A queue that reports delayed job support, like Redis-backed queues do.
		// delayedJobsEnabled() is final and defers to supportsDelayedJobs(),
		// so that is what the stand-in overrides; the parent constructor is
		// not needed for what the retry code asks of the queue.
		$queue = new class() extends \JobQueueMemory {
			public function __construct() {
			}

			protected function supportsDelayedJobs() {
				return true;
			}
		};

		$group = $this->createMock( \JobQueueGroup::class );
		$group->method( 'get' )->willReturn( $queue );
		$group->method( 'lazyPush' )->willReturnCallback(
			static function ( $jobs ) use ( &$pushed ) {
				foreach ( is_array( $jobs ) ? $jobs : [ $jobs ] as $pushedJob ) {
					$pushed[] = $pushedJob;
				}
			}
		);
		$this->setService( 'JobQueueGroup', $group );

		// Replacing a service resets the stash that holds the marker, so the
		// state the job should find is set up afterwards. The job counts
		// itself as the next attempt: from marker 2 that is attempt 3, whose
		// delay step (SELF_UPDATE_RETRY_DELAY_SECONDS[2]) is 2 seconds.
		$this->advanceSelfUpdateMarker( $title, 2 );

		$before = time();
		$job->run();
		$after = time();

		$retries = array_values( array_filter(
			$pushed,
			static fn ( $pushedJob ) => $pushedJob->getType() === 'smw.update'
		) );

		$this->assertCount( 1, $retries, 'The empty re-parse must queue exactly one retry.' );

		$releaseTimestamp = $retries[0]->getParams()['jobReleaseTimestamp'] ?? null;

		$this->assertNotNull( $releaseTimestamp, 'A queue with delay support must get a delayed retry.' );
		$this->assertGreaterThanOrEqual( $before + 2, $releaseTimestamp );
		$this->assertLessThanOrEqual( $after + 2, $releaseTimestamp );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testAPageWithoutAValidTitleIsIgnored() {
		$subject = new DIWikiPage( 'Invalid|Title', NS_MAIN );

		$this->assertNull( $subject->getTitle(), 'Precondition: the subject must not resolve to a title.' );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete(
				smwfGetStore(),
				new SemanticData( $subject ),
				// Never read: the hook returns before it looks at the diff.
				null
			)
		);
	}
}
