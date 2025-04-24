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

	private $jobPushed = false;

	protected function setUp(): void {
		parent::setUp();

		global $wgSDUProperty, $wgSDUTraversed, $wgSDUUseJobQueue;

		$wgSDUProperty = 'Depends On';
		$wgSDUTraversed = [];
		$wgSDUUseJobQueue = false;

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
}
