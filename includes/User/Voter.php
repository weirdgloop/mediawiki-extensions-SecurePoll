<?php

namespace MediaWiki\Extensions\SecurePoll\User;

use MediaWiki\Extensions\SecurePoll\Context;
use stdClass;

/**
 * Class representing a voter. A voter is associated with one election only. Voter
 * properties include a snapshot of heuristic qualifications such as edit count.
 */
class Voter {
	public $id, $electionId, $name, $domain, $wiki, $type, $url;
	public $properties = [];
	public $context;

	private static $paramNames = [
		'id',
		'electionId',
		'name',
		'domain',
		'wiki',
		'type',
		'url',
		'properties'
	];

	/**
	 * Create a voter from the given associative array of parameters
	 * @param Context $context
	 * @param array $params
	 */
	public function __construct( $context, $params ) {
		$this->context = $context;
		foreach ( self::$paramNames as $name ) {
			if ( isset( $params[$name] ) ) {
				$this->$name = $params[$name];
			}
		}
	}

	/**
	 * Create a voter object from the database
	 * @param Context $context
	 * @param int $id
	 * @return Voter|bool false if the ID is not valid
	 */
	public static function newFromId( $context, $id ) {
		$db = $context->getDB();
		$row = $db->selectRow( 'securepoll_voters', '*', [ 'voter_id' => $id ], __METHOD__ );
		if ( !$row ) {
			return false;
		}

		return self::newFromRow( $context, $row );
	}

	/**
	 * Create a voter from a DB result row
	 * @param Context $context
	 * @param stdClass $row
	 * @return self
	 */
	public static function newFromRow( $context, $row ) {
		return new self(
			$context, [
				'id' => $row->voter_id,
				'electionId' => $row->voter_election,
				'name' => $row->voter_name,
				'domain' => $row->voter_domain,
				'type' => $row->voter_type,
				'url' => $row->voter_url,
				'properties' => self::decodeProperties( $row->voter_properties )
			]
		);
	}

	/**
	 * Create a voter with the given parameters. Assumes the voter does not exist,
	 * and inserts it into the database.
	 *
	 * The row needs to be locked before this function is called, to avoid
	 * duplicate key errors.
	 * @param Context $context
	 * @param array $params
	 * @return self
	 */
	public static function createVoter( $context, $params ) {
		$db = $context->getDB();
		$id = $db->nextSequenceValue( 'voters_voter_id' );
		$row = [
			'voter_id' => $id,
			'voter_election' => $params['electionId'],
			'voter_name' => $params['name'],
			'voter_type' => $params['type'],
			'voter_domain' => $params['domain'],
			'voter_url' => $params['url'],
			'voter_properties' => self::encodeProperties( $params['properties'] )
		];
		$db->insert( 'securepoll_voters', $row, __METHOD__ );
		$params['id'] = $db->insertId();

		return new self( $context, $params );
	}

	/**
	 * Get the voter ID
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Get the voter name. This is a short, ambiguous name appropriate for
	 * display.
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Get the authorization type.
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * Get the voter domain. The name and domain, taken together, should usually be
	 * unique, although this is not strictly necessary.
	 * @return string
	 */
	public function getDomain() {
		return $this->domain;
	}

	/**
	 * Get a URL uniquely identifying the underlying user.
	 * @return string
	 */
	public function getUrl() {
		return $this->url;
	}

	/**
	 * Get the associated election ID
	 * @return int
	 */
	public function getElectionId() {
		return $this->electionId;
	}

	/**
	 * Get the voter's preferred language
	 * @return mixed
	 */
	public function getLanguage() {
		return $this->getProperty( 'language', 'en' );
	}

	/**
	 * Get a property from the property blob
	 * @param string $name
	 * @param string|false $default
	 * @return mixed
	 */
	public function getProperty( $name, $default = false ) {
		if ( isset( $this->properties[$name] ) ) {
			return $this->properties[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Returns true if the voter is a guest user.
	 * @return bool
	 */
	public function isRemote() {
		return $this->type !== 'local';
	}

	/**
	 * Decode the properties blob to produce an associative array.
	 * @param string $blob
	 * @return array
	 */
	public static function decodeProperties( $blob ) {
		if ( strval( $blob ) == '' ) {
			return [];
		} else {
			return unserialize( $blob );
		}
	}

	/**
	 * Encode an associative array of properties to a blob suitable for storing
	 * in the database.
	 * @param array $props
	 * @return string
	 */
	public static function encodeProperties( $props ) {
		return serialize( $props );
	}

	public function doCookieCheck() {
		$cookieName = wfWikiID() . '_securepoll_check';
		if ( isset( $_COOKIE[$cookieName] ) ) {
			$otherVoterId = intval( $_COOKIE[$cookieName] );
			if ( $otherVoterId != $this->getId() ) {
				$otherVoter = self::newFromId( $this->context, $otherVoterId );
				if ( $otherVoter && $otherVoter->getElectionId() == $this->getElectionId() ) {
					$this->addCookieDup( $otherVoterId );
				}
			}
		} else {
			setcookie( $cookieName, $this->getId(), time() + 86400 * 30 );
		}
	}

	/**
	 * Flag a duplicate voter
	 * @param int $voterId
	 */
	public function addCookieDup( $voterId ) {
		$dbw = $this->context->getDB();
		# Insert the log record
		$dbw->insert(
			'securepoll_cookie_match',
			[
				'cm_election' => $this->getElectionId(),
				'cm_voter_1' => $this->getId(),
				'cm_voter_2' => $voterId,
				'cm_timestamp' => wfTimestampNow()
			],
			__METHOD__
		);

		# Update the denormalized fields
		$dbw->update(
			'securepoll_votes',
			[ 'vote_cookie_dup' => 1 ],
			[
				'vote_election' => $this->getElectionId(),
				'vote_voter' => [
					$this->getId(),
					$voterId
				]
			],
			__METHOD__
		);
	}

}
