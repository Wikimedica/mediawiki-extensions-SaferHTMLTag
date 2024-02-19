<?php
/**
 * SaferHTMLTag extension main class
 *
 * @file
 * @ingroup Extensions
 * @author Antoine Mercier-Linteau
 * @license GPL-2
 */

/* Covered user cases:
 * NOTE: should be tested with the visuel editor as well.
 * -User creates a new page, adds an HTML tag and previews.
 * -User creates a new page, adds an HTML tag and saves.
 * -User edits a page with no html tags, adds an HTML tag and previews.
 * -User edits a page with no html tags, adds an HTML tag and saves.
 * -User edits a page containing html tags and previews.
 * -User edits a page containing html tags and saves.
 */

namespace MediaWiki\Extension\SaferHTMLTag;

use EditPage;
use OutputPage;
use Title;
use WikiPage;
use RequestContext;
use Parser;

/**
 * SaferHTMLTag extension class.
 */
class SaferHTMLTag {

	const PERMISSION = 'edit-html';

	/**
	 * Stores page content between events.
	 * */
	private static $_data = [];

	/**
	 * Event called just before the preview and edit form are rendered.
	 * @param EditPage $editPage the current EditPage object.
	 * @param OutputPage $output the OutputPage
	 * @return bool if the event handling should proceed
	 * */
	public static function onEditPage_showEditForm_initial( EditPage &$editPage, OutputPage &$output ) {

		global $wgRawHtml;

		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}

		if(!static::contentHasHTMLTags($editPage->textbox1)) {
			return true;
		}

		self::$_data['saferthtmltag-pagecontent'] = $editPage->textbox1;

		return true;
	}

	/**
	 * Hardcode a safeguard to prevent articles from being saved by unthorized users.
	 * This prevent obfsucated ways of defining the html tag that have not readily passed contentHasHtmlTag() to get through.
	 * 
	 * @param RenderedRevision $renderedRevision representing the planned revision
	 * @param UserIdentity $user the user saving the article
	 * @param CommentStoreComment $summary object containing the edit comment
	 * @param int $flags: All EDIT_… flags (including EDIT_MINOR) as an integer number
	 * @param Status $hookStatus: if the hook is aborted, error code can be placed into this Status, e.g. $hookStatus->fatal( 'disallowed-by-some-extension' )
	 * @return false to abort saving the page.
	 * 
	 */
	public static function onMultiContentSave( \MediaWiki\Revision\RenderedRevision $renderedRevision, \MediaWiki\User\UserIdentity $user, \MediaWiki\CommentStore\CommentStoreComment $summary, $flags, $hookStatus ) { 
		global $wgRawHtml;

		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}

		if(self::checkUserPermissions($user)) {
			return true; // Saving can proceed.
		}

		//Create a diffrent parser to avoid interfering with the first one.
		$parser = \MediaWiki\MediaWikiServices::getInstance()->getParserFactory()->create();

		$options = \ParserOptions::newFromContext( RequestContext::getMain() );

		$page = $renderedRevision->getRevision()->getPage();

		$parser->startExternalParse( $page, $options, \Parser::OT_HTML );
		$parser->setHook('html', [__CLASS__, 'htmlOverride']); // Override html tag hook to detect it's presence.
		$parser->setFunctionHook('if', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override if tag hook.
		$parser->setFunctionHook('ifeq', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override ifdeq tag hook.
		$parser->setFunctionHook('iferror', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override iferror tag hook.
		$parser->setFunctionHook('ifexpr', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override ifexpr tag hook.
		$parser->setFunctionHook('switch', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override switch tag hook.
		$parser->setFunctionHook('ifexist', [__CLASS__, 'conditionOverride'], Parser::SFH_OBJECT_ARGS); // Override ifexist tag hook.
		// $parser->clearTagHooks(); // cannot be used because this would clear the #tag hook which could be used to call the html tag.

		$content = $renderedRevision->getRevision()->getContent( \MediaWiki\Revision\SlotRecord::MAIN );
		if ( !( $content instanceof \TextContent ) ) {
			return true; // Not implemented on non-text contents.
		}
		/*
		Parse the text, relying on MediaWiki's ability to detect html tags. Should there be one, the overriden html hook will be called.

		This allows the detection of obfuscated ways of defining an html tag such as:
		* {{#tag:h{{ns:0}}tml|<script>alert(1)</script>}}
		* {{#ifexpr:{{safesubt:#time:U}}+20 > {{#time:U}}|{{#tag:h{{ns:0}}tml|<script>alert(1)</script>}}}} // Will output html tag 20s after the page has been saved.
		*/
		//$text = $parser->recursiveTagParseFully( $content->getText() );
		$parser->parse($content->getText(), $page, $options);

		if(!self::$_usesHtmlTag) {
			return true;
		}

		// There was an html call in the wikicode.

		self::$_usesHtmlTag = false; // Reset in case that hook gets called again.

		$hookStatus->fatal('saferhtmltag-html-detected-in-edit-page');

		return false; // Abort saving.
	}

	/** @param bool if a call to the html tag was made during parsing. */
	private static $_usesHtmlTag = false;

	/**
	 * Parser hook override for checking the presence of the HTML tag.
	 * 
	 * @param ?string $content
	 * @param array $attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function htmlOverride(?string $content, array $attributes, $parser, $frame) {
		
		if($parser->getTitle()->getFullText() != $frame->getTitle()->getFullText())	{
			/* If the titles do not match, this means the expression being expanded is in a different page. 
			 * Having html tags in templates is fine because we can assume they were created by users with the required rights. */
			return '';
		}
		
		self::$_usesHtmlTag = true; // A call to the html tag was made.
		return '';
	}

	/**
	 * Parser hook override conditions type parser hooks. This allows call such as
	 * {{#ifexpr:{{safesubt:#time:U}}+20 > {{#time:U}}|{{#tag:h{{ns:0}}tml|<script>alert(1)</script>}}}}
	 * to be parser no matter what trick a hacker uses to prevent detection of the html tag.
	 * 
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param PPNode[] $args
	 * @return string
	 */
	public static function conditionOverride($parser, $frame, array $args) {
		
		if($parser->getTitle()->getFullText() != $frame->getTitle()->getFullText())	{
			/* If the titles do not match, this means the expression being expanded is in a different page. 
			 * Having html tags in templates is fine because we can assume they were created by users with the required rights. */
			return '';
		}
		
		$result = '';

		foreach($args as $arg) { $result .= ';'.$frame->expand($arg); } // Expand all parts of a condition.

		return $result; // Pass the arguments directly so they get interpreted by the parser.
	}


	/**
	 * Event called when the edit notices for an article are rendered.
	 * @param Title $title the title of the article
	 * @param int $oldid the id of the version of the article being modified
	 * @param array $notices the notices in wikitext that are to be displayed
	 * @return bool if the event handling should proceed
	 * */
	public static function onTitleGetEditNotices($title, $oldid, &$notices) {
		global $wgRawHtml;

		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}

		if(isset(self::$_data['saferthtmltag-pagecontent'])) { // If the article does not yet exist or is being modified, we should have gotten it's content from onEditPage.
			$content = self::$_data['saferthtmltag-pagecontent'];
		} else if($title->exists()) {
			$content = static::getPageContent( $title ); // Get the article's content from the database.
			$content = $content ? $content->getText() : '';  // Make sure $content is not null before calling getText() (to account for rare cases of revisions gone missing).
		} else {
			return true;
		}

		if(!static::contentHasHTMLTags($content)) {
			return true; // The article does not contain any HTML tags.
		}

		if(static::checkUserPermissions(RequestContext::getMain()->getUser())) { // The user is allowed to edit HTML tags.

			/* Ideally, saving pages with HTML tags in them should be considered a sensitive operation, but since
			 * needing the user to reauthenticate would seriously mess up with the form data or with the visual editor,
			 * this check cannot be done for now. */
			/*$status = AuthManager::singleton()->securitySensitiveOperationStatus('edit-page-with-html-tag');

			switch($status)
			{
				case AuthManager::SEC_OK:
					return true; // The user is cleared to do a security sensitive operation.
				case AuthManager::SEC_REAUTH: // The user needs to reauthenticate.
					$request = \RequestContext::getMain()->getRequest();

					$query = [
							'returnto' => $title->getPrefixedDBkey(),
							'returntoquery' => wfArrayToCgi( array_diff_key( $request->getQueryValues(),
									[ 'title' => true ] ) ),
							'force' => 'edit-page-with-html-tag',
					];
					$url = \SpecialPage::getTitleFor( 'Userlogin' )->getFullURL( $query, false, PROTO_HTTPS );

					\RequestContext::getMain()->getOutput()->redirect( $url );
					return true;
				case AuthManager::SEC_FAIL:
				default:
			}*/

			return true;
		}

		// Add an edit notice informing the user that raw HTML has been detected in the page an he can't edit it.
		$notices[] = wfMessage( 'saferhtmltag-html-detected-in-edit-page' )->parse();

		return true;
	}

	/**
	 * Post section-merge edit filter.
	 * @param IContextSource $context object implementing the IContextSource interface.
	 * @param Content $content content of the edit box, as a Content objec
	 * @param Status $status Status object to represent errors, etc.
	 * @param string $summary Edit summary for page
	 * @param User $user the User object representing the user whois performing the edit
	 * @param bool $minoredit whether the edit was marked as minor by the user.
	 * @return bool false to abort the edit or true to continue
	 * */
	public static function onEditFilterMergedContent( $context, $content, $status, $summary, $user, $minoredit ) {

		global $wgRawHtml;

		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}

		if(!static::contentHasHTMLTags($content->getText())) {
			return true; // No HTML was detected in the page, proceed as usual.
		}

		if(static::checkUserPermissions($user)) { // Must be after the check because the user may need to be authentified again.
			return true; // The user can work with HTML tags.
		}

		// Inform the user he cannot edit the page.
		$status->error(wfMessage( 'saferhtmltag-html-detected-in-edit-page'));
		$status->setOk(false); // The edit cannot proceed.

		return true; // Continue user interaction, the error has been shown in the status object.
	}

	/**
	 * Add a permissions error when permissions errors are checked for.
	 * @param Title $title Title object being checked against
	 * @param User $user Current user object
	 * @param string $action Action being checked
	 * @param array|string &$result User permissions error to add. If none, return true. $result can be returned as a single error message key (string), or an array of error message keys when multiple messages are needed (although it seems to take an array as one message key with parameters?).
	 * @return bool if the user has permissions to do the action
	 * */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {

		if(defined('MW_ENTRY_POINT') && MW_ENTRY_POINT == 'cli' ) {
			return true; // Do not check permissions in command line mode.
		}
		
		global $wgRawHtml;
		static $resultCache = []; // Caches the permission results for a user.

		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}

		if($action != 'edit' || !$title->exists()) {
			return true; // User can view pages and create new pages.
		} else if (!$title->isWikitextPage()) {
			return true; // HTML tags only matter on wikitext pages.
		}

		$key = $title->getPrefixedDBKey();

		if(!isset($resultCache[$key])) { // If we haven't seen this title before.

			if(static::checkUserPermissions($user)) {
				$resultCache[$key] = true; // The user can work with HTML tags.
			}
			else {
				$resultCache[$key] = !static::contentHasHTMLTags( static::getPageContent( $title )->getText() );
			}
		}

		if(!$resultCache[$key]) { // The user cannot edit this page.
			$result = wfMessage( 'saferhtmltag-html-detected-in-edit-page');
			return false;
		}

		return true; // The text does not contain HTML tags or the user has been permitted to edit it.
	}

	/**
	 * Parse a string for the presence of HTML tags. This does a simple string bases check to make things quick.
	 * onMultiContentSave() does full reparse of the text to prevent obfuscated ways of defining the tag to get through.
	 * 
	 * @param string $content the text to parse
	 * @return bool true of $content has HTML tags
	 * */
	public static function contentHasHTMLTags( $content ){

		if(!$content) { // Content is empty.
			return false;
		}

		// The tag parser function can also be used to output HTML.
		// TODO: replace with a regex.
		if(strpos($content, '#tag:') !== false){
			$content = str_replace(' ', '', $content); // Remove all spaces.

			if(strpos($content, '{{#tag:html') !== false) {
				return true; // The HTML tag has been detected.
			}
		}

		return strpos($content, '<html>') !== false;
	}

	/**
	 * Check if a user can edit pages with HTML tags.
	 * @param User $user the user to check permissions for
	 * @return bool true if the user is allowed.
	 * */
	public static function checkUserPermissions($user) {
	    return !$user->isAnon() &&
	       \MediaWiki\MediaWikiServices::getInstance()->getPermissionManager()->userHasRight($user, self::PERMISSION);
	}

	/** Obtain a page's content.
	 *  Workaround for MediaWiki 1.36+ which deprecated Wikipage::factory.
	 * @param string $title the title of the page
	 * @return Content content object of the page
	 */
	private static function getPageContent( $title ) {
		if ( method_exists( \MediaWiki\MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			$wikiPageFactory = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory();
			$page = $wikiPageFactory->newFromTitle( $title );
		} else {
			$page = WikiPage::factory( $title );
		}
		return $page->getContent();
	}
}
