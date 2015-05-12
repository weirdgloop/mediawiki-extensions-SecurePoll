<?php

/**
 * have made at least 300 edits before 15 April 2015 across Wikimedia wikis
 * (edits on several wikis can be combined if your accounts are unified into a global account); and
 * have made at least 20 edits between 15 October 2014 and 15 April 2015.
 */

require( dirname(__FILE__) . '/../../cli.inc' );

$dbr = wfGetDB( DB_SLAVE );
$dbw = wfGetDB( DB_MASTER );

$maxUser = $dbr->selectField( 'user', 'MAX(user_id)', false );
$beforeTime = '20150401500000';
$betweenTime = array( '20141015000000', '20150415235959' );
$fname = 'populateEditCount';

$numUsers = 0;

for ( $userId = 1; $userId <= $maxUser; $userId++ ) {
	$exists = $dbr->selectField( 'user', '1', array( 'user_id' => $userId ) );
	if ( !$exists ) {
		continue;
	}

	$longEdits = $dbr->selectField( 'revision', 'COUNT(*)',
		array(
			'rev_user' => $userId,
			'rev_timestamp < ' . $dbr->addQuotes( $beforeTime )
		), $fname
	);

	$shortEdits = $dbr->selectField( 'revision', 'COUNT(*)',
		array(
			'rev_user' => $userId,
			'rev_timestamp BETWEEN ' . $dbr->addQuotes( $betweenTime[0] ) .
				' AND ' . $dbr->addQuotes( $betweenTime[1] )
		),
		$fname
	);

	if ( $longEdits !== 0 || $shortEdits !== 0 ) {
		$dbw->insert( 'bv2015_edits',
			array(
				'bv_user' => $userId,
				'bv_long_edits' => $longEdits,
				'bv_short_edits' => $shortEdits
			),
			$fname
		);
		$numUsers++;
	}
}

echo wfWikiID() . ": $numUsers users added\n";
