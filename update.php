<?php

require_once 'includes/WebMgmt.php';

if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || isMgClient() ) {
	header( 'Content-Type: text/plain' );

	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

	include 'maintenance/update.php';
}
