<?php

namespace SDU\Tests;

use MediaWikiIntegrationTestCase;
use SDU\Hooks;
use SMW\DIProperty;
use SMW\SQLStore\ChangeOp\ChangeOp;
use Title;

/**
 * @group SemanticDependencyUpdater
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		global $wgSDUProperty, $wgSDUTraversed, $wgSDUIgnoredProperties;

		$wgSDUProperty = 'Depends On';
		$wgSDUTraversed = null;
		$wgSDUIgnoredProperties = [];

		Hooks::setup();
	}

	private function makeSemanticData( $title, array $props = [] ) {
		$subject = new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' );
		$semanticData = new \SMW\SemanticData( $subject );

		foreach ( $props as $propName => $values ) {
			$property = DIProperty::newFromUserLabel( $propName );
			foreach ( $values as $value ) {
				$semanticData->addPropertyObjectValue( $property, new \SMWDIBlob( $value ) );
			}
		}

		return $semanticData;
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testNoPropertyDoesNotTriggerUpdate() {
		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageWithoutSDUProperty', NS_MAIN );
		$this->editPage( $title, 'Test content' );

		$data = $this->makeSemanticData( $title );
		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [] );
		$mockDiff->method( 'getSubject' )->willReturn( new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' ) );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff )
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testNoDataChangeDoesNotTriggerUpdate() {
		global $wgSDUProperty;

		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageWithSDUProperty', NS_MAIN );
		$this->editPage( $title, '[[Depends On::TestPage]]' );

		$data = $this->makeSemanticData( $title, [ $wgSDUProperty => [ 'TestPage' ] ] );
		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [] );
		$mockDiff->method( 'getSubject' )->willReturn( new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' ) );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff )
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testSemanticChangeTriggersUpdate() {
		global $wgSDUProperty;

		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageWithSDUProperty', NS_MAIN );
		$this->editPage( $title, '[[Depends On::PageB]]' );

		$data = $this->makeSemanticData( $title, [ $wgSDUProperty => [ 'PageB' ] ] );

		$subject = new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' );

		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [
			'smw_di_blob' => [
				'insert' => [ [
					's_id' => $subject->getId(),
					'p_id' => 123
				] ]
			]
		] );
		$mockDiff->method( 'getSubject' )->willReturn( $subject );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff )
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testTriggerSemanticDependenciesSetToFalse() {
		global $wgSDUTraversed;
		global $wgSDUProperty;

		$id = 'TestID';
		$wgSDUTraversed[$id] = 3;

		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageWithSDUProperty', NS_MAIN );
		$this->editPage( $title, '[[Depends On::PageB]]' );

		$data = $this->makeSemanticData( $title, [ $wgSDUProperty => [ 'PageB' ] ] );
		$subject = new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' );

		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [
			'smw_di_blob' => [
				'insert' => [ [
					's_id' => $subject->getId(),
					'p_id' => 506
				] ]
			]
		] );
		$mockDiff->method( 'getSubject' )->willReturn( $subject );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff )
		);
	}

	/**
	 * Regression test for a bug found while auditing the self-update-pending
	 * marker: a self-referencing page (Depends On -> itself) whose value
	 * keeps producing a genuine, non-empty diff on consecutive calls (rather
	 * than the documented case of resolving after one retry) must have its
	 * self-update-pending attempt count ACCUMULATE across those calls, not
	 * reset to 1 on every one of them. Before the fix,
	 * onAfterDataUpdateComplete() unconditionally cleared the marker before
	 * (re-)marking a still-self-referencing page, so the marker never
	 * advanced past attempt 1 - and since $wgSDUTraversed (the only other
	 * bound) is explicitly documented as not surviving across real
	 * job-queue-runner process boundaries, such a page could re-trigger
	 * itself indefinitely across separate job-runner invocations in
	 * production.
	 *
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testSelfReferencingPageAccumulatesAttemptsAcrossConsecutiveGenuineChanges() {
		global $wgSDUProperty, $wgSDUTraversed;

		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageDependingOnItself', NS_MAIN );
		$this->editPage( $title, '[[Depends On::PageDependingOnItself]]' );

		$data = $this->makeSemanticData( $title, [ $wgSDUProperty => [ 'PageDependingOnItself' ] ] );
		$subject = new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' );

		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [
			'smw_di_blob' => [
				'insert' => [ [
					's_id' => $subject->getId(),
					'p_id' => 123
				] ]
			]
		] );
		$mockDiff->method( 'getSubject' )->willReturn( $subject );

		// Each call simulates one more genuine (non-empty diff) re-parse of
		// this permanently self-referencing page - $wgSDUTraversed is reset
		// between them to simulate separate job-queue-runner processes
		// (each with its own fresh, empty $wgSDUTraversed), the scenario
		// where that process-local guard cannot help at all.
		$wgSDUTraversed = [];
		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff );
		$this->assertSame(
			1,
			Hooks::getSelfUpdatePendingAttemptForTesting( $title->getPrefixedDBKey() ),
			'First genuine self-referencing change must mark the page as ' .
			'self-update-pending at attempt 1.'
		);

		$wgSDUTraversed = [];
		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff );
		$this->assertSame(
			2,
			Hooks::getSelfUpdatePendingAttemptForTesting( $title->getPrefixedDBKey() ),
			'A second consecutive genuine change on the same still-self-referencing ' .
			'page must ADVANCE the attempt count to 2, not reset it back to 1 - ' .
			'otherwise the marker could never bound a non-stabilizing self-reference ' .
			'across separate job-queue-runner processes.'
		);

		$wgSDUTraversed = [];
		Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff );
		$this->assertSame(
			3,
			Hooks::getSelfUpdatePendingAttemptForTesting( $title->getPrefixedDBKey() ),
			'A third consecutive genuine change must advance the attempt count to 3.'
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::updatePagesMatchingQuery
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testUpdaterAlreadyTraversed() {
		global $wgSDUTraversed;
		global $wgSDUProperty;

		$id = 'PageWithSDUProperty';
		$wgSDUTraversed[$id] = 3;

		/** @phpstan-ignore class.notFound */
		$title = Title::newFromText( 'PageWithSDUProperty', NS_MAIN );
		$this->editPage( $title, '[[Depends On::PageB]]' );

		$data = $this->makeSemanticData( $title, [ $wgSDUProperty => [ 'PageB' ] ] );
		$subject = new \SMW\DIWikiPage( $title->getText(), $title->getNamespace(), '' );

		$mockDiff = $this->createMock( ChangeOp::class );
		$mockDiff->method( 'getOrderedDiffByTable' )->willReturn( [
			'smw_di_blob' => [
				'insert' => [ [
					's_id' => $subject->getId(),
					'p_id' => 506
				] ]
			]
		] );
		$mockDiff->method( 'getSubject' )->willReturn( $subject );

		$this->assertTrue(
			Hooks::onAfterDataUpdateComplete( smwfGetStore(), $data, $mockDiff )
		);
	}
}
