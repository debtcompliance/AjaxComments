<?php
/**
 * Upgrade the wiki's AjaxComments data to version 2.0 and restore the articles affected by 1.x
 */
$path = $_SERVER['argv'][0];
if( $path[0] != '/' ) die( "Please use absolute path to execute the script so I can assess where the code-base resides.\n" );
$IP = preg_replace( '|(^.+)/extensions/.+$|', '$1', $path );
putenv( "MW_INSTALL_PATH=$IP" );
require_once( "$IP/maintenance/Maintenance.php" );

// The old data index constants
define( 'AJAXCOMMENTS_USER', 1 );
define( 'AJAXCOMMENTS_DATE', 2 );
define( 'AJAXCOMMENTS_TEXT', 3 );
define( 'AJAXCOMMENTS_PARENT', 4 );
define( 'AJAXCOMMENTS_REPLIES', 5 );
define( 'AJAXCOMMENTS_LIKE', 6 );

class UpgradeAjaxComments extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Upgrade the wiki's AjaxComments data to version 2.0 and restore the articles affected by 1.x";
	}

	public function execute() {
		$dbw = wfGetDB( DB_MASTER );

		// If the table already exists, bail
		$tbl = $dbw->tableName( AJAXCOMMENTS_TABLE );
		$tblq = str_replace( '`', "'", $tbl );
		if( $dbw->query( "SHOW TABLES LIKE $tblq" )->result->num_rows ) {
			$this->output( "Already upgraded!\n" );
			return;
		}

		// Scan all talk pages for AjaxComments data structures
		$this->output( "\nScanning talk pages for comments needing migration...\n" );
		$res = $dbw->select( 'page', array( 'page_id' ), array( 'page_namespace & 1' ) );
		$data = array();
		$pages = 0;
		$cpages = 0;
		foreach( $res as $row ) {
			$id = $row->page_id;
			$title = Title::newFromId( $id );
			if( $title->exists() ) {
				$pages++;
				$article = new Article( $title );
				$content = $article->getPage()->getContent()->getNativeData();

				// This page ID of the associated content page
				$page = Title::newFromText( $title->getText(), $title->getNamespace() - 1 );
				if( $page ) {
					$page = $page->getArticleID();
					$this->output( "   Processing talk page with ID $id (associated content page has ID $page)\n" );

					// If this page has AjaxComments data in it's current revision, extract the data,
					if( $ac = $this->textToData( $content ) ) {
						$cpages++;
						foreach( $ac as $k => $v ) {
							$data[$k] = $v;
							$data[$k]['talk'] = $id;
							$data[$k]['page'] = $page;
							$data[$k]['id'] = count( $data );
						}
					
						// and revert it to it's state prior to AjaxComments, or delete it
						$rev = Revision::newFromId( $title->getLatestRevID() );
						do { $rev = $rev->getPrevious(); } while( $rev && strpos( $comment = $rev->getRawComment(), 'AjaxComments' ) !== false );
						if( $rev ) {
							$this->output( "      Reverting (talkpage $id) to comment " . $rev->getId() . " (Edit comment: '$comment').\n" );
							$article->doEdit( $rev->getText(), 'Reverted talkpage to state prior to AJAXCOMMENTS additions', EDIT_UPDATE );
						} else {
							$this->output( "      Deleting (talkpage $id) as it has only AjaxComments revisions.\n" );
							$article->doDelete( 'Deleting talkpage, comments data has been moved into the "ajaxcomments" database table.' );
						}
					}
				}
			}
		}
		$this->output( "   Done (" . count( $data ) . " comments migrated from $cpages talkpages out of $pages in total)\n" );

		// Update the data to the new format
		$this->output( "\nUpgrading comment data...\n" );
		foreach( $data as $k => $v ) {
			$id = $data[$k]['id'];
			$name = $data[$k][AJAXCOMMENTS_USER];
			$uid = User::newFromName( $name )->getId();
			if( $uid < 1 ) {
				$uid = 0;
				$this->output( '   WARNING: Invalid user in comment $k in page ' . $data[$k]['page'] . " ID set to zero\n" );
			}
			$data[$k][AJAXCOMMENTS_USER] = $uid;
			$this->output( "   New id for $k is $id, user '$name' has ID $uid\n" );
			if( $parent = $data[$k][AJAXCOMMENTS_PARENT] ) {
				$data[$k][AJAXCOMMENTS_PARENT] = $data[$parent]['id'];
				$this->output( "      'parent' field changed from $parent to " . $data[$parent]['id'] . "\n" );
			}
			if( $n = count( $data[$k][AJAXCOMMENTS_LIKE] ) ) {
				$likes = array();
				foreach( $data[$k][AJAXCOMMENTS_LIKE] as $name => $val ) {
					$uid = User::newFromName( $name )->getId();
					if( $uid ) $likes[$uid] = $val;
					else $this->output( "      WARNING: Invalid user in 'like' field, item dropped\n" );
				}
				$data[$k][AJAXCOMMENTS_LIKE] = $likes;
				$this->output( "      $n usernames changed to IDs in the 'like' field\n" );
			}
		}
		$this->output( "   Done\n" );

		// Add the new table
		$this->output( "\nAdding table $tbl if it doesn't already exist, clearing data if it does exist...\n" );
		$dbw->query( "CREATE TABLE $tbl (
			ac_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
			ac_type   INT UNSIGNED NOT NULL,
			ac_parent INT UNSIGNED,
			ac_user   INT UNSIGNED,
			ac_page   INT UNSIGNED,
			ac_time   INT UNSIGNED,
			ac_data   TEXT,
			PRIMARY KEY (ac_id)
		)" );
		$this->output( "   Done\n" );

		// Insert the upgraded data into the table
		$this->output( "\nInserting the upgraded data into the table...\n" );
		foreach( $data as $k => $v ) {
			$id = $data[$k]['id'];
			$page = $data[$k]['page'];
			$this->output( "   Inserting comment $id (was $k)\n" );
			$dbw->insert( AJAXCOMMENTS_TABLE, array(
				'ac_type' => AJAXCOMMENTS_DATATYPE_COMMENT,
				'ac_parent' => $data[$k][AJAXCOMMENTS_PARENT],
				'ac_user' => $data[$k][AJAXCOMMENTS_USER],
				'ac_page' => $page,
				'ac_time' => $data[$k][AJAXCOMMENTS_DATE],
				'ac_data' => $data[$k][AJAXCOMMENTS_TEXT],
			) );

			// Insert a row for each 'like' in this comment
			if( $n = count( $data[$k][AJAXCOMMENTS_LIKE] ) ) {
				foreach( $data[$k][AJAXCOMMENTS_LIKE] as $uid => $val ) {
					$dbw->insert( AJAXCOMMENTS_TABLE, array(
						'ac_type' => AJAXCOMMENTS_DATATYPE_LIKE,
						'ac_parent' => $id,
						'ac_user' => $uid,
						'ac_page' => $page,
						'ac_data' => $val,
					) );
				}
				$this->output( "      $n 'like' rows added for this comment\n" );
			}
		}
	}

	/**
	 * Return the passed talk text as a data structure of comments
	 * - detect if the content needs to be base64 decoded before unserialising
	 */
	private function textToData( $text ) {
		if( preg_match( "|== AjaxComments:DataStart ==\s*(.+?)\s*== AjaxComments:DataEnd ==|s", $text, $m ) ) {
			$data = $m[1];
			if( substr( $data, -1 ) != '}' ) $data = base64_decode( $data );
			return unserialize( $data );
		}
		return array();
	}
}

$maintClass = "UpgradeAjaxComments";
require_once RUN_MAINTENANCE_IF_MAIN;
