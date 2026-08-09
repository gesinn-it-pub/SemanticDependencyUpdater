<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;
use SMW\DIWikiPage;

/**
 * Reproduces the "Update Self" scenario documented at
 * https://www.mediawiki.org/wiki/Extension:SemanticDependencyUpdater#Update_Self :
 *
 *   {{#set:Semantic Dependency={{FULLPAGENAME}}}}
 *
 * A page that sets a property (Source) and, in the same edit, derives a
 * second property (Derived) from a live store query against itself
 * (mirroring the semantic-app-* `{{Self|X}}` -> `{{#show:{{FULLPAGENAME}}|?X}}`
 * pattern) cannot see its own Source value during the first parse, because
 * SMW only writes facts to the store in AfterDataUpdateComplete, which runs
 * after the parse that produced them. The documented fix is that SDU queues
 * a forced second UpdateJob ("saves the current page twice"), which re-parses
 * the page against a store that by then contains the first pass's facts.
 *
 * Property type declarations are made in addDBData() and drained via
 * runJobs() there, so the ChangePropagationUpdateJob SMW schedules for a
 * property's own type change is fully settled before the actual test
 * scenario runs. Without this separation, that unrelated SMW-internal job
 * would also reparse the test page and mask whether SDU's own dependency
 * handling is what causes the second pass.
 *
 * If a test in this class fails only when run together with other test
 * classes (not alone via
 * `composer phpunit -- --filter SelfReferenceIntegrationTest`), see the
 * "Known limitation" note on SduIntegrationTestCase before assuming a
 * regression in Hooks.php.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class SelfReferenceIntegrationTest extends SduIntegrationTestCase {

	public function addDBData() {
		parent::addDBData();

		// SMW defaults undeclared properties to type Page ($smwgPDefaultType),
		// not Text - without this declaration, "SDUTestSource"/"SDUTestDerived"
		// would resolve as page references instead of text. This mirrors the
		// "Mandatory configuration" step documented on mediawiki.org.
		foreach ( [ 'SDUTestSource', 'SDUTestDerived' ] as $property ) {
			$this->editPage(
				Title::newFromText( $property, SMW_NS_PROPERTY ),
				'{{#set:Has type=Text}}'
			);
		}

		// Drain the ChangePropagationUpdateJob(s) SMW schedules for these
		// type declarations now, so they cannot fire during the actual test
		// and be mistaken for SDU-triggered behaviour.
		$this->getServiceContainer()->getJobRunner()->run( [] );
	}

	private function getPropertyStringValues( Title $title, string $propertyLabel ): array {
		$store = smwfGetStore();
		$subject = DIWikiPage::newFromTitle( $title );
		$semanticData = $store->getSemanticData( $subject );

		$property = \SMW\DIProperty::newFromUserLabel( $propertyLabel );
		$values = $semanticData->getPropertyValues( $property );

		return array_map( static fn ( $v ) => $v->getString(), $values );
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testSelfReferencingDependencyEventuallyResolvesDerivedValue() {
		$title = Title::newFromText( 'SDUSelfReferenceTestPage', NS_MAIN );

		// One template block sets "Source" directly; a second block derives
		// "Derived" from a live store query against the same page - this can
		// only resolve once the store has been updated by a previous pass.
		$wikitext = '{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );

		// First pass: the store did not contain "Source" yet when "Derived"
		// was evaluated, so it is empty right after the initial save.
		$this->assertSame(
			[],
			$this->getPropertyStringValues( $title, 'SDUTestDerived' ),
			'Sanity check: Derived is empty immediately after the first parse, ' .
			'confirming the store-timing gap this test is about.'
		);

		// SDU's self-referencing "Semantic Dependency" should have queued a
		// forced smw.update UpdateJob for the very same page (the "save the
		// page twice" behaviour documented on mediawiki.org), alongside
		// MediaWiki's regular post-edit housekeeping jobs.
		$status = $this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertContains(
			'smw.update',
			array_column( $status['jobs'], 'type' ),
			'SDU must queue a forced SMW UpdateJob for the self-referencing page.'
		);

		$this->assertSame(
			[ 'SourceValue' ],
			$this->getPropertyStringValues( $title, 'SDUTestDerived' ),
			'After SDU\'s self-triggered re-update runs, Derived should ' .
			'resolve to the Source value set in the same original edit.'
		);
	}

	/**
	 * Verifies the retry half of "Update Self": if the store still isn't
	 * caught up when SDU's forced self-UpdateJob runs (simulated here by a
	 * dependency page that doesn't exist yet at that point, rather than
	 * mocking store timing directly), the derived value stays unresolved
	 * after that first retry - but a second retry, once the dependency
	 * exists, succeeds. Without the self-update-pending marker gating
	 * retrySelfUpdateIfWithinTraversalLimit(), this would either never retry
	 * at all (old behaviour) or retry unboundedly/on unrelated empty diffs
	 * (the bug in an earlier version of the retry itself).
	 *
	 * Jobs are drained one at a time (maxJobs: 1): this test environment's
	 * job queue does not support delayed jobs
	 * (retrySelfUpdateIfWithinTraversalLimit() falls back to an immediate
	 * re-push here), and the marker's attempt count bounds retries at
	 * SELF_UPDATE_MAX_ATTEMPTS - draining the whole queue in one runJobs()
	 * call would burn through all attempts before this test gets a chance to
	 * create the dependency page in between.
	 *
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 * @covers \SDU\Hooks::rebuildData
	 */
	public function testSelfUpdateRetriesUntilStoreCatchesUp() {
		$title = Title::newFromText( 'SDUSelfUpdateRetryTestPage', NS_MAIN );
		$dependencyTitle = Title::newFromText( 'SDUSelfUpdateRetryDependency', NS_MAIN );

		// SDUTestDerived resolves from a page that doesn't exist yet - the
		// first self-UpdateJob's re-parse will legitimately find nothing,
		// exercising the same "empty diff" path a lagging store would.
		$wikitext = '{{#set:SDUTestDerived={{#show:SDUSelfUpdateRetryDependency|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );

		// Drain only the initial self-UpdateJob: its re-parse still finds the
		// dependency page missing, so it re-pushes a retry instead of giving
		// up, but that retry job itself must not run yet in this step.
		$this->getServiceContainer()->getJobRunner()->run( [ 'maxJobs' => 1 ] );

		$this->assertSame(
			[],
			$this->getPropertyStringValues( $title, 'SDUTestDerived' ),
			'Sanity check: still unresolved after the first attempt, since the ' .
			'dependency page does not exist yet.'
		);

		// Now the dependency becomes available before the retried job runs.
		$this->editPage(
			$dependencyTitle,
			'{{#set:SDUTestSource=RetryResolvedValue}}'
		);

		$this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertSame(
			[ 'RetryResolvedValue' ],
			$this->getPropertyStringValues( $title, 'SDUTestDerived' ),
			'Once the dependency exists, a subsequent retry must resolve ' .
			'SDUTestDerived without requiring a fresh edit to the original page.'
		);
	}

	/**
	 * Documents a real limitation rather than testing a fix: if
	 * MediaWikiServices::getMainObjectStash() resolves to a no-op store (e.g.
	 * $wgMainStash left at CACHE_NONE), the self-update-pending marker can
	 * never actually be read back - EmptyBagOStuff::set() always reports
	 * success while get() always returns false. The retry mechanism then
	 * fails silently (no retry) rather than throwing, since
	 * getSelfUpdateAttempt() reads 0 exactly as if no self-UpdateJob had
	 * ever been pushed for this page. getSelfUpdateMarkerCache() logs a
	 * warning via wfLogWarning() (implemented as trigger_error() with
	 * E_USER_WARNING) so this is at least discoverable - this test installs
	 * a temporary error handler to capture and assert on that warning,
	 * since MediaWiki's test harness otherwise turns any triggered PHP
	 * warning into a hard test failure by design (so a silent regression
	 * here can't hide as a passing test either).
	 *
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testSelfUpdateDoesNotRetryWithoutADurableCache() {
		$this->overrideConfigValue( 'MainStash', CACHE_NONE );

		$title = Title::newFromText( 'SDUSelfUpdateNoCachePage', NS_MAIN );

		// Depends on a page that doesn't exist, same as
		// testSelfUpdateRetriesUntilStoreCatchesUp() - the point here is not
		// whether it resolves, but that draining the queue doesn't throw and
		// doesn't push a retry it can never bound without a durable marker.
		$wikitext = '{{#set:SDUTestDerived={{#show:SDUSelfUpdateNoCacheDependency|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$capturedWarnings = [];
		set_error_handler( static function ( int $errno, string $errstr ) use ( &$capturedWarnings ): bool {
			if ( $errno === E_USER_WARNING ) {
				$capturedWarnings[] = $errstr;
				return true;
			}
			return false;
		} );

		try {
			$this->editPage( $title, $wikitext );

			// Drain everything the initial edit queues (the self-UpdateJob)
			// plus anything that job itself pushes, one batch at a time,
			// until the queue is empty or a small ceiling is hit - if the
			// (bugged) retry somehow queued itself unboundedly, this loop -
			// not the assertions below - is what would catch it, by never
			// seeing the queue empty.
			$totalSmwUpdateJobs = 0;
			for ( $round = 0; $round < 5; $round++ ) {
				$status = $this->getServiceContainer()->getJobRunner()->run( [] );

				$totalSmwUpdateJobs += count(
					array_filter( $status['jobs'], static fn ( $job ) => $job['type'] === 'smw.update' )
				);

				if ( $status['reached'] === 'none-ready' && $status['jobs'] === [] ) {
					break;
				}
			}
		} finally {
			restore_error_handler();
		}

		$this->assertNotEmpty(
			array_filter(
				$capturedWarnings,
				static fn ( $w ) => str_contains( $w, 'no durable cache is configured' )
			),
			'A non-durable $wgMainStash must produce a discoverable warning, ' .
			'not fail completely silently.'
		);

		$this->assertSame(
			1,
			$totalSmwUpdateJobs,
			'Without a durable cache, the marker can never be read back, so ' .
			'no retry job beyond the initial self-UpdateJob should be pushed.'
		);
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testSelfReferenceDoesNotRecurseIndefinitely() {
		global $wgSDUTraversed;

		$title = Title::newFromText( 'SDUSelfReferenceRecursionTestPage', NS_MAIN );

		$wikitext = '{{#set:SDUTestSource=SourceValue}}'
			. '{{#set:SDUTestDerived={{#show:{{FULLPAGENAME}}|?SDUTestSource}}}}'
			. '{{#set:Semantic Dependency={{FULLPAGENAME}}}}';

		$this->editPage( $title, $wikitext );

		$status = $this->getServiceContainer()->getJobRunner()->run( [] );

		$id = $title->getPrefixedDBKey();

		$this->assertArrayHasKey(
			$id,
			$wgSDUTraversed,
			'Traversal guard must still track the self-referencing page.'
		);
		$this->assertLessThanOrEqual(
			3,
			$wgSDUTraversed[$id],
			'Self-reference must not cause unbounded recursive re-updates.'
		);
		$this->assertSame( 'none-ready', $status['reached'] );
	}
}
