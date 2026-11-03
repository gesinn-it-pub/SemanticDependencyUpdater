<?php

namespace SDU\Tests\Integration;

use MediaWikiIntegrationTestCase;
use SDU\Hooks;
use Title;

/**
 * Covers the helpers of the page_props refresh that need only MediaWiki, not
 * SMW's store pipeline, so they also run where SduIntegrationTestCase skips
 * (SMW < 7.0), e.g. against MediaWiki 1.39.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class PagePropsDisplayTitleHelpersTest extends MediaWikiIntegrationTestCase {

	private function call( string $method, ...$args ) {
		$reflection = new \ReflectionMethod( Hooks::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testNormalizeDisplayTitle() {
		$this->assertSame(
			'Tom & Jerry foo bar',
			$this->call( 'normalizeDisplayTitle', ' <i>Tom</i> &amp; Jerry_foo   bar ' )
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testGetPagePropsDisplayTitleReadsPrimaryDatabase() {
		$title = Title::newFromText( 'SDUDtHelperPage' );
		$this->editPage( $title, '{{DISPLAYTITLE:sDUDtHelperPage}}' );

		$this->assertSame( 'sDUDtHelperPage', $this->call( 'getPagePropsDisplayTitle', $title ) );

		$plain = Title::newFromText( 'SDUDtHelperPlain' );
		$this->editPage( $plain, 'text' );

		$this->assertSame( 'SDUDtHelperPlain', $this->call( 'getPagePropsDisplayTitle', $plain ) );
	}
}
