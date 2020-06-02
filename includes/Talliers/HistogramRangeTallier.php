<?php

namespace MediaWiki\Extensions\SecurePoll\Talliers;

use MWException;
use Xml;

class HistogramRangeTallier extends Tallier {
	public $histogram = [];
	public $sums = [];
	public $counts = [];
	public $averages;
	public $minScore, $maxScore;

	public function __construct( $context, $electionTallier, $question ) {
		parent::__construct( $context, $electionTallier, $question );
		$this->minScore = intval( $question->getProperty( 'min-score' ) );
		$this->maxScore = intval( $question->getProperty( 'max-score' ) );
		if ( $this->minScore >= $this->maxScore ) {
			throw new MWException( __METHOD__ . ': min-score/max-score configured incorrectly' );
		}

		foreach ( $question->getOptions() as $option ) {
			$this->histogram[$option->getId()] = array_fill(
				$this->minScore,
				$this->maxScore - $this->minScore + 1,
				0
			);
			$this->sums[$option->getId()] = 0;
			$this->counts[$option->getId()] = 0;
		}
	}

	public function addVote( $scores ) {
		foreach ( $scores as $oid => $score ) {
			$this->histogram[$oid][$score]++;
			$this->sums[$oid] += $score;
			$this->counts[$oid]++;
		}

		return true;
	}

	public function finishTally() {
		$this->averages = [];
		foreach ( $this->sums as $oid => $sum ) {
			if ( $this->counts[$oid] === 0 ) {
				$this->averages[$oid] = 'N/A';
				break;
			}
			$this->averages[$oid] = $sum / $this->counts[$oid];
		}
		arsort( $this->averages );
	}

	public function getHtmlResult() {
		$ballot = $this->election->getBallot();
		if ( !is_callable(
			[
				$ballot,
				'getColumnLabels'
			]
		)
		) {
			throw new MWException( __METHOD__ . ': ballot type not supported by this tallier' );
		}
		$optionLabels = [];
		foreach ( $this->question->getOptions() as $option ) {
			$optionLabels[$option->getId()] = $option->parseMessageInline( 'text' );
		}

		// @phan-suppress-next-line PhanUndeclaredMethod Checked by is_callable
		$labels = $ballot->getColumnLabels( $this->question );
		$s = "<table class=\"securepoll-table\">\n" . "<tr>\n" . "<th>&#160;</th>\n";
		foreach ( $labels as $label ) {
			$s .= Xml::element( 'th', [], $label ) . "\n";
		}
		$s .= Xml::element( 'th', [], wfMessage( 'securepoll-average-score' )->text() );
		$s .= "</tr>\n";

		foreach ( $this->averages as $oid => $average ) {
			$s .= "<tr>\n" . Xml::tags(
					'td',
					[ 'class' => 'securepoll-results-row-heading' ],
					$optionLabels[$oid]
				) . "\n";
			foreach ( $labels as $score => $label ) {
				$s .= Xml::element( 'td', [], $this->histogram[$oid][$score] ) . "\n";
			}
			$s .= Xml::element( 'td', [], $average ) . "\n";
			$s .= "</tr>\n";
		}
		$s .= "</table>\n";

		return $s;
	}

	public function getTextResult() {
		return $this->getHtmlResult();
	}
}
