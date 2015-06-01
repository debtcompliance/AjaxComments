<?php
/**
 * AjaxComments extension - Add comments to the end of the page that can be edited, deleted or replied to instead of using the talk pages
 *
 * @file
 * @ingroup Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/aran Aran Dunkley]
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

// Add a new log type
$wgLogTypes[]                       = 'ajaxcomments';
$wgLogNames['ajaxcomments']         = 'ajaxcomments-logpage';
$wgLogHeaders['ajaxcomments']       = 'ajaxcomments-logpagetext';
$wgLogActions['ajaxcomments/add']   = 'ajaxcomments-add-desc';
$wgLogActions['ajaxcomments/reply'] = 'ajaxcomments-reply-desc';
$wgLogActions['ajaxcomments/edit']  = 'ajaxcomments-edit-desc';
$wgLogActions['ajaxcomments/del']   = 'ajaxcomments-del-desc';

$wgAjaxExportList[] = 'AjaxComments::ajax';

$wgExtensionCredits['other'][] = array(
	'path'        => __FILE__,
	'name'        => 'AjaxComments',
	'author'      => '[http://www.organicdesign.co.nz/aran Aran Dunkley]',
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
		global $wgOut, $wgResourceModules, $wgAjaxCommentsPollServer, $wgAjaxCommentsLikeDislike, $wgExtensionAssetsPath, $wgUser;

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
				'ajaxcomments-sig',
				'ajaxcomments-confirmdel',
				'ajaxcomments-confirm',
				'ajaxcomments-yes',
				'ajaxcomments-post',
				'ajaxcomments-cancel',
				'ajaxcomments-nolike',
				'ajaxcomments-onelike',
				'ajaxcomments-manylike',
				'ajaxcomments-nodislike',
				'ajaxcomments-onedislike',
				'ajaxcomments-manydislike',
			),
		);
		$wgOut->addModules( 'ext.ajaxcomments' );
		$wgOut->addStyle( "$path/ajaxcomments.css" );
		$wgOut->addJsConfigVars( 'ajaxCommentsPollServer', $wgAjaxCommentsPollServer );
		$wgOut->addJsConfigVars( 'ajaxCommentsCanComment', $this->canComment );
		$wgOut->addJsConfigVars( 'ajaxCommentsLikeDislike', $wgAjaxCommentsLikeDislike );
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
	public static function ajax( $type, $page, $id = 0, $data = '' ) {
		global $wgOut, $wgRequest;
		header( 'Content-Type: application/json' );
		$result = array();
sleep(1);
		// Perform the command on the talk content
		switch( $type ) {

			case 'add':
				$result = self::add( $data, $page );
			break;

			case 'reply':
				$result = self::reply( $data, $page, $id );
			break;

			case 'edit':
				$result = self::edit( $data, $page, $id );
			break;

			case 'del':
				$result = self::delete( $page, $id );
			break;

			case 'like':
				$msg = self::like( $data, $id );
				$comment = self::getComment( $id );
				$result = array(
					'like' => $comment['like'],
					'dislike' => $comment['dislike']
				);
			break;

			case 'get':
				$result = self::getComments( $page, $id );
			break;

			default:
				$result['error'] = "unknown action";
		}

		return json_encode( $result );
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
		$id = $dbw->insertId();
		self::comment( 'add', $page, $id );
		return self::getComment( $id );
	}

	/**
	 * Edit an existing comment in the data structure
	 */
	private static function edit( $text, $page, $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( AJAXCOMMENTS_TABLE, array( 'ac_data' => $text ), array( 'ac_id' => $id ) );
		self::comment( 'edit', $page, $id );
		return self::getComment( $id );
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
		$dbw->insert( AJAXCOMMENTS_TABLE, array(
			'ac_type'   => AJAXCOMMENTS_DATATYPE_COMMENT,
			'ac_parent' => $parent,
			'ac_page'   => $page,
			'ac_user'   => $uid,
			'ac_time'   => $ts,
			'ac_data'   => $text,
		) );
		$id = $dbw->insertId();
		self::comment( 'reply', $page, $id );
		return self::getComment( $id );
	}

	/**
	 * Delete a comment amd all its replies from the data structure
	 */
	private static function delete( $page, $id ) {
		global $wgUser;
		$dbw = wfGetDB( DB_MASTER );

		// Die if the comment is not owned by this user unless sysop
		if( !in_array( 'sysop', $wgUser->getEffectiveGroups() ) ) {
			$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_user', array( 'ac_id' => $id ) );
			if( $uid->ac_user != $wgUser->getId() ) return "Only sysops can delete someone else's comment";
		}

		// Delete this comment and all child comments and likes
		$children = self::children( $id );
		$dbw->delete( AJAXCOMMENTS_TABLE, 'ac_id IN (' . implode( ',', $children ) . ')' );
		self::comment( 'del', $page, $id );
		return $children;
	}

	/**
	 * Like/unlike a comment returning a message describing the change
	 */
	private static function like( $val, $id ) {
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
			'ac_user'   => $uid,
		) );

		// If there is an existing value,
		if( $row ) {
			if( $val == $row->ac_data ) return false; // Already liked/disliked
			$like = $row->ac_data + $val;
			$lid = $row->ac_id;

			// Remove the user if they now nolonger like or dislike, 
			if( $like == 0 ) $dbw->delete( AJAXCOMMENTS_TABLE, array( 'ac_id' => $lid ) );

			// Otherwise update their value
			else $dbw->update( AJAXCOMMENTS_TABLE, array( 'ac_data' => $like ), array( 'ac_id' => $lid ) );
		}

		// If no existing value, insert one now
		else {
			$like = $val;
			$dbw->insert( AJAXCOMMENTS_TABLE, array(
				'ac_type'   => AJAXCOMMENTS_DATATYPE_LIKE,
				'ac_parent' => $id,
				'ac_user'   => $uid,
				'ac_data'   => $val,
			) );
		}

		// Return a message string about the update
		if( $val > 0 ) {
			if( $like == 0 ) return wfMessage( 'ajaxcomments-undislike', $name, $cname )->text();
			else return wfMessage( 'ajaxcomments-like', $name, $cname )->text();
		} else {
			if( $like == 0 ) return wfMessage( 'ajaxcomments-unlike', $name, $cname )->text();
			else return wfMessage( 'ajaxcomments-dislike', $name, $cname )->text();
		}
	}

	// Add a log entry about this activity
	private static function comment( $type, $page, $id ) {
		$title = Title::newFromId( $page );
		$summary = wfMessage( "ajaxcomments-$type-summary", $title->getPrefixedText(), $id )->text();
		$log = new LogPage( 'ajaxcomments', true );
		$log->addEntry( $type, $title, $summary, array( $title->getPrefixedText() ) );
	}

	/**
	 * Get all child comments and likes of the passed id (i.e. replies and replies of replies etc)
	 */
	private static function children( $id ) {
		$children = array( $id );
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( AJAXCOMMENTS_TABLE, 'ac_id', array( 'ac_parent' => $id ) );
		foreach( $res as $row ) $children = array_merge( $children, self::children( $row->ac_id ) );
		return $children;
	}

	/**
	 * Return the passed comment in client-ready format
	 * - row can be a comment id or a db row structure
	 */
	private static function getComment( $row ) {
		global $wgLang, $wgAjaxCommentsAvatars;
		$likes = $dislikes = array();
		$dbr = wfGetDB( DB_SLAVE );

		// Read the row from DB if id supplied
		if( is_numeric( $row ) ) {
			$id = $row;
			$row = $dbr->selectRow( AJAXCOMMENTS_TABLE, '*', array( 'ac_id' => $id ) );
			if( !$row ) die( "Error: Comment $id not found" );
		}

		// Get the like data for this comment
		if( $row->ac_type == AJAXCOMMENTS_DATATYPE_COMMENT ) {
			$res = $dbr->select(
				AJAXCOMMENTS_TABLE,
				'ac_user,ac_data',
				array( 'ac_type' => AJAXCOMMENTS_DATATYPE_LIKE, 'ac_parent' => $row->ac_id ),
				__METHOD__,
				array( 'ORDER BY' => 'ac_time' )
			);
			foreach( $res as $like ) {
				$name = User::newFromId( $like->ac_user )->getName();
				$like->ac_data > 0 ? $likes[] = $name : $dislikes[] = $name;
			}
		}

		// Convert to client-ready format
		$user = User::newFromId( $row->ac_user );
		return array(
			'id'      => $row->ac_id,
			'parent'  => $row->ac_parent,
			'user'    => $row->ac_user,
			'name'    => $user->getName(),
			'time'    => $row->ac_time,
			'date'    => $wgLang->timeanddate( $row->ac_time, true ),
			'text'    => $row->ac_data,
			'html'    => self::parse( $row->ac_data, $row->ac_page ),
			'like'    => $likes,
			'dislike' => $dislikes,
			'avatar'  => $wgAjaxCommentsAvatars && $user->isEmailConfirmed()
				? "https://www.gravatar.com/avatar/" . md5( strtolower( $user->getEmail() ) ) . "?s=50&d=wavatar" : false
		);
	}

	/**
	 * Get comments for passed page greater than passed timestamp in a client-ready format
	 */
	private static function getComments( $page, $ts = false ) {

		// Query DB for all comments and likes for the page (after ts if supplied)
		$cond = array( 'ac_type' => AJAXCOMMENTS_DATATYPE_COMMENT, 'ac_page' => $page );
		if( $ts ) $cond[] = "ac_time > $ts";
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			AJAXCOMMENTS_TABLE,
			'*',
			$cond,
			__METHOD__,
			array( 'ORDER BY' => 'ac_time' )
		);

		$comments = array();
		foreach( $res as $row ) $comments[] = self::getComment( $row );
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
