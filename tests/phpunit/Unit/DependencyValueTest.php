<?php

namespace SDU\Tests;

use MediaWikiIntegrationTestCase;
use SDU\DependencyValue;

/**
 * @group SemanticDependencyUpdater
 * @covers \SDU\DependencyValue
 */
class DependencyValueTest extends MediaWikiIntegrationTestCase {

	public static function provideSplit(): iterable {
		yield 'entity ends in the separator' => [ 'Itest A &#38; B', ';', true, [ 'Itest A & B' ] ];
		yield 'apostrophe entity' => [ 'Itest A&#39;B', ';', true, [ "Itest A'B" ] ];
		yield 'two values, one with entity' => [ 'A &#38; B;C', ';', true, [ 'A & B', 'C' ] ];
		yield 'raw semicolon separates' => [ 'A;B', ';', true, [ 'A', 'B' ] ];
		yield 'entity of the semicolon is not a separator' => [ 'A&#59;B;C', ';', true, [ 'A;B', 'C' ] ];
		yield 'text amp decodes once' => [ 'A &#38;amp B', ';', true, [ 'A &amp B' ] ];
		yield 'stored value is not decoded' => [ 'A &#38; B', null, false, [ 'A &#38; B' ] ];
		yield 'stored raw values split' => [ "A'B;C & D", ';', false, [ "A'B", 'C & D' ] ];
		yield 'no separator configured' => [ 'A;B', null, true, [ 'A;B' ] ];
		yield 'empty pieces dropped' => [ 'A;;B; ', ';', true, [ 'A', 'B' ] ];
	}

	/**
	 * @dataProvider provideSplit
	 */
	public function testSplit( string $value, ?string $separator, bool $encoded, array $expected ) {
		$this->assertSame( $expected, DependencyValue::split( $value, $separator, $encoded ) );
	}

	public static function provideCondition(): iterable {
		yield 'page name stays' => [ 'Itest AORB', false, 'Itest AORB' ];
		yield 'AND inside a word' => [ 'Itest AANDB', false, 'Itest AANDB' ];
		yield 'BRAND' => [ 'BRAND', false, 'BRAND' ];
		yield 'page name with operator word' => [ 'Itest A OR B', true, 'Itest A OR B' ];
		yield 'OR operator' => [ 'Category:A OR Category:B', false, 'Category:A ]] OR [[ Category:B' ];
		yield 'AND operator' => [ 'Category:A AND Has x::y', false, 'Category:A ]] [[ Has x::y' ];
	}

	/**
	 * @dataProvider provideCondition
	 */
	public function testToCondition( string $text, bool $isPageName, string $expected ) {
		$this->assertSame( $expected, DependencyValue::toCondition( $text, $isPageName ) );
	}
}
