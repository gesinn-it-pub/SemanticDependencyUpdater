<?php

namespace SDU\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use SDU\Hooks;

/**
 * Covers SDU\Hooks::onOutputPageParserOutput(), which renders the
 * `.sdu-reload-pending` div and queues the `ext.sdu.reload` module for a
 * self-referencing page whose real, non-ignored change would otherwise be
 * masked by its own forced self-UpdateJob before SMW's own PostProcHandler
 * ever gets a chance to show a reload prompt - see that method's own
 * docblock for the two conditions ("authorized" via either MediaWiki's own
 * post-edit cookie or the reload-pending marker) that gate rendering.
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
		// isolating the marker-based half of onOutputPageParserOutput()'s
		// "authorized" check from the cookie-based half.
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
	public function testRendersThePromptWhenThePostEditCookieIsPresent() {
		$title = Title::newFromText( 'SDUOutputPageCookieTestPage', NS_MAIN );

		// No SDU property at all, and thus no reload-pending marker - the
		// post-edit cookie alone must be sufficient to authorize rendering,
		// per onOutputPageParserOutput()'s own docblock on why it deliberately
		// checks EITHER condition, not just the marker.
		$this->editPage( $title, 'Just some ordinary wikitext.' );
		$revId = $title->getLatestRevID();

		$outputPage = $this->renderFor( $title, [
			'PostEditRevision' . $revId => '1',
		] );

		$this->assertStringContainsString(
			'sdu-reload-pending',
			$outputPage->getHTML(),
			'MediaWiki\'s own post-edit cookie for the current revision must ' .
			'alone be sufficient to render the prompt, independent of whether ' .
			'a reload-pending marker exists.'
		);
	}

	/**
	 * @covers \SDU\Hooks::onOutputPageParserOutput
	 */
	public function testDoesNotRenderWithoutEitherAuthorizationSignal() {
		$title = Title::newFromText( 'SDUOutputPageUnauthorizedTestPage', NS_MAIN );

		$this->editPage( $title, 'Just some ordinary wikitext, no self-update cycle at all.' );

		$outputPage = $this->renderFor( $title );

		$this->assertStringNotContainsString(
			'sdu-reload-pending',
			$outputPage->getHTML(),
			'Without a matching post-edit cookie or reload-pending marker, ' .
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
