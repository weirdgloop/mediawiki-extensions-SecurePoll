<?php

require __DIR__ . '/cli.inc';

$usage = <<<EOT
Import configuration files into the local SecurePoll database. Files can be
generated with dump.php.

Usage: import.php [options] <file>

Options are:
	--update-msgs       Update the internationalised text for the elections, do
                        not update configuration.

	--replace           If an election with a conflicting title exists already,
                        replace it, updating its configuration. The default is
						to exit with an error.

Note that any vote records will NOT be imported.

For the moment, the entity IDs are preserved, to allow easier implementation of
the message update feature. This means conflicting entity IDs in the local
database will generate an error. This restriction will be removed in the
future.

EOT;

# Most of the code here will eventually be refactored into the update interfaces
# of the entity and context classes, but that project can wait until we have a
# setup UI.

if ( !isset( $args[0] ) ) {
	echo $usage;
	exit( 1 );
}
if ( !file_exists( $args[0] ) ) {
	echo "The specified file \"{$args[0]}\" does not exist\n";
	exit( 1 );
}

// $options already defined in global scope!
foreach ( [ 'update-msgs', 'replace' ] as $optName ) {
	if ( !isset( $options[$optName] ) ) {
		$options[$optName] = false;
	}
}

$success = spImportDump( $args[0], $options );
exit( $success ? 0 : 1 );

/**
 * @param $fileName string
 * @param $options
 * @return bool
 */
function spImportDump( $fileName, $options ) {
	$store = new SecurePoll_XMLStore( $fileName );
	$success = $store->readFile();
	if ( !$success ) {
		echo "Error reading XML dump, possibly corrupt\n";
		return false;
	}
	$electionIds = $store->getAllElectionIds();
	if ( !count( $electionIds ) ) {
		echo "No elections found to import.\n";
		return true;
	}

	$xc = new SecurePoll_Context;
	$xc->setStore( $store );
	$dbw = wfGetDB( DB_MASTER );

	# Start the configuration transaction
	$dbw->begin( __METHOD__ );
	foreach ( $electionIds as $id ) {
		$elections = $store->getElectionInfo( [ $id ] );
		$electionInfo = reset( $elections );
		$existingId = $dbw->selectField(
			'securepoll_elections',
			'el_entity',
			[ 'el_title' => $electionInfo['title'] ],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);
		if ( $existingId !== false ) {
			if ( $options['replace'] ) {
				spDeleteElection( $existingId );
				$success = spImportConfiguration( $store, $electionInfo );
			} elseif ( $options['update-msgs'] ) {
				# Do the message update and move on to the next election
				$success = spUpdateMessages( $store, $electionInfo );
			} else {
				echo "Conflicting election title found \"{$electionInfo['title']}\"\n";
				echo "Use --replace to replace the existing election.\n";
				$success = false;
			}
		} elseif ( $options['update-msgs'] ) {
			echo "Cannot update messages: election \"{$electionInfo['title']}\" not found.\n";
			echo "Import the configuration first, without the --update-msgs switch.\n";
			$success = false;
		} else {
			$success = spImportConfiguration( $store, $electionInfo );
		}
		if ( !$success ) {
			$dbw->rollback( __METHOD__ );
			echo "Faied!\n";
			return false;
		}
	}
	$dbw->commit( __METHOD__ );
	echo "Finished!\n";
	return true;
}

/**
 * @param $electionId int|string
 */
function spDeleteElection( $electionId ) {
	$dbw = wfGetDB( DB_MASTER );

	# Get a list of entity IDs and lock them
	$questionIds = [];
	$res = $dbw->select( 'securepoll_questions', [ 'qu_entity' ],
		[ 'qu_election' => $electionId ],
		__METHOD__, [ 'FOR UPDATE' ] );
	foreach ( $res as $row ) {
		$questionIds[] = $row->qu_entity;
	}

	$res = $dbw->select( 'securepoll_options', [ 'op_entity' ],
		[ 'op_election' => $electionId ],
		__METHOD__, [ 'FOR UPDATE' ] );
	$optionIds = [];
	foreach ( $res as $row ) {
		$optionIds[] = $row->op_entity;
	}

	$entityIds = array_merge( $optionIds, $questionIds, [ $electionId ] );

	# Delete the messages and properties
	$dbw->delete( 'securepoll_msgs', [ 'msg_entity' => $entityIds ] );
	$dbw->delete( 'securepoll_properties', [ 'pr_entity' => $entityIds ] );

	# Delete the entities
	$dbw->delete( 'securepoll_options', [ 'op_entity' => $optionIds ], __METHOD__ );
	$dbw->delete( 'securepoll_questions', [ 'qu_entity' => $questionIds ], __METHOD__ );
	$dbw->delete( 'securepoll_elections', [ 'el_entity' => $electionId ], __METHOD__ );
	$dbw->delete( 'securepoll_entity', [ 'en_id' => $entityIds ], __METHOD__ );
}

/**
 * @param $type string
 * @param $id string
 */
function spInsertEntity( $type, $id ) {
	$dbw = wfGetDB( DB_MASTER );
	$dbw->insert( 'securepoll_entity',
		[
			'en_id' => $id,
			'en_type' => $type,
		],
		__METHOD__
	);
}

/**
 * @param $store SecurePoll_Store
 * @param $electionInfo
 * @return bool
 */
function spImportConfiguration( $store, $electionInfo ) {
	$dbw = wfGetDB( DB_MASTER );
	$sourceIds = [];

	# Election
	spInsertEntity( 'election', $electionInfo['id'] );
	$dbw->insert( 'securepoll_elections',
		[
			'el_entity' => $electionInfo['id'],
			'el_title' => $electionInfo['title'],
			'el_ballot' => $electionInfo['ballot'],
			'el_tally' => $electionInfo['tally'],
			'el_primary_lang' => $electionInfo['primaryLang'],
			'el_start_date' => $dbw->timestamp( $electionInfo['startDate'] ),
			'el_end_date' => $dbw->timestamp( $electionInfo['endDate'] ),
			'el_auth_type' => $electionInfo['auth']
		],
		__METHOD__ );
	$sourceIds[] = $electionInfo['id'];

	if ( isset( $electionInfo['questions'] ) ) {
		# Questions
		$index = 1;
		foreach ( $electionInfo['questions'] as $questionInfo ) {
			spInsertEntity( 'question', $questionInfo['id'] );
			$dbw->insert( 'securepoll_questions',
				[
					'qu_entity' => $questionInfo['id'],
					'qu_election' => $electionInfo['id'],
					'qu_index' => $index++,
				],
				__METHOD__ );
			$sourceIds[] = $questionInfo['id'];

			# Options
			$insertBatch = [];
			foreach ( $questionInfo['options'] as $optionInfo ) {
				spInsertEntity( 'option', $optionInfo['id'] );
				$insertBatch[] = [
					'op_entity' => $optionInfo['id'],
					'op_election' => $electionInfo['id'],
					'op_question' => $questionInfo['id']
				];
				$sourceIds[] = $optionInfo['id'];
			}
			$dbw->insert( 'securepoll_options', $insertBatch, __METHOD__ );
		}
	}

	# Messages
	spInsertMessages( $store, $sourceIds );

	# Properties
	$properties = $store->getProperties( $sourceIds );
	$insertBatch = [];
	foreach ( $properties as $id => $entityProps ) {
		foreach ( $entityProps as $key => $value ) {
			$insertBatch[] = [
				'pr_entity' => $id,
				'pr_key' => $key,
				'pr_value' => $value
			];
		}
	}
	if ( $insertBatch ) {
		$dbw->insert( 'securepoll_properties', $insertBatch, __METHOD__ );
	}
	return true;
}

/**
 * @param $store SecurePoll_Store
 * @param $entityIds
 */
function spInsertMessages( $store, $entityIds ) {
	$langs = $store->getLangList( $entityIds );
	$insertBatch = [];
	foreach ( $langs as $lang ) {
		$messages = $store->getMessages( $lang, $entityIds );
		foreach ( $messages as $id => $entityMsgs ) {
			foreach ( $entityMsgs as $key => $text ) {
				$insertBatch[] = [
					'msg_entity' => $id,
					'msg_lang' => $lang,
					'msg_key' => $key,
					'msg_text' => $text
				];
			}
		}
	}
	if ( $insertBatch ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'securepoll_msgs', $insertBatch, __METHOD__ );
	}
}

/**
 * @param $store SecurePoll_Store
 * @param $electionInfo
 * @return bool
 */
function spUpdateMessages( $store, $electionInfo ) {
	$entityIds = [ $electionInfo['id'] ];
	if ( isset( $electionInfo['questions'] ) ) {
		foreach ( $electionInfo['questions'] as $questionInfo ) {
			$entityIds[] = $questionInfo['id'];
			foreach ( $questionInfo['options'] as $optionInfo ) {
				$entityIds[] = $optionInfo['id'];
			}
		}
	}

	# Delete existing messages
	$dbw = wfGetDB( DB_MASTER );
	$dbw->delete( 'securepoll_msgs', [ 'msg_entity' => $entityIds ], __METHOD__ );

	# Insert new messages
	spInsertMessages( $store, $entityIds );
	return true;
}
