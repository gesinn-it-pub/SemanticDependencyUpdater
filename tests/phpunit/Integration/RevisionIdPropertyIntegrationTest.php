<?php

namespace SDU\Tests\Integration;

use Title;

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

		// SMW defaults undeclared properties to type Page ($smwgPDefaultType),
		// not Text - needed by testNullRevisionWithOnlyIgnoredPropertyChangeDoesNotClearPendingSelfUpdate()'s
		// self-referencing "Update Self" scenario (see SelfReferenceIntegrationTest).
		foreach ( [ 'SDUTestSource', 'SDUTestDerived' ] as $property ) {
			$this->editPage(
				Title::newFromText( $property, SMW_NS_PROPERTY ),
				'{{#set:Has type=Text}}'
			);
		}

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
			\CommentStoreComment::newUnsavedComment( 'null revision' )
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

		// SDURevIdNullEditPage points at another page, not at itself, so this
		// must never mark it as self-update-pending - only a genuine
		// self-reference (Semantic Dependency={{FULLPAGENAME}}) should.
		$this->assertSelfUpdatePendingAttempt(
			0,
			$title,
			'A page whose Semantic Dependency points at a different page must ' .
			'never be marked as self-update-pending.'
		);

		$this->forceNullRevision( $title );

		$this->assertSame(
			0,
			$this->pushedUpdateJobCount(),
			'A null revision that only changes the SESP ___REVID property (via a ' .
			'new MediaWiki revision) and leaves all other semantic data untouched ' .
			'must not cause SDU to push a forced UpdateJob.'
		);

		// Regression check for the bug this test previously missed: an
		// ignored-property-only diff must not falsely mark an ordinary page
		// (no self-reference at all) as self-update-pending either - see
		// Hooks::onAfterDataUpdateComplete()'s $triggerSemanticDependencies
		// guard around markSelfUpdatePending().
		$this->assertSelfUpdatePendingAttempt(
			0,
			$title,
			'A null revision touching only an ignored property must not mark ' .
			'an ordinary (non-self-referencing) page as self-update-pending.'
		);
	}

	/**
	 * Reproduces the interaction between $wgSDUIgnoredProperties and the
	 * "Update Self" self-update-pending marker (see
	 * SelfReferenceIntegrationTest::testSelfUpdateRetriesUntilStoreCatchesUp()):
	 * a page with a pending self-update marker (waiting for a store-timing gap
	 * to close) must not have that marker wiped by an unrelated null revision
	 * that only touches an ignored property such as ___REVID. Before the fix,
	 * onAfterDataUpdateComplete() cleared the marker whenever the diff table
	 * was merely non-empty, regardless of whether the only changes were
	 * ignored ones - losing the pending retry context for the real "Update
	 * Self" cycle.
	 *
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testNullRevisionWithOnlyIgnoredPropertyChangeDoesNotClearPendingSelfUpdate() {
		$title = Title::newFromText( 'SDURevIdSelfUpdatePendingPage', NS_MAIN );

		// A genuine self-reference (Semantic Dependency={{FULLPAGENAME}}, the
		// documented "Update Self" pattern - see SelfReferenceIntegrationTest)
		// is required here: only that makes onAfterDataUpdateComplete()
		// legitimately mark this page as self-update-pending. SDUTestDerived
		// resolves from a page that doesn't exist yet, so the first
		// self-UpdateJob's re-parse legitimately finds nothing.
		$wikitext = '{{#set:SDUTestDerived={{#show:SDURevIdSelfUpdateDependency|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );

		$this->assertSelfUpdatePendingAttempt(
			1,
			$title,
			'The genuine self-reference must mark the page as self-update-pending.'
		);

		// Drain jobs until the initial self-UpdateJob itself has run (queue
		// ordering relative to MediaWiki's own housekeeping jobs, e.g.
		// htmlCacheUpdate, is not guaranteed). Its re-parse finds the
		// dependency page missing and advances the page's
		// self-update-pending marker via the retry mechanism rather than
		// giving up - but that retry job itself must not run yet, so this
		// stops right after the first smw.update job rather than draining
		// the whole queue.
		$this->runJobsUntilOneUpdateJobRan();

		$this->assertSelfUpdatePendingAttempt(
			2,
			$title,
			'The re-parse finding the dependency still missing must advance ' .
			'the marker to attempt 2 via the retry mechanism.'
		);

		// An unrelated null revision now lands on the same page, changing only
		// the ignored ___REVID property, BEFORE the attempt-2 retry job
		// (already queued above, with a delay this test environment doesn't
		// actually support) gets a chance to run. This must not wipe the
		// pending self-update marker set above, and per
		// $wgSDUIgnoredProperties' documented purpose must not itself cause
		// SDU to do anything for this page.
		$this->forceNullRevision( $title );

		// This is the core regression check: before the fix, any non-empty
		// diff table unconditionally cleared the marker via
		// clearSelfUpdatePending(), even when - as here - the only change was
		// an ignored property completely unrelated to the pending "Update
		// Self" cycle. A related bug also let this same ignored-only change
		// advance an already-pending marker's attempt count via a spurious
		// synchronous re-parse. This uses the "at least" variant, not an
		// exact match: in this test environment, when run after another test
		// in the same class, SESP's ___REVID annotation is occasionally not
		// part of the diff at all (only the filtered-out _MDAT table is),
		// which lands in the ordinary "empty diff" branch instead and
		// legitimately advances the marker further per that branch's own
		// documented inability to distinguish an unrelated empty re-parse
		// from the page's own pending retry. What this regression test
		// actually guards against is the marker being wiped to 0, not the
		// exact count.
		$this->assertSelfUpdatePendingAttemptAtLeast(
			2,
			$title,
			'A null revision that only changes an ignored property must not ' .
			'clear a pending "Update Self" retry marker for an unrelated reason.'
		);

		// The retry originally queued by the initial self-UpdateJob (attempt
		// 2, asserted above) must still fire on its own schedule - draining
		// the queue now must push (at least) one more smw.update job for
		// this page, proving the self-update-pending marker survived the
		// intervening ignored-only null revision without being consumed by
		// it.
		$this->assertGreaterThan(
			0,
			$this->pushedUpdateJobCount(),
			'The pre-existing self-update retry must still fire after an ' .
			'intervening ignored-only null revision.'
		);
	}
}
