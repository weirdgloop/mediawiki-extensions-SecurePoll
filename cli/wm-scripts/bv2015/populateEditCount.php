<?php

/**
 * have made at least 300 edits before 15 April 2015 across Wikimedia wikis
 * (edits on several wikis can be combined if your accounts are unified into a global account); and
 * have made at least 20 edits between 15 October 2014 and 15 April 2015.
 */

require __DIR__ . '/../../cli.inc';

$dbr = wfGetDB( DB_REPLICA );
$dbw = wfGetDB( DB_MASTER );

$maxUser = $dbr->selectField( 'user', 'MAX(user_id)', false );
$beforeTime = $dbr->addQuotes( '20150415000000' );
$betweenTime = [
	$dbr->addQuotes( '20141015000000' ),
	$dbr->addQuotes( '20150415235959' )
];
$fname = 'populateEditCount';

$numUsers = 0;

for ( $userId = 1; $userId <= $maxUser; $userId++ ) {
	$user = User::newFromId( $userId );
	if ( $user->isAnon() ) {
		continue;
	}

	$longEdits = 0;
	$shortEdits = 0;

	$revWhere = ActorMigration::newMigration()
		->getWhere( $dbr, 'rev_user', $user );

	foreach ( $revWhere['orconds'] as $key => $cond ) {
		$tsField = $key === 'actor' ? 'revactor_timestamp' : 'rev_timestamp';

		$longEdits += $dbr->selectField(
			[ 'revision' ] + $revWhere['tables'],
			'COUNT(*)',
			[
				$cond,
				$tsField . ' < ' . $beforeTime
			],
			$fname,
			[],
			$revWhere['joins']
		);

		$shortEdits += (int)$dbr->selectField(
			[ 'revision' ] + $revWhere['tables'],
			'COUNT(*)',
			[
				$cond,
				$tsField . ' BETWEEN ' . $betweenTime[0] . ' AND ' . $betweenTime[1]
			],
			$fname,
			[],
			$revWhere['joins']
		);
	}

	if ( $longEdits != 0 || $shortEdits != 0 ) {
		$dbw->insert( 'bv2015_edits',
			[
				'bv_user' => $userId,
				'bv_long_edits' => $longEdits,
				'bv_short_edits' => $shortEdits
			],
			$fname
		);
		$numUsers++;
	}
}

echo wfWikiID() . ": $numUsers users added\n";
