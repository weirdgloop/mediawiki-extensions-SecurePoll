<?php

namespace MediaWiki\Extensions\SecurePoll\User;

use CentralAuthUser;
use CentralAuthUtils;
use ExtensionRegistry;
use Hooks;
use MediaWiki\Extensions\SecurePoll\Entities\Election;
use RequestContext;
use Status;
use User;

/**
 * Authorization class for locally created accounts.
 * Certain functions in this class are also used for sending local voter
 * parameters to a remote SecurePoll installation.
 */
class LocalAuth extends Auth {
	/**
	 * Create a voter transparently, without user interaction.
	 * Sessions authorized against local accounts are created this way.
	 * @param Election $election
	 * @return Status
	 */
	public function autoLogin( $election ) {
		$user = RequestContext::getMain()->getUser();
		if ( $user->isAnon() ) {
			return Status::newFatal( 'securepoll-not-logged-in' );
		}
		$params = $this->getUserParams( $user );
		$params['electionId'] = $election->getId();
		$qualStatus = $election->getQualifiedStatus( $params );
		if ( !$qualStatus->isOK() ) {
			return $qualStatus;
		}
		$voter = $this->getVoter( $params );

		return Status::newGood( $voter );
	}

	/**
	 * Get voter parameters for a local User object.
	 * @param User $user
	 * @return array
	 */
	public function getUserParams( $user ) {
		global $wgServer;
		$params = [
			'name' => $user->getName(),
			'type' => 'local',
			'domain' => preg_replace( '!.*/(.*)$!', '$1', $wgServer ),
			'url' => $user->getUserPage()->getCanonicalURL(),
			'properties' => [
				'wiki' => wfWikiID(),
				'blocked' => $user->isBlocked(),
				'central-block-count' => $this->getCentralBlockCount( $user ),
				'edit-count' => $user->getEditCount(),
				'bot' => $user->isAllowed( 'bot' ),
				'language' => $user->getOption( 'language' ),
				'groups' => $user->getGroups(),
				'lists' => $this->getLists( $user ),
				'central-lists' => $this->getCentralLists( $user ),
				'registration' => $user->getRegistration(),
			]
		];

		Hooks::run(
			'SecurePoll_GetUserParams',
			[
				$this,
				$user,
				&$params
			]
		);

		return $params;
	}

	/**
	 * Get the lists a given local user belongs to
	 * @param User $user
	 * @return array
	 */
	public function getLists( $user ) {
		$dbr = $this->context->getDB();
		$res = $dbr->select(
			'securepoll_lists',
			[ 'li_name' ],
			[ 'li_member' => $user->getId() ],
			__METHOD__
		);
		$lists = [];
		foreach ( $res as $row ) {
			$lists[] = $row->li_name;
		}

		return $lists;
	}

	/**
	 * Get the CentralAuth lists the user belongs to
	 * @param User $user
	 * @return array
	 */
	public function getCentralLists( $user ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return [];
		}
		$centralUser = CentralAuthUser::getInstance( $user );
		if ( !$centralUser->isAttached() ) {
			return [];
		}
		$dbc = CentralAuthUtils::getCentralReplicaDB();
		$res = $dbc->select(
			'securepoll_lists',
			[ 'li_name' ],
			[ 'li_member' => $centralUser->getId() ],
			__METHOD__
		);
		$lists = [];
		foreach ( $res as $row ) {
			$lists[] = $row->li_name;
		}

		return $lists;
	}

	/**
	 * Checks how many central wikis the user is blocked on
	 * @param User $user
	 * @return int the number of wikis the user is blocked on.
	 */
	public function getCentralBlockCount( $user ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return 0;
		}

		$centralUser = new CentralAuthUser( $user->getName() );

		$attached = $centralUser->queryAttached();
		$blockCount = 0;

		foreach ( $attached as $data ) {
			if ( $data['blocked'] ) {
				$blockCount++;
			}
		}

		return $blockCount;
	}
}
