$(document).ready( function() {

	// Client copy of all comments on this page
	var comments = {};
	var count = 0;

	// Remember ID of this page for ajax requests
	var page = mw.config.get('wgArticleId');

	// User info
	var user = mw.config.get('wgUserId');
	var groups = mw.config.get('wgUserGroups');
	var sysop = $.inArray( 'sysop', groups );

	// Is the user allowed to add comments/edit etc?
	var canComment = mw.config.get('ajaxCommentsCanComment');

	// Remember latest comment received
	var timestamp = 0;

	// Whether WebSocket is available
	var ws = false;

	// Names for our events that is specific to this article on this wiki
	var prefix = mw.config.get('wsWikiID') + page + ':';
	var wsRender = prefix + 'Render';
	var wsDelete = prefix + 'Delete';

	// Get the Ajax polling rate (-1 means comments are disabled for this page)
	var poll = mw.config.get('ajaxCommentsPollServer');
	if(poll < 0) return;

	// If the comments area has been added, create a div for the comments to render into with a loader in it
	if($('#ajaxcomments-name').length > 0) {

		// Change the talk page tab to a local link to the comments at the end of the page if it exists
		$('#ca-talk a').attr('href','#ajaxcomments');
		$('#ca-talk').removeClass('new');

		// Create a target for the comments and put a loader in it
		$('#ajaxcomments-name').after('<div id="ajaxcomments-wrapper"><div id="ajaxcomments" class="ajaxcomments-loader"></div></div>');
	}

	// If WebSocket is available, connect it and set rendering and deleting of comments to occur when notified
	updateComments();
	if(typeof webSocket === 'object') {
		ws = webSocket.connect();
		webSocket.disconnected(updateComments);
		webSocket.subscribe(wsRender, function(data) { renderComments(data.msg) } );
		webSocket.subscribe(wsDelete, function(data) { del(data.msg, false) } );
	}

	/**
	 * Ask the server for the rendered comments on a regular intervale (unless WebSocket connected)
	 */
	function updateComments() {
		$.ajax({
			type: 'GET',
			url: mw.util.wikiScript(),
			data: { action: 'ajax', rs: 'AjaxComments::ajax', rsargs: ['get', page, timestamp] },
			dataType: 'json',
			success: function(data) {
				renderComments(data);
			}
		}).then(function() {
			if(!(typeof webSocket === 'object' && webSocket.connected())) setTimeout(updateComments, poll * 1000);
		});
	}

	/**
	 * An delete link has been clicked
	 */
	function del(idlist, notify) {
		var i, id, buttons;
		for( i = 0; i < idlist.length; i++ ) {
			id = idlist[i];
			buttons = {}, e = $('#ajaxcomment-' + id);
			buttons[mw.message( 'ajaxcomments-yes' ).escaped()] = function() {
				console.log('AjaxComments: del(' + id + ')');

				// Replace the comment content with a loader
				$('.ajaxcomment-text:first', e).html('<div class="ajaxcomments-loader"></div>');

				// Submit the delete request
				$.ajax({
					type: 'POST',
					url: mw.util.wikiScript(),
					data: { action: 'ajax', rs: 'AjaxComments::ajax', rsargs: ['del', page, id] },
					dataType: 'json',
					success: function(data) {

						// Delete the this comment's visual element (which contains all replies)
						e.fadeOut(500);

						// Delete the returned ID's from the local comments data store
						for( i = 0; i < data.length; i++ ) delete(comments[data[i]]);

						// If no comments, add message
						noComments();

						// If notify set, send this id list via WebSocket
						if(ws && notify) webSocket.send(wsDelete, data);
					}
				});
				$(this).dialog('close');
			 };
			buttons[mw.message( 'ajaxcomments-cancel' ).escaped()] = function() { $(this).dialog('close'); };
			$('<div>' + mw.message( 'ajaxcomments-confirmdel' ).escaped() + '</div>').dialog({
				modal: true,
				resizable: false,
				width: 400,
				title: mw.message( 'ajaxcomments-confirm' ).escaped(),
				buttons: buttons
			});
		}
	}

	/**
	 * Send a request to like/dislike an item
	 * - the returned response is the new like/dislike links
	 */
	function like(id, val) {
		var target = $('#ajaxcomments-' + id);
		console.log('AjaxComments: like(' + id + ')');
		$.ajax({
			type: 'GET',
			url: mw.util.wikiScript(),
			data: {
				action: 'ajax',
				rs: 'AjaxComments::ajax',
				rsargs: ['like', page, id, val],
			},
			dataType: 'json',
			success: function(html) {

				// If something is returned, replace the like/dislike links with it
				if(html) {
					$('#ajaxcomment-like',this).first().remove();
					$('#ajaxcomment-dislike',this).first().replaceWith(html);
					if(ws) webSocket.send(wsAjaxCommentsEvent);
				}
			}
		});
	}

	/**
	 * Open a comment input box to add, edit or reply
	 */
	function input(type, id) {
		var html, sel = '#ajaxcomment-' + id;

		// Cancel any existing inputs
		cancel();

		// Hide the no comments message if exists
		$('#ajaxcomments-none').hide();

		// Build the input with it's submit and cancel buttons
		var html = '<div id="ajaxcomment-input" class="ajaxcomment-input ' + type + '"><textarea></textarea>';
		html += '<button class="submit">' + mw.message('ajaxcomments-post').text() + '</button>';
		html += '<button class="cancel">' + mw.message('ajaxcomments-cancel').text() + '</button>';
		html += '</div>';

		// If replying or adding, surround the input with new comment structure
		if(type == 'add' || type == 'reply') html = '<div class="ajaxcomment-container" id="ajaxcomment-new"><div class="ajaxcomment">' + html + '</div></div>';

		// Put the input in the comment in the page
		if(type == 'add') $('#ajaxcomments-add').after(html);
		else if(type == 'edit') $(sel + ' .ajaxcomment-text:first').after(html);
		else if(type == 'reply') {
			$(sel + ' .replies:first').prepend(html);
			sel = '#ajaxcomment-new';
		}

		// Disable the buttons and hide the text and add the source if editing
		if(type == 'edit') {
			$(sel + ' .buttons:first').hide()
			$(sel + ' .ajaxcomment-text:first').hide();
			$(sel + ' textarea:first').text(comments[id].text);
		}

		// Activate the buttons
		$(sel + ' button.cancel:first').click(function() { cancel(); $('#ajaxcomment-new').remove(); });
		$(sel + ' button.submit:first').data({'id': id, 'type': type}).click(function() {
			submit( $(this).data('type'), $(this).data('id') );
		});
	}

	/**
	 * Remove any current comment input box, or new comment
	 */
	function cancel() {
		$('#ajaxcomment-input').remove();
		$('.ajaxcomment-text').show();
		$('#ajaxcomments .buttons').show();
		$('#ajaxcomments-none').show();
	}

	/**
	 * Submit a new comment, edit or reply
	 */
	function submit(type, id) {
		var text, e = $('#ajaxcomment-' + ( type == 'reply' ? 'new' : id ) );
		console.log('AjaxComments: ' + type + '(' + id + ')');

		// Get the new text from the textarea and clear the input
		text = $('textarea:first', e).val();
		cancel();

		// Replace the comment content with a loader
		$('#ajaxcomment-new .ajaxcomment').append('<div class="ajaxcomment-text"></div>');
		$('.ajaxcomment-text:first', e).html('<div class="ajaxcomments-loader"></div>');

		// Send the command and render the returned data
		$.ajax({
			type: 'POST',
			url: mw.util.wikiScript(),
			data: { action: 'ajax', rs: 'AjaxComments::ajax', rsargs: [type, page, id, text] },
			dataType: 'json',
			success: function(data) {
				$('#ajaxcomment-new').remove();
				renderComments([data]);
				if(ws) webSocket.send(wsRender, [data]);
			}
		});
	}

	/**
	 * Render the passed comments data as HTML and insert into the page
	 * - this may be new comments to insert or prepend, or ones that need to be replaced
	 */
	function renderComments(data) {
		var i, sel, c, html;
		
		// If first render,
		if($('#ajaxcomments').hasClass('ajaxcomments-loader')) {

			// Remove loader
			$('#ajaxcomments').removeClass('ajaxcomments-loader');

			// If canComment, include an add comment button
			var html = canComment
				? '<button id="ajaxcomments-add">' + mw.message('ajaxcomments-add').text() + '</button>'
				: '<i>' + mw.message('ajaxcomments-anon').text() + '</i>';
			$('#ajaxcomments').before('<div class="ajaxcomment-links">' + html + '</div>');
			$('#ajaxcomments-add').click(function() { input('add', 'new'); });
		}

		// Copy all the data into the main comments data structure with rendered html
		for( i = 0; i < data.length; i++ ) {
			c = data[i];
			c.rendered = renderComment(c);
			if(!(c.id in comments)) count++;
			comments[c.id] = c;
			if(c.time > timestamp) timestamp = c.time;
		}

		// Scan through again inserting them into the comments area of into their parent comments
		// - they're ordered by timestamp, so parents always exist before their replies are processed
		for( i = 0; i < data.length; i++ ) {
			c = data[i];

			// If this comment is already rendered, update it
			sel = '#ajaxcomment-' + c.id;
			if($(sel).length > 0) {
				html = $(sel + ' .replies').html()
				$(sel).replaceWith(c.rendered);
				$(sel + ' .replies').html(html);
			} else {

				// If it's a reply, insert it at the top of the replies for it's parent
				if(c.parent > 0) $('#ajaxcomment-' + c.parent + ' .replies:first').prepend(c.rendered);

				// If it's a new comment insert it at the top
				else $('#ajaxcomments').prepend(c.rendered);
			}

			// Activate it's buttons
			$(sel + ' button').data('id', c.id);
			$(sel + ' .reply ').click(function() { input('reply', $(this).data('id')); });
			$(sel + ' .edit ').click(function() { input('edit', $(this).data('id')); });
			$(sel + ' .del ').click(function() { del([$(this).data('id')], true); });
		}

		// If no comments, add message
		noComments();
	}

	/**
	 * Render a single comment as HTML (without its replies)
	 */
	function renderComment(c) {
		var ulink = '<a href="' + mw.util.getUrl('User:' + c.name) + '" title="' + c.name + '">' + c.name + '</a>';
		var html = '<div class="ajaxcomment-container" id="ajaxcomment-' + c.id + '">'
			+ '<div class="ajaxcomment">'
			+ '<div class="ajaxcomment-sig">' + mw.message('ajaxcomments-sig', ulink, c.date ).text() + '</div>'
			+ ( c.avatar ? '<div class="ajaxcomment-icon"><img src="' + c.avatar + '" alt="' + c.name + '" /></div>' : '' )
			+ '<div class="ajaxcomment-text">' + c.html + '</div>';
		if( canComment) {
				// Reply link
				html += '<div class="buttons">'
				html += '<button class="reply">' + mw.message('ajaxcomments-reply').text() + '</button>';

				// If sysop, or no replies and current user is owner, add edit/del links
				if( sysop || user == c.user ) {
					html += '<button class="edit">' + mw.message('ajaxcomments-edit').text() + '</button>'
						+ '<button class="del">' + mw.message('ajaxcomments-del').text() + '</button>';
				}
				html += '</div>';
		}
		html += '</div><div class="replies"></div></div>';
		return html;
	}

	/**
	 * Add a message if no comments, remove message if comments
	 */
	function noComments() {
		console.dir(comments);
		if(hasKeys(comments)) $('#ajaxcomments-none').remove();
		else $('#ajaxcomments').html( '<div id="ajaxcomments-none">' + mw.message('ajaxcomments-none').text() + '</div>' );
	}

	/**
	 * Return whether or not the passed object has any keys without iterating over it or using Object.keys
	 */
	function hasKeys(o) {
		var i = false;
		for(i in o) break;
		return i !== false;
	};
/*
	 // Render the comment data structure as HTML
	 // - also render a no comments message if none
	 // - and an add comments link at the top
	private function renderComments() {
		global $wgUser;
		$html = '';

		if( $html == '' ) $html = "<i id=\"ajaxcomments-none\">" . wfMessage( 'ajaxcomments-none' )->text() . "</i><br />";


	}

	 // Render a single comment and any of it's replies
	 // - this is recursive - it will render any replies which could in turn contain replies etc
	 // - renders edit/delete link if sysop, or no replies and current user is owner
	 // - if likeonly is set, return only the like/dislike links
	private function renderComment( $id, $likeonly = false ) {
		$curName = $wgUser->getName();
		$c = $this->comments[$id];
		$html = '';

		// Render user name as link
		$name = $c[AJAXCOMMENTS_USER];
		$user = User::newFromName( $name );
		$url = $user->getUserPage()->getLocalUrl();
		$ulink = "<a href=\"$url\">$name</a>";

		// Get the user's gravitar url
		if( $wgAjaxCommentsAvatars && $user->isEmailConfirmed() ) {
			$email = $user->getEmail();
			$grav = "http://www.gravatar.com/avatar/" . md5( strtolower( $email ) ) . "?s=50&d=wavatar";
			$grav = "<img src=\"$grav\" alt=\"$name\" />";
		} else $grav = '';

		if( !$likeonly ) $html .= "<div class=\"ajaxcomment\" id=\"ajaxcomments-$id\">\n" .
			"<div class=\"ajaxcomment-sig\">" .
				wfMessage( 'ajaxcomments-sig', $ulink, $wgLang->timeanddate( $c[AJAXCOMMENTS_DATE], true ) )->text() .
			"</div>\n<div class=\"ajaxcomment-icon\">$grav</div><div class=\"ajaxcomment-text\">" .
				$wgParser->parse( $c[AJAXCOMMENTS_TEXT], $this->talk, new ParserOptions(), true, true )->getText() .
			"</div>\n<ul class=\"ajaxcomment-links\">";

		// If logged in, allow replies and editing etc
		if( $this->canComment ) {

			if( !$likeonly ) {

				// Reply link
				$html .= "<li id=\"ajaxcomment-reply\"><a href=\"javascript:ajaxcomment_reply('$id')\">" . wfMessage( 'ajaxcomments-reply' )->text() . "</a></li>\n";

				// If sysop, or no replies and current user is owner, add edit/del links
				if( in_array( 'sysop', $wgUser->getEffectiveGroups() ) || ( $curName == $c[AJAXCOMMENTS_USER] && $r == '' ) ) {
					$html .= "<li id=\"ajaxcomment-edit\"><a href=\"javascript:ajaxcomment_edit('$id')\">" . wfMessage( 'ajaxcomments-edit' )->text() . "</a></li>\n";
					$html .= "<li id=\"ajaxcomment-del\"><a href=\"javascript:ajaxcomment_del('$id')\">" . wfMessage( 'ajaxcomments-del' )->text() . "</a></li>\n";
				}
			}

			// Make the like/dislike links
			if( $wgAjaxCommentsLikeDislike ) {
				if( $curName != $name ) {
					if( $like <= 0 ) $likelink = " onclick=\"javascript:ajaxcomment_like('$id',1)\" class=\"ajaxcomment-active\"";
					if( $like >= 0 ) $dislikelink = " onclick=\"javascript:ajaxcomment_like('$id',-1)\" class=\"ajaxcomment-active\"";
				}

				// Add the likes and dislikes links
				$clikes = count( $likes );
				$cdislikes = count( $dislikes );
				$likes = $this->formatNameList( $likes, 'like' );
				$dislikes = $this->formatNameList( $dislikes, 'dislike' );
				$html .= "<li title=\"$likes\" id=\"ajaxcomment-like\"$likelink>$clikes</li>\n";
				$html .= "<li title=\"$dislikes\" id=\"ajaxcomment-dislike\"$dislikelink>$cdislikes</li>\n";
			}
		}

		if( !$likeonly ) $html .= "</ul>$r</div>\n";
		return $html;
	}

	private function formatNameList( $list, $msg ) {
		$len = count( $list );
		if( $len < 1 ) return wfMessage( "ajaxcomments-no$msg" )->text();
		if( $len == 1 ) return wfMessage( "ajaxcomments-one$msg", $list[0] )->text();
		$last = array_pop( $list );
		return wfMessage( "ajaxcomments-many$msg", join( ', ', $list ), $last )->text();
	}
*/


});
