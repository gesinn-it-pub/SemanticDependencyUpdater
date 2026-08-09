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

		global $wgSDUProperty, $wgSDUTraversed, $wgSDUUseJobQueue, $wgSDUIgnoredProperties;

		$wgSDUProperty = 'Semantic Dependency';
		$wgSDUTraversed = null;
		$wgSDUUseJobQueue = true;
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

}
