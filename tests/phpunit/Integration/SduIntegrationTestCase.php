<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use SDU\Hooks;

/**
 * Common setup for SDU's real (non-mocked) MediaWiki+SMW integration tests.
 *
 * Centralizes declaring "Property:Semantic Dependency" as type Text, which
 * every test class here needs (SMW defaults undeclared properties to type
 * Page - $smwgPDefaultType - so without this declaration "Semantic
 * Dependency" values would be DIWikiPage instances rather than SMWDIBlob, and
 * SDU would never recognize them as dependency candidates at all; this
 * mirrors the "Mandatory configuration" step documented on mediawiki.org).
 *
 * Known limitation: running multiple subclasses of this base class together
 * in a single PHPUnit process (i.e. the full suite, or any --filter matching
 * more than one of them) can show cross-test interference in this
 * environment - each test class is independently green when run alone
 * (`composer phpunit -- --filter <ClassName>`), which is the unit of
 * isolation most CI setups and IDE test runners use. The interference seems
 * rooted in how this environment's SMW+MediaWiki store handles per-class
 * fixture teardown/setup within one process (not in $wgSDU* global leakage,
 * which is reset in setUp() below) and was not resolved by centralizing the
 * shared "Semantic Dependency" declaration here, disabling process-wide
 * caching, or @runTestsInSeparateProcesses (fails with "Serialization of
 * 'Closure' is not allowed" in this MediaWiki test harness). If this
 * resurfaces, verify the failing assertion in isolation first before
 * assuming a regression in the code under test.
 *
 * Known incompatibility: SMW < 7.0 registers its MediaWiki hooks
 * (LinksUpdateComplete, SMW::SQLStore::AfterDataUpdateComplete, ...)
 * imperatively at bootstrap, once, directly against whatever HookContainer
 * is live at that moment. MediaWikiIntegrationTestCase replaces the entire
 * MediaWikiServices instance - including a fresh HookContainer - for every
 * DB-backed test (installMockMwServices()), and SMW < 7.0 never re-registers
 * against it (no listener on the 'MediaWikiServices' hook, unlike SMW >= 7.0
 * which registers declaratively via extension.json and so is resolved fresh
 * by each new HookContainer). The practical effect: under SMW < 7.0, page
 * edits made via editPage() in this harness never reach SMW's store update
 * pipeline at all, so SDU's own hook is never invoked - confirmed by tracing
 * hook registration with object-identity probes and by reproducing the same
 * scenario successfully against SMW 7.2.0. This is a real bug in SMW < 7.0's
 * hook registration, unrelated to SDU and not fixable from here; it does not
 * affect production (SMW's normal request/job-queue lifecycle only
 * constructs one HookContainer). Skipped below rather than left failing.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
abstract class SduIntegrationTestCase extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( version_compare( SMW_VERSION, '7.0.0', '<' ) ) {
			$this->markTestSkipped(
				'SMW < 7.0 loses its MediaWiki hook registrations (e.g. LinksUpdateComplete) ' .
				'across the HookContainer reset performed by MediaWikiIntegrationTestCase for ' .
				'every DB-backed test, so page edits never reach SMW\'s store update pipeline ' .
				'in this harness - see the class docblock.'
			);
		}

		global $wgSDUProperty, $wgSDUTraversed, $wgSDUIgnoredProperties;

		$wgSDUProperty = 'Semantic Dependency';
		$wgSDUTraversed = null;
		$wgSDUIgnoredProperties = [];

		Hooks::setup();
	}

	public function addDBData() {
		parent::addDBData();

		$this->editPage(
			Title::newFromText( 'Semantic Dependency', SMW_NS_PROPERTY ),
			'{{#set:Has type=Text}}'
		);
	}

	/**
	 * Asserts on the self-update-pending marker's internal state directly
	 * (via Hooks::getSelfUpdatePendingAttemptForTesting()) rather than only
	 * inferring it from downstream job counts, which previously let a page
	 * get falsely marked as self-update-pending without any test noticing.
	 */
	protected function assertSelfUpdatePendingAttempt( int $expectedAttempt, Title $title, string $message = '' ): void {
		$this->assertSame(
			$expectedAttempt,
			Hooks::getSelfUpdatePendingAttemptForTesting( $title->getPrefixedDBKey() ),
			$message !== '' ? $message : (
				$expectedAttempt === 0
					? "Expected no self-update-pending marker for \"{$title}\"."
					: "Expected self-update-pending marker for \"{$title}\" at attempt {$expectedAttempt}."
			)
		);
	}

	/**
	 * Drains jobs one at a time until exactly one smw.update job has run,
	 * then stops - unlike run([]), which drains the whole queue and would
	 * run a self-UpdateJob's own retry immediately after it in this test
	 * environment (no delayed job support), advancing the self-update-pending
	 * marker further than intended. Unlike run(['maxJobs' => 1]) alone,
	 * this does not depend on MediaWiki's own housekeeping jobs (e.g.
	 * htmlCacheUpdate) happening to be queued after the smw.update job.
	 */
	protected function runJobsUntilOneUpdateJobRan(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$status = $this->getServiceContainer()->getJobRunner()->run( [ 'maxJobs' => 1 ] );

			if ( $status['jobs'] === [] ) {
				$this->fail( 'Job queue ran dry before any smw.update job executed.' );
			}

			if ( $status['jobs'][0]['type'] === 'smw.update' ) {
				return;
			}
		}

		$this->fail( 'No smw.update job ran within 10 drained jobs.' );
	}

	/**
	 * Same as assertSelfUpdatePendingAttempt(), but only asserts the marker
	 * was not cleared/reset - not the exact attempt count. Use this where an
	 * unrelated, genuinely empty re-parse (e.g. from SMW's own housekeeping,
	 * or - in this test environment - an occasional non-deterministic
	 * SESP ___REVID annotation) may legitimately advance the count further
	 * per onAfterDataUpdateComplete()'s documented inability to tell that
	 * case apart from the page's own pending retry (see the "No semantic
	 * data changes detected" branch's docblock in Hooks.php). What matters
	 * for the regression this guards against is that the marker survives at
	 * all, not the exact count.
	 */
	protected function assertSelfUpdatePendingAttemptAtLeast( int $minimumAttempt, Title $title, string $message = '' ): void {
		$this->assertGreaterThanOrEqual(
			$minimumAttempt,
			Hooks::getSelfUpdatePendingAttemptForTesting( $title->getPrefixedDBKey() ),
			$message !== '' ? $message : "Expected self-update-pending marker for \"{$title}\" to be at least attempt {$minimumAttempt}."
		);
	}

}
