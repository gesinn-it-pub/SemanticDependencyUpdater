<?php

namespace SDU\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use SDU\Hooks;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Covers SDU\Hooks::onOutputPageParserOutput(), which renders the
 * `.sdu-reload-pending` div and queues the `ext.sdu.reload` module for a
 * self-referencing page whose real, non-ignored change would otherwise be
 * masked by its own forced self-UpdateJob before SMW's own PostProcHandler
 * ever gets a chance to show a reload prompt - see that method's own
 * docblock for the condition (the reload-pending marker matching the current
 * revision) that gates rendering.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class OutputPageParserOutputIntegrationTest extends SduIntegrationTestCase {

	private function renderFor( Title $title, array $cookies = [] ): OutputPage {
		$request = new FauxRequest();
		foreach ( $cookies as $key => $value ) {
			$request->setCookie( $key, $value );
		}

		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setTitle( $title );
		$context->setUser( $this->getTestUser()->getUser() );

		$outputPage = new OutputPage( $context );
		$outputPage->setTitle( $title );

		Hooks::onOutputPageParserOutput( $outputPage, new ParserOutput() );

		return $outputPage;
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testRendersThePromptWhenTheReloadPendingMarkerMatchesTheCurrentRevision() {
		$title = Title::newFromText( 'SDUOutputPageMarkerTestPage', NS_MAIN );

		$wikitext = '{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		// A genuine self-referencing edit sets the reload-pending marker for
		// this exact revision (see onAfterDataUpdateComplete()'s
		// markReloadPending() call) - no post-edit cookie is involved here,
		// so rendering here depends on the marker alone.
		$this->editPage( $title, $wikitext );

		$outputPage = $this->renderFor( $title );

		$this->assertStringContainsString(
			'sdu-reload-pending',
			$outputPage->getHTML(),
			'The reload-pending marker matching this page\'s current revision ' .
			'must render the .sdu-reload-pending div.'
		);
		$this->assertContains(
			'ext.sdu.reload',
			$outputPage->getModules(),
			'ext.sdu.reload must be queued so the client-side poll actually runs.'
		);
	}

	/**
	 * The client polls the status API at the interval the server hands it,
	 * via a data attribute of the prompt element - $wgSDUReloadPollIntervalMs,
	 * default 250, kept within sane bounds so a misconfiguration can neither
	 * hammer the wiki nor leave the editor waiting minutes.
	 *
	 * @dataProvider providePollIntervals
	 * @covers \SDU\Hooks
	 */
	public function testPromptCarriesTheConfiguredPollInterval( $configured, int $expected ) {
		if ( $configured !== 'unset' ) {
			$this->overrideConfigValue( 'SDUReloadPollIntervalMs', $configured );
		}

		$title = Title::newFromText( 'SDUOutputPagePollIntervalPage' . md5( serialize( $configured ) ), NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);

		$this->assertStringContainsString(
			'data-poll-interval="' . $expected . '"',
			$this->renderFor( $title )->getHTML()
		);
	}

	public static function providePollIntervals(): array {
		return [
			'default' => [ 'unset', 250 ],
			'configured' => [ 1000, 1000 ],
			'too small is raised to the minimum' => [ 1, 100 ],
			'too large is lowered to the maximum' => [ 600000, 10000 ],
			'garbage falls back to the default' => [ 'abc', 250 ],
		];
	}

	/**
	 * Edits a page and records what a browser that follows the redirect right
	 * away would get: MediaWiki runs PageSaveComplete before the response is
	 * sent, while the store update (and with it SDU's cycle) only runs
	 * afterwards. The state seen from this hook is therefore the state the
	 * redirect GET can already rely on.
	 *
	 * @return array{pending: bool, html: string}
	 */
	private function editAndObserveBeforeTheStoreUpdate( Title $title, string $wikitext ): array {
		$seen = null;

		$this->setTemporaryHook(
			'PageSaveComplete',
			function ( $wikiPage, $user, $summary, $flags, $revisionRecord ) use ( $title, &$seen ) {
				if ( !$wikiPage->getTitle()->equals( $title ) ) {
					return;
				}

				$fresh = Title::newFromText( $title->getPrefixedText() );

				$seen = [
					'pending' => Hooks::isSelfUpdateReloadPending(
						$title->getPrefixedDBKey(),
						$revisionRecord->getId()
					),
					'html' => $this->renderFor( $fresh )->getHTML(),
				];
			},
			false
		);

		$this->editPage( $title, $wikitext );

		$this->assertNotNull( $seen, 'PageSaveComplete must have fired for the edited page.' );

		return $seen;
	}

	/**
	 * A browser follows the redirect of a save before SDU's post-send cycle
	 * has run. A page that names itself as a dependency must already show the
	 * reload prompt then, or the editor sees stale values without any hint
	 * that they are about to change.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testSelfReferencingSaveIsPendingBeforeTheStoreUpdateRuns() {
		$title = Title::newFromText( 'SDUOutputPagePreSendSelfPage', NS_MAIN );

		$seen = $this->editAndObserveBeforeTheStoreUpdate(
			$title,
			'{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);

		$this->assertTrue(
			$seen['pending'],
			'The reload must already be pending when the save request is about to be answered.'
		);
		$this->assertStringContainsString( 'sdu-reload-pending', $seen['html'] );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPageThatOnlyNamesOtherPagesIsNotPendingBeforeTheStoreUpdateRuns() {
		$title = Title::newFromText( 'SDUOutputPagePreSendRemotePage', NS_MAIN );

		$seen = $this->editAndObserveBeforeTheStoreUpdate(
			$title,
			'{{#set:SDUTestSource=SourceValue}}{{#set:Semantic Dependency=SDUSomeOtherPage}}'
		);

		$this->assertFalse( $seen['pending'], 'A page that does not name itself has nothing to wait for.' );
		$this->assertStringNotContainsString( 'sdu-reload-pending', $seen['html'] );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testPageWithoutTheDependencyPropertyIsNotPendingBeforeTheStoreUpdateRuns() {
		$title = Title::newFromText( 'SDUOutputPagePreSendPlainPage', NS_MAIN );

		$seen = $this->editAndObserveBeforeTheStoreUpdate( $title, '{{#set:SDUTestSource=SourceValue}}' );

		$this->assertFalse( $seen['pending'] );
		$this->assertStringNotContainsString( 'sdu-reload-pending', $seen['html'] );
	}

	/**
	 * Which dependency values count as "names this page itself" for the
	 * marker set before the response: the page alone, in any spelling
	 * MediaWiki treats as the same title, or as one alternative of a union -
	 * but not another page whose name merely starts the same way.
	 *
	 * @dataProvider provideDependencyValues
	 * @covers \SDU\Hooks
	 */
	public function testOnlyAValueNamingThePageItselfIsPendingBeforeTheStoreUpdateRuns(
		string $value,
		bool $expectedPending
	) {
		$title = Title::newFromText( 'SDU PreSend Value Page ' . md5( $value ), NS_MAIN );

		$seen = $this->editAndObserveBeforeTheStoreUpdate(
			$title,
			'{{#set:SDUTestSource=x}}{{#set:Semantic Dependency='
			. str_replace(
				[ '%s', '%u' ],
				[ $title->getText(), $title->getDBkey() ],
				$value
			) . '}}'
		);

		$this->assertSame( $expectedPending, $seen['pending'] );
	}

	public static function provideDependencyValues(): array {
		return [
			'the page itself' => [ '%s', true ],
			'underscores instead of spaces' => [ '%u', true ],
			'as one alternative of a union' => [ 'SDUSomeOtherPage OR %s', true ],
			'as the last alternative of a union' => [ '%s OR SDUSomeOtherPage', true ],
			'only another page' => [ 'SDUSomeOtherPage', false ],
			'another page whose name starts the same way' => [ '%s Extra', false ],
		];
	}

	/**
	 * Without a declared type the dependency value is not a text value, SDU
	 * itself ignores it - and the save must neither break nor wait on it.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testPropertyWithoutADeclaredTypeIsNotPendingAndDoesNotBreakTheSave() {
		$this->overrideConfigValue( 'SDUProperty', 'SDU Undeclared Dependency' );

		$title = Title::newFromText( 'SDUOutputPageUndeclaredPropertyPage', NS_MAIN );

		$seen = $this->editAndObserveBeforeTheStoreUpdate(
			$title,
			'{{#set:SDU Undeclared Dependency={{FULLPAGENAME}}}}'
		);

		$this->assertFalse( $seen['pending'] );
		$this->assertTrue( $title->exists() );
	}

	/**
	 * A failure of this optimization must never cost anybody a save: without
	 * an update in progress (here: called outside any edit) the hook has
	 * nothing to look at and has to leave quietly.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testPageSaveCompleteHookLeavesQuietlyWithoutAnUpdateInProgress() {
		$title = Title::newFromText( 'SDUOutputPageNoUpdatePage', NS_MAIN );
		$this->editPage( $title, '{{#set:SDUTestSource=x}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}' );
		// Let the edit's own cycle end, so a marker found afterwards can
		// only come from the call below.
		$this->runAllJobsWithFreshCaches();

		$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$this->assertTrue( Hooks::onPageSaveComplete(
			$wikiPage,
			$this->getTestUser()->getUser(),
			'',
			0,
			$wikiPage->getRevisionRecord(),
			new EditResult( false, null, null, null, null, false, false, [] )
		) );
		$this->assertFalse( Hooks::isSelfUpdateReloadPending(
			$title->getPrefixedDBKey(),
			$wikiPage->getLatest()
		) );
	}

	/**
	 * A re-save that changes nothing creates no revision and runs no store
	 * update, so nothing could ever withdraw a marker set for it.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testNullEditDoesNotLeaveAMarkerBehind() {
		$title = Title::newFromText( 'SDUOutputPageNullEditPage', NS_MAIN );
		$wikitext = '{{#set:SDUTestSource=x}}{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );
		$this->runAllJobsWithFreshCaches();

		$this->editPage( $title, $wikitext );

		$this->assertFalse(
			Hooks::isSelfUpdateReloadPending( $title->getPrefixedDBKey(), $title->getLatestRevID() )
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testDoesNotRenderForThePostEditCookieAloneWithoutAPendingCycle() {
		$title = Title::newFromText( 'SDUOutputPageCookieTestPage', NS_MAIN );

		// No SDU property at all, and thus no reload-pending marker: the
		// sduselfupdatestatus API would answer "pending: false" for this
		// revision, so the prompt must not render either - otherwise the
		// client purges, polls, gets "false", reloads, and is served the
		// prompt again for as long as the cookie lives (up to 20 minutes
		// when the cookie is never consumed, e.g. ApprovedRevs showing an
		// older revision).
		$this->editPage( $title, 'Just some ordinary wikitext.' );
		$revId = $title->getLatestRevID();

		$outputPage = $this->renderFor( $title, [
			'PostEditRevision' . $revId => '1',
		] );

		$this->assertStringNotContainsString( 'sdu-reload-pending', $outputPage->getHTML() );
		$this->assertNotContains( 'ext.sdu.reload', $outputPage->getModules() );
		$this->assertFalse( Hooks::isSelfUpdateReloadPending( $title->getPrefixedDBKey(), $revId ) );
	}

	/**
	 * ApprovedRevs scenario: the page is displayed at an older (approved)
	 * revision while the post-edit cookie belongs to the newer, latest one
	 * and core never consumes it.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testDoesNotRenderWhenCookieIsSetForNewerRevisionThanDisplayed() {
		$title = Title::newFromText( 'SDUOutputPageOlderRevisionTestPage', NS_MAIN );

		$this->editPage( $title, 'First revision.' );
		$this->editPage( $title, 'Second revision.' );
		$latestRevId = $title->getLatestRevID( IDBAccessObject::READ_LATEST );

		$outputPage = $this->renderFor( $title, [
			'PostEditRevision' . $latestRevId => '1',
		] );

		$this->assertStringNotContainsString( 'sdu-reload-pending', $outputPage->getHTML() );
	}

	/**
	 * Marker emitted must imply the status API reports pending for the same
	 * revision, so server render and API cannot disagree.
	 *
	 * @covers \SDU\Hooks
	 */
	public function testEmittedMarkerImpliesStatusApiReportsPending() {
		$title = Title::newFromText( 'SDUOutputPageConsistencyTestPage', NS_MAIN );

		$this->editPage( $title,
			'{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}'
		);
		$revId = $title->getLatestRevID();

		$outputPage = $this->renderFor( $title, [ 'PostEditRevision' . $revId => '1' ] );

		$this->assertStringContainsString( 'sdu-reload-pending', $outputPage->getHTML() );
		$this->assertTrue( Hooks::isSelfUpdateReloadPending( $title->getPrefixedDBKey(), $revId ) );
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testDoesNotRenderWithoutAPendingMarker() {
		$title = Title::newFromText( 'SDUOutputPageUnauthorizedTestPage', NS_MAIN );

		$this->editPage( $title, 'Just some ordinary wikitext, no self-update cycle at all.' );

		$outputPage = $this->renderFor( $title );

		$this->assertStringNotContainsString(
			'sdu-reload-pending',
			$outputPage->getHTML(),
			'Without a matching reload-pending marker, ' .
			'the prompt must not render - e.g. an unrelated visitor loading ' .
			'this page from a link.'
		);
		$this->assertNotContains(
			'ext.sdu.reload',
			$outputPage->getModules(),
			'ext.sdu.reload must not be queued when the prompt itself does not render.'
		);
	}

	/**
	 * @covers \SDU\Hooks
	 */
	public function testDoesNotRenderForANonExistentTitle() {
		$title = Title::newFromText( 'SDUOutputPageNonExistentTestPage', NS_MAIN );
		$this->assertFalse( $title->exists(), 'Sanity check: this title must not exist yet.' );

		$outputPage = $this->renderFor( $title );

		$this->assertStringNotContainsString(
			'sdu-reload-pending',
			$outputPage->getHTML(),
			'A non-existent title (e.g. a red link preview) has no revision to ' .
			'authorize against and must not render the prompt.'
		);
	}

}
