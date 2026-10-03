<?php
// Usage: php maintenance/run.php /tmp/sdu-perf/fixture.php [childCount]
// Creates property pages, a plain page, K children and the self-referencing PerfSite.
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\User;

require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';

class SduPerfFixture extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addArg( 'children', 'number of remote dependents', false );
	}
	private function put( string $title, string $text ): void {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( Title::newFromText( $title ) );
		$updater = $page->newPageUpdater( User::newSystemUser( 'Maintenance script', [ 'steal' => true ] ) );
		$updater->setContent( SlotRecord::MAIN, new WikitextContent( $text ) );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'perf fixture' ) );
	}
	public function execute() {
		$k = (int)$this->getArg( 0, 35 );
		foreach ( [ 'Semantic Dependency', 'Perf Status', 'Perf Derived', 'Perf Value' ] as $p ) {
			$this->put( "Property:$p", '[[Has type::Text]]' );
		}
		$this->put( 'Property:Perf Part of', '[[Has type::Page]]' );
		$this->put( 'PerfPlain', "Plain page without SDU.\n\n== Section ==\nLorem ipsum." );
		for ( $i = 1; $i <= $k; $i++ ) {
			$n = sprintf( 'PerfChild%02d', $i );
			$this->put( $n, '{{#set:Perf Part of=PerfSite|Perf Value={{#show:PerfSite|?Perf Derived}}}}' );
		}
		$this->put( 'PerfSite',
			"{{#set:Perf Status=initial}}{{#set:Perf Derived={{#show:PerfSite|?Perf Status}}}}"
			. "{{#set:Semantic Dependency=PerfSite}}{{#set:Semantic Dependency=Perf Part of::PerfSite}}\n"
			. "{{#ask:[[Perf Part of::PerfSite]]|?Perf Value|limit=50}}\n" );
		$this->output( "fixture ready: $k children\n" );
	}
}
$maintClass = SduPerfFixture::class;
require_once RUN_MAINTENANCE_IF_MAIN;
