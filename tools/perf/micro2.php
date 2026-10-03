<?php
// php maintenance/run.php /tmp/sdu-perf/micro2.php : per-call cost of small SDU code paths (CLI, no side effects)
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\SemanticData;
require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
class SduMicro2 extends Maintenance {
	private function q(): int {
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		return (int)$db->query( "SHOW SESSION STATUS LIKE 'Questions'", __METHOD__ )->fetchRow()['Value'];
	}
	private function bench( string $name, int $n, callable $f ): void {
		$f(); // warm-up
		$q0 = $this->q(); $t = hrtime( true );
		for ( $i = 0; $i < $n; $i++ ) { $f(); }
		$us = ( hrtime( true ) - $t ) / 1000 / $n;
		$qs = ( $this->q() - $q0 - 1 ) / $n;
		printf( "%s_us_per_call\t%.1f\n%s_queries_per_call\t%.2f\n", $name, $us, $name, $qs );
	}
	public function execute() {
		global $wgSDUProperty;
		$store = smwfGetStore();
		// M1: hook on a page WITHOUT the SDU property (what every ordinary save/refresh in the wiki pays)
		$sd = new SemanticData( DIWikiPage::newFromTitle( Title::newFromText( 'PerfPlain' ) ) );
		foreach ( [ 'A', 'B', 'C', 'D', 'E' ] as $p ) {
			$sd->addPropertyObjectValue( new DIProperty( "Prop$p" ), new SMWDIBlob( "v$p" ) );
		}
		$this->bench( 'm1_hook_nonSDU_page', 20000, static function () use ( $store, $sd ) {
			SDU\Hooks::onAfterDataUpdateComplete( $store, $sd, null );
		} );
		// M2: ignored-property ID resolution (once per SDU-page save with a diff)
		$rm = new ReflectionMethod( SDU\Hooks::class, 'getIgnoredPropertyIds' );
		$rm->setAccessible( true );
		$this->bench( 'm2_ignoredPropertyIds', 2000, static function () use ( $rm, $store ) {
			$rm->invoke( null, $store );
		} );
		// M3: delete hook data load: full SemanticData vs only the SDU property
		$subj = DIWikiPage::newFromTitle( Title::newFromText( 'PerfSite' ) );
		$this->bench( 'm3_delete_full_getSemanticData', 300, static function () use ( $store, $subj ) {
			\SMW\SQLStore\EntityStore\CachingSemanticDataLookup::clear();
			$store->getSemanticData( $subj );
		} );
		$prop = new DIProperty( str_replace( ' ', '_', $wgSDUProperty ) );
		$this->bench( 'm3_delete_only_sdu_property', 300, static function () use ( $store, $subj, $prop ) {
			\SMW\SQLStore\EntityStore\CachingSemanticDataLookup::clear();
			$store->getPropertyValues( $subj, $prop );
		} );
	}
}
$maintClass = SduMicro2::class;
require_once RUN_MAINTENANCE_IF_MAIN;
