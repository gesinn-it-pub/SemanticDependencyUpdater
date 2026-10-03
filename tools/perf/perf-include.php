<?php
// Measurement-only include, required from LocalSettings.php of the TEST wiki.
// Never part of the SDU repo. Counts full page parses; optional SDU debug log.
$sduPerfDir = '/tmp/sdu-perf';
if ( !is_dir( $sduPerfDir ) ) {
	@mkdir( $sduPerfDir, 0777, true );
	@chmod( $sduPerfDir, 0777 );
}
// Jobs are driven explicitly by the harness (like production's external runner).
$wgJobRunRate = 0;

// One line per completed full parse of a Perf* page: ts, pid, title.
$wgHooks['ParserAfterTidy'][] = static function ( $parser, &$text ) use ( $sduPerfDir ) {
	$t = $parser->getPage();
	$name = $t ? $t->getDBkey() : '?';
	if ( strpos( $name, 'Perf' ) === 0 ) {
		@file_put_contents(
			"$sduPerfDir/parses.log",
			sprintf( "%.3f\t%d\t%s\t%s\t%s\n", microtime( true ), getmypid(), $name, PHP_SAPI,
				PHP_SAPI === 'cli' ? '-' : ( $_SERVER['REQUEST_METHOD'] ?? '' ) . ' ' . ( $_SERVER['REQUEST_URI'] ?? '' ) ),
			FILE_APPEND | LOCK_EX
		);
	}
	return true;
};

// SDU debug log only when explicitly requested (it changes SDU's own cost).
if ( file_exists( "$sduPerfDir/debug-on" ) ) {
	$wgDebugLogGroups['SemanticDependencyUpdater'] = "$sduPerfDir/sdu_debug.log";
}

// One line per WEB request: ts, ms, method, kind, parsedPerfPage(0/1). "kind" = view | edit | api:<action> | other.
if ( PHP_SAPI !== 'cli' ) {
	$GLOBALS['sduPerfStart'] = microtime( true );
	$GLOBALS['sduPerfParsed'] = 0;
	$wgHooks['ParserAfterTidy'][] = static function () { $GLOBALS['sduPerfParsed'] = 1; return true; };
	register_shutdown_function( static function () use ( $sduPerfDir ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		$q = [];
		parse_str( (string)parse_url( $uri, PHP_URL_QUERY ), $q );
		$action = $q['action'] ?? ( $_POST['action'] ?? '' );
		if ( strpos( $uri, '/api.php' ) !== false ) {
			$kind = 'api:' . $action;
		} elseif ( strpos( $uri, '/load.php' ) !== false ) {
			$kind = 'load';
		} elseif ( $action === 'edit' || $action === 'submit' ) {
			$kind = 'edit';
		} elseif ( strpos( $uri, 'PerfSite' ) !== false || strpos( $uri, 'PerfPlain' ) !== false ) {
			$kind = 'view';
		} else {
			$kind = 'other';
		}
		@file_put_contents( "$sduPerfDir/requests.log", sprintf( "%.3f\t%.0f\t%s\t%s\t%d\n",
			microtime( true ), ( microtime( true ) - $GLOBALS['sduPerfStart'] ) * 1000,
			$_SERVER['REQUEST_METHOD'] ?? '', $kind, $GLOBALS['sduPerfParsed'] ), FILE_APPEND | LOCK_EX );
	} );
}
