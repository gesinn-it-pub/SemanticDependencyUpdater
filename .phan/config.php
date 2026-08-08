<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['baseline_path'] = __DIR__ . '/baseline.php';

// Analyse extension source code; vendor + node_modules are excluded by default
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'src',
	]
);

$IP = getenv( 'MW_INSTALL_PATH' ) !== false
	? str_replace( '\\', '/', getenv( 'MW_INSTALL_PATH' ) )
	: '../..';

$dependencyExtensions = [
	'SemanticMediaWiki',
];

foreach ( $dependencyExtensions as $ext ) {
	$cfg['directory_list'][] = $IP . '/extensions/' . $ext;
	$cfg['exclude_analysis_directory_list'][] = $IP . '/extensions/' . $ext;
}

return $cfg;
