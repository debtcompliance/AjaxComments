<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'FooBar' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['FooBar'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['FooBar'] = __DIR__ . '/FooBar.alias.php';
	wfWarn(
		'Deprecated PHP entry point used for FooBar extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die(
		'<b>Fatal error:</b> This version of the FooBar extension requires MediaWiki 1.25+, ' .
		'either <a href="https://mediawiki.org/wiki/Download">upgrade your MediaWiki</a> or download the extension code from the ' .
		'<a href="https://github.com/OrganicDesign/extensions/tree/MediaWiki-1.24/MediaWiki">1.24 branch</a>.'
	);
}
