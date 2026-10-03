<?php
// php maintenance/run.php /tmp/sdu-perf/hook-micro.php  -> ms per onOutputPageParserOutput call
use MediaWiki\Context\RequestContext;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
class SduHookMicro extends Maintenance {
	public function execute() {
		$title = Title::newFromText( 'PerfPlain' );
		$title->getLatestRevID();
		$ctx = new RequestContext();
		$ctx->setTitle( $title );
		$out = new OutputPage( $ctx );
		$po = new ParserOutput();
		SDU\Hooks::onOutputPageParserOutput( $out, $po ); // warm-up
		$n = 2000;
		$t = hrtime( true );
		for ( $i = 0; $i < $n; $i++ ) {
			SDU\Hooks::onOutputPageParserOutput( $out, $po );
		}
		printf( "hook_us_per_call\t%.1f\n", ( hrtime( true ) - $t ) / 1000 / $n );
	}
}
$maintClass = SduHookMicro::class;
require_once RUN_MAINTENANCE_IF_MAIN;
