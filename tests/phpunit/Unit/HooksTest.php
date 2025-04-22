<?php

use SDU\Hooks;

/**
 * @group SemanticDependencyUpdater
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	protected $title;
	protected $subject;
	protected $semanticData;
	protected $mockStore;

	protected function setUp(): void {
		parent::setUp();

		/** @phpstan-ignore-next-line */
		$this->title = Title::newFromText( 'PageA', 5010 );
		$this->subject = new \SMW\DIWikiPage( $this->title->getDBkey(), $this->title->getNamespace() );
		$this->semanticData = new \SMW\SemanticData( $this->subject );
		/** @phpstan-ignore-next-line */
		$this->mockStore = $this->createMock( \SMW\Store::class );
		$this->mockStore->method( 'getQueryResult' )->willReturn( [] );

		$GLOBALS['smwgStore'] = $this->mockStore;
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testOnAfterDataUpdateComplete_withDiff() {
		global $wgSDUProperty, $wgSDUUseJobQueue, $wgSDUTraversed;

		$wgSDUProperty = 'Semantic Dependency';
		$wgSDUUseJobQueue = true;
		$wgSDUTraversed = null;

		$property = \SMW\DIProperty::newFromUserLabel( $wgSDUProperty );
		$targetPage = new \SMW\DIWikiPage( 'PageB', NS_MAIN, '' );
		$this->semanticData->addPropertyObjectValue( $property, $targetPage );

		$diff = [
			'smw_di' => [
				'insert' => [
					[ 's_id' => 123, 'p_id' => 999 ]
				]
			]
		];
		$changeOp = new \SMW\SQLStore\ChangeOp\ChangeOp( $this->subject, $diff );

		$result = Hooks::onAfterDataUpdateComplete(
			$this->mockStore,
			$this->semanticData,
			$changeOp
		);

		/** @phpstan-ignore-next-line */
		$this->assertTrue( $result );
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testOnAfterDataUpdateComplete_withNoDiff() {
		global $wgSDUProperty, $wgSDUUseJobQueue, $wgSDUTraversed;

		$wgSDUProperty = 'Semantic Dependency';
		$wgSDUUseJobQueue = false;
		$wgSDUTraversed = null;

		$property = \SMW\DIProperty::newFromUserLabel( $wgSDUProperty );
		$targetPage = new \SMW\DIWikiPage( 'PageB', NS_MAIN, '' );
		$this->semanticData->addPropertyObjectValue( $property, $targetPage );

		$diff = [
			'smw_di' => [
				'insert' => [],
				'delete' => []
			]
		];
		$changeOp = new \SMW\SQLStore\ChangeOp\ChangeOp( $this->subject, $diff );

		$result = Hooks::onAfterDataUpdateComplete(
			$this->mockStore,
			$this->semanticData,
			$changeOp
		);

		/** @phpstan-ignore-next-line */
		$this->assertTrue( $result );
	}

}
