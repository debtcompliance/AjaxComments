<?php

use MediaWiki\MediaWikiServices;

class AjaxComments {

	public static $instance = null;
	private static $admin = null;

	private $comments = [];
	private $talk = false;
	private static $canComment = false;

	public static function onRegistration() {
		global $wgLogTypes, $wgLogNames, $wgLogHeaders, $wgLogActions, $wgAPIModules;

		// Constants
		define( 'AJAXCOMMENTS_TABLE', 'ajaxcomments' );
		define( 'AJAXCOMMENTS_DATATYPE_COMMENT', 1 );
		define( 'AJAXCOMMENTS_DATATYPE_LIKE', 2 );

		// Add a new log type
		$wgLogTypes[]                       = 'ajaxcomments';
		$wgLogNames['ajaxcomments']         = 'ajaxcomments-logpage';
		$wgLogHeaders['ajaxcomments']       = 'ajaxcomments-logpagetext';
		$wgLogActions['ajaxcomments/add']   = 'ajaxcomments-add-desc';
		$wgLogActions['ajaxcomments/reply'] = 'ajaxcomments-reply-desc';
		$wgLogActions['ajaxcomments/edit']  = 'ajaxcomments-edit-desc';
		$wgLogActions['ajaxcomments/del']   = 'ajaxcomments-del-desc';

		// Instantiate a singleton instance
		self::$instance = new self();

		// Register our ajax handler
		$wgAPIModules['ajaxcomments'] = 'ApiAjaxComments';

		// Do the rest of the setup after user and title are ready
		MediaWikiServices::getInstance()->getHookContainer()->register(
			'UserGetRights', self::$instance );
	}

	/**
	 * Singleton object allowing common access to non-static methods
	 */
	public static function singleton() {
		return self::$instance;
	}

	/**
	 * Using this hook for setup so that user and title are setup
	 */
	public function onUserGetRights( $user, &$rights ) {
		global $wgAjaxCommentsPollServer;

		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();

		$out = RequestContext::getMain()->getOutput();
		$title = RequestContext::getMain()->getTitle();

		// Create a hook to allow external condition for whether there should be comments shown
		if ( !array_key_exists( 'action', $_REQUEST ) && self::checkTitle( $title ) ) {
			$hookContainer->register( 'BeforePageDisplay', $this );
		} else {
			$wgAjaxCommentsPollServer = -1;
		}

		// Redirect talk pages with AjaxComments to the comments
		if ( is_object( $title )
			&& $title->getNamespace() > 0
			&& ( $title->getNamespace() & 1 )
		) {
			$ret = true;
			$hookContainer->run( 'AjaxCommentsCheckTitle', [ $userpage, &$ret ] );
			if ( $ret ) {
				$userpage = Title::newFromText( $title->getText(), $title->getNamespace() - 1 );
				global $wgMediaWiki;
				if ( is_object( $wgMediaWiki ) ) {
					$wgMediaWiki->restInPeace();
				}
				$out->disable();
				wfResetOutputBuffers();
				$url = $userpage->getLocalUrl();
				header( "Location: $url#ajaxcomments" );
				wfDebugLog( __CLASS__, "Redirecting to $url" );
				exit;
			}
		}

		// Load JS and CSS
		$out->addModules( [ 'ext.ajaxcomments' ] );
	}

		/**
	 * Determine if the current user is an admin for comments
	 */
	private static function isAdmin() {
		global $wgAjaxCommentsAdmins;

		$user = RequestContext::getMain()->getUser();
		$groups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserEffectiveGroups( $user );
		self::$admin = count( array_intersect( $wgAjaxCommentsAdmins, $groups ) ) > 0;
		return self::$admin;
	}

	/**
	 * Allow other extensions to check or set if a title has comments
	 */
	public static function checkTitle( $title ) {
		$ret = true;
		if ( $title && !is_object( $title ) ) {
			$title = Title::newFromText( $title );
		}
		if ( !is_object( $title )
			|| $title->getArticleID() == 0
			|| $title->isRedirect()
			|| $title->getNamespace() == 8
			|| ( $title->getNamespace() & 1 )
		) {
			$ret = false;
		} else {
			MediaWikiServices::getInstance()->getHookContainer()->run(
				'AjaxCommentsCheckTitle', [ $title, &$ret ] );
		}
		return $ret;
	}

	/**
	 * Add JS config vars
	 */
	public static function onMakeGlobalVariablesScript( &$vars, $out ) {
		global $wgAjaxCommentsPollServer, $wgAjaxCommentsLikeDislike;
		$vars['ajaxCommentsPollServer'] = $wgAjaxCommentsPollServer;
		$vars['ajaxCommentsCanComment'] = self::$canComment;
		$vars['ajaxCommentsLikeDislike'] = $wgAjaxCommentsLikeDislike;
		$vars['ajaxCommentsAdmin'] = self::isAdmin();
		return true;
	}

	/**
	 * Render a name at the end of the page so redirected talk pages can go
	 * there before ajax loads the content,
	 */
	public function onBeforePageDisplay( $out, $skin ) {
		$out->addHtml( '<h2>' . wfMessage( 'ajaxcomments-heading' )->text()
			. '</h2><a id="ajaxcomments-name" name="ajaxcomments"></a>' );
		return true;
	}

	/**
	 * Make a hook available just before the content is loaded
	 * to allow adding external conditions for whether comments
	 * can be added or replied to
	 * - default is just user logged in
	 */
	public static function onArticleViewHeader( &$article, &$outputDone, &$pcache ) {
		self::$canComment = RequestContext::getMain()->getUser()->isRegistered();
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'AjaxCommentsCheckWritable', [ $article->getTitle(), &self::$canComment ] );
	}

	/**
	 * Add a new comment to the data structure, return it's insert ID
	 */
	public static function add( $text, $page, $user = false ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$row = [
			'ac_type' => AJAXCOMMENTS_DATATYPE_COMMENT,
			'ac_user' => $user ?: RequestContext::getMain()->getUser()->getId(),
			'ac_page' => $page,
			'ac_time' => time(),
			'ac_data' => $text,
		];
		$dbw->insert( AJAXCOMMENTS_TABLE, $row );
		$id = $dbw->insertId();
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'AjaxCommentsChange', [ 'add', $page, $id ]
		);
		self::comment( 'add', $page, $id );
		
		$row['ac_id'] = $id;
		return self::getComment( (object)$row );
	}

	/**
	 * Edit an existing comment in the data structure
	 */
	public static function edit( $text, $page, $id ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->update( AJAXCOMMENTS_TABLE, [ 'ac_data' => $text ], [ 'ac_id' => $id ] );
		$comment = self::comment( 'edit', $page, $id );
		if ( $comment ) {
			return $comment;
		}
		return self::getComment( $id );
	}

	/**
	 * Add a new comment as a reply to an existing comment in the data structure
	 * - return the new comment in client-ready format
	 */
	public static function reply( $text, $page, $parent ) {
		$uid = RequestContext::getMain()->getUser()->getId();
		$ts = time();
		$dbw = wfGetDB( DB_PRIMARY );
		$row = [
			'ac_type'   => AJAXCOMMENTS_DATATYPE_COMMENT,
			'ac_parent' => $parent,
			'ac_page'   => $page,
			'ac_user'   => $uid,
			'ac_time'   => $ts,
			'ac_data'   => $text,
		];
		$dbw->insert( AJAXCOMMENTS_TABLE, $row );
		$id = $dbw->insertId();
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'AjaxCommentsChange', [ 'reply', $page, $id ]
		);
		$comment = self::comment( 'reply', $page, $id );
		if ( $comment ) {
			return $comment;
		}
		return self::getComment( $id );
	}

	/**
	 * Delete a comment amd all its replies from the data structure
	 */
	public static function delete( $page, $id ) {
		global $wgAjaxCommentsAdmins;
		$dbw = wfGetDB( DB_PRIMARY );

		// Die if the comment is not owned by this user unless sysop
		if ( !self::isAdmin() ) {
			$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_user', [ 'ac_id' => $id ] );
			if ( $row->ac_user != RequestContext::getMain()->getUser()->getId() ) {
				return "Only admins can delete someone else's comment";
			}
		}

		// Delete this comment and all child comments and likes
		$children = self::children( $id );
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'AjaxCommentsChange', [ 'delete', $page, $id ]
		);
		$dbw->delete( AJAXCOMMENTS_TABLE, 'ac_id IN (' . implode( ',', $children ) . ')' );
		self::comment( 'del', $page, $id );
		return $children;
	}

	/**
	 * Like/unlike a comment returning a message describing the change
	 */
	public static function like( $val, $id ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_user', [ 'ac_id' => $id ] );

		$user = RequestContext::getMain()->getUser();

		$uid = $user->getId();
		$name = $user->getName();
		$cname = User::newFromId( $row->ac_user )->getName();

		// Get this users like value for this comment if any
		$row = $dbw->selectRow( AJAXCOMMENTS_TABLE, 'ac_data, ac_id', [
			'ac_type'   => AJAXCOMMENTS_DATATYPE_LIKE,
			'ac_parent' => $id,
			'ac_user'   => $uid,
		] );

		// If there is an existing value,
		if ( $row ) {

			// Already liked/disliked
			if ( $val == $row->ac_data ) {
				return false;
			}

			$like = $row->ac_data + $val;
			$lid = $row->ac_id;

			// Remove the user if they now nolonger like or dislike,
			// Otherwise update their value
			if ( $like == 0 ) {
				$dbw->delete( AJAXCOMMENTS_TABLE, [ 'ac_id' => $lid ] );
			} else {
				$dbw->update( AJAXCOMMENTS_TABLE, ['ac_data' => $like], ['ac_id' => $lid] );
			}
		}

		// If no existing value, insert one now
		else {
			$like = $val;
			$dbw->insert( AJAXCOMMENTS_TABLE, [
				'ac_type'   => AJAXCOMMENTS_DATATYPE_LIKE,
				'ac_parent' => $id,
				'ac_user'   => $uid,
				'ac_data'   => $val,
			] );
		}

		// Return a message string about the update
		if ( $val > 0 ) {
			if ( $like == 0 ) {
				return wfMessage( 'ajaxcomments-undislike', $name, $cname )->text();
			} else {
				return wfMessage( 'ajaxcomments-like', $name, $cname )->text();
			}
		} else {
			if ( $like == 0 ) {
				return wfMessage( 'ajaxcomments-unlike', $name, $cname )->text();
			} else {
				return wfMessage( 'ajaxcomments-dislike', $name, $cname )->text();
			}
		}
	}

	/**
	 * Do notifications about comment activity and return the comment
	 */
	private static function comment( $type, $page, $id ) {
		global $wgAjaxCommentsEmailNotify;
		$title = Title::newFromId( $page );
		$pagename = $title->getPrefixedText();
		$summary = wfMessage( "ajaxcomments-$type-summary", $pagename, $id )->text();
		$log = new LogPage( 'ajaxcomments', true );
		$log->addEntry( $type, $title, $summary, [ $pagename ], RequestContext::getMain()->getUser() );

		// Notify by email if config enabled
		if ( $wgAjaxCommentsEmailNotify && $type != 'del' ) {
			$comment = self::getComment( $id );

			// Get the parent comment if any
			$parent = $comment['parent'] ? self::getComment( $comment['parent'] ) : false;

			// Send to the reply-parent item's author (maybe send to the whole chain later)
			if ( $parent && ( $type == 'reply' || $type == 'edit' ) ) {
				$user = User::newFromId( $parent['user'] );
				$lang = $user->getOption( 'language' );
				$body = wfMessage( "ajaxcomments-email-reply-$type", $pagename, $comment['name'] )->text();
				$body .= "\n\n"
					. wfMessage(
						'ajaxcomments-email-link',
						$comment['name'],
						$title->getFullUrl(),
						$id
					)->inLanguage( $lang )->text();
				$subject = wfMessage( 'ajaxcomments-email-subject', $pagename )->inLanguage( $lang )->text();
				self::emailUser( $user, $subject, $body );
			}

			// Get list of users watching this page
			// - excluding the user who made the comment and user notified about reply if any
			$dbr = wfGetDB( DB_REPLICA );
			$cond = [
				'wl_title' => $title->getDBkey(),
				'wl_namespace' => $title->getNamespace(),
				'wl_user <> ' . $comment['user']
			];
			if ( $parent ) {
				$cond[] = 'wl_user <> ' . $parent['user'];
			}
			$res = $dbr->select( 'watchlist', [ 'wl_user' ], $cond, __METHOD__ );
			wfDebugLog( __CLASS__, "Watcher query: " . $dbr->lastQuery() );
			$watchers = [];
			foreach ( $res as $row ) {
				$watchers[$row->wl_user] = true;
			}

			// If this is a user page, ensure the user is listed as a watcher
			// - unless it's the same user that created the comment
			if ( $title->getNamespace() == NS_USER ) {
				$uid = User::newFromName( $title->getText() )->getId();
				if ( $uid != $comment['user'] ) {
					$watchers[$uid] = true;
					wfDebugLog(
						__CLASS__,
						"Comment is on a user page, adding user id $uid to the watchers email list"
					);
				}
			}

			// Loop through all watchers in the list
			$watchers = array_keys( $watchers );
			wfDebugLog( __CLASS__, "Sending to watchers: " . implode( ',', $watchers ) );
			foreach ( $watchers as $uid ) {
				$watcher = User::newFromId( $uid );

				// If this watcher wants to be notified by email of watchlist changes,
				// and the comment is something to notify about,
				if ( $watcher->getOption( 'enotifwatchlistpages' )
					&& $watcher->isEmailConfirmed()
					&& ( $type == 'add' || $type == 'reply' || $type == 'edit' )
				) {
					// Compose the email and send
					$lang = $watcher->getOption( 'language' );
					$subject = wfMessage( 'ajaxcomments-email-subject', $pagename )->inLanguage( $lang )->text();
					$body = wfMessage(
						"ajaxcomments-email-watch-$type",
						$pagename,
						$comment['name'],
						$parent ? $parent['name'] : null
					);
					$body = $body->inLanguage( $lang )->text();
					$body .= "\n\n"
						. wfMessage(
							'ajaxcomments-email-link',
							$comment['name'],
							$title->getFullUrl(),
							$id
						)->inLanguage( $lang )->text();
					self::emailUser( $watcher, $subject, $body );
				}
			}
			return $comment;
		}
	}

	/**
	 * Queue an email to be sent to a user
	 */
	private static function emailUser( $user, $subject, $body ) {
		global $wgPasswordSender, $wgPasswordSenderName, $wgSitename;
		$lang = $user->getOption( 'language' );
		$name = $user->getRealName() ?: $user->getName();
		$body = wfMessage( 'ajaxcomments-email-hello', $name )->inLanguage( $lang )->text() . "\n\n$body";
		$from = new MailAddress( $wgPasswordSender, $wgPasswordSenderName ?: $wgSitename );
		$to = new MailAddress( $user->getEmail(), $user->getName(), $user->getRealName() );
		$body = wordwrap( $body, 72 );
		$job = new EmaillingJob(
			Title::newFromText( $name ),
			[
				'to' => $to,
				'from' => $from,
				'subj' => $subject,
				'body' => $body
			]
		);
		JobQueueGroup::singleton()->lazyPush( $job );
		wfDebugLog( __CLASS__, "Queued email notification to " . $user->getEmail() );
	}

	/**
	 * Get all child comments and likes of the passed id (i.e. replies and replies of replies etc)
	 */
	private static function children( $id ) {
		$children = [ $id ];
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( AJAXCOMMENTS_TABLE, 'ac_id', [ 'ac_parent' => $id ] );
		foreach ( $res as $row ) {
			$children = array_merge( $children, self::children( $row->ac_id ) );
		}
		return $children;
	}

	/**
	 * Return the passed comment in client-ready format
	 * - row can be a comment id or a db row structure
	 */
	public static function getComment( $row ) {
		global $wgAjaxCommentsAvatars;
		$likes = $dislikes = [];
		$dbr = wfGetDB( DB_REPLICA );

		// Read the row from DB if id supplied
		if ( is_numeric( $row ) ) {
			$id = $row;
			$row = $dbr->selectRow( AJAXCOMMENTS_TABLE, '*', [ 'ac_id' => $id ] );
			if ( !$row ) {
				die( "Error: Comment $id not found" );
			}
		}

		// Get the like data for this comment
		if ( $row->ac_type == AJAXCOMMENTS_DATATYPE_COMMENT ) {
			$res = $dbr->select(
				AJAXCOMMENTS_TABLE,
				'ac_user,ac_data',
				[ 'ac_type' => AJAXCOMMENTS_DATATYPE_LIKE, 'ac_parent' => $row->ac_id ],
				__METHOD__,
				[ 'ORDER BY' => 'ac_time' ]
			);
			foreach ( $res as $like ) {
				$name = User::newFromId( $like->ac_user )->getName();
				$like->ac_data > 0 ? $likes[] = $name : $dislikes[] = $name;
			}
		}

		// Convert to client-ready format
		$user = User::newFromId( $row->ac_user );
		$lang = RequestContext::getMain()->getLanguage();
		return [
			'id'      => $row->ac_id,
			'parent'  => $row->ac_parent,
			'user'    => $row->ac_user,
			'name'    => $user->getName(),
			'time'    => $row->ac_time,
			'date'    => $lang->timeanddate( $row->ac_time, true ),
			'text'    => $row->ac_data,
			'html'    => self::parse( $row->ac_data, $row->ac_page ),
			'like'    => $likes,
			'dislike' => $dislikes,
			'avatar'  => $wgAjaxCommentsAvatars && $user->isEmailConfirmed()
				? "https://www.gravatar.com/avatar/" . md5( strtolower( $user->getEmail() ) ) . "?s=50&d=wavatar"
				: false
		];
	}

	/**
	 * Get comments for passed page greater than passed timestamp in a client-ready format
	 */
	public static function getComments( $page, $ts = false ) {

		// Query DB for all comments and likes for the page (after ts if supplied)
		$cond = [ 'ac_type' => AJAXCOMMENTS_DATATYPE_COMMENT, 'ac_page' => $page ];
		if ( $ts ) {
			$cond[] = "ac_time > $ts";
		}
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			AJAXCOMMENTS_TABLE,
			'*',
			$cond,
			__METHOD__,
			[ 'ORDER BY' => 'ac_time' ]
		);

		$comments = [];
		foreach ( $res as $row ) {
			$comments[] = self::getComment( $row );
		}
		return $comments;
	}

	/**
	 * Parse wikitext
	 */
	private static function parse( $text, $page ) {
		$title = Title::newFromId( $page );
		$parser = MediaWikiServices::getInstance()->getParser();
		$options = ParserOptions::newFromUser( RequestContext::getMain()->getUser() );
		return $parser->parse( $text, $title, $options, true, true )->getText();
	}
}
