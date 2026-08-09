<?php

namespace SDU\Tests\Integration;

use MediaWiki\Title\Title;

/**
 * Regression test for a crash in getIgnoredPropertyIds() when
 * $wgSDUIgnoredProperties' default value, `___REVID`, does not resolve to a
 * predefined property - i.e. exactly the "SemanticExtraSpecialProperties is
 * not installed" case extension.json's config description calls "harmless".
 *
 * DIProperty::newFromUserLabel() falls through to `new DIProperty( $label )`
 * for any label it cannot otherwise resolve. That constructor throws
 * SMW\Exception\PredefinedPropertyLabelMismatchException for any "_"-prefixed
 * key that is not registered as a predefined property, instead of returning
 * a value getIgnoredPropertyIds() could treat as "not found" - unlike an
 * ordinary (non-underscore-prefixed) unknown label, which resolves to a
 * regular property with no matching object ID (getSMWPropertyID() then
 * simply returns 0). Without SESP registering `___REVID` as a predefined
 * property, this exception was propagating out of getIgnoredPropertyIds()
 * uncaught, crashing onAfterDataUpdateComplete() and silently breaking SDU
 * entirely (not just the ignored-properties feature) on every installation
 * that keeps the default value without SESP installed.
 *
 * This test is skipped when SESP IS installed - see
 * RevisionIdPropertyIntegrationTest for that side of the matrix.
 *
 * @group SemanticDependencyUpdater
 * @group Database
 */
class IgnoredPropertyNotInstalledIntegrationTest extends SduIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( class_exists( '\SESP\PropertyAnnotators\RevisionIDPropertyAnnotator' ) ) {
			$this->markTestSkipped( 'SemanticExtraSpecialProperties is installed in this environment.' );
		}

		global $wgSDUIgnoredProperties;

		$wgSDUIgnoredProperties = [ '___REVID' ];
	}

	/**
	 * @covers \SDU\Hooks::onAfterDataUpdateComplete
	 */
	public function testEditDoesNotCrashAndStillTriggersDependencyUpdate() {
		$this->editPage(
			Title::newFromText( 'SDUIgnoredPropertyNotInstalledDependantPage', NS_MAIN ),
			'unrelated page'
		);

		$this->editPage(
			Title::newFromText( 'SDUIgnoredPropertyNotInstalledEditPage', NS_MAIN ),
			'{{#set:Semantic Dependency=SDUIgnoredPropertyNotInstalledDependantPage}}'
		);

		$status = $this->getServiceContainer()->getJobRunner()->run( [] );

		$this->assertGreaterThan(
			0,
			count( array_filter(
				$status['jobs'],
				static fn ( $job ) => $job['type'] === 'smw.update'
			) ),
			'The default $wgSDUIgnoredProperties value ("___REVID") must not ' .
			'crash onAfterDataUpdateComplete() or suppress dependency updates ' .
			'when SemanticExtraSpecialProperties is not installed.'
		);
	}

}
