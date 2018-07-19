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

/**
 * SaferHTMLTag extension class.
 */
class SaferHTMLTag {
	
	private static $_data = [];

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
		
	public static function onTitleGetEditNotices($title, $oldid, &$notices) {
		global $wgRawHtml;
		
		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}
		
		if(isset(self::$_data['saferthtmltag-pagecontent'])) { // If the article does not yet exist or is being modified, we should have gotten it's content from onEditPage.
			$content = self::$_data['saferthtmltag-pagecontent'];
		} else if($title->exists()) {
			$content = WikiPage::factory($title)->getContent()->getNativeData();
		} else {
			return true;
		}
		
		if(!static::contentHasHTMLTags($content)) {
			return true;
		}
		
		if(static::checkUserPermissions(\RequestContext::getMain()->getUser())) { // The user is allowed to edit HTML tags.
			
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
		
		$notices[] = wfMessage( 'saferhtmltag-html-detected-in-edit-page' )->parse();
		
		return true;
	}
	
	public static function onEditFilterMergedContent( $context, $content, $status, $summary, $user, $minoredit ) { 
		
		global $wgRawHtml;
		
		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}
		
		if(!static::contentHasHTMLTags($content->getNativeData())) {
			return true;
		}
		
		if(static::checkUserPermissions($user)) { // Must be after the check because the user may need to be authentified again.	
			return true; // The user can work with HTML tags.
		}
		
		$status->error(wfMessage( 'saferhtmltag-denied-edit' ));
		$status->setOk(false);
		
		return true; // Continue user interaction, the error has been shown in the status object.
	}
	
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		
		global $wgRawHtml;
		static $resultCache = [];
		
		if(!$wgRawHtml) {
			return true; // Raw HTML has been disabled.
		}
		
		if($action != 'edit' || !$title->exists()) {
			return true; 
		}
		else if (!$title->isWikitextPage()) {
			return true; // HTML tags only matter on wikitext pages.
		}
		
		$key = $title->getPrefixedDBKey();
		
		if(!isset($resultCache[$key])) { // If we haven't seen this title before.
			
			if(static::checkUserPermissions($user)) {
				$resultCache[$key] = true; // The user can work with HTML tags.
			}
			else {
				$resultCache[$key] = !static::contentHasHTMLTags(WikiPage::factory($title)->getContent()->getNativeData());
			}
		}		
		
		if(!$resultCache[$key]) { // The user cannot edit this page.
			$result = wfMessage( 'saferhtmltag-denied-edit' );
			return false;
		}
		
		return true; // The text does not contain HTML tags or the user has been permitted to edit it.
	}
	
	public static function contentHasHTMLTags( $content ){
		return $content && strpos($content, '<html>') !== false;
	}
	
	public static function checkUserPermissions($user) {
		
		global $wgSaferHTMLTagEditorGroup;
		
		$groups = ['sysop']; // sysops can always work with HTML tags.
		
		if($wgSaferHTMLTagEditorGroup) { // If there is an editor group that can work with HTML tags.
			$groups[] = $wgSaferHTMLTagEditorGroup;
		}
		
		return !$user->isAnon() && !empty(array_intersect($groups, $user->getGroups())); 
	}
	
}
