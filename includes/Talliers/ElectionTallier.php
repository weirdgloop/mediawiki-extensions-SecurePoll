<?php

namespace MediaWiki\Extensions\SecurePoll\Talliers;

use MediaWiki\Extensions\SecurePoll\Context;
use MediaWiki\Extensions\SecurePoll\Entities\Election;
use MediaWiki\Extensions\SecurePoll\Store;
use MWException;
use Status;

/**
 * A helper class for tallying a whole election (with multiple questions).
 * Most of the functionality is contained in the Tallier subclasses
 * which operate on a single question at a time.
 *
 * A convenience function for accessing this class is
 * Election::tally().
 */
class ElectionTallier {
	public $ballot, $context, $crypt, $election, $questions, $talliers;

	/**
	 * Constructor.
	 * @param Context $context
	 * @param Election $election
	 */
	public function __construct( $context, $election ) {
		$this->context = $context;
		$this->election = $election;
	}

	/**
	 * Do the tally. Returns a Status object. On success, the value property
	 * of the status will be an array of Tallier objects, which can
	 * be queried for results information.
	 * @return Status
	 */
	public function execute() {
		$store = $this->context->getStore();
		$this->crypt = $this->election->getCrypt();
		$this->ballot = $this->election->getBallot();
		$questions = $this->election->getQuestions();
		$this->talliers = [];
		$tallyType = $this->election->getTallyType();
		foreach ( $questions as $question ) {
			$tallier = $this->context->newTallier( $tallyType, $this, $question );
			if ( !$tallier ) {
				throw new MWException( 'Invalid tally type' );
			}
			$this->talliers[$question->getId()] = $tallier;
		}

		$status = $store->callbackValidVotes(
			$this->election->getId(),
			[
				$this,
				'addRecord'
			]
		);
		if ( !$status->isOK() ) {
			return $status;
		}

		foreach ( $this->talliers as $tallier ) {
			$tallier->finishTally();
		}

		return Status::newGood( $this->talliers );
	}

	/**
	 * Add a record. This is the callback function for Store::callbackValidVotes().
	 * On error, the Status object returned here will be passed through back to
	 * the caller of callbackValidVotes().
	 *
	 * @param Store $store
	 * @param string $record Encrypted, packed record.
	 * @return Status
	 */
	public function addRecord( $store, $record ) {
		# Decrypt and unpack
		if ( $this->crypt ) {
			$status = $this->crypt->decrypt( $record );
			if ( !$status->isOK() ) {
				return $status;
			}
			$record = $status->value;
		}
		$record = rtrim( $record );
		$scores = $this->ballot->unpackRecord( $record );

		# Add the record to the underlying question-specific tallier objects
		foreach ( $this->election->getQuestions() as $question ) {
			$qid = $question->getId();
			if ( !isset( $scores[$qid] ) ) {
				return Status::newFatal( 'securepoll-tally-error' );
			}
			if ( !$this->talliers[$qid]->addVote( $scores[$qid] ) ) {
				return Status::newFatal( 'securepoll-tally-error' );
			}
		}

		return Status::newGood();
	}

	/**
	 * Get HTML formatted results for this tally. Should only be called after
	 * execute().
	 * @return string
	 */
	public function getHtmlResult() {
		$s = '';
		foreach ( $this->election->getQuestions() as $question ) {
			if ( $s !== '' ) {
				$s .= "<hr/>\n";
			}
			$tallier = $this->talliers[$question->getId()];
			$s .= $tallier->getHtmlResult();
		}

		return $s;
	}

	/**
	 * Get text formatted results for this tally. Should only be called after
	 * execute().
	 * @return string
	 */
	public function getTextResult() {
		$s = '';
		foreach ( $this->election->getQuestions() as $question ) {
			if ( $s !== '' ) {
				$s .= "\n";
			}
			$tallier = $this->talliers[$question->getId()];
			$s .= $tallier->getTextResult();
		}

		return $s;
	}
}
