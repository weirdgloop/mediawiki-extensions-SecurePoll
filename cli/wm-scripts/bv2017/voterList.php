<?php

require __DIR__ . '/../../cli.inc';
$dbcr = CentralAuthUser::getCentralSlaveDB();
$dbcw = CentralAuthUser::getCentralDB();

$fname = 'voterList.php';
$listName = 'board-vote-2017';

$dbcw->delete( 'securepoll_lists', [ 'li_name' => $listName ], $fname );

$totalUsers = $dbcr->selectField( 'globaluser', 'MAX(gu_id)', false, $fname );

$userName = '';
$numUsers = 0;
$numQualified = 0;
while ( true ) {
	$res = $dbcr->select( 'globaluser',
		[ 'gu_id', 'gu_name' ],
		[ 'gu_name > ' . $dbcr->addQuotes( $userName ) ],
		$fname,
		[ 'LIMIT' => 1000, 'ORDER BY' => 'gu_name' ] );
	if ( !$res->numRows() ) {
		break;
	}

	$users = [];
	foreach ( $res as $row ) {
		$users[$row->gu_id] = $row->gu_name;
		$userName = $row->gu_name;
		$numUsers++;
	}

	$qualifieds = spGetQualifiedUsers( $users );
	$insertBatch = [];
	foreach ( $qualifieds as $id => $name ) {
		$insertBatch[] = [
			'li_name' => $listName,
			'li_member' => $id
		];
	}
	if ( $insertBatch ) {
		$dbcw->insert( 'securepoll_lists', $insertBatch, $fname );
		$numQualified += count( $insertBatch );
	}
	spReportProgress( $numUsers, $totalUsers );
}
echo wfWikiID() . " qualified \t$numQualified\n";

/**
 * @param $users array
 * @return array
 */
function spGetQualifiedUsers( $users ) {
	global $wgLocalDatabases;
	$dbcr = CentralAuthUser::getCentralSlaveDB();

	$res = $dbcr->select( 'localuser',
		[ 'lu_name', 'lu_wiki' ],
		[ 'lu_name' => $users ],
		__METHOD__ );

	$editCounts = [];
	$foreignUsers = [];
	foreach ( $res as $row ) {
		$foreignUsers[$row->lu_wiki][] = $row->lu_name;
		$editCounts[$row->lu_name] = [ 0, 0 ];
	}

	foreach ( $foreignUsers as $wiki => $wikiUsers ) {
		if ( !in_array( $wiki, $wgLocalDatabases ) ) {
			continue;
		}
		$lb = wfGetLB( $wiki );
		$db = $lb->getConnection( DB_SLAVE, [], $wiki );
		$foreignEditCounts = spGetEditCounts( $db, $wikiUsers );
		$lb->reuseConnection( $db );
		foreach ( $foreignEditCounts as $name => $count ) {
			$editCounts[$name][0] += $count[0];
			$editCounts[$name][1] += $count[1];
		}
	}

	$idsByUser = array_flip( $users );
	$qualifiedUsers = [];
	foreach ( $editCounts as $user => $count ) {
		if ( spIsQualified( $count[0], $count[1] ) ) {
			$id = $idsByUser[$user];
			$qualifiedUsers[$id] = $user;
		}
	}

	return $qualifiedUsers;
}

/**
 * @param $db DatabaseBase
 * @param $userNames
 * @return array
 */
function spGetEditCounts( $db, $userNames ) {
	$res = $db->select(
		[ 'user', 'bv2017_edits' ],
		[ 'user_name', 'bv_long_edits', 'bv_short_edits' ],
		[ 'bv_user=user_id', 'user_name' => $userNames ],
		__METHOD__
	);
	$editCounts = [];
	foreach ( $res as $row ) {
		$editCounts[$row->user_name] = [ $row->bv_short_edits, $row->bv_long_edits ];
	}
	foreach ( $userNames as $user ) {
		if ( !isset( $editCounts[$user] ) ) {
			$editCounts[$user] = [ 0, 0 ];
		}
	}
	return $editCounts;
}

/**
 * Returns whether a user "is qualified" to vote based on edit count
 *
 * @param $short
 * @param $long
 * @return bool
 */
function spIsQualified( $short, $long ) {
	return $short >= 20 && $long >= 300;
}

/**
 * Report progress
 */
function spReportProgress( $current, $total ) {
	static $lastReportTime, $startTime;

	$now = time();
	if ( !$startTime ) {
		$startTime = $now;
	}
	if ( $now - $lastReportTime < 10 ) {
		return;
	}
	$lastReportTime = $now;
	$lang = Language::factory( 'en' );
	$estTotalDuration = ( $now - $startTime ) * $total / $current;
	$estRemaining = $estTotalDuration - ( $now - $startTime );

	print $lang->commafy( $current ) . " of " .
		$lang->commafy( $total ) . " ; " .
		number_format( $current / $total * 100, 2 ) .  '% ; estimated time remaining: ' .
		$lang->formatDuration( $estRemaining ) .
		"\n";
}