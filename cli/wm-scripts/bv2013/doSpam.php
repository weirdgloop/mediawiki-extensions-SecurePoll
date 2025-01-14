<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../../../..';
}
require_once "$IP/maintenance/commandLine.inc";

ini_set( 'display_errors', 1 );

$err = fopen( 'php://stderr', 'a' );

/**
 * A list of usernames that don't want email about elections
 * e.g. copied from https://meta.wikimedia.org/wiki/Wikimedia_nomail_list
 * @var array
 */
$nomail = file( '/a/common/elections-2013-spam/nomail-list-stripped' );
$nomail = array_map( 'trim', $nomail );

/**
 * Name of the list of allowed voters
 * @var string
 */
$list_name = 'board-vote-2013';

/**
 * ID number of the election
 * @var int
 */
$election_id = 290;

$voted = [];
$vdb = wfGetDB( DB_REPLICA, [], 'votewiki' );
$res = $vdb->select(
	[ 'securepoll_votes', 'securepoll_voters' ],
	[ 'voter_name', 'voter_properties' ],
	[ 'voter_id=vote_voter', 'vote_election' => $election_id ]
);
foreach ( $res as $row ) {
	$row->voter_properties = unserialize( $row->voter_properties );
	$voted[$row->voter_properties['wiki']][$row->voter_name] = 1;
}

$wikis = CentralAuthServices::getWikiListService()->getWikiList();
# $wikis = array( 'frwiki', 'dewiki', 'commonswiki', 'usabilitywiki' );
$wgConf->loadFullData();

$users = [];

$specialWikis = array_map( 'trim', file( '/a/common/special.dblist' ) );

fwrite( $err, "Loading data from database (pass 1)\n" );
foreach ( $wikis as $w ) {
	fwrite( $err, "$w...\n" );
	list( $site, $siteLang ) = $wgConf->siteFromDB( $w );
	$tags = [];
	$pendingChecks = [];
	$doneChecks = [];

	if ( in_array( $w, $specialWikis ) ) {
		$tags[] = 'special';
	}

	$defaultLang = $wgConf->get( 'wgLanguageCode', $w, null, [ 'lang' => $siteLang ], $tags );

	$db = wfGetDB( DB_REPLICA, null, $w );

	try {
		$res = $db->select(
			[ 'securepoll_lists', 'user', 'user_properties' ],
			'*',
			[ 'li_name' => $list_name ],
			__METHOD__,
			[],
			[
				'user' => [ 'left join', 'user_id=li_member' ],
				'user_properties' => [ 'left join',
					[ 'up_user=li_member', 'up_property' => 'language' ]
				]
			]
		);

		foreach ( $res as $row ) {
			$lang = $row->up_value;
			if ( !$lang ) {
				$lang = $defaultLang;
			}
			$mail = $row->user_email;
			$name = $row->user_name;

			if ( !isset( $users[$name] ) ) {
				$users[$name] = [];
			}
			$users[$name][$w] = [ 'name' => $name, 'mail' => $mail, 'lang' => $lang,
						'editcount' => $row->user_editcount, 'project' => $site,
						'db' => $w, 'id' => $row->user_id, 'ineligible' => false,
						'voted' => isset( $voted[$w][$name] )
			];

			if ( !isset( $doneChecks[$row->user_id] ) ) {
				$pendingChecks[$row->user_id] = $row->user_name;
				if ( count( $pendingChecks ) > 100 ) {
					runChecks( $w, $pendingChecks );
					$doneChecks += $pendingChecks;
					$pendingChecks = [];
				}
			}
		}

		if ( count( $pendingChecks ) > 0 ) {
			runChecks( $w, $pendingChecks );
		}
	} catch ( MWException $excep ) {
		fwrite( $err, "Error in query: " . $excep->getMessage() . "\n" );
	}
}

fwrite( $err, "Pass 2: Checking for users listed twice.\n" );
$notifyUsers = [];
foreach ( $users as $name => $info ) {
	if ( in_array( $name, $nomail ) ) {
		fwrite( $err, "Name $name is on the nomail list, ignoring\n" );
		continue;
	}

	// Grab the best language by looking at the wiki with the most edits.
	$bestEditCount = -1;
	$bestSite = null;
	$mail = null;
	foreach ( $info as $site => $wiki ) {
		if ( $wiki['voted'] ) {
			fwrite( $err, "Name $name already voted from $site, ignoring\n" );
			continue 2;
		}

		if ( $wiki['ineligible'] ) {
			fwrite( $err, "Name $name is not eligible ($wiki[ineligible] on $site), ignoring\n" );
			continue 2;
		}

		if ( $bestEditCount < $wiki['editcount'] ) {
			$bestEditCount = $wiki['editcount'];
			$bestSite = $site;

			if ( $wiki['mail'] ) {
				$mail = $wiki['mail'];
			}
		}

		if ( !$mail && $wiki['mail'] ) {
			$mail = $wiki['mail'];
		}
	}

	if ( !$mail ) {
		continue;
	}

	$bestWiki = $info[$bestSite];
	$lang = $bestWiki['lang'];
	$project = $bestWiki['project'];

	if ( isset( $notifyUsers[$mail] ) ) {
		$name2 = $notifyUsers[$mail]['name'];
		if ( $notifyUsers[$mail]['editcount'] >= $bestEditCount ) {
			fwrite( $err, "Ignoring user $name in favor of user $name2 with the same address\n" );
			continue;
		} else {
			fwrite( $err, "Ignoring user $name2 in favor of user $name with the same address\n" );
		}
	}

	$notifyUsers[$mail] = [
		'name' => $name,
		'editcount' => $bestEditCount,
		'row' => "$mail\t$lang\t$project\t$name\n",
	];
}

/**
 * @suppress SecurityCheck-XSS
 * @param string $val
 */
function out( $val ) {
	echo $val;
}

fwrite( $err, "Pass 3: Outputting user data.\n" );
foreach ( $notifyUsers as $info ) {
	out( $info['row'] );
}

fwrite( $err, "Done.\n" );

/**
 * Checks for ineligibility due to blocks or groups
 *
 * @param string $wiki
 * @param int[] $usersToCheck User ID
 */
function runChecks( $wiki, $usersToCheck ) {
	global $users;
	$dbr = wfGetDB( DB_REPLICA, null, $wiki );

	$res = $dbr->select( 'ipblocks', 'ipb_user',
		[
			'ipb_user' => array_keys( $usersToCheck ),
			'ipb_expiry > ' . $dbr->addQuotes( $dbr->timestamp( wfTimestampNow() ) )
		],
		__METHOD__
	);

	foreach ( $res as $row ) {
		$userName = $usersToCheck[$row->ipb_user];
		if ( isset( $users[$userName][$wiki] ) ) {
			$users[$userName][$wiki]['ineligible'] = 'blocked';
		}
	}

	$res = $dbr->select( 'user_groups', 'ug_user',
		[ 'ug_user' => array_keys( $usersToCheck ), 'ug_group' => 'bot' ],
		__METHOD__
	);

	foreach ( $res as $row ) {
		$userName = $usersToCheck[$row->ug_user];
		if ( isset( $users[$userName][$wiki] ) ) {
			$users[$userName][$wiki]['ineligible'] = 'bot';
		}
	}
}
