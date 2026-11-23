<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;

/**
 * Page names with characters that {{FULLPAGENAME}} writes as character
 * references ("A &#38; B"), that look like query operators ("A OR B") or that
 * equal the PageForms list separator (";") must still be found by the
 * dependency query - for a page naming itself and for a page naming another.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class SpecialCharacterTitleIntegrationTest extends SduIntegrationTestCase {

	public static function provideTitleTexts(): iterable {
		$texts = [
			'ampersand' => 'Itest A & B',
			'apostrophe' => "Itest A'B",
			'quote' => 'Itest A "B"',
			'semicolon' => 'Itest A;B',
			'equals' => 'Itest A=B',
			'plus' => 'Itest A+B',
			'percent' => 'Itest A%B',
			'question mark' => 'Itest A?B',
			'exclamation mark' => 'Itest A!B',
			'parentheses' => 'Itest A (B)',
			'asterisk' => 'Itest A*B',
			'at' => 'Itest A@B',
			'dollar' => 'Itest A$B',
			'comma' => 'Itest A, B',
			'dot' => 'Itest A.B',
			'tilde' => 'Itest A~B',
			'caret' => 'Itest A^B',
			'backtick' => 'Itest A`B',
			'colon' => 'Itest A:B',
			'slash' => 'Itest A/B',
			'backslash' => 'Itest A\\B',
			'umlauts' => 'Itest Äöü ß é',
			'euro' => 'Itest €',
			'emoji' => 'Itest 😀 B',
			'text amp' => 'Itest A &amp; B',
			'text numeric reference' => 'Itest A &#38; B',
			'upper OR as word' => 'Itest A OR B',
			'upper AND as word' => 'Itest A AND B',
			'OR inside a word' => 'Itest AORB',
			'AND inside a word' => 'Itest AANDB',
			'ORDER' => 'Itest ORDER',
			'BRAND' => 'Itest BRAND',
			'lower or' => 'Itest A or B',
			'trailing ampersand' => 'Itest A &',
			'trailing apostrophe' => "Itest A'",
			'only specials' => "Itest &'\";=",
		];

		foreach ( $texts as $label => $text ) {
			yield $label => [ $text ];
		}
	}

	public function addDBData() {
		parent::addDBData();

		$this->editPage(
			Title::newFromText( 'SDUTestSource', SMW_NS_PROPERTY ),
			'{{#set:Has type=Text}}'
		);
		$this->getServiceContainer()->getJobRunner()->run( [] );
	}

	protected function setUp(): void {
		parent::setUp();

		// SDU reads the list separator PageForms declares
		$this->overrideConfigValue( 'PageFormsListSeparator', ';' );
		$GLOBALS['wgPageFormsListSeparator'] = ';';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wgPageFormsListSeparator'] );
		parent::tearDown();
	}

	/**
	 * @dataProvider provideTitleTexts
	 * @covers \SDU\Hooks
	 */
	public function testSelfReferenceStartsSelfUpdateCycle( string $text ) {
		$title = Title::newFromText( $text );
		$this->assertNotNull( $title, "'$text' must be a valid title" );

		$this->editPage( $title, '{{#set:Semantic Dependency={{FULLPAGENAME}}}}{{#set:SDUTestSource=1}}' );

		$this->assertSelfUpdatePendingAttempt( 1, $title, "self reference of '$text'" );
	}

	/**
	 * A page that names another page in its dependency property queues an
	 * update of that page when it changes.
	 *
	 * @dataProvider provideTitleTexts
	 * @covers \SDU\Hooks
	 */
	public function testPageNamingAnotherPageQueuesItsUpdate( string $text ) {
		$target = Title::newFromText( $text );
		$source = Title::newFromText( 'SDUSourceNaming ' . md5( $text ) );

		$this->editPage( $target, '{{#set:SDUTestSource=1}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}{{#set:Semantic Dependency={{FULLPAGENAME:' . $target->getPrefixedText() . '}}}}'
		);

		$this->assertContains(
			$target->getPrefixedDBKey(),
			$this->getQueuedUpdateJobTitles(),
			"page naming '$text'"
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testSeveralValuesAreStillSplitAtTheListSeparator() {
		$first = Title::newFromText( 'Itest A & B' );
		$second = Title::newFromText( 'Itest Plain' );
		$source = Title::newFromText( 'SDUSourceNamingBoth' );

		$this->editPage( $first, '{{#set:SDUTestSource=1}}' );
		$this->editPage( $second, '{{#set:SDUTestSource=1}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME:Itest A & B}};{{FULLPAGENAME:Itest Plain}}}}'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertContains( $first->getPrefixedDBKey(), $queued );
		$this->assertContains( $second->getPrefixedDBKey(), $queued );
	}

	public static function providePropertyListValues(): iterable {
		yield 'plain names' => [ 'SDUMulT1 AxB', 'SDUMulT2 AxB' ];
		yield 'ampersand in one name' => [ 'SDUMulT1 A & B', 'SDUMulT2 AxB' ];
		yield 'apostrophe in both names' => [ "SDUMulT1 A'B", "SDUMulT2 A'B" ];
	}

	/**
	 * "Property::Value1;Value2" lists several values of one property: every
	 * page that has one of them must be updated.
	 *
	 * @dataProvider providePropertyListValues
	 * @covers \SDU\Hooks
	 */
	public function testPropertyConditionWithSeveralValuesMatchesEveryValue( string $first, string $second ) {
		$dependent1 = Title::newFromText( 'SDUPropListDependent1 ' . md5( $first ) );
		$dependent2 = Title::newFromText( 'SDUPropListDependent2 ' . md5( $first ) );
		$source = Title::newFromText( 'SDUPropListSource ' . md5( $first ) );

		$this->editPage( $dependent1, '{{#set:SDUPropListRef=' . $first . '}}' );
		$this->editPage( $dependent2, '{{#set:SDUPropListRef=' . $second . '}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}{{#set:Semantic Dependency=SDUPropListRef::'
			. '{{FULLPAGENAME:' . $first . '}};{{FULLPAGENAME:' . $second . '}}}}'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertContains( $dependent1->getPrefixedDBKey(), $queued );
		$this->assertContains( $dependent2->getPrefixedDBKey(), $queued );
	}

	public static function provideReadAsListNames(): iterable {
		yield 'OR as a word' => [ 'SDULimit A OR SDULimit B' ];
	}

	/**
	 * Documents a known limitation (see the README, "Page names with special
	 * characters"): a value that is not the name of an existing page, and not
	 * the name of the page being saved, is read as a list of values. A
	 * reference to a page that does not exist yet and whose name contains " OR "
	 * as a word therefore updates the pages named by its parts, not that
	 * page. This test pins that behaviour so a change to it is noticed.
	 *
	 * @dataProvider provideReadAsListNames
	 * @covers \SDU\Hooks
	 */
	public function testKnownLimitationNameWithOperatorWordOfMissingPageIsReadAsList( string $name ) {
		$partA = Title::newFromText( 'SDULimit A' );
		$partB = Title::newFromText( 'SDULimit B' );
		$source = Title::newFromText( 'SDULimitSource ' . md5( $name ) );

		$this->assertFalse( Title::newFromText( $name )->exists(), 'the named page must not exist' );

		$this->editPage( $partA, '{{#set:SDUTestSource=1}}' );
		$this->editPage( $partB, '{{#set:SDUTestSource=1}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}{{#set:Semantic Dependency={{FULLPAGENAME:' . $name . '}}}}'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertContains( $partA->getPrefixedDBKey(), $queued );
		$this->assertContains( $partB->getPrefixedDBKey(), $queued );
	}

	/**
	 * The counterpart of the limitation: once the page exists, or when it is
	 * the page being saved, the same name is one page, not a list.
	 *
	 * @dataProvider provideReadAsListNames
	 * @covers \SDU\Hooks
	 */
	public function testNameWithOperatorWordOfExistingPageIsOnePage( string $name ) {
		$partA = Title::newFromText( 'SDULimit A' );
		$named = Title::newFromText( $name );
		$source = Title::newFromText( 'SDULimitSourceExisting ' . md5( $name ) );

		$this->editPage( $partA, '{{#set:SDUTestSource=1}}' );
		$this->editPage( $named, '{{#set:SDUTestSource=1}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}{{#set:Semantic Dependency={{FULLPAGENAME:' . $name . '}}}}'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertContains( $named->getPrefixedDBKey(), $queued );
		$this->assertNotContains( $partA->getPrefixedDBKey(), $queued );
	}

	/**
	 * {{FULLPAGENAME}} writes ";" as a character reference, which is not a
	 * list separator: a missing page whose name contains ";" is one name and
	 * updates none of the pages named by its parts.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testNameWithSemicolonOfMissingPageIsNotReadAsList() {
		$name = 'SDUSemi A;SDUSemi B';
		$partA = Title::newFromText( 'SDUSemi A' );
		$partB = Title::newFromText( 'SDUSemi B' );
		$source = Title::newFromText( 'SDUSemiSource' );

		$this->editPage( $partA, '{{#set:SDUTestSource=1}}' );
		$this->editPage( $partB, '{{#set:SDUTestSource=1}}' );
		$this->runAllJobsWithFreshCaches();

		$this->editPage(
			$source,
			'{{#set:SDUTestSource=1}}{{#set:Semantic Dependency={{FULLPAGENAME:' . $name . '}}}}'
		);

		$queued = $this->getQueuedUpdateJobTitles();

		$this->assertNotContains( $partA->getPrefixedDBKey(), $queued );
		$this->assertNotContains( $partB->getPrefixedDBKey(), $queued );
	}
}
