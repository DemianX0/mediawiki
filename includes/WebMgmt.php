<?php

$IP = $IP ?? dirname( __DIR__ );
if ( !defined( 'MW_CONFIG_FILE' ) ) {
	define( 'MW_CONFIG_FILE', $IP . '/_appconfig.php' );
}

require_once( $IP . '/_siteconfig.php' );

function isMgClient() {
	global $wgMgClients;
	if ( $wgMgClients === 'ALL' ) {
		return true;
	}
	$client = sha1( $_SERVER['REMOTE_ADDR'] ?? '' );
	return $client === $wgMgClients || is_array( $wgMgClients ) && in_array( $client, $wgMgClients );
}
