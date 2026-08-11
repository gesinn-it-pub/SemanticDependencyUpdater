<?php

namespace SDU\Tests\Integration;

use MediaWiki\Api\ApiMain;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;

/**
 * Covers SDU\Api\ApiSduSelfUpdateStatus, the read-only API module
 * res/sdu/ext.sdu.reload.js polls to ask "is a reload for this exact
 * revision still pending?" - see that class's own docblock for why it
 * exists rather than reusing action=purge or action=parse.
 *
 * Drives the module through a real MediaWiki\Api\ApiMain request (rather
 * than instantiating ApiSduSelfUpdateStatus directly) so the extension.json
 * "APIModules" registration, parameter validation, and response shape
 * (including the META_BC_BOOLS boolean handling the class's own docblock
 * documents as easy to silently regress) are all exercised the same way a
 * real client request would be, not just the class's own execute() body in
 * isolation.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class ApiSduSelfUpdateStatusIntegrationTest extends SduIntegrationTestCase {

	private function callApi( array $params ): array {
		$request = new FauxRequest( $params + [ 'action' => 'sduselfupdatestatus' ] );

		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setUser( $this->getTestUser()->getUser() );

		$api = new ApiMain( $context );
		$api->execute();

		return $api->getResult()->getResultData( [], [ 'BC' => [] ] );
	}

	/**
	 * @covers \SDU\Api\ApiSduSelfUpdateStatus::execute
	 * @covers \SDU\Api\ApiSduSelfUpdateStatus::getAllowedParams
	 */
	public function testReportsPendingTrueForARevisionWithAGenuineSelfReferencingChange() {
		$title = Title::newFromText( 'SDUApiStatusPendingTestPage', NS_MAIN );

		$wikitext = '{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );
		$revId = $title->getLatestRevID();

		$result = $this->callApi( [
			'title' => $title->getPrefixedText(),
			'revid' => (string)$revId,
		] );

		$this->assertSame(
			true,
			$result['sduselfupdatestatus']['pending'],
			'The API must report pending:true (a real PHP boolean, not an empty ' .
			'string or missing key - see ApiSduSelfUpdateStatus\'s own docblock on ' .
			'ApiResult::META_BC_BOOLS) for the revision that just triggered a ' .
			'genuine self-referencing change.'
		);
	}

	/**
	 * @covers \SDU\Api\ApiSduSelfUpdateStatus::execute
	 */
	public function testReportsPendingFalseOnceTheSelfUpdateCycleHasEnded() {
		$title = Title::newFromText( 'SDUApiStatusResolvedTestPage', NS_MAIN );

		$wikitext = '{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );
		$revId = $title->getLatestRevID();

		// Drain the whole queue: the forced self-UpdateJob resolves Derived,
		// and the subsequent empty-diff retries exhaust
		// MAX_CONSECUTIVE_EMPTY_DIFFS, ending the cycle and clearing the marker.
		$this->getServiceContainer()->getJobRunner()->run( [] );

		$result = $this->callApi( [
			'title' => $title->getPrefixedText(),
			'revid' => (string)$revId,
		] );

		$this->assertSame(
			false,
			$result['sduselfupdatestatus']['pending'],
			'Once the self-update cycle has ended, the API must report ' .
			'pending:false (a real PHP boolean, not an empty string) for the ' .
			'revision that originally triggered it.'
		);
	}

	/**
	 * @covers \SDU\Api\ApiSduSelfUpdateStatus::execute
	 */
	public function testReportsPendingFalseForATitleThatWasNeverSelfReferencing() {
		$title = Title::newFromText( 'SDUApiStatusUnrelatedTestPage', NS_MAIN );

		$this->editPage( $title, 'Just some ordinary wikitext, no SDU property at all.' );

		$result = $this->callApi( [
			'title' => $title->getPrefixedText(),
			'revid' => (string)$title->getLatestRevID(),
		] );

		$this->assertSame(
			false,
			$result['sduselfupdatestatus']['pending'],
			'A page that never had a self-update cycle at all must report ' .
			'pending:false, not error out or default to true.'
		);
	}

	/**
	 * @covers \SDU\Api\ApiSduSelfUpdateStatus::execute
	 */
	public function testDiesWithAnErrorForAnInvalidTitle() {
		$this->expectException( \MediaWiki\Api\ApiUsageException::class );

		// "[[" is not a valid title in any namespace - Title::newFromText()
		// returns null for it, which execute() must turn into a clean API
		// error (dieWithError()) rather than a fatal on a null title.
		$this->callApi( [
			'title' => '[[',
			'revid' => '1',
		] );
	}

}
