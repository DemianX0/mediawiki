<?php

require_once 'includes/WebMgmt.php';

if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || isMgClient() ) {
	header( 'Content-Type: text/plain' );
	include 'maintenance/update.php';
}
