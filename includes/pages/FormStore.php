<?php

/**
 * SecurePoll_Store for loading the form data.
 */
class SecurePoll_FormStore extends SecurePoll_MemoryStore {
	public $eId, $rId = 0;
	public $qIds = [], $oIds = [];
	public $remoteWikis;

	private $lang;

	public function __construct( $formData, $userId ) {
		global $wgSecurePollCreateWikiGroupDir, $wgSecurePollCreateWikiGroups,
			$wgSecurePollCreateRemoteScriptPath;

		$curId = 0;

		$wikis = $formData['property_wiki'] ?? wfWikiID();
		if ( $wikis === '*' ) {
			$wikis = array_values( self::getWikiList() );
		} elseif ( substr( $wikis, 0, 1 ) === '@' ) {
			$file = substr( $wikis, 1 );
			$wikis = false;

			// HTMLForm already checked this, but let's do it again anyway.
			if ( isset( $wgSecurePollCreateWikiGroups[$file] ) ) {
				$wikis = file_get_contents(
					$wgSecurePollCreateWikiGroupDir . $file . '.dblist'
				);
			}

			if ( !$wikis ) {
				throw new SecurePoll_StatusException( 'securepoll-create-fail-bad-dblist' );
			}
			$wikis = array_map( 'trim', explode( "\n", trim( $wikis ) ) );
		} else {
			$wikis = (array)$wikis;
		}

		$this->remoteWikis = array_diff( $wikis, [ wfWikiID() ] );

		// Create the entry for the election
		list( $ballot, $tally ) = explode( '+', $formData['election_type'] );
		$crypt = $formData['election_crypt'];

		$date = new DateTime(
			"{$formData['election_startdate']}T00:00:00Z",
			new DateTimeZone( 'GMT' )
		);
		$startDate = $date->format( 'YmdHis' );

		$date->add( new DateInterval( "P{$formData['election_duration']}D" ) );
		$endDate = $date->format( 'YmdHis' );

		$this->lang = $formData['election_primaryLang'];

		$eId = (int)$formData['election_id'] <= 0 ? --$curId : (int)$formData['election_id'];
		$this->eId = $eId;
		$this->entityInfo[$eId] = [
			'id' => $eId,
			'type' => 'election',
			'title' => $formData['election_title'],
			'ballot' => $ballot,
			'tally' => $tally,
			'primaryLang' => $this->lang,
			'startDate' => wfTimestamp( TS_MW, $startDate ),
			'endDate' => wfTimestamp( TS_MW, $endDate ),
			'auth' => $this->remoteWikis ? 'remote-mw' : 'local',
			'owner' => $userId,
			'questions' => [],
		];
		$this->properties[$eId] = [
			'encrypt-type' => $crypt,
			'wikis' => implode( "\n", $wikis ),
			'wikis-val' => $formData['property_wiki'] ?? wfWikiID(),
			'return-url' => $formData['return-url'],
			'disallow-change' => $formData['disallow-change'] ? 1 : 0,
			'voter-privacy' => $formData['voter-privacy'] ? 1 : 0,
		];
		$this->messages[$this->lang][$eId] = [
			'title' => $formData['election_title'],
		];

		$admins = $this->getAdminsList( $formData['property_admins'] );
		$this->properties[$eId]['admins'] = $admins;

		if ( $this->remoteWikis ) {
			$this->properties[$eId]['remote-mw-script-path'] = $wgSecurePollCreateRemoteScriptPath;

			$this->rId = $rId = --$curId;
			$this->entityInfo[$rId] = [
				'id' => $rId,
				'type' => 'election',
				'title' => $formData['election_title'],
				'ballot' => $ballot,
				'tally' => $tally,
				'primaryLang' => $this->lang,
				'startDate' => wfTimestamp( TS_MW, $startDate ),
				'endDate' => wfTimestamp( TS_MW, $endDate ),
				'auth' => 'local',
				'questions' => [],
			];
			$this->properties[$rId]['main-wiki'] = wfWikiID();
			$this->properties[$rId]['jump-url'] =
				SpecialPage::getTitleFor( 'SecurePoll' )->getFullUrl();
			$this->properties[$rId]['jump-id'] = $eId;
			$this->properties[$rId]['admins'] = $admins;
			$this->messages[$this->lang][$rId] = [
				'title' => $formData['election_title'],
				'jump-text' => $formData['jump-text'],
			];
		}

		$this->processFormData(
			$eId, $formData, SecurePoll_Ballot::$ballotTypes[$ballot], 'election'
		);
		$this->processFormData(
			$eId, $formData, SecurePoll_Tallier::$tallierTypes[$tally], 'election'
		);
		$this->processFormData(
			$eId, $formData, SecurePoll_Crypt::$cryptTypes[$crypt], 'election'
		);

		// Process each question
		foreach ( $formData['questions'] as $question ) {
			if ( (int)$question['id'] <= 0 ) {
				$qId = --$curId;
			} else {
				$qId = (int)$question['id'];
				$this->qIds[] = $qId;
			}
			$this->entityInfo[$qId] = [
				'id' => $qId,
				'type' => 'question',
				'election' => $eId,
				'options' => [],
			];
			$this->properties[$qId] = [];
			$this->messages[$this->lang][$qId] = [
				'text' => $question['text'],
			];

			$this->processFormData(
				$qId, $question, SecurePoll_Ballot::$ballotTypes[$ballot], 'question'
			);
			$this->processFormData(
				$qId, $question, SecurePoll_Tallier::$tallierTypes[$tally], 'question'
			);
			$this->processFormData(
				$qId, $question, SecurePoll_Crypt::$cryptTypes[$crypt], 'question'
			);

			// Process options for this question
			foreach ( $question['options'] as $option ) {
				if ( (int)$option['id'] <= 0 ) {
					$oId = --$curId;
				} else {
					$oId = (int)$option['id'];
					$this->oIds[] = $oId;
				}
				$this->entityInfo[$oId] = [
					'id' => $oId,
					'type' => 'option',
					'election' => $eId,
					'question' => $qId,
				];
				$this->properties[$oId] = [];
				$this->messages[$this->lang][$oId] = [
					'text' => $option['text'],
				];

				$this->processFormData(
					$oId, $option, SecurePoll_Ballot::$ballotTypes[$ballot], 'option'
				);
				$this->processFormData(
					$oId, $option, SecurePoll_Tallier::$tallierTypes[$tally], 'option'
				);
				$this->processFormData(
					$oId, $option, SecurePoll_Crypt::$cryptTypes[$crypt], 'option'
				);

				$this->entityInfo[$qId]['options'][] = &$this->entityInfo[$oId];
			}

			$this->entityInfo[$eId]['questions'][] = &$this->entityInfo[$qId];
		}
	}

	/**
	 * Extract the values for the class's properties and messages
	 *
	 * @param int $id
	 * @param array $formData Form data array
	 * @param string|false $class Class with the ::getCreateDescriptors static method
	 * @param string|null $category If given, ::getCreateDescriptors is
	 *    expected to return an array with subarrays for different categories
	 *    of descriptors, and this selects which subarray to process.
	 */
	private function processFormData( $id, $formData, $class, $category ) {
		if ( $class === false ) {
			return;
		}

		$items = call_user_func_array( [ $class, 'getCreateDescriptors' ], [] );

		if ( $category ) {
			if ( !isset( $items[$category] ) ) {
				return;
			}
			$items = $items[$category];
		}

		foreach ( $items as $key => $item ) {
			if ( !isset( $item['SecurePoll_type'] ) ) {
				continue;
			}
			$value = $formData[$key];
			switch ( $item['SecurePoll_type'] ) {
				case 'property':
					$this->properties[$id][$key] = $value;
					break;
				case 'properties':
					foreach ( $value as $k => $v ) {
						$this->properties[$id][$k] = $v;
					}
					break;
				case 'message':
					$this->messages[$this->lang][$id][$key] = $value;
					break;
				case 'messages':
					foreach ( $value as $k => $v ) {
						$this->messages[$this->lang][$id][$k] = $v;
					}
					break;
			}
		}
	}

	/**
	 * Get the name of a wiki
	 *
	 * @param string $dbname
	 * @return string
	 */
	public static function getWikiName( $dbname ) {
		$name = WikiMap::getWikiName( $dbname );
		return $name ?: $dbname;
	}

	/**
	 * Get the list of wiki names
	 *
	 * @return array
	 */
	public static function getWikiList() {
		global $wgConf;

		$wikiNames = [];
		foreach ( $wgConf->getLocalDatabases() as $dbname ) {
			$host = self::getWikiName( $dbname );
			if ( strpos( $host, '.' ) ) {
				// e.g. "en.wikipedia.org"
				$wikiNames[$host] = $dbname;
			}
		}

		// Make sure the local wiki is represented
		$dbname = wfWikiID();
		$wikiNames[self::getWikiName( $dbname )] = $dbname;

		ksort( $wikiNames );

		return $wikiNames;
	}

	/**
	 * Convert the submitted array of admin usernames into a string for
	 * insertion into the database.
	 *
	 * @param array[] $data
	 * @return string
	 */
	private function getAdminsList( $data ) {
		$admins = [];
		foreach ( $data as $admin ) {
			$admins[] = User::getCanonicalName( $admin['username'] );
		}
		return implode( '|', $admins );
	}
}