<?php

require_once '/srv/mediawiki/multiversion/MWVersion.php';
require_once getMediaWiki( 'maintenance/commandLine.inc', 'enwiki' );

$wgConf->loadFullData();

/**
 * A list of usernames that don't want email about elections
 * e.g. copied from https://meta.wikimedia.org/wiki/Wikimedia_nomail_list
 * @var array
 */
$nomail = array();
$raw = file_get_contents( 'https://meta.wikimedia.org/wiki/Wikimedia_Foundation_nomail_list?action=raw' );
if ( preg_match( '/(?<=<pre>).*(?=<\/pre>)/ms', $raw, $matches ) ) {
	$nomail = array_filter( array_map( 'trim', explode( "\n", $matches[0] ) ) );
}

/**
 * Name of the list of allowed voters
 * @var string
 */
$listName = 'board-vote-2015a';

/**
 * ID number of the election
 * @var int
 */
$electionId = 512;

$specialWikis = MWWikiversions::readDbListFile( '/srv/mediawiki/special.dblist' );

function getDefaultLang( $db ) {
	global $wgConf, $specialWikis;
	static $langs = array();

	if ( empty( $langs[$db] ) ) {
		list( $site, $siteLang ) = $wgConf->siteFromDB( $db );
		$tags = array();
		if ( in_array( $db, $specialWikis ) ) {
			$tags[] = 'special';
		}
		$langs[$db] = RequestContext::sanitizeLangCode(
			$wgConf->get( 'wgLanguageCode', $db, null, array( 'lang' => $siteLang ), $tags ) );
	}

	return $langs[$db];
}

function getLanguage( $userId, $wikiId ) {
	$db = CentralAuthUser::getLocalDB( $wikiId );
	$lang = false;
	try {
		$lang = RequestContext::sanitizeLangCode(
			$db->selectField( 'user_properties', 'up_value',
			array( 'up_user' => $userId, 'up_property' => 'language' ) ) );
	} catch ( Exception $e ) {
		// echo 'Caught exception: ' .  $e->getMessage() . "\n";
	}
	if ( !$lang ) {
		$lang = getDefaultLang( $wikiId );
	}
	return $lang;
}



$voted = array();
$vdb = wfGetDB( DB_SLAVE, array(), 'votewiki' );
$voted = $vdb->selectFieldValues( 'securepoll_voters', 'voter_name',
	array( 'voter_election' => $electionId ) );


$db = CentralAuthUser::getCentralSlaveDB();
$res = $db->select(
	array( 'securepoll_lists', 'globaluser' ),
	array(
		'gu_id',
		'gu_name',
		'gu_email',
		'gu_home_db',
	),
	array(
		'gu_id=li_member',
		'li_name' => $listName,
		'gu_email_authenticated is not null',
		'gu_email is not null',
	)
);

$users = array();
foreach ( $res as $row ) {
	if ( !$row->gu_email ) {
		continue;
	}
	if ( in_array( $row->gu_email, $nomail ) ) {
		// echo "Skipping {$row->gu_email}; in nomail list.\n";
		continue;
	}
	if ( in_array( $row->gu_name, $voted ) ) {
		// echo "Skipping {$row->gu_name}; already voted.\n";
		continue;
	} else {
		$users[] = array(
			'id'      => $row->gu_id,
			'mail'    => $row->gu_email,
			'name'    => $row->gu_name,
			'project' => $row->gu_home_db,
		);
	}
}

foreach ( $users as $user ) {
	if ( empty( $user['project'] ) ) {
		$caUser = new CentralAuthUser( $user['name'] );
		$user['project'] = $caUser->getHomeWiki();
	}
	$user['lang'] = getLanguage( $user['id'], $user['project'] );
	echo "{$user['mail']}\t{$user['lang']}\t{$user['project']}\t{$user['name']}\n";
}
