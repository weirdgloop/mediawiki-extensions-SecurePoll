<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'SecurePoll' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['SecurePoll'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['SecurePollAlias'] = __DIR__ . '/SecurePoll.alias.php';
	/*wfWarn(
		'Deprecated PHP entry point used for SecurePoll extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);*/
	return;
} else {
	die( 'This version of the SecurePoll extension requires MediaWiki 1.27+' );
}
