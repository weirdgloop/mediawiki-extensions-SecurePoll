<?php
/**
 *
 *
 * Created on Jul 7, 2015
 *
 * Copyright © 2015 Frances Hocutt "<Firstinitial><Lastname>@wikimedia.org"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extensions\SecurePoll\Api;

use ApiBase;
use MediaWiki\Extensions\SecurePoll\Pages\ListPage;
use MediaWiki\Extensions\SecurePoll\SpecialSecurePoll;

/**
 * API module to facilitate striking/unstriking SecurePoll votes.
 *
 * @ingroup API
 */
class ApiStrikeVote extends ApiBase {
	/**
	 * Strike or unstrike a vote.
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$option = $params['option'];
		$voteid = $params['voteid'];
		$reason = $params['reason'];

		// FIXME: thoughts on whether error checks should go here or in strike()?
		// if not logged in: fail
		$user = $this->getUser();
		if ( !$user->isLoggedIn() ) {
			$this->dieWithError(
				'apierror-securepoll-mustbeloggedin-strikevote',
				'notloggedin'
			);
		}

		// see if vote exists
		$page = new SpecialSecurePoll;
		$context = $page->sp_context;
		$db = $context->getDB();
		$table = $db->tableName( 'securepoll_elections' );
		$row = $db->selectRow(
			[
				'securepoll_votes',
				'securepoll_elections'
			],
			"$table.*",
			[
				'vote_id' => $voteid,
				'vote_election=el_entity'
			],
			__METHOD__
		);

		// if no vote: fail
		if ( !$row ) {
			$this->dieWithError(
				[
					'apierror-securepoll-badvoteid',
					$voteid
				],
				'novote'
			);
		}

		// strike the vote
		$subpage = new ListPage( $page );
		$subpage->election = $context->newElectionFromRow( $row );
		$status = $subpage->strike( $option, $voteid, $reason );

		$result = [];
		if ( $status->isGood() ) {
			$result['status'] = 'good';
		} else {
			$this->dieStatus( $status );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'option' => [
				ApiBase::PARAM_TYPE => [
					'strike',
					'unstrike'
				],
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'reason' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
			'voteid' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=strikevote&option=strike&reason=duplication&voteid=1&token=123ABC' =>
				'apihelp-strikevote-example-strike',
			'action=strikevote&option=unstrike&reason=mistake&voteid=1&token=123ABC' =>
				'apihelp-strikevote-example-unstrike',
		];
	}
}
