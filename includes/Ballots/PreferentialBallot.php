<?php

namespace MediaWiki\Extensions\SecurePoll\Ballots;

use MediaWiki\Extensions\SecurePoll\Entities\Question;
use RequestContext;

/**
 * Ballot for preferential voting
 * Properties:
 *     shuffle-questions
 *     shuffle-options
 *     must-rank-all
 */
class PreferentialBallot extends Ballot {
	public static function getTallyTypes() {
		return [ 'schulze' ];
	}

	public static function getCreateDescriptors() {
		$ret = parent::getCreateDescriptors();
		$ret['election'] += [
			'must-rank-all' => [
				'label-message' => 'securepoll-create-label-must_rank_all',
				'type' => 'check',
				'hidelabel' => true,
				'SecurePoll_type' => 'property',
			],
		];

		return $ret;
	}

	/**
	 * @param Question $question
	 * @param array $options
	 * @return string
	 */
	public function getQuestionForm( $question, $options ) {
		$name = 'securepoll_q' . $question->getId();
		$fieldset = new \OOUI\FieldsetLayout();
		$request = RequestContext::getMain()->getRequest();
		foreach ( $options as $option ) {
			$optionHTML = $option->parseMessageInline( 'text' );
			$optionId = $option->getId();
			$inputId = "{$name}_opt{$optionId}";
			$oldValue = $request->getVal( $inputId, '' );

			$widget = new \OOUI\NumberInputWidget( [
				'name' => $inputId,
				'default' => $oldValue,
				'min' => 1,
				'max' => 999,
				'required' => $this->election->getProperty( 'must-rank-all' ),
			] );

			$label = new \OOUI\LabelWidget( [
				'label' => new \OOUI\HtmlSnippet( $this->errorLocationIndicator( $optionId ) . $optionHTML ),
				'input' => $widget,
			] );

			$fieldset->appendContent( new \OOUI\HorizontalLayout(
				[
					'classes' => [ 'securepoll-option-preferential' ],
					'items' => [
						$widget,
						$label,
					],
				]
			) );
		}

		return $fieldset;
	}

	/**
	 * @param Question $question
	 * @param BallotStatus $status
	 * @return string
	 */
	public function submitQuestion( $question, $status ) {
		$options = $question->getOptions();
		$record = '';
		$ok = true;
		foreach ( $options as $option ) {
			$id = 'securepoll_q' . $question->getId() . '_opt' . $option->getId();
			$rank = RequestContext::getMain()->getRequest()->getVal( $id );

			if ( is_numeric( $rank ) ) {
				if ( $rank <= 0 || $rank >= 1000 ) {
					$status->sp_fatal( 'securepoll-invalid-rank', $id );
					$ok = false;
					continue;
				} else {
					$rank = intval( $rank );
				}
			} elseif ( strval( $rank ) === '' ) {
				if ( $this->election->getProperty( 'must-rank-all' ) ) {
					$status->sp_fatal( 'securepoll-unranked-options', $id );
					$ok = false;
					continue;
				} else {
					$rank = 1000;
				}
			} else {
				$status->sp_fatal( 'securepoll-invalid-rank', $id );
				$ok = false;
				continue;
			}
			$record .= sprintf(
				'Q%08X-A%08X-R%08X--',
				$question->getId(),
				$option->getId(),
				$rank
			);
		}
		if ( $ok ) {
			return $record;
		}
	}

	public function unpackRecord( $record ) {
		$ranks = [];
		$itemLength = 3 * 8 + 7;
		for ( $offset = 0, $len = strlen( $record ); $offset < $len; $offset += $itemLength ) {
			if ( !preg_match(
				'/Q([0-9A-F]{8})-A([0-9A-F]{8})-R([0-9A-F]{8})--/A',
				$record,
				$m,
				0,
				$offset
			)
			) {
				wfDebug( __METHOD__ . ": regex doesn't match\n" );

				return false;
			}
			$qid = intval( base_convert( $m[1], 16, 10 ) );
			$oid = intval( base_convert( $m[2], 16, 10 ) );
			$rank = intval( base_convert( $m[3], 16, 10 ) );
			$ranks[$qid][$oid] = $rank;
		}

		return $ranks;
	}

	public function convertScores( $scores, $params = [] ) {
		$result = [];
		foreach ( $this->election->getQuestions() as $question ) {
			$qid = $question->getId();
			if ( !isset( $scores[$qid] ) ) {
				return false;
			}
			$s = '';
			$qscores = $scores[$qid];
			ksort( $qscores );
			$first = true;
			foreach ( $qscores as $rank ) {
				if ( $first ) {
					$first = false;
				} else {
					$s .= ', ';
				}
				if ( $rank == 1000 ) {
					$s .= '-';
				} else {
					$s .= $rank;
				}
			}
			$result[$qid] = $s;
		}

		return $result;
	}
}
