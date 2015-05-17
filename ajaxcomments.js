$(document).ready( function() {

	// Client copy of all comments on this page
	var comments = {};
	var count = 0;

	// Remember ID of this page for ajax requests
	var page = mw.config.get('wgArticleId');

	// User info
	var user = mw.config.get('wgUserId');
	var username = mw.config.get('wgUserName');
	var groups = mw.config.get('wgUserGroups');
	var sysop = $.inArray( 'sysop', groups ) >= 0;

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
		console.log('AjaxComments: ' + (val < 0 ? 'dis' : '') + 'like(' + id + ')');
		$.ajax({
			type: 'GET',
			url: mw.util.wikiScript(),
			data: { action: 'ajax', rs: 'AjaxComments::ajax', rsargs: ['like', page, id, val] },
			dataType: 'json',
			success: function(data) {
				var c = comments[id];
				c['like'] = data.like;
				c['dislike'] = data.dislike;
				renderComments([c]);
				if(ws) webSocket.send(wsAjaxCommentsRender, [id]);
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

			// Activate it's buttons if the user can comment
			if(canComment) {
				$(sel + ' button').data('id', c.id);
				$(sel + ' .reply').click(function() { input('reply', $(this).data('id')); });
				$(sel + ' .edit').click(function() { input('edit', $(this).data('id')); });
				$(sel + ' .del').click(function() { del([$(this).data('id')], true); });
				if($.inArray(username, c.like) < 0) $(sel + ' .like').css('cursor','pointer').click(function() { like($(this).data('id'), 1); });
				if($.inArray(username, c.dislike) < 0) $(sel + ' .dislike').css('cursor','pointer').click(function() { like($(this).data('id'), -1); });
			}
		}

		// If no comments, add message
		noComments();
	}

	/**
	 * Render a single comment as HTML (without its replies)
	 */
	function renderComment(c) {
		var hash = window.location.hash == '#comment' + c.id ? ' selected' : '';                                            // Make this comment selected if t's ID is in the # fragment
		var ulink = '<a href="' + mw.util.getUrl('User:' + c.name) + '" title="' + c.name + '">' + c.name + '</a>';         // Link to user page
		var html = '<div class="ajaxcomment-container" id="ajaxcomment-' + c.id + '">'
			+ '<a name="comment' + c.id + '"></a><div class="ajaxcomment' + hash + '">'                                     // Allow scrolling to the comment with #
			+ '<div class="ajaxcomment-sig">' + mw.message('ajaxcomments-sig', ulink, c.date ).text() + '</div>'            // Signature
			+ ( c.avatar ? '<div class="ajaxcomment-icon"><img src="' + c.avatar + '" alt="' + c.name + '" /></div>' : '' ) // Avatar
			+ '<div class="ajaxcomment-text">' + c.html + '</div>'                                                          // Comment body
			+ '<div class="buttons">'
			+ likeButton(c, 'like') + likeButton(c, 'dislike');                                                             // Like and dislike buttons

		// Add reply and edit/del buttons if user can comment and has right to edit this comment
		if(canComment) {
			html += '<button class="reply">' + mw.message('ajaxcomments-reply').text() + '</button>';
			if( sysop || user == c.user ) {
				html += '<button class="edit">' + mw.message('ajaxcomments-edit').text() + '</button>'
					+ '<button class="del">' + mw.message('ajaxcomments-del').text() + '</button>';
			}
		}
		html += '</div></div><div class="replies"></div></div>';
		if(hash) window.location = '#comment' + c.id;
		return html;
	}

	/**
	 * Add a message if no comments, remove message if comments
	 */
	function noComments() {
		if(hasKeys(comments)) $('#ajaxcomments-none').remove();
		else $('#ajaxcomments').html( '<div id="ajaxcomments-none">' + mw.message('ajaxcomments-none').text() + '</div>' );
	}

	/**
	 * Render a like or dislike button for the passed comment
	 */
	function likeButton(c, type) {
		var i, csv = '', sep = '', names = c[type], len = names.length, title = '';

		// Format the list of names
		if(len < 1) title = mw.message('ajaxcomments-no' + type).text();
		else if(len == 1) title = mw.message('ajaxcomments-one' + type, names[0]).text();
		else {
			for( i = 0; i < len - 1; i++ ) {
				csv += sep + names[i];
				sep = ', ';
			}
			title = mw.message('ajaxcomments-many' + type, csv, names[len - 1]).text();
		}

		return '<button class="' + type + '" title="' + title + '">' + ( type == 'like' ? '+' : '-' ) + len + '</button>';
	}

	/**
	 * Return whether or not the passed object has any keys without iterating over it or using Object.keys
	 */
	function hasKeys(o) {
		var i = false;
		for(i in o) break;
		return i !== false;
	};
});
