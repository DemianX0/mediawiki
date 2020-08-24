<?php

if ( PHP_SAPI != 'cli' && PHP_SAPI != 'phpdbg' ) {
	die( "This script can only be run from the command line.\n" );
}

define( 'AUTOLOADGENERATOR', 1 );
require_once __DIR__ . '/../includes/AutoLoader.php';
require_once __DIR__ . '/../includes/utils/AutoloadGenerator.php';

// Mediawiki installation directory
$IP = dirname( __DIR__ );
genAutoload( $IP . '/includes' );
genAutoload( $IP . '/languages' );
genAutoload( $IP . '/maintenance' );
//genAutoload( $IP . '/mw-config' ); // No classes.

function genAutoload( string $base ) {
	$generator = new AutoloadGenerator( $base, 'local' );
	$generator->setPsr4Namespaces( AutoLoader::getAutoloadNamespaces() );
	$generator->readDir( $base );

	// Write out the autoload
	$fileinfo = $generator->getTargetFileinfo();
	file_put_contents(
		$fileinfo['filename'],
		$generator->getAutoload( 'maintenance/generateLocalAutoload.php' )
	);
}

