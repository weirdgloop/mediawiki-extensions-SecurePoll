<?php

/**
 * A ballot form for range voting where the number of allowed responses is small,
 * allowing a radio button table interface and histogram tallying.
 *
 * Election properties:
 *     must-answer-all
 *
 * Question properties:
 *     min-score
 *     max-score
 *     column-label-msgs
 *     column-order
 *
 * Question messages:
 *     column-1, column0, column+1, etc.
 */
class SecurePoll_RadioRangeBallot extends SecurePoll_Ballot {
	public $columnLabels, $minMax;

	static function getTallyTypes() {
		return [ 'plurality', 'histogram-range' ];
	}

	static function getCreateDescriptors() {
		$ret = parent::getCreateDescriptors();
		$ret['election'] += [
			'must-answer-all' => [
				'label-message' => 'securepoll-create-label-must_answer_all',
				'type' => 'check',
				'hidelabel' => true,
				'SecurePoll_type' => 'property',
			],
		];
		$ret['question'] += [
			[
				'type' => 'info',
				'rawrow' => true,
				'default' => '<table class="securepoll-layout-table"><tr><td>',
			],

			'min-score' => [
				'label-message' => 'securepoll-create-label-min_score',
				'type' => 'int',
				'required' => true,
				'SecurePoll_type' => 'property',
			],

			[
				'type' => 'info',
				'rawrow' => true,
				'default' => '</td><td>',
			],

			'max-score' => [
				'label-message' => 'securepoll-create-label-max_score',
				'type' => 'int',
				'required' => true,
				'SecurePoll_type' => 'property',
			],

			[
				'type' => 'info',
				'rawrow' => true,
				'default' => '</td><td>',
			],

			'default-score' => [
				'label-message' => 'securepoll-create-label-default_score',
				'type' => 'int',
				'SecurePoll_type' => 'property',
			],

			[
				'type' => 'info',
				'rawrow' => true,
				'default' => '</td></tr></table>',
			],

			'column-order' => [
				'label-message' => 'securepoll-create-label-column_order',
				'type' => 'select',
				'options-messages' => [
					'securepoll-create-option-column_order-asc' => 'asc',
					'securepoll-create-option-column_order-desc' => 'desc',
				],
				'SecurePoll_type' => 'property',
			],
			'column-label-msgs' => [
				'label-message' => 'securepoll-create-label-column_label_msgs',
				'type' => 'check',
				'hidelabel' => true,
				'SecurePoll_type' => 'property',
			],
			'column-messages' => [
				'hide-if' => [ '!==', 'column-label-msgs', '1' ],
				'class' => 'SecurePoll_HTMLFormRadioRangeColumnLabels',
				'SecurePoll_type' => 'messages',
			],
		];
		return $ret;
	}

	/**
	 * @param $question SecurePoll_Question
	 * @return array
	 * @throws MWException
	 */
	function getMinMax( $question ) {
		$min = intval( $question->getProperty( 'min-score' ) );
		$max = intval( $question->getProperty( 'max-score' ) );
		if ( $max <= $min ) {
			throw new MWException( __METHOD__.': min/max not configured' );
		}
		return [ $min, $max ];
	}

	/**
	 * @param $question SecurePoll_Question
	 * @return int
	 * @throws MWException
	 */
	function getColumnDirection( $question ) {
		$order = $question->getProperty( 'column-order' );
		if ( !$order ) {
			return 1;
		} elseif ( preg_match( '/^asc/i', $order ) ) {
			return 1;
		} elseif ( preg_match( '/^desc/i', $order ) ) {
			return -1;
		} else {
			throw new MWException( __METHOD__.': column-order configured incorrectly' );
		}
	}

	/**
	 * @param $question SecurePoll_Question
	 * @return array
	 */
	function getScoresLeftToRight( $question ) {
		$incr = $this->getColumnDirection( $question );
		list( $min, $max ) = $this->getMinMax( $question );
		if ( $incr > 0 ) {
			$left = $min;
			$right = $max;
		} else {
			$left = $max;
			$right = $min;
		}
		return range( $left, $right );
	}

	/**
	 * @param $question SecurePoll_Question
	 * @return array
	 */
	function getColumnLabels( $question ) {
		// list( $min, $max ) = $this->getMinMax( $question );
		$labels = [];
		$useMessageLabels = $question->getProperty( 'column-label-msgs' );
		$scores = $this->getScoresLeftToRight( $question );
		if ( $useMessageLabels ) {
			foreach ( $scores as $score ) {
				$signedScore = $this->addSign( $question, $score );
				$labels[$score] = $question->parseMessageInline( "column$signedScore" );
			}
		} else {
			global $wgLang;
			foreach ( $scores as $score ) {
				$labels[$score] = $wgLang->formatNum( $score );
			}
		}
		return $labels;
	}

	function getMessageNames( $entity = null ) {
		if ( $entity === null || $entity->getType() !== 'question' ) {
			return [];
		}
		if ( !$entity->getProperty( 'column-label-msgs' ) ) {
			return [];
		}
		$msgs = [];
		list( $min, $max ) = $this->getMinMax( $entity );
		for ( $score = $min; $score <= $max; $score++ ) {
			$signedScore = $this->addSign( $entity, $score );
			$msgs[] = "column$signedScore";
		}
		return $msgs;
	}

	function addSign( $question, $score ) {
		list( $min, $max ) = $this->getMinMax( $question );
		if ( $min < 0 && $score > 0 ) {
			return "+$score";
		} else {
			return $score;
		}
	}

	/**
	 * @param $question SecurePoll_Question
	 * @param $options array
	 * @return array
	 */
	function getQuestionForm( $question, $options ) {
		global $wgRequest;
		$name = 'securepoll_q' . $question->getId();
		list( $min, $max ) = $this->getMinMax( $question );
		$labels = $this->getColumnLabels( $question );

		$s = "<table class=\"securepoll-ballot-table\">\n" .
			"<tr>\n" .
			"<th>&#160;</th>\n";
		foreach ( $labels as $label ) {
			$s .= Html::rawElement( 'th', [], $label ) . "\n";
		}
		$s .= "</tr>\n";
		$defaultScore = $question->getProperty( 'default-score' );

		foreach ( $options as $option ) {
			$optionHTML = $option->parseMessageInline( 'text' );
			$optionId = $option->getId();
			$inputId = "{$name}_opt{$optionId}";
			$oldValue = $wgRequest->getVal( $inputId, $defaultScore );
			$s .= "<tr class=\"securepoll-ballot-row\">\n" .
				Xml::tags( 'td',
					[ 'class' => 'securepoll-ballot-optlabel' ],
					$this->errorLocationIndicator( $inputId ) . $optionHTML
				);

			foreach ( $labels as $score => $label ) {
				$s .=
					Xml::tags( 'td', [],
						Xml::radio( $inputId, $score, !strcmp( $oldValue, $score ),
							[ 'title' => $label ] )
					) . "\n";
			}
			$s .= "</tr>\n";
		}
		$s .= "</table>\n";
		return $s;
	}

	/**
	 * @param $question SecurePoll_Question
	 * @param $status Status
	 * @return array
	 */
	function submitQuestion( $question, $status ) {
		global $wgRequest, $wgLang;

		$options = $question->getOptions();
		$record = '';
		$ok = true;
		list( $min, $max ) = $this->getMinMax( $question );
		$defaultScore = $question->getProperty( 'default-score' );
		foreach ( $options as $option ) {
			$id = 'securepoll_q' . $question->getId() . '_opt' . $option->getId();
			$score = $wgRequest->getVal( $id );

			if ( is_numeric( $score ) ) {
				if ( $score < $min || $score > $max ) {
					$status->sp_fatal( 'securepoll-invalid-score', $id,
						$wgLang->formatNum( $min ), $wgLang->formatNum( $max ) );
					$ok = false;
					continue;
				} else {
					$score = intval( $score );
				}
			} elseif ( strval( $score ) === '' ) {
				if ( $this->election->getProperty( 'must-answer-all' ) ) {
					$status->sp_fatal( 'securepoll-unanswered-options', $id );
					$ok = false;
					continue;
				} else {
					$score = $defaultScore;
				}
			} else {
				$status->sp_fatal( 'securepoll-invalid-score', $id,
					$wgLang->formatNum( $min ), $wgLang->formatNum( $max ) );
				$ok = false;
				continue;
			}
			$record .= sprintf( 'Q%08X-A%08X-S%+011d--',
				$question->getId(), $option->getId(), $score );
		}
		if ( $ok ) {
			return $record;
		}
	}

	function unpackRecord( $record ) {
		$scores = [];
		$itemLength = 8 + 8 + 11 + 7;
		$questions = [];
		foreach ( $this->election->getQuestions() as $question ) {
			$questions[$question->getId()] = $question;
		}
		for ( $offset = 0, $len = strlen( $record ); $offset < $len; $offset += $itemLength ) {
			if ( !preg_match( '/Q([0-9A-F]{8})-A([0-9A-F]{8})-S([+-][0-9]{10})--/A',
				$record, $m, 0, $offset )
			) {
				wfDebug( __METHOD__.": regex doesn't match\n" );
				return false;
			}
			$qid = intval( base_convert( $m[1], 16, 10 ) );
			$oid = intval( base_convert( $m[2], 16, 10 ) );
			$score = intval( $m[3] );
			if ( !isset( $questions[$qid] ) ) {
				wfDebug( __METHOD__.": invalid question ID\n" );
				return false;
			}
			list( $min, $max ) = $this->getMinMax( $questions[$qid] );
			if ( $score < $min || $score > $max ) {
				wfDebug( __METHOD__.": score out of range\n" );
				return false;
			}
			$scores[$qid][$oid] = $score;
		}
		return $scores;
	}

	function convertScores( $scores, $params = [] ) {
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
			foreach ( $qscores as $score ) {
				if ( $first ) {
					$first = false;
				} else {
					$s .= ', ';
				}
				$s .= $score;
			}
			$result[$qid] = $s;
		}
		return $result;
	}
}
