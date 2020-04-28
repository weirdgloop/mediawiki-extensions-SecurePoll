<?php

namespace MediaWiki\Extensions\SecurePoll\Pages;

use Language;
use MediaWiki\Extensions\SecurePoll\Context;
use MediaWiki\Extensions\SecurePoll\Entities\Election;
use MediaWiki\Extensions\SecurePoll\SpecialSecurePoll;
use MediaWiki\Extensions\SecurePoll\User\Auth;
use MediaWiki\Extensions\SecurePoll\User\Voter;
use Message;
use User;

/**
 * Parent class for Special:SecurePoll subpages.
 */
abstract class ActionPage {
	/** @var SpecialSecurePoll */
	public $specialPage;
	/** @var Election */
	public $election;
	/** @var Auth */
	public $auth;
	/** @var User */
	public $user;
	/** @var Context */
	public $context;

	/**
	 * Constructor.
	 * @param SpecialSecurePoll $specialPage
	 */
	public function __construct( $specialPage ) {
		$this->specialPage = $specialPage;
		$this->context = $specialPage->sp_context;
	}

	/**
	 * Execute the subpage.
	 * @param array $params Array of subpage parameters.
	 */
	abstract public function execute( $params );

	/**
	 * Internal utility function for initializing the global entity language
	 * fallback sequence.
	 * @param Voter|User $user
	 * @param Election $election
	 */
	public function initLanguage( $user, $election ) {
		$uselang = $this->specialPage->getRequest()->getVal( 'uselang' );
		if ( $uselang !== null ) {
			$userLang = $uselang;
		} elseif ( $user instanceof Voter ) {
			$userLang = $user->getLanguage();
		} else {
			$userLang = $user->getOption( 'language' );
		}

		$languages = array_merge(
			[ $userLang ],
			Language::getFallbacksFor( $userLang )
		);

		if ( !in_array( $election->getLanguage(), $languages ) ) {
			$languages[] = $election->getLanguage();
		}
		if ( !in_array( 'en', $languages ) ) {
			$languages[] = 'en';
		}
		$this->context->setLanguages( $languages );
	}

	/**
	 * Relay for SpecialPage::msg
	 * @param string ...$args
	 * @return Message
	 */
	protected function msg( ...$args ) {
		return call_user_func_array(
			[
				$this->specialPage,
				'msg'
			],
			$args
		);
	}
}
