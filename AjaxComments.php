<?php
/**
 * AjaxComments extension - Add comments to the end of the page that can be edited, deleted or replied to instead of using the talk pages
 *
 * @file
 * @ingroup Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/nad User:Nad]
 * @copyright © 2012-2015 Aran Dunkley
 * @licence GNU General Public Licence 2.0 or later
 * 
 * Version 2.0 (started on 2015-05-01) stores comments in the DB and leaves the talk page alone, comment rendering is done via JS
 * 
 */
if( !defined( 'MEDIAWIKI' ) ) die( 'Not an entry point.' );

define( 'AJAXCOMMENTS_VERSION', '2.0.0, 2015-05-01' );
define( 'AJAXCOMMENTS_TABLE', 'ajaxcomments' );
define( 'AJAXCOMMENTS_DATATYPE_COMMENT', 1 );
define( 'AJAXCOMMENTS_DATATYPE_LIKE', 2 );

$wgAjaxCommentsLikeDislike = true;        // add a like/dislike link to each comment
$wgAjaxCommentsAvatars = true;            // use the gravatar service for users icons
$wgAjaxCommentsPollServer = 0;            // poll the server to see if any changes to comments have been made and update if so

$wgAjaxExportList[] = 'AjaxComments::ajax';

$wgExtensionCredits['other'][] = array(
	'path'        => __FILE__,
	'name'        => 'AjaxComments',
	'author'      => '[http://www.organicdesign.co.nz/User:Nad Aran Dunkley]',
	'url'         => 'http://www.mediawiki.org/wiki/Extension:AjaxComments',
	'description' => 'Add comments to the end of the page that can be edited, deleted or replied to instead of using the talk pages',
	'version'     => AJAXCOMMENTS_VERSION
);

$wgExtensionMessagesFiles['AjaxComments'] = __DIR__ . '/AjaxComments.i18n.php';

class AjaxComments {

	private $comments = array();
	private $talk = false;
	private $canComment = false;

	function __construct() {
		global $wgExtensionFunctions;
		$wgExtensionFunctions[] = array( $this, 'setup' );
	}

	public function setup() {
		global $wgOut, $wgResourceModules, $wgAjaxCommentsPollServer, $wgExtensionAssetsPath, $wgUser;

		// Create a hook to allow external condition for whether there should be comments shown
		$title = array_key_exists( 'title', $_GET ) ? Title::newFromText( $_GET['title'] ) : false;
		if( !array_key_exists( 'action', $_REQUEST ) && self::checkTitle( $title ) ) Hooks::register( 'BeforePageDisplay', $this );
		else $wgAjaxCommentsPollServer = -1;

		// Create a hook to allow external condition for whether comments can be added or replied to (default is just user logged in)
		$this->canComment = $wgUser->isLoggedIn();
		Hooks::run( 'AjaxCommentsCheckWritable', array( $title, &$this->canComment ) );

		// Redirect talk pages with AjaxComments to the comments
		if( is_object( $title ) && $title->getNamespace() > 0 && ($title->getNamespace()&1) ) {
			$title = Title::newFromText( $title->getText(), $title->getNamespace() - 1 );
			$ret = true;
			Hooks::run( 'AjaxCommentsCheckTitle', array( $title, &$ret ) );
			if( $ret ) {
				$wgOut->disable();
				wfResetOutputBuffers();
				$url = $title->getLocalUrl();
				header( "Location: $url#ajaxcomments" );
				exit;
			}
		}

		// Set up JavaScript and CSS resources
		$path = $wgExtensionAssetsPath . '/' . basename( __DIR__ );
		$wgResourceModules['ext.ajaxcomments'] = array(
			'scripts'        => array( 'ajaxcomments.js' ),
			'dependencies'   => array( 'jquery.ui.dialog' ),
			'localBasePath'  => __DIR__,
			'remoteBasePath' => $path,
			'messages' => array(
				'ajaxcomments-add',
				'ajaxcomments-edit',
				'ajaxcomments-reply',
				'ajaxcomments-del',
				'ajaxcomments-none',
				'ajaxcomments-anon',
				'ajaxcomments-confirmdel',
				'ajaxcomments-confirm',
				'ajaxcomments-yes',
				'ajaxcomments-post',
				'ajaxcomments-cancel',
			),
		);
		$wgOut->addModules( 'ext.ajaxcomments' );
		$wgOut->addStyle( "$path/ajaxcomments.css" );
		$wgOut->addJsConfigVars( 'ajaxCommentsPollServer', $wgAjaxCommentsPollServer );
		$wgOut->addJsConfigVars( 'ajaxCommentsCanComment', $this->canComment );
	}

	/**
	 * Allow other extensions to check if a title has comments
	 */
	public static function checkTitle( $title ) {
		$ret = true;
		if( $title && !is_object( $title ) ) $title = Title::newFromText( $title );
		if( !is_object( $title ) || $title->getArticleID() == 0 || $title->isRedirect() || ($title->getNamespace()&1) ) $ret = false;
		else Hooks::run( 'AjaxCommentsCheckTitle', array( $title, &$ret ) );
		return $ret;
	}

	/**
	 * Render a name at the end of the page so redirected talk pages can go there before ajax loads the content
	 */
	public function onBeforePageDisplay( $out, $skin ) {
		$out->addHtml( '<h2>' . wfMessage( 'ajaxcomments-heading' )->text() . '</h2><a id="ajaxcomments-name" name="ajaxcomments"></a>' );
		return true;
	}

	/**
	 * Process the Ajax requests
	 */
	public static function ajax( $command, $page, $id = 0, $data  = '' ) {
		global $wgOut, $wgRequest;
		header( 'Content-Type: application/json' );
		$data = array();

		// Perform the command on the talk content
		switch( $command ) {

			case 'add':
				$data = self::add( $text, $page );
			break;

			case 'reply':
				$data = self::reply( $text, $page, $id );
			break;

			case 'edit':
				$data = self::edit( $text, $page, $id );
			break;

			case 'del':
				$data = self::delete( $id ) === true ? array( 'success' => 1 ) : array( 'error' => $data );
			break;

			case 'like':
				$data = array( 'message' => self::like( $id, $text ) );
			break;

			case 'get':
				$data = self::getComments( $page, $id );
			break;

			default:
				$data['error'] = "unknown action";
		}

		return json_encode( $data );
	}

	/**
	 * Add a new comment to the data structure, return it's insert ID
	 */
	private static function add( $text, $page ) {
		global $wgUser;
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( AJAXCOMMENTS_TABLE, array(
			'ac_type' => AJAXCOMMENTS_DATATYPE_COMMENT,
			'ac_user' => $wgUser->getId(),
			'ac_page' => $page,
			'ac_time' => time(),
			'ac_data' => $text,
		) );
		return $dbw->insertId();
	}

	/**
	 * Edit an existing comment in the data structure
	 */
	private static function edit( $id, $text ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( AJAXCOMMENTS_TABLE, array( 'ac_data' => $text ), array( 'ac_id' => $id ) );
	}

	/**
	 * Add a new comment as a reply to an existing comment in the data structure
	 * - return the new comment in client-ready format
	 */
	private static function reply( $text, $page, $parent ) {
		global $wgUser;
		$uid = $wgUser->getId();
		$ts = time();
		$dbw = wfGetDB( DB_MASTER );
		$row = array(
			'ac_type'   => AJAXCOMMENTS_DATATYPE_COMMENT,
			'ac_parent' => $parent,
			'ac_page'   => $page,
			'ac_user'   => $uid,
			'ac_time'   => $ts,
			'ac_data'   => $text,
		);
		$dbw->insert( AJAXCOMMENTS_TABLE, $row );
		return getComment( $row );
	}

	/**
	 * Delete a comment amd all its replies from the data structure
	 */
	private static function delete( $id ) {
		$dbw = wfGetDB( DB_MASTER );

		// Die if the comment is not owned by this user unless sysop
		if( !in_array( 'sysop', $wgUser->getEffectiveGroups() ) ) {
			$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_user', array( 'ac_id' => $id ) );
			if( $uid->ac_user != $wgUser->getId() ) return "Only sysops can delete someone else's comment";
		}

		// Delete this comment and all child comments and likes
		$children = self::getChildren( $id, $id );
		$children = implode( ',', $children );
		return $dbw->delete( AJAXCOMMENTS_TABLE, "ac_id IN ($children)" );
	}

	/**
	 * Like/unlike a comment returning a message describing the change
	 */
	private static function like( $id, $val ) {
		global $wgUser;
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_user', array( 'ac_id' => $id ) );
		$uid = $wgUser->getId();
		$name = $wgUser->getName();
		$cname = User::newFromId( $row->ac_user )->getName();

		// Get this users like value for this comment if any
		$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_data, ac_id', array(
			'ac_type'   => AJAXCOMMENTS_DATATYPE_LIKE,
			'ac_parent' => $id,
			'ac_user'   => $uid
		) );
		$like = $row ? $row->ac_page : 0;
		$lid = $row ? $row->ac_id : 0;

		// Remove the user if they now nolonger like or dislike, otherwise update their value
		if( $like + $val == 0 ) $dbw->delete( AJAXCOMMENTS_TABLE, array( 'ac_id' => $lid ) );
		else $dbw->update( AJAXCOMMENTS_TABLE, array( 'ac_data' => $like + $val ), array( 'ac_id' => $lid ) );

		// Return a message string about the update
		if( $val > 0 ) {
			if( $like < 0 ) return wfMessage( 'ajaxcomments-undislike', $name, $cname )->text();
			else return wfMessage( 'ajaxcomments-like', $name, $cname )->text();
		} else {
			if( $like > 0 ) return wfMessage( 'ajaxcomments-unlike', $name, $cname )->text();
			else return wfMessage( 'ajaxcomments-dislike', $name, $cname )->text();
		}
	}

	/**
	 * Get all child comments and likes of the passed id (i.e. replies and replies of replies etc)
	 */
	private static function getChildren( $id, $children = array() ) {
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( AJAXCOMMENTS_TABLE, 'ac_id', array( 'ac_parent' => $id ) );
		foreach( $res as $row ) $children = $this->children( $row->ac_id, $children );
		return $children;
	}

	/**
	 * Return the passed comment in client-ready format
	 * - row can be a comment id or a db row structure
	 */
	private static function getComment( $row ) {
		$likes = $dislikes = array();

		// Read the row from DB if id supplied
		if( is_numeric( $row ) ) {
			$id = $row;
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow( AJAXCOMMENTS_TABLE, '*', array( 'ac_id' => $id ) );

			// Get the like data for this comment
			if( $row->ac_type == AJAXCOMMENTS_DATATYPE_COMMENT ) {
				$res = $dbw->select(
					AJAXCOMMENTS_TABLE,
					'ac_user,ac_data',
					array( 'ac_type' => AJAXCOMMENTS_DATATYPE_LIKE, 'ac_parent' => $id ),
					__METHOD__,
					array( 'ORDER BY' => 'ac_time' )
				);
				foreach( $res as $row ) {
					$name = User::newFromId( $row->ac_user )->getName();
					$row->ac_data > 0 ? $likes[] = $name : $dislikes[] = $name;
				}
			}
		}

		// Convert to client-ready format
		return array(
			'id'      => $row->ac_id,
			'parent'  => $row->ac_parent,
			'user'    => $row->ac_user,
			'name'    => User::newFromId( $row->ac_user )->getName(),
			'time'    => $row->ac_time,
			'text'    => $row->ac_data,
			'html'    => self::parse( $row->ac_data, $row->ac_page ),
			'like'    => $likes,
			'dislike' => $dislikes,
		);
	}

	/**
	 * Get comments for passed page greater than passed timestamp in a client-ready format
	 */
	private static function getComments( $page, $ts = false ) {

		// Query DB for all comments and likes for the page (after ts if supplied)
		$cond = array( 'ac_page' => $page );
		if( $ts ) $cond[] = "ac_time > $ts";
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			AJAXCOMMENTS_TABLE,
			'*',
			$cond,
			__METHOD__,
			array( 'ORDER BY' => 'ac_time' )
		);

		// Get rows changing user id's to names and separating into lists of comments and likes
		$comments = $likes = array();
		foreach( $res as $row ) {
			$item = self::getComment( $row );
			$row->ac_type == AJAXCOMMENTS_DATATYPE_COMMENT ? $comments[] = $item : $likes[] = $item;
		}

		// Put the like data into the associated comment data
		foreach( $likes as $id => $item ) {
			$item['data'] > 0 ? $comments[$item['parent']]['like'][] = $item['name'] : $comments[$item['parent']]['dislike'][] = $item['name'];
		}

		return $comments;
	}

	/**
	 * Parse wikitext
	 */
	private static function parse( $text, $page ) {
		global $wgUser;
		$title = Title::newFromId( $page );
		$parser = new Parser;
		$options = ParserOptions::newFromUser( $wgUser );
		return $parser->parse( $text, $title, $options, true, true )->getText();
	}
}

new AjaxComments();
