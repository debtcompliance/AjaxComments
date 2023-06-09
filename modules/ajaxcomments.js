$(document).ready( function() {

	"use strict";

	// Client copy of all comments on this page
	let comments = {};
	let count = 0;

	// Remember ID of this page for ajax requests
	const page = mw.config.get('wgArticleId');

	// User info
	const user = mw.config.get('wgUserId');
	const username = mw.config.get('wgUserName');
	const groups = mw.config.get('wgUserGroups');
	const sysop = mw.config.get('ajaxCommentsAdmin');

	// Is the user allowed to add comments/edit etc?
	const canComment = mw.config.get('ajaxCommentsCanComment');

	// Are we using like/dislike links?
	const likeDislike = mw.config.get('ajaxCommentsLikeDislike');

	// Remember latest comment received
	let timestamp = 0;

	// Whether WebSocket is available
	let ws = false;

	// Names for our events that is specific to this article on this wiki
	let prefix = mw.config.get('wsWikiID') + page + ':';
	let wsRender = prefix + 'Render';
	let wsDelete = prefix + 'Delete';

	// Get the Ajax polling rate (-1 means comments are disabled for this page)
	let poll = mw.config.get('ajaxCommentsPollServer');

	// Most of the ajax request data is the same for all requests
	let request = {
		type: 'POST',
		url: mw.util.wikiScript('api'),
		data: {
			action: 'ajaxcomments',
			format: 'json',
		},
		dataType: 'json',
	};

	// If the comments area has been added, create a div for the comments to render into with a loader in it
	if($('#ajaxcomments-name').length > 0) {

		// Change the talk page tab to a local link to the comments at the end of the page if it exists
		$('#ca-talk a').attr('href','#ajaxcomments');
		$('#ca-talk').removeClass('new');

		// Create a target for the comments and put a loader in it
		$('#ajaxcomments-name').after('<div id="ajaxcomments-wrapper"><div id="ajaxcomments" class="ajaxcomments-loader"></div></div>');

		// If WebSocket is available, connect it and set rendering and deleting of comments to occur when notified
		updateComments();
		if(typeof webSocket === 'object') {
			ws = webSocket.connect();
			webSocket.disconnected(updateComments);
			webSocket.subscribe(wsRender, function(data) { renderComments(data.msg) } );
			webSocket.subscribe(wsDelete, function(data) { del(data.msg, false) } );
		}
	}

	/**
	 * Ask the server for the rendered comments on a regular intervale (unless WebSocket connected)
	 */
	function updateComments() {
		request.data.type = 'get';
		request.data.page = page;
		request.data.id = timestamp;
		request.success = function(json) {
			renderComments(json.ajaxcomments);
		};
		$.ajax(request).then(function() {
			if(!(typeof webSocket === 'object' && webSocket.connected()) && poll > 0 ) setTimeout(updateComments, poll * 1000);
		});
	}

	/**
	 * An delete link has been clicked
	 */
	function del(idlist, notify) {
		let i, id, buttons;
		for( i = 0; i < idlist.length; i++ ) {
			id = idlist[i];
			if(notify) {
				buttons = {}
				buttons[mw.message( 'ajaxcomments-yes' ).escaped()] = function() {
					delReal(id, page, notify);
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
			} else delReal(id, page, notify);
		}
	}

	/**
	 * Do the actual delete
	 */
	function delReal(id, page, notify) {
		let i, e;
		console.log('AjaxComments: del(' + id + ')');

		// Replace the comment content with a loader
		e = $('#ajaxcomment-' + id);
		e.addClass('loading');

		// Submit the delete request
		request.data.type = 'del';
		request.data.page = page;
		request.data.id = id;
		request.success = function(json) {
			let data = json.ajaxcomments;

			// Delete the this comment's visual element (which contains all replies)
			e.fadeOut(500);

			// Delete the returned ID's from the local comments data store
			for( i = 0; i < data.length; i++ ) delete(comments[data[i]]);

			// If no comments, add message
			noComments();

			// If notify set, send this id list via WebSocket
			if(ws && notify) webSocket.send(wsDelete, data);
		};
		$.ajax(request);
	}

	/**
	 * Send a request to like/dislike an item
	 * - the returned response is the new like/dislike links
	 */
	function like(id, val) {
		let e = $('#ajaxcomment-' + id);
		console.log('AjaxComments: ' + (val < 0 ? 'dis' : '') + 'like(' + id + ')');
		e.addClass('loading');
		request.data.type = 'like';
		request.data.page = page;
		request.data.id = id;
		request.data.data = val;
		request.success = function(json) {
			let data = json.ajaxcomments;
			let c = comments[id];
			c['like'] = data.like;
			c['dislike'] = data.dislike;
			renderComments([c]);
			if(ws) webSocket.send(wsAjaxCommentsRender, [id]);
		};
		$.ajax(request);
	}

	/**
	 * Open a comment input box to add, edit or reply
	 */
	function input(type, id) {
		let c, html, sel = '#ajaxcomment-' + id;

		// Don't add if add already open
		if(type == 'add' && $('#ajaxcomment-0').length > 0) return;

		// Cancel any existing inputs
		cancel();

		// Hide the no comments message if exists
		$('#ajaxcomments-none').hide();

		// Build the input with it's submit and cancel buttons
		html = '<div id="ajaxcomment-input" class="ajaxcomment-input ' + type + '"><textarea></textarea>'
			+ '<button class="submit">' + mw.message('ajaxcomments-post').text() + '</button>'
			+ '<button class="cancel">' + mw.message('ajaxcomments-cancel').text() + '</button>'
			+ '</div>';

		// If replying or adding, create a new empty comment structure with the input in it
		if(type == 'add' || type == 'reply') {
			html = renderComment({
				id: 0,
				parent: type == 'add' ? null : id,
				user: user,
				name: username,
				time: '',
				date: '',
				text: '',
				html: '',
				like: [],
				dislike: [],
				avatar: false,
			}, html);
		};

		// Put the input in the comment in the page
		if(type == 'add') $('#ajaxcomments-add').after(html);
		else if(type == 'edit') $(sel + ' .ajaxcomment-text:first').after(html);
		else if(type == 'reply') {
			$(sel + ' .replies:first').prepend(html);
			sel = '#ajaxcomment-0';
		}
		if(type == 'add' || type == 'reply') $('#ajaxcomment-0').fadeIn(500);

		// Hide the buttons, avatar and text
		$(sel + ' .buttons:first').hide()

		// If editing, add the source
		if(type == 'edit') $(sel + ' textarea:first').text(comments[id].text);

		// Activate the buttons
		$(sel + ' button.cancel:first').click(function() { cancel(); });
		$(sel + ' button.submit:first').data({'id': id, 'type': type}).click(function() {
			submit( $(this).data('type'), $(this).data('id') );
		});
	}

	/**
	 * Remove any current comment input box, or new comment
	 */
	function cancel() {
		if($('#ajaxcomment-0').length > 0) return $('#ajaxcomment-0').fadeOut(300, function() { $('#ajaxcomment-0').remove(); });
		$('#ajaxcomment-input button').hide();
		$('#ajaxcomment-input').fadeOut(300, function() { $(this).remove(); });
		$('.ajaxcomment-icon').show();
		$('.ajaxcomment-text').show();
		$('#ajaxcomments .buttons').show();
		$('#ajaxcomments-none').show();
	}

	/**
	 * Submit a new comment, edit or reply
	 */
	function submit(type, id) {
		let text, e = $('#ajaxcomment-' + ( type == 'reply' ? '0' : id ) );
		console.log('AjaxComments: ' + type + '(' + id + ')');

		// Get the new text from the textarea and remove it
		text = $('textarea:first', e).val();
		$('.ajaxcomment-input', e).remove();
		$('.ajaxcomment .buttons').show();

		// Add a loader
		e.addClass('loading');

		// Send the command and render the returned data
		request.data.type = type;
		request.data.page = page;
		request.data.id = id;
		request.data.data = text;
		request.success = function(json) {
			let data = json.ajaxcomments;
			$('#ajaxcomment-0').remove();
			renderComments([data]);
			if (ws) webSocket.send(wsRender, [data]);
		};
		$.ajax(request);
	}

	/**
	 * Render the passed comments data as HTML and insert into the page
	 * - this may be new comments to insert or prepend, or ones that need to be replaced
	 */
	function renderComments(data) {
		let i, replies, sel, bsel, c, html;
		
		// If first render,
		if($('#ajaxcomments').hasClass('ajaxcomments-loader')) {

			// Remove loader
			$('#ajaxcomments').removeClass('ajaxcomments-loader');

			// If canComment, include an add comment button
			let html = canComment
				? '<button id="ajaxcomments-add">' + mw.message('ajaxcomments-add').text() + '</button>'
				: '<i>' + mw.message('ajaxcomments-anon').text() + '</i>';
			$('#ajaxcomments').before('<div class="ajaxcomment-links">' + html + '</div>');
			$('#ajaxcomments-add').click(function() { input('add', 0); });
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

			// If this comment is already rendered, update it, but preseve the replies
			sel = '#ajaxcomment-' + c.id;
			if($(sel).length > 0) {
				replies = $(sel + ' .replies:first').detach();
				$(sel).replaceWith(c.rendered);
				$(sel + ' .replies').append(replies);
			} else {

				// If it's a reply, insert it at the top of the replies for it's parent
				if(c.parent > 0) $('#ajaxcomment-' + c.parent + ' .replies:first').prepend(c.rendered);

				// If it's a new comment insert it at the top
				else $('#ajaxcomments').prepend(c.rendered);
			}

			// Activate it's buttons if the user can comment
			if(canComment) {
				bsel = sel + ' .ajaxcomment:first';
				$(bsel + ' button').data('id', c.id);
				$(bsel + ' .reply').click(function() { input('reply', $(this).data('id')); });
				$(bsel + ' .edit').click(function() { input('edit', $(this).data('id')); });
				$(bsel + ' .del').click(function() { del([$(this).data('id')], true); });
				if($.inArray(username, c.like) < 0) $(bsel + ' .like').css('cursor','pointer').click(function() { like($(this).data('id'), 1); });
				if($.inArray(username, c.dislike) < 0) $(bsel + ' .dislike').css('cursor','pointer').click(function() { like($(this).data('id'), -1); });
			}
		}

		// If no comments, add message
		noComments();
	}

	/**
	 * Render a single comment as HTML (without its replies)
	 */
	function renderComment(c, input) {
		let hash = window.location.hash == '#comment' + c.id ? ' selected' : '';                                          // Make this comment selected if t's ID is in the # fragment
		let ulink = '<a href="' + mw.util.getUrl('User:' + c.name) + '" title="' + c.name + '">' + c.name + '</a>';       // Link to user page
		let html = '<div class="ajaxcomment-container" id="ajaxcomment-' + c.id + '">'
			+ '<a name="comment' + c.id + '"></a><div class="ajaxcomment' + hash + '">'                                     // Allow scrolling to the comment with #
			+ '<div class="ajaxcomment-sig">' + mw.message('ajaxcomments-sig', ulink, c.date ).text() + '</div>'            // Signature
			+ ( c.avatar ? '<div class="ajaxcomment-icon"><img src="' + c.avatar + '" alt="' + c.name + '" /></div>' : '' ) // Avatar
			+ '<div class="ajaxcomment-text">' + c.html + '</div>'                                                          // Comment body
			+ ( input === undefined ? '' : input )                                                                          // if a text input was supplied add it here
			+ '<div class="buttons">'
			+ ( likeDislike ? likeButton(c, 'like') + likeButton(c, 'dislike') : '' );                                      // Like and dislike buttons

		// Add reply and edit/del buttons if user can comment and has right to edit this comment
		if(canComment) {
			html += '<button class="reply">' + mw.message('ajaxcomments-reply').text() + '</button>';
			if( sysop || user == c.user ) {
				html += '<button class="edit">' + mw.message('ajaxcomments-edit').text() + '</button>'
					+ '<button class="del">' + mw.message('ajaxcomments-del').text() + '</button>';
			}
		}
		html += '<span class="loader"></span></div></div><div class="replies"></div></div>';
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
		let i, csv = '', sep = '', names = c[type], len = names.length, title = '';

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
		let i = false;
		for(i in o) break;
		return i !== false;
	};
});
