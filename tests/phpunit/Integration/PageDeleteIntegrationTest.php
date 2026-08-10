<?php

namespace SDU\Tests\Integration;

use Title;

/**
 * Verifies that deleting a page which itself declares a Semantic Dependency
 * on another page triggers a rebuild of that dependency target, per
 * Hooks::onPageDelete()/runDependencyUpdateOnDelete() - mirroring how a
 * genuine change to the property's value is handled in
 * onAfterDataUpdateComplete(), just for the "value disappeared because the
 * page was deleted" case.
 *
 * This requires "ArticleDelete" to actually be registered against
 * SDU\Hooks::onPageDelete in extension.json - without that registration,
 * MediaWiki never calls the handler and this whole feature is silently
 * dead. It must specifically be ArticleDelete, not PageDeleteComplete: SMW's
 * own ArticleDelete handler deletes the page's semantic data via a
 * DeferredUpdate whose execution timing depends on request context
 * (SMW\Site::isCommandLineMode()) - genuinely deferred to POSTSEND in a web
 * request (after PageDeleteComplete already fired), but run synchronously
 * and immediately in CLI/job-queue context (before PageDeleteComplete).
 * PHPUnit always runs as CLI, so this test would fail to see any semantic
 * data at all - reproducing a real gap in production job-queue-driven
 * deletions - if onPageDelete were still hooked on PageDeleteComplete.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class PageDeleteIntegrationTest extends SduIntegrationTestCase {

	private function pushedUpdateJobCount(): int {
		$status = $this->getServiceContainer()->getJobRunner()->run( [] );

		return count( array_filter(
			$status['jobs'],
			static fn ( $job ) => $job['type'] === 'smw.update'
		) );
	}

	/**
	 * @covers \SDU\Hooks::onPageDelete
	 */
	public function testDeletingPageWithDependencyTriggersTargetUpdate() {
		$targetTitle = Title::newFromText( 'SDUDeleteTargetPage', NS_MAIN );
		$dependantTitle = Title::newFromText( 'SDUDeleteDependantPage', NS_MAIN );

		$this->editPage( $targetTitle, 'unrelated content' );

		$this->editPage(
			$dependantTitle,
			'{{#set:Semantic Dependency=SDUDeleteTargetPage}}'
		);

		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'Sanity check: saving the page with a Semantic Dependency value must trigger SDU.'
		);

		$this->deletePage( $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $dependantTitle ) );

		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'Deleting a page that declares a Semantic Dependency on another page must ' .
			'push a forced UpdateJob for that target - this only happens if ' .
			'"ArticleDelete" is actually registered against SDU\\Hooks::onPageDelete ' .
			'in extension.json and still sees the semantic data before SMW\'s own ' .
			'cleanup removes it.'
		);
	}

	/**
	 * @covers \SDU\Hooks::onPageDelete
	 */
	public function testDeletingUnrelatedPageDoesNotTriggerUpdate() {
		$title = Title::newFromText( 'SDUDeleteUnrelatedPage', NS_MAIN );

		$this->editPage( $title, 'no semantic dependency property here' );

		$this->assertSame(
			0,
			$this->pushedUpdateJobCount(),
			'Sanity check: saving a page without the SDU property must not trigger SDU.'
		);

		$this->deletePage( $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title ) );

		$this->assertSame(
			0,
			$this->pushedUpdateJobCount(),
			'Deleting a page that carries no Semantic Dependency property must not ' .
			'push any forced UpdateJob.'
		);
	}
}
