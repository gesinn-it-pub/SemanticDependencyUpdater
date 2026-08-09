<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;

/**
 * Reproduces the "revision ID change is good, but must not trigger UpdateJob
 * for semantic dependencies" filter in onAfterDataUpdateComplete(), which is
 * meant to ignore modification-date-only changes (SMW's `_MDAT` property).
 *
 * That filter compares `p_id` against a hardcoded `506`. `_MDAT`'s fixed
 * property ID has been `29` since SMW's fixed-ID scheme was introduced
 * (SMW\TypesRegistry::getFixedProperties()) and has never been `506` in any
 * SMW version. Separately, `_MDAT` changes are recorded in the dedicated
 * `smw_fpt_mdat` fixed-property table (no `p_id` column at all - fixed
 * property tables are already bound to a single property), which is already
 * unset() from $diffTable before this loop runs and is excluded again by the
 * `strpos( $key, 'smw_di' ) !== 0` guard. So `_MDAT` changes can never reach
 * the `p_id != 506` comparison at all; the check is dead code that doesn't
 * do what its comment claims, on any SMW version.
 *
 * This test does not care about the specific numeric ID; it verifies the
 * *behaviour* the comment promises: a page whose only actual change is its
 * revision/modification date (a null-edit with identical semantic content)
 * must not cause SDU to push a forced UpdateJob, while a page whose semantic
 * content genuinely changes must.
 *
 * `506` most plausibly comes from `SemanticExtraSpecialProperties`'s
 * `___REVID` ("Revision ID") property, which (unlike `_MDAT`) is a normal,
 * non-fixed property recorded in the generic `smw_di_number` table and DOES
 * reach the `p_id != 506` comparison on every edit, since its value (the new
 * MediaWiki revision ID) changes on every save including pure null-edits.
 * `506` is that property's SMW object ID in the environment this code was
 * written against - a dynamically assigned, installation-specific ID, not a
 * fixed/portable one, which is exactly why hardcoding it here is fragile
 * (verified in tests/phpunit/Integration/RevisionIdPropertyIntegrationTest.php,
 * which requires SemanticExtraSpecialProperties to be installed - see
 * extensions.local.json).
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class ModificationDateFilterIntegrationTest extends SduIntegrationTestCase {

	public function addDBData() {
		parent::addDBData();

		// Both possible dependency targets must exist for
		// updatePagesMatchingQuery()'s [[PageName]] condition to match
		// anything at all - otherwise a genuine trigger would still push
		// zero jobs, for a reason unrelated to the _MDAT filter under test.
		$this->editPage( Title::newFromText( 'SDUMdatDependantPage', NS_MAIN ), 'unrelated page' );
		$this->editPage( Title::newFromText( 'SDUMdatOtherDependantPage', NS_MAIN ), 'unrelated page' );

		// Drain the ChangePropagationUpdateJob SMW schedules for the property
		// type declaration now, so it cannot fire during the actual test and
		// be mistaken for SDU-triggered behaviour.
		$this->getServiceContainer()->getJobRunner()->run( [] );
	}

	private function pushedUpdateJobCount(): int {
		$status = $this->getServiceContainer()->getJobRunner()->run( [] );

		return count( array_filter(
			$status['jobs'],
			static fn ( $job ) => $job['type'] === 'smw.update'
		) );
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testPureNullEditDoesNotTriggerDependencyUpdate() {
		$title = Title::newFromText( 'SDUMdatNullEditPage', NS_MAIN );
		$wikitext = '{{#set:Semantic Dependency=SDUMdatDependantPage}}';

		$this->editPage( $title, $wikitext );

		// First save carries real semantic content changes (the SDU property
		// itself being set) and is expected to trigger.
		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'Sanity check: the initial save with real semantic content must trigger SDU.'
		);

		// A genuine null-edit (identical wikitext) only changes _MDAT.
		$this->editPage( $title, $wikitext );

		$this->assertSame(
			0,
			$this->pushedUpdateJobCount(),
			'A pure null-edit (identical semantic content, only _MDAT changes) ' .
			'must not cause SDU to push a forced UpdateJob for its dependency.'
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testGenuineSemanticChangeStillTriggersDependencyUpdate() {
		$title = Title::newFromText( 'SDUMdatRealChangePage', NS_MAIN );

		$this->editPage( $title, '{{#set:Semantic Dependency=SDUMdatDependantPage}}' );
		$this->pushedUpdateJobCount();

		// Change the actual semantic content, not just the revision.
		$this->editPage( $title, '{{#set:Semantic Dependency=SDUMdatOtherDependantPage}}' );

		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'A genuine semantic content change must still trigger SDU, ' .
			'regardless of how _MDAT-only changes are filtered.'
		);
	}
}
