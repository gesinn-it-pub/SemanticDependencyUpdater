<?php

namespace SDU\Tests\Integration;

use FauxRequest;
use OutputPage;
use ParserOutput;
use RequestContext;
use SDU\Hooks;
use Title;
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
	 * @covers \SDU\Hooks::onOutputPageParserOutput
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
