<?php

namespace SDU\Tests\Integration;

use MediaWiki\Http\Telemetry;
use MediaWiki\Title\Title;
use SDU\Hooks;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\Enum;
use SMW\SQLStore\ChangeOp\ChangeOp;

/**
 * Only the pass of a job that SDU queued for its own cycle counts as evidence
 * that a self-update cycle has settled. Any other store update of the page -
 * the refresh a purge triggers at the next parse, an update from another
 * request, a job of an earlier cycle - can be empty without saying anything
 * about the state the cycle is waiting for, and must neither end the cycle
 * nor use up its attempts.
 *
 * A pass belongs to the cycle if it comes from an UpdateJob that was forced
 * (SMW marks the SemanticData of such a pass) and was queued by one of the
 * requests that made a real change during the cycle (a job carries the request
 * ID of the request that queued it).
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class PassProvenanceIntegrationTest extends SduIntegrationTestCase {

	private const SELF_REFERENCING = '{{#set:SDUTestSource=%s}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

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

	protected function setUp(): void {
		parent::setUp();

		// A single pass is enough to end a cycle: the case that depends on the
		// pass being the right one.
		$this->overrideConfigValue( 'SDUSettlePasses', 1 );
	}

	protected function tearDown(): void {
		Telemetry::getInstance()->regenerateRequestId();

		parent::tearDown();
	}

	private function startCycle( string $name, string $value = 'First' ): Title {
		$title = Title::newFromText( $name, NS_MAIN );

		$this->editPage( $title, sprintf( self::SELF_REFERENCING, $value ) );
		$this->assertSelfUpdatePendingAttempt( 1, $title, 'The self-reference must have started a cycle.' );

		return $title;
	}

	/**
	 * A store update of the page as seen by SDU's hook: no semantic change, or
	 * a change of the given ignored property only.
	 */
	private function deliverPass( Title $title, bool $fromForcedJob, ?string $onlyChangedProperty = null ): void {
		$subject = DIWikiPage::newFromTitle( $title );
		$data = clone smwfGetStore()->getSemanticData( $subject );

		if ( $fromForcedJob ) {
			$data->setOption( Enum::FORCED_UPDATE, (string)time() );
		}

		$diffTables = [];

		if ( $onlyChangedProperty !== null ) {
			$propertyId = smwfGetStore()->getObjectIds()->getSMWPropertyID(
				DIProperty::newFromUserLabel( $onlyChangedProperty )
			);
			$diffTables = [ 'smw_di_blob' => [ 'insert' => [ [ 's_id' => $subject->getId(), 'p_id' => $propertyId ] ] ] ];
		}

		$diff = $this->createMock( ChangeOp::class );
		$diff->method( 'getOrderedDiffByTable' )->willReturn( $diffTables );
		$diff->method( 'getSubject' )->willReturn( $subject );

		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $diff );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testEmptyPassThatIsNoForcedJobDoesNotEndTheCycle() {
		$title = $this->startCycle( 'SDUProvenanceUnforcedPage' );

		// E.g. the refresh SMW runs at the next parse after a purge.
		$this->deliverPass( $title, false );

		$this->assertSelfUpdatePendingAttempt(
			1,
			$title,
			'A store update that is no job of the cycle says nothing about the state it waits for: ' .
			'it must neither end the cycle nor use up an attempt.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testEmptyPassOfAForcedJobOfTheCycleEndsIt() {
		$title = $this->startCycle( 'SDUProvenanceForcedPage' );

		$this->deliverPass( $title, true );

		$this->assertSelfUpdatePendingAttempt( 0, $title );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testEmptyPassOfAForcedJobOfAnotherRequestDoesNotEndTheCycle() {
		$title = $this->startCycle( 'SDUProvenanceOtherRequestPage' );

		// E.g. a job of an earlier cycle that happens to finish just now.
		Telemetry::getInstance()->overrideRequestId( 'a-request-that-is-not-part-of-this-cycle' );
		$this->deliverPass( $title, true );

		$this->assertSelfUpdatePendingAttempt( 1, $title );
	}

	/**
	 * Identical jobs of different requests are duplicates for the job queue,
	 * and the queue keeps the one that was queued first. So the job that is
	 * left may carry the request ID of ANY real pass of the cycle, not just the
	 * latest one.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testPassOfEveryRequestThatChangedSomethingInTheCycleCounts() {
		$firstRequest = Telemetry::getInstance()->getRequestId();
		$title = $this->startCycle( 'SDUProvenanceEveryRequestPage' );

		Telemetry::getInstance()->overrideRequestId( 'a-later-request-with-a-real-change' );
		$this->editPage( $title, sprintf( self::SELF_REFERENCING, 'Second' ) );
		$this->assertSelfUpdatePendingAttempt( 2, $title, 'The second real change must continue the same cycle.' );

		Telemetry::getInstance()->overrideRequestId( $firstRequest );
		$this->deliverPass( $title, true );

		$this->assertSelfUpdatePendingAttempt(
			0,
			$title,
			'The job that is left in the queue may stem from the first request of the cycle.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPassOfAForcedJobWithOnlyIgnoredChangesCountsAsAnEmptyOne() {
		global $wgSDUIgnoredProperties;
		$wgSDUIgnoredProperties = [ 'SDUTestDerived' ];

		$title = $this->startCycle( 'SDUProvenanceIgnoredForcedPage' );

		$this->deliverPass( $title, true, 'SDUTestDerived' );

		$this->assertSelfUpdatePendingAttempt( 0, $title );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testIgnoredOnlyChangeThatIsNoForcedJobDoesNotEndTheCycle() {
		global $wgSDUIgnoredProperties;
		$wgSDUIgnoredProperties = [ 'SDUTestDerived' ];

		$title = $this->startCycle( 'SDUProvenanceIgnoredUnforcedPage' );

		$this->deliverPass( $title, false, 'SDUTestDerived' );

		$this->assertSelfUpdatePendingAttempt( 1, $title );
	}

}
