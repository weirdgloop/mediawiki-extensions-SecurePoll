<?php

/**
 * Dump all votes from an election from a dump file or local database.
 *
 * For the purposes of the Personal Image Filter referendum, this script
 * dumps the answers to all questions in key order.
 *
 * Can be used to tally very large numbers of votes, when the web interface is
 * not feasible.
 */

$optionsWithArgs = [ 'name' ];
require __DIR__ . '/cli.inc';

$wgTitle = Title::newFromText( 'Special:SecurePoll' );

$usage = <<<EOT
Usage:
  php dumpVoteCsv.php [--html] --name <election name>
  php dumpVoteCsv.php [--html] <dump file>
EOT;

if ( !isset( $options['name'] ) && !isset( $args[0] ) ) {
	spFatal( $usage );
}

if ( !class_exists( 'SecurePoll_Context' ) ) {
	if ( isset( $options['name'] ) ) {
		spFatal( "Cannot load from database when SecurePoll is not installed" );
	}
	require __DIR__ . '/../SecurePoll.php';
}

$context = new SecurePoll_Context;
if ( !isset( $options['name'] ) ) {
	$context = SecurePoll_Context::newFromXmlFile( $args[0] );
	if ( !$context ) {
		spFatal( "Unable to parse XML file \"{$args[0]}\"" );
	}
	$electionIds = $context->getStore()->getAllElectionIds();
	if ( !count( $electionIds ) ) {
		spFatal( "No elections found in XML file \"{$args[0]}\"" );
	}
	$election = $context->getElection( reset( $electionIds ) );
} else {
	$election = $context->getElectionByTitle( $options['name'] );
	if ( !$election ) {
		spFatal( "The specified election does not exist." );
	}
}

$tallier = new SecurePoll_CommentDumper( $context, $election, false );
$status = $tallier->execute();
if ( !$status->isOK() ) {
	spFatal( "Tally error: " . $status->getWikiText() );
}
// $tallier = $status->value;
if ( isset( $options['html'] ) ) {
	echo $tallier->getHtmlResult();
} else {
	echo $tallier->getTextResult();
}

function spFatal( $message ) {
	fwrite( STDERR, rtrim( $message ) . "\n" );
	exit( 1 );
}
