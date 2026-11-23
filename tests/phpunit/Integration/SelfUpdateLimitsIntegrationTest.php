<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use ReflectionMethod;
use SDU\Hooks;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\SemanticData;
use SMW\SQLStore\ChangeOp\ChangeOp;

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
	 * A genuine (non-empty) change found by a PASS - a job re-parsing the page,
	 * not a save, which would restart the budget - on a self-referencing page
	 * that has already used up its attempts must neither re-queue the page
	 * itself nor drop its other dependency targets.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testRealChangeAtTheAttemptLimitStopsTheCycleButKeepsRemoteTargets() {
		$selfTitle = Title::newFromText( 'SDULimitRealDiffSelfPage', NS_MAIN );
		$remoteTitle = Title::newFromText( 'SDULimitRealDiffRemotePage', NS_MAIN );

		$this->editPage( $remoteTitle, 'Remote content.' );
		$this->editPage(
			$selfTitle,
			'{{#set:SDUTestSource=LimitValue}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}} OR SDULimitRealDiffRemotePage}}'
		);

		// Let the cycle of the save end, so that nothing is left in the queue.
		$this->runAllJobsWithFreshCaches();
		$this->assertSelfUpdatePendingAttempt( 0, $selfTitle );

		$this->advanceSelfUpdateMarker( $selfTitle, self::MAX_ATTEMPTS );
		$this->assertSelfUpdatePendingAttempt( self::MAX_ATTEMPTS, $selfTitle );

		$this->deliverRealChangePass( $selfTitle );

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
	 * A store update of the page as SDU's hook sees it, with a real change of
	 * the page's derived value (what a job pass finds when the cycle is not
	 * done yet).
	 */
	private function deliverRealChangePass( Title $title ): void {
		$subject = DIWikiPage::newFromTitle( $title );
		$data = clone smwfGetStore()->getSemanticData( $subject );
		$propertyId = smwfGetStore()->getObjectIds()->getSMWPropertyID(
			DIProperty::newFromUserLabel( 'SDUTestDerived' )
		);

		$diff = $this->createMock( ChangeOp::class );
		$diff->method( 'getOrderedDiffByTable' )->willReturn(
			[ 'smw_di_blob' => [ 'insert' => [ [ 's_id' => $subject->getId(), 'p_id' => $propertyId ] ] ] ]
		);
		$diff->method( 'getSubject' )->willReturn( $subject );

		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $diff );
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
	 * $wgSDUSettlePasses is the number of consecutive empty re-parses that
	 * end a self-update cycle. After the page's first forced re-parse found
	 * nothing new, a threshold of 1 (or anything below, which is clamped to
	 * 1) must end the cycle, while the default of 2 still needs one more
	 * confirmation pass.
	 *
	 * @dataProvider provideSettlePasses
	 * @covers \SDU\Hooks
	 */
	public function testSettlePassesDecideWhetherTheFirstEmptyPassEndsTheCycle(
		$settlePasses,
		bool $expectCycleEnded
	) {
		if ( $settlePasses !== 'unset' ) {
			$this->overrideConfigValue( 'SDUSettlePasses', $settlePasses );
		}

		$title = Title::newFromText( 'SDUSettlePassesPage' . md5( serialize( $settlePasses ) ), NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=SettledValue}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);
		$this->assertSelfUpdatePendingAttempt( 1, $title );

		// The page's own forced re-parse finds nothing new: one empty pass.
		$this->runJobsUntilOneUpdateJobRan();

		if ( $expectCycleEnded ) {
			$this->assertSelfUpdatePendingAttempt(
				0,
				$title,
				"With \$wgSDUSettlePasses = " . var_export( $settlePasses, true ) .
				' a single empty pass must end the cycle.'
			);
			$this->assertNotContains( $title->getPrefixedDBKey(), $this->getQueuedUpdateJobTitles() );
		} else {
			$this->assertSelfUpdatePendingAttemptAtLeast(
				1,
				$title,
				"With \$wgSDUSettlePasses = " . var_export( $settlePasses, true ) .
				' one empty pass is not yet enough to end the cycle.'
			);
			$this->assertContains( $title->getPrefixedDBKey(), $this->getQueuedUpdateJobTitles() );
		}
	}

	public static function provideSettlePasses(): array {
		return [
			'1 ends after one empty pass' => [ 1, true ],
			'0 is clamped to 1' => [ 0, true ],
			'negative is clamped to 1' => [ -5, true ],
			'2 needs a confirmation pass' => [ 2, false ],
			'default (2) needs a confirmation pass' => [ 'unset', false ],
		];
	}

	/**
	 * A pass of a running self-update cycle whose only difference is an ignored
	 * property (in production usually SESP's revision ID, which can differ
	 * between a save's own store update and the job that follows it) finds
	 * nothing the cycle could still change. It must count like an empty pass.
	 * Left alone, the cycle neither ended nor retried: the reload marker stayed
	 * until it expired and the editor's browser waited for a reload that never
	 * came, with the job queue already empty.
	 *
	 * The cycle's own derived value is put on the ignored list here, so that
	 * the one job pass that resolves it has an ignored-only difference.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testPassWithOnlyIgnoredChangesDoesNotLeaveTheCycleHanging() {
		global $wgSDUIgnoredProperties;

		$wgSDUIgnoredProperties = [ 'SDUTestDerived' ];

		$title = Title::newFromText( 'SDULimitIgnoredOnlyPassPage', NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=IgnoredValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);
		$this->assertSelfUpdatePendingAttempt( 1, $title );

		$this->runAllJobsWithFreshCaches();

		$this->assertSelfUpdatePendingAttempt(
			0,
			$title,
			'The cycle must have ended once nothing but ignored properties was left to change.'
		);
		$this->assertFalse(
			Hooks::isSelfUpdateReloadPending( $title->getPrefixedDBKey(), $title->getLatestRevID() ),
			'No reload may stay pending after the cycle is over.'
		);
	}

	/**
	 * Pages that are not in a cycle must not start one because of an
	 * ignored-only change - the property exists precisely so that such
	 * changes trigger nothing.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testIgnoredOnlyChangeOnAPageWithoutACycleStartsNothing() {
		global $wgSDUIgnoredProperties;

		$wgSDUIgnoredProperties = [ 'SDUTestSource' ];

		$title = Title::newFromText( 'SDULimitIgnoredOnlyNoCyclePage', NS_MAIN );

		$this->editPage( $title, '{{#set:SDUTestSource=Only}}{{#set:Semantic Dependency=SDULimitSomeOtherPage}}' );
		$this->runAllJobsWithFreshCaches();
		$this->editPage( $title, '{{#set:SDUTestSource=Changed}}{{#set:Semantic Dependency=SDULimitSomeOtherPage}}' );

		$this->assertSelfUpdatePendingAttempt( 0, $title );
		$this->assertNotContains( $title->getPrefixedDBKey(), $this->getQueuedUpdateJobTitles() );
	}

	/**
	 * A save that takes the dependency property off a page while its
	 * self-update cycle is still running ends that cycle: nothing is left to
	 * wait for, and the targets held back for it must not stay held back
	 * until the marker expires.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testRemovingTheDependencyPropertyEndsARunningCycle() {
		$title = Title::newFromText( 'SDULimitPropertyRemovedPage', NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=Value}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);
		$this->assertSelfUpdatePendingAttempt( 1, $title );

		$this->editPage( $title, '{{#set:SDUTestSource=Value}}' );

		$this->assertSelfUpdatePendingAttempt(
			0,
			$title,
			'Without the dependency property no self-update cycle may stay pending.'
		);
		$this->assertFalse(
			Hooks::isSelfUpdateReloadPending( $title->getPrefixedDBKey(), $title->getLatestRevID() )
		);
	}

	/**
	 * The attempt budget of a cycle bounds what a page's own passes may cost.
	 * A new save is a new event of its own - a user or a bot did it, not the
	 * cycle - so it must not inherit what the previous save's passes used up:
	 * two saves in quick succession used to exhaust the budget between them,
	 * and the cycle was ended by the limit while the data was still changing.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testANewSaveRestartsTheAttemptBudgetOfARunningCycle() {
		$title = Title::newFromText( 'SDULimitSaveRestartsBudgetPage', NS_MAIN );

		$this->editPage( $title, '{{#set:SDUTestSource=One}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}' );
		$this->advanceSelfUpdateMarker( $title, 2 );
		$this->assertSelfUpdatePendingAttempt( 3, $title );

		$this->editPage( $title, '{{#set:SDUTestSource=Two}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}' );

		// The save restarts the count at 1; its own real pass is the second attempt.
		$this->assertSelfUpdatePendingAttempt(
			2,
			$title,
			'A new save must get the budget of a fresh cycle, not what the previous save used up.'
		);
	}

	/**
	 * A save that changes nothing a cycle could act on (here: only prose, the
	 * ignored revision ID differs) must not abandon the running cycle: it keeps
	 * running with a restarted budget instead of being dropped without its end
	 * ever being handled (its marker and the targets held back for it would
	 * stay until they expire).
	 *
	 * @covers \SDU\Hooks
	 */
	public function testASaveWithoutSemanticChangesKeepsTheRunningCycleAlive() {
		global $wgSDUIgnoredProperties;
		$wgSDUIgnoredProperties = [ '___REVID' ];

		$title = Title::newFromText( 'SDULimitSaveKeepsCyclePage', NS_MAIN );
		$text = '{{#set:SDUTestSource=One}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $text );
		$this->advanceSelfUpdateMarker( $title, 2 );
		$this->assertSelfUpdatePendingAttempt( 3, $title );

		$this->editPage( $title, $text . "\nSome more prose." );

		$this->assertSelfUpdatePendingAttempt(
			1,
			$title,
			'The cycle must still be running, with its budget restarted.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testASaveOfAPageWithoutARunningCycleDoesNotStartOne() {
		$title = Title::newFromText( 'SDULimitSaveNoCyclePage', NS_MAIN );

		$this->editPage( $title, 'Just some prose, no dependency property at all.' );
		$this->editPage( $title, 'Just some other prose.' );

		$this->assertSelfUpdatePendingAttempt( 0, $title );
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
