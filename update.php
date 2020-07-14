<?php

require_once 'includes/WebMgmt.php';

if ( isMgClient() ) {
	header( 'Content-Type: text/plain' );
	include 'maintenance/update.php';
}
