<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;

/**
 * Verifies $wgSDUIgnoredProperties against the real-world case that motivated
 * it: onAfterDataUpdateComplete() used to compare `p_id` against a hardcoded
 * `506` (comment: "revision ID change is good, but must not trigger UpdateJob
 * for semantic dependencies").
 *
 * `506` could not have been SMW's own `_MDAT` (modification date): that
 * property has a fixed ID of `29` since SMW's fixed-ID scheme was introduced
 * and is filtered out earlier via `unset( $diffTable['smw_fpt_mdat'] )`
 * regardless (see ModificationDateFilterIntegrationTest). But the optional
 * SemanticExtraSpecialProperties (SESP) extension provides a `___REVID`
 * ("Revision ID") property that behaves exactly as the comment describes: its
 * value is the current MediaWiki revision ID, so it changes on every single
 * edit including pure null-edits, and - unlike `_MDAT` - it is stored as a
 * normal (non-fixed) property in the generic `smw_di_number` table. `506` was
 * almost certainly that property's SMW object ID (smw_object_ids.smw_id) in
 * whatever installation this code was originally written against - a
 * dynamically assigned, per-installation value, not a portable constant,
 * which is exactly why the fix resolves configured property names to their
 * numeric ID at runtime via $wgSDUIgnoredProperties instead.
 *
 * This test is skipped when SESP is not installed (see extensions.local.json
 * and this repo's docker-compose-ci setup, which enables `___REVID` via
 * `$sespgEnabledPropertyList` in local_settings).
 *
 * `extension.json`'s $wgSDUIgnoredProperties now defaults to `[ "___REVID" ]`
 * (rather than an empty list) based on an empirical check against a real MW
 * 1.43 installation with SESP + PageForms + Approved Revs etc. installed:
 * ___REVID's stored value changed on every edit including a forced null
 * revision, while another SESP-provided property enabled in that same
 * installation's ignore list, ___CUSER, did not change in the same check -
 * so only ___REVID is defaulted here, not a broader guess at "any SESP
 * property".
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class RevisionIdPropertyIntegrationTest extends SduIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( !class_exists( '\SESP\PropertyAnnotators\RevisionIDPropertyAnnotator' ) ) {
			$this->markTestSkipped( 'SemanticExtraSpecialProperties is not installed in this environment.' );
		}

		global $sespgEnabledPropertyList, $wgSDUIgnoredProperties;

		if ( !in_array( '_REVID', $sespgEnabledPropertyList ?? [], true ) ) {
			$this->markTestSkipped( '$sespgEnabledPropertyList must include "_REVID" for this test.' );
		}

		$wgSDUIgnoredProperties = [ '___REVID' ];
	}

	public function addDBData() {
		parent::addDBData();

		$this->editPage( Title::newFromText( 'SDURevIdDependantPage', NS_MAIN ), 'unrelated page' );

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
	 * Forces a genuine null revision (MediaWiki's own mechanism for "mark an
	 * event without changing content", e.g. used for page moves) - unlike a
	 * byte-for-byte identical editPage() call, which MediaWiki rejects as a
	 * no-op (no new revision at all, so AfterDataUpdateComplete never fires).
	 * A null revision changes ___REVID (its value IS the new revision ID)
	 * while leaving every actual SMW fact, including wikitext, untouched -
	 * precisely isolating the scenario the "revision ID change is good"
	 * comment describes, with no risk of an unrelated side effect (e.g. an
	 * incidental wikitext change nudging some other internal property).
	 */
	private function forceNullRevision( Title $title ): void {
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$updater = $page->newPageUpdater( $this->getTestSysop()->getAuthority() );
		$updater->setForceEmptyRevision( true );
		$updater->saveRevision(
			\MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( 'null revision' )
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testNullRevisionWithOnlyRevisionIdChangeDoesNotTriggerDependencyUpdate() {
		$title = Title::newFromText( 'SDURevIdNullEditPage', NS_MAIN );

		$this->editPage(
			$title,
			'{{#set:Semantic Dependency=SDURevIdDependantPage}}'
		);

		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'Sanity check: the initial save with real semantic content must trigger SDU.'
		);

		$this->forceNullRevision( $title );

		$this->assertSame(
			0,
			$this->pushedUpdateJobCount(),
			'A null revision that only changes the SESP ___REVID property (via a ' .
			'new MediaWiki revision) and leaves all other semantic data untouched ' .
			'must not cause SDU to push a forced UpdateJob.'
		);
	}
}
