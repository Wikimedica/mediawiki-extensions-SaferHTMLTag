<?php
/**
 * SaferHTMLTag is an extension that prevents unauthorized users from editing
 * pages where the <html> tag is defined when raw html ($wgRawHtml) is enabled.
 *
 * https://www.mediawiki.org/wiki/Extension:SaferHTMLTag
 * 
 * @file
 * @author Antoine Mercier-Linteau
 * @license GPL-2
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'SaferHTMLTag' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['SaferHTMLTag'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for SaferHTMLTag extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the OpenGraphMeta extension requires MediaWiki 1.25+' );
}

if ( !defined( 'MEDIAWIKI' ) ) {
	die();
}