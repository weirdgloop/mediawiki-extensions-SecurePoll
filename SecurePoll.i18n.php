<?php
/**
 * This is a backwards-compatibility shim, generated by:
 * https://git.wikimedia.org/blob/mediawiki%2Fcore.git/HEAD/maintenance%2FgenerateJsonI18n.php
 *
 * Beginning with MediaWiki 1.23, translation strings are stored in json files,
 * and the EXTENSION.i18n.php file only exists to provide compatibility with
 * older releases of MediaWiki. For more information about this migration, see:
 * https://www.mediawiki.org/wiki/Requests_for_comment/Localisation_format
 *
 * This shim maintains compatibility back to MediaWiki 1.17.
 */
$messages = [];
if ( !function_exists( 'wfJsonI18nShim310d2110c88636f7' ) ) {
	function wfJsonI18nShim310d2110c88636f7( $cache, $code, &$cachedData ) {
		$codeSequence = array_merge( [ $code ], $cachedData['fallbackSequence'] );
		foreach ( $codeSequence as $csCode ) {
			$fileName = __DIR__ . "/i18n/$csCode.json";
			if ( is_readable( $fileName ) ) {
				$data = FormatJson::decode( file_get_contents( $fileName ), true );
				foreach ( array_keys( $data ) as $key ) {
					if ( $key === '' || $key[0] === '@' ) {
						unset( $data[$key] );
					}
				}
				$cachedData['messages'] = array_merge( $data, $cachedData['messages'] );
			}

			$cachedData['deps'][] = new FileDependency( $fileName );
		}
		return true;
	}

	$GLOBALS['wgHooks']['LocalisationCacheRecache'][] = 'wfJsonI18nShim310d2110c88636f7';
}
