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

	// A name for our event that is specific to this article on this wiki
	var wsAjaxCommentsEvent = mw.config.get('wsWikiID') + page + ':UpdateComments';

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

	// If WebSocket is available, connect it and set updating to occur when notified
	updateComments();
	if(typeof webSocket === 'object') {
		ws = webSocket.connect();
		webSocket.disconnected(updateComments);
		webSocket.subscribe(wsAjaxCommentsEvent, updateComments);
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
	function del(id) {
		var target = $('#ajaxcomment-' + id);
		var buttons = {};
		buttons[mw.message( 'ajaxcomments-yes' ).escaped()] = function() {
			target.html('<div class="ajaxcomments-loader"></div>');
			$.ajax({
				type: 'GET',
				url: mw.util.wikiScript(),
				data: {
					action: 'ajaxcomments',
					title: mw.config.get('wgPageName'),
					cmd: 'del',
					id: id,
				},
				context: target,
				dataType: 'html',
				success: function(html) {
					this.replaceWith(html);
					if(ws) webSocket.send(wsAjaxCommentsEvent);
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

	/**
	 * Send a request to like/dislike an item
	 * - the returned response is the new like/dislike links
	 */
	function like(id, val) {
		var target = $('#ajaxcomments-' + id);
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
		var sel, html;
		if(id === undefined) id = 'new';
		sel = '#ajaxcomment-' + id;

		// Cancel any existing inputs
		cancel();

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
		else if(type == 'reply') $('.replies:first', sel).prepend(html);

		// Disable the buttons and hide the text and add the source if editing
		if(type == 'edit') {
			$(sel + ' .buttons:first').hide()
			$(sel + ' .ajaxcomment-text:first').hide();
			$(sel + ' textarea:first').text(comments[id].text);
		}

		// Activate the buttons
		$(sel + ' button.cancel:first').click(cancel);
		$(sel + ' button.submit:first').data({'id': id, 'type': type}).click(function() {
			submit( $(this).data('type'), $(this).data('id') );
		});
	}

	/**
	 * Remove any current comment input box, or new comment
	 */
	function cancel() {
		$('#ajaxcomment-new').remove();
		$('#ajaxcomment-input').remove();
		$('.ajaxcomment-text').show();
		$('#ajaxcomments .buttons').show();
	}

	/**
	 * Submit a new comment, edit or reply
	 */
	function submit(type, id) {
		var text, e = $('#ajaxcomment-' + id);
/*
		// If it's an add, create the target at the end
		if( type == 'add' ) {
			$('#ajaxcomment-add').parent().after('<div id="ajaxcomments-new"></div>');
			target = $('#ajaxcomments-new');
			text = $('#ajaxcomment-input textarea').val();
		}

		// If it's an edit, create the target as the current comment
		if( cmd == 'edit' ) {
			var c = e.parent().parent();
			target = $('.ajaxcomment-text', c).first();
			text = $('#ajaxcomment-input textarea').val();
			id = c.attr('id').substr(13);
		}

		// If it's a reply, create the target within the current comment
		if( cmd == 'reply' ) {
			e.parent().before('<div id="ajaxcomments-new"></div>');
			target = $('#ajaxcomments-new');
			text = $('#ajaxcomment-input textarea').val();
			id = target.parent().attr('id').substr(13);
		}
*/
		// Get the new text from the textarea
		text = $('textarea:first', e).val();
		console.log(text);

		// Replace the comment content with a loader
		cancel();
		$('.ajaxcomment-text:first', e).html('<div class="ajaxcomments-loader"></div>');

		// Send the command and render the returned data
		$.ajax({
			type: 'GET',
			url: mw.util.wikiScript(),
			data: { action: 'ajax', rs: 'AjaxComments::ajax', rsargs: [type, page, id, text] },
			dataType: 'json',
			success: function(data) {
				renderComments(data);
				if(ws) webSocket.send(wsAjaxCommentsEvent);
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
			$('#ajaxcomments-add').click(function() { input('add'); });
		}

		// Copy all the data into the main comments data structure with rendered html
		for( i = 0; i < data.length; i++ ) {
			c = data[i];
			c.rendered = renderComment(c);
			if(!(c.id in comments)) count++;
			comments[c.id] = c;
			if(c.time > timestamp) timestamp = c.time;
		}

		// If no comments, add message and bail
		if( count == 0 ) {
			$('#ajaxcomments').html( '<i>' + mw.message('ajaxcomments-none').text() + '</i>' );
			return;
		}

		// Scan through again inserting them into the comments area of into their parent comments
		// - they're ordered by timestamp, so parents always exist before their replies are processed
		for( i = 0; i < data.length; i++ ) {
			c = data[i];

			// If this comment is already rendered, update it
			sel = '#ajaxcomment-' + c.id;
			console.log(sel);
			if($(sel).length > 0) $(sel).replace(c.rendered);
			else {

				// If it's a reply, insert it at the top of the replies for it's parent
				if(c.parent > 0) $('#ajaxcomment-' + c.parent + ' .replies').prepend(c.rendered);

				// If it's a new comment insert it at the top
				else $('#ajaxcomments').prepend(c.rendered);

				// Since it's a newly added comment, activate it's buttons
				$('button', sel).data('id', c.id);
				$('.reply ', sel).click(function() { input('reply', $(this).data('id')); });
				$('.edit ', sel).click(function() { input('edit', $(this).data('id')); });
				$('.del ', sel).click(function() { del($(this).data('id')); });
			}
		}
	}

	/**
	 * Render a single comment as HTML (without its replies)
	 */
	function renderComment(c) {
		var html = '<div class="ajaxcomment-container" id="ajaxcomment-' + c.id + '">'
			+ '<div class="ajaxcomment">'
			+ '<div class="ajaxcomment-text">' + c.html + '</div>';
		if( canComment) {
				// Reply link
				html += '<div class="buttons">'
				html += '<button class="reply">' + mw.message( 'ajaxcomments-reply' ).text() + '</button>';

				// If sysop, or no replies and current user is owner, add edit/del links
				if( sysop || user == c.user ) {
					html += '<button class="edit">' + mw.message( 'ajaxcomments-edit' ).text() + '</button>'
						+ '<button class="del">' + mw.message( 'ajaxcomments-del' ).text() + '</button>';
				}
				html += '</div>';
		}
		html += '</div><div class="replies"></div></div>';
		return html;
	}

/*
	 // Render the comment data structure as HTML
	 // - also render a no comments message if none
	 // - and an add comments link at the top
	private function renderComments() {
		global $wgUser;
		$html = '';
		foreach( $this->comments as $id => $comment ) {
			if( $comment[AJAXCOMMENTS_PARENT] === false ) $html = $this->renderComment( $id ) . $html;
		}
		if( $html == '' ) $html = "<i id=\"ajaxcomments-none\">" . wfMessage( 'ajaxcomments-none' )->text() . "</i><br />";

		// If logged in, allow replies and editing etc
		if( $this->canComment ) {
			$html = "<ul class=\"ajaxcomment-links\">" .
				"<li id=\"ajaxcomment-add\"><a href=\"javascript:ajaxcomment_add()\">" . wfMessage( 'ajaxcomments-add' )->text() . "</a></li>\n" .
				"</ul>\n$html";
		} else $html = "<i id=\"ajaxcomments-none\">" . wfMessage( 'ajaxcomments-anon' )->text() . "</i><br />$html";
		return $html;
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
