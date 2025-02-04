<?php

namespace MediaWiki\Extensions\SecurePoll\Jobs;

use Job;
use MediaWiki\Extensions\SecurePoll\Context;

/**
 * Log whenever an admin looks at Special:SecurePoll/list/{id}
 */
class LogAdminActionJob extends Job {
	/**
	 * @inheritDoc
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'securePollLogAdminAction', $title, $params );
	}

	/**
	 * @return bool
	 */
	public function run() {
		$context = new Context();
		$dbw = $context->getDB( DB_PRIMARY );
		$fields = $this->params['fields'];
		$fields['spl_timestamp'] = $dbw->timestamp( time() );
		$dbw->insert( 'securepoll_log', $fields, __METHOD__ );
		return true;
	}
}
