/* global jQuery, wp, acppauthorchat_config, acppauthorchat_ajax */
(function () {
'use strict';
var __ = wp.i18n.__;
var _n = wp.i18n._n;
var sprintf = wp.i18n.sprintf;
var wpDate = wp.date;
/*  Author Chat  v2.1.2  */
/**************************/

/* global acppauthorchat_config */
/* global acppauthorchat_ajax */

var acppauthorchat_Chat = function ()
{	
    var $this = this;

    $this.count = 0;
    $this.title = document.title;
    $this.interval_id = null;
    $this.interval_secs = parseInt(acppauthorchat_config.set_interval); /* interval time in seconds to check for some new message */
    $this.last_date = '';
    $this.last_id = '0';
    $this.total_rows = 0;
    $this.scroll_id = null;
    $this.win_is_focus = true;
    $this.truncate = [35, 10]; /* to truncate the length of url text */
    $this.uid_list = [];
    $this.uid_colors = Object.create(null);
    $this.uid_last_color = 1;
    $this.room_changed = false;
    $this.request_generation = 0;
    $this.request_serial = 0;
    $this.message_serial = 0;
    $this.active_requests = Object.create(null);
    $this.pending_reads = Object.create(null);
    $this.drafts = Object.create(null);
    $this.outbox = [];
    $this.sending = null;
    $this.history_request = null;
    $this.session_ended = false;
    $this.show_my_name = String(acppauthorchat_config.set_show_my_name) === '1';
    $this.show_weekdays = String(acppauthorchat_config.set_weekdays) === '1';
    $this.room_pressed_button_id = '0';
    $this.room_numbers = Object.create(null);
    $this.is_room_owner = false;

    /* list of ASCII Emoticons to detect */
    $this.emoticons = [
        [/(^|\s)(0:\)|0:\-\)|O:\))/gi, 'angel'],
        [/(^|\s)(>:\(|>:\-\()/gi, 'angry'],
        [/(^|\s)(:S|:'\-S)/gi, 'confused'],
        [/(^|\s)(:´\(|:\&#39;\()/gi, 'cry'],
        [/(^|\s)(\(6\)|>:\)|>:\-\))/gi, 'devil'],
        [/(^|\s)(:\||:\-\|)/gi, 'disappont'],
        [/(^|\s)(^|\s)(:\/|:\-\/)/gi, 'doubt'],
        [/(^|\s)(:D|:\-D)/gi, 'happy'],
        [/(^|\s)(XD|X\-D|=D)/gi, 'happy-x'],
        [/(^|\s)(:\{|:\-\{)/gi, 'pain'],
        [/(^|\s)(:\(|:\-\()/gi, 'sad'],
        [/(^|\s)(:\)|:\-\))/gi, 'smiley'],
        [/(^|\s)(=\)|=\-\))/gi, 'smiley-x'],
        [/(^|\s)(:o|:\-o)/gi, 'suprice'],
        [/(^|\s)(:P|:\-P)/gi, 'tongle'],
        [/(^|\s)(XP|X\-P|=P)/gi, 'tongle-x'],
        [/(^|\s)(;\)|;\-\))/gi, 'wink']
    ];

    this.bindEvents();
};

var _proto_ = acppauthorchat_Chat.prototype;

/** Bind view/editor events separately from state initialization. */
_proto_.bindEvents = function () {
    var $this = this;
    var $_chatArea = jQuery('#author-chat-area');
    var $_floatingPanel = jQuery('#acppauthorchat-panel');

    
    /* Click event of the Button to change room */
    var $_btnToPrivateConversation = jQuery('#author-chat #ac-rooms');
    $_btnToPrivateConversation.on("click", "button", function () {
        $this.selectRoom(this.id);
    });

    // The textarea maxlength attribute handles input limits without blocking navigation keys.
    /* watch textarea for release of key press */
    jQuery('#author-chat .ac-textarea').keyup(function (event)
    {
        var $me = jQuery(this);

        /* only send in case of press Enter, not if we press Shift + Enter */
        if (event.keyCode === 13 && !event.shiftKey)
        {
            var text = $me.val();
            var maxLength = parseInt(jQuery(this).attr("maxlength"), 10);
            var length = text.length;

            /* send */
            if (length <= maxLength + 1)
            {
                $this.send();
                // Pending messages are retained separately from the next draft.
            } else
            {
                $me.val(text.substring(0, maxLength));
            }
        }
    });

    /* Set focus on textarea with just click inside the Chat Area */
    $_chatArea.mouseup(function (event) {
        /* don't make focus on right click or if we select some text */
        if (event.which === 1 && window.getSelection().toString() === '')
        {
            jQuery('#author-chat .ac-textarea').focus();
        }

    });

    /* Set onBlur event of current window */
    jQuery(window).blur(function ()
    {
        $this.win_is_focus = false;
    });

    /* Set onFocus event of current window */
    jQuery(window).focus(function ()
    {
        $this.win_is_focus = true;
        if ($this.count)
        {
            /* check if we have the floating window */
            if ($_floatingPanel.length)
            {
                /* check if the floating window is minimized */
                if ($_floatingPanel.is(":hidden") === false)
                {
                    $this.clearCount();
                }
            } else
            {
                $this.clearCount();
            }
            document.title = $this.title;
        }
    });

    jQuery(window).on('beforeunload', function (event) {
        if ($this.outbox.length || jQuery('#author-chat .ac-textarea').val() ||
            Object.keys($this.drafts).some(function (room) { return room !== $this.room_pressed_button_id && $this.drafts[room]; })) {
            event.preventDefault();
            (event.originalEvent || event).returnValue = '';
            return '';
        }
    });

    /* MouseWheel event */
    $_chatArea.on('DOMMouseScroll mousewheel', function (ev) {
        var $me = jQuery(this),
                scroll_top = this.scrollTop,
                scroll_height = this.scrollHeight,
                height = $me.innerHeight(),
                delta = ev.originalEvent.wheelDelta,
                up = delta > 0;

        clearTimeout($this.scroll_id);

        /* prevent MouseWheel Scrolling of parent elements if we are on the chat-area */
        var prevent = function () {
            ev.stopPropagation();
            ev.preventDefault();
            ev.returnValue = false;
            return false;
        };
        if (!up && -delta >= scroll_height - height - scroll_top)
        {
            // Scrolling down, but this will take us past the bottom.
            $me.scrollTop(scroll_height);
            return prevent();
        } else if (up && delta > scroll_top)
        {
            // Scrolling up, but this will take us past the top.
            $me.scrollTop(0);
            return prevent();
        } 
    });

};

/* Get/Set the seconds for the getState interval */
_proto_.intervalSecs = function (seconds)
{
    this.interval_secs = seconds || this.interval_secs;
    return this.interval_secs;
};

/* Start the getState interval */
_proto_.start = function (seconds)
{
    var $this = this;

    /* set the new interval time, if we define one in the arguments */
    $this.intervalSecs(seconds);

    /* stop the current interval, if we have one */
    $this.stop();

    /* start a new interval time */
    $this.interval_id = setInterval(function ()
    {
        $this.getState();
    },
            $this.interval_secs * 1000);
};

/* Stop the getState interval */
_proto_.stop = function ()
{
    var $this = this;
    clearInterval($this.interval_id);
};

/* Clear the Counter */
_proto_.clearCount = function ()
{
    this.count = 0;
    jQuery('#author-chat-count').text('').css('display', 'none');
    jQuery('#acppauthorchat-unread-label').text('');
    jQuery('#acppauthorchat-toggle').attr('data-acppauthorchat-unread', 'false');
    document.title = this.title;
};

/* Requests belong to a view generation. Writes settle separately from DOM callbacks. */
_proto_.request = function (options)
{
    var chat = this;
    if (chat.session_ended) { return null; }
    var generation = chat.request_generation;
    var room = String(options.data.room_id === undefined ?
        (options.data.room_pressed_button_id === undefined ? chat.room_pressed_button_id : options.data.room_pressed_button_id) : options.data.room_id);
    var operation = options.data['function'];
    var read = ['initiate', 'update', 'getState', 'getRoomsForUser', 'getUsersForRoom', 'whoIsChannelOwner', 'searchUser'].indexOf(operation) !== -1;
    var key = generation + ':' + room + ':' + operation + (operation === 'searchUser' ? ':' + options.data.search_user : '');
    if (read && chat.pending_reads[key]) { return chat.pending_reads[key].xhr; }
    var entry = {read: read, generation: generation, xhr: null};
    var id = ++chat.request_serial;
    chat.active_requests[id] = entry;
    if (read) { chat.pending_reads[key] = entry; }
    var success = options.success, failure = options.error, complete = options.complete, settled = options.settled;
    delete options.settled;
    var outcome = null;
    function current() {
        return generation === chat.request_generation && room === chat.room_pressed_button_id && !chat.session_ended;
    }
    options.url = acppauthorchat_ajax.url;
    options.type = 'POST';
    options.timeout = 15000;
    options.data.action = acppauthorchat_ajax.action;
    options.data.nonce = acppauthorchat_ajax.nonce;
    options.success = function (data) {
        if (entry.done) { return; }
        outcome = {ok: true, data: data};
        if (!current()) { return; }
        if (operation === 'send') { jQuery('#author-chat .ac-request-status').text(''); }
        if (success) { success(data); }
    };
    options.error = function (xhr, status) {
        if (entry.done) { return; }
        outcome = {ok: false, xhr: xhr};
        if (!current() || status === 'abort') { return; }
        var code = xhr.responseJSON && xhr.responseJSON.code;
        var message = xhr.responseJSON && typeof xhr.responseJSON.message === 'string' ?
            xhr.responseJSON.message : __("Chat unavailable. Please try again.", 'author-chat');
        var loggedOut = xhr.status === 400 && String(xhr.responseText || '').trim() === '0';
        if (failure) { failure(xhr); }
        if (loggedOut || xhr.status === 401 || code === 'author_chat_invalid_nonce' || code === 'author_chat_access_denied' ||
            (xhr.status === 403 && (room === '0' || !/^(send|update|initiate|getUsersForRoom|whoIsChannelOwner|searchUser|addUser|removeUser)$/.test(operation)))) {
            chat.endSession(__("Please sign in again or reload the page. Your unsent text is kept below.", 'author-chat'));
        } else if (xhr.status === 403) {
            // Membership/ownership denial concerns this room, not the entire session.
            chat.selectRoom('0');
            jQuery('#author-chat .ac-request-status').text(__("This room is no longer available. Your unsent text has been kept.", 'author-chat'));
        } else {
            /* translators: %s: Error message explaining why the request failed. */
            jQuery('#author-chat .ac-request-status').text(sprintf(__("%s Your unsent text has been kept.", 'author-chat'), message));
        }
    };
    options.complete = function () {
        if (entry.done) { return; }
        entry.done = true;
        delete chat.active_requests[id];
        if (chat.pending_reads[key] === entry) { delete chat.pending_reads[key]; }
        // Always resolve the outbox model, even after a room switch. It must not
        // erase a new draft or render an obsolete history response.
        if (settled) { settled(outcome || {ok: false, xhr: {}}, current()); }
        if (current() && complete) { complete(); }
    };
    entry.xhr = jQuery.ajax(options);
    return entry.xhr;
};

_proto_.invalidateView = function ()
{
    this.request_generation++;
    this.history_request = null;
    var active = this.active_requests;
    Object.keys(active).forEach(function (id) {
        if (active[id].read && active[id].xhr && active[id].xhr.abort) { active[id].xhr.abort(); }
    });
};

_proto_.endSession = function (message)
{
    this.session_ended = true;
    this.invalidateView();
    this.stop();
    this.is_room_owner = false;
    jQuery('#author-chat-area ul, #ac-rooms, #ac-private-conversation, #ac-search-user, #ac-room-users-list').empty();
    jQuery('#author-chat .ac-request-status').text(message);
    this.renderOutbox();
};

_proto_.selectRoom = function (id)
{
    id = String(id);
    if (!/^(0|[1-9][0-9]*)$/.test(id) || id === this.room_pressed_button_id || this.session_ended) { return; }
    var textarea = jQuery('#author-chat .ac-textarea');
    this.drafts[this.room_pressed_button_id] = textarea.val();
    this.invalidateView();
    this.room_pressed_button_id = id;
    textarea.val(this.drafts[id] || '');
    this.is_room_owner = false;
    this.last_id = '0';
    this.last_date = '';
    this.room_changed = false;
    jQuery('#author-chat-area ul, #ac-search-user, #ac-room-users-list').empty();
    this.renderOutbox();
    this.initiate();
    this.getRoomsForUser();
    if (id !== '0') {
        this.whoIsChannelOwner();
        this.getUsersForRoom();
    }
};

/* Each view has its own cursor; no full-table COUNT or full-history polling. */
_proto_.getState = function ()
{
    if (this.session_ended) { return; }
    this.update();
    this.getRoomsForUser();
    if (this.room_pressed_button_id !== '0') {
        this.getUsersForRoom();
        this.whoIsChannelOwner();
    }
    this.renderOutbox();
};

/* IDs are decimal strings: preserve BIGINT precision in JavaScript. */
_proto_.compareIds = function (left, right)
{
    left = String(left); right = String(right);
    return left.length === right.length ? (left === right ? 0 : (left > right ? 1 : -1)) : (left.length > right.length ? 1 : -1);
};

_proto_.loadHistory = function (initial)
{
    var chat = this;
    if (chat.session_ended || chat.history_request) { return; }
    var token = {generation: chat.request_generation, room: chat.room_pressed_button_id};
    var cursor = chat.last_id;
    var more = false;
    chat.history_request = token;
    var requestOffset = -(new Date().getTimezoneOffset() / 60);
    chat.request({
        data: {'function': initial ? 'initiate' : 'update', room_pressed_button_id: token.room,
            after_id: cursor, user_time_zone: requestOffset},
        dataType: 'json',
        success: function (data) {
            if (!data || !Array.isArray(data.id) || !['id', 'uid', 'nick', 'msg', 'date'].every(function (field) {
                return Array.isArray(data[field]) && data[field].length === data.id.length;
            })) {
                jQuery('#author-chat .ac-request-status').text(__("Invalid chat response. Please reload.", 'author-chat'));
                return;
            }
            // Check the whole page before rendering anything or moving the cursor.
            var previous = cursor;
            if (!data.id.every(function (id) {
                id = String(id);
                if (!/^[1-9][0-9]*$/.test(id) || chat.compareIds(id, previous) <= 0) { return false; }
                previous = id;
                return true;
            }) || (data.has_more === true && (!data.id.length || String(data.next_id) !== previous))) {
                jQuery('#author-chat .ac-request-status').text(__("Invalid history cursor. Please reload.", 'author-chat'));
                return;
            }
            data.id.forEach(function (id, index) {
                if (chat.compareIds(id, chat.last_id) <= 0) { return; }
                chat.showMsg(data.uid[index], data.nick[index], data.msg[index], data.date[index], !initial, requestOffset);
                chat.last_id = String(id);
                if (!initial) { chat.notify(); }
            });
            more = data.has_more === true;
            if (data.id.length) { chat.scrollToBottom(); }
        },
        complete: function () {
            if (chat.history_request !== token) { return; }
            chat.history_request = null;
            if (more) { chat.loadHistory(initial); }
        }
    });
};

_proto_.update = function () { this.loadHistory(false); };

_proto_.initiate = function (seconds)
{
    this.loadHistory(true);
    jQuery('#author-chat').show();
    this.start(seconds);
};

_proto_.notify = function ()
{
    var dialog = jQuery('#acppauthorchat-panel');
    if (this.win_is_focus !== false && !(dialog.length && dialog.is(':hidden'))) { return; }
    this.count++;
    if (dialog.length && dialog.is(':hidden')) {
        jQuery('#acppauthorchat-toggle').attr('data-acppauthorchat-unread', 'true');
        jQuery('#author-chat-count').text(this.count).show();
        /* translators: %d: Number of unread chat messages. */
        jQuery('#acppauthorchat-unread-label').text(sprintf(_n('%d unread message', '%d unread messages', this.count, 'author-chat'), this.count));
    }
    /* translators: 1: Number of unread chat messages. 2: Original page title. */
    document.title = sprintf(__('(%1$d) %2$s', 'author-chat'), this.count, this.title);
    var sound = document.getElementById('author-chat-sound');
    if (sound) {
        var playing = sound.play();
        if (playing && playing.catch) { playing.catch(function () {}); }
    }
};

/* The editor only contains the NEXT draft; pending/failed messages live in an outbox. */
_proto_.send = function ()
{
    if (this.session_ended) { return; }
    var textarea = jQuery('#author-chat .ac-textarea');
    var draft = textarea.val() || '';
    var message = draft.replace(/\r?\n$/, '');
    if (!message.trim()) { return; }
    this.outbox.push({id: ++this.message_serial, room: this.room_pressed_button_id,
        draft: draft, message: message, status: 'queued'});
    textarea.val('');
    this.renderOutbox();
    this.flushOutbox();
};

_proto_.flushOutbox = function ()
{
    var chat = this;
    if (chat.session_ended || chat.sending) { return; }
    var item = chat.outbox.find(function (candidate, index) {
        return candidate.status === 'queued' && !chat.outbox.slice(0, index).some(function (earlier) {
            return earlier.room === candidate.room && earlier.status === 'failed';
        });
    });
    if (!item) { return; }
    chat.sending = item;
    item.status = 'sending';
    chat.renderOutbox();
    chat.request({
        data: {'function': 'send', message: item.message, room_pressed_button_id: item.room},
        dataType: 'json',
        settled: function (outcome, current) {
            if (outcome.ok) { chat.outbox = chat.outbox.filter(function (entry) { return entry !== item; }); }
            else { item.status = 'failed'; }
            chat.sending = null;
            // Render the current outbox model, never an old view's message list.
            chat.renderOutbox();
            if (current && outcome.ok) { chat.update(); }
            chat.flushOutbox();
        }
    });
};

_proto_.roomLabel = function (id)
{
    if (id === '0') { return __('Main room', 'author-chat'); }
    /* translators: %s: Private room number. */
    return sprintf(__('Room %s', 'author-chat'), this.room_numbers[id] || id);
};

_proto_.renderOutbox = function ()
{
    var chat = this, generation = chat.request_generation;
    var panel = jQuery('#author-chat .ac-outbox').empty();
    chat.outbox.forEach(function (item) {
        var row = jQuery('<div>').addClass('ac-outbox-message wp-exclude-emoji');
        var status = item.status === 'failed' ? __("Delivery unconfirmed. Check history before retrying.", 'author-chat') :
            (item.status === 'sending' ? __("Sending...", 'author-chat') : __("Queued", 'author-chat'));
        /* translators: 1: Room name. 2: Message delivery status. */
        row.append(jQuery('<div>').text(sprintf(__('%1$s: %2$s', 'author-chat'), chat.roomLabel(item.room), status)));
        row.append(jQuery('<pre>').addClass('wp-exclude-emoji').css({whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}).text(item.message));
        if (item.status === 'failed') {
            row.append(jQuery('<button>').attr('type', 'button').text(__("Retry", 'author-chat')).on('click', function () {
                if (generation !== chat.request_generation || chat.session_ended || item.status !== 'failed') { return; }
                item.status = 'queued';
                chat.flushOutbox();
            }));
            row.append(jQuery('<button>').attr('type', 'button').text(__("Move to draft", 'author-chat')).on('click', function () {
                if (generation !== chat.request_generation || chat.session_ended || item.status !== 'failed') { return; }
                chat.selectRoom(item.room);
                var textarea = jQuery('#author-chat .ac-textarea');
                textarea.val(item.message + (textarea.val() ? '\n' + textarea.val() : ''));
                chat.outbox = chat.outbox.filter(function (entry) { return entry !== item; });
                chat.renderOutbox();
                chat.flushOutbox();
            }));
        }
        panel.append(row);
    });
    if (chat.session_ended) {
        // Other rooms can no longer be selected, so keep their local drafts
        // visible for copying before the user reloads or signs in again.
        Object.keys(chat.drafts).forEach(function (room) {
            if (room === chat.room_pressed_button_id || !chat.drafts[room]) { return; }
            /* translators: %s: Room name. */
            panel.append(jQuery('<div>').text(sprintf(__('Unsent draft (%s)', 'author-chat'), chat.roomLabel(room))));
            panel.append(jQuery('<pre>').css({whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}).text(chat.drafts[room]));
        });
    }
};

/* Scroll to the bottom the chat area */
_proto_.scrollToBottom = function ()
{
    var $this = this,
            $_charArea = jQuery('#author-chat-area'),
            scroll_top = $_charArea.scrollTop(),
            scroll_height = $_charArea.prop('scrollHeight'),
            height = $_charArea.innerHeight();

    $this.scroll_id = null;

    if (scroll_top !== scroll_height - height)
    {
        /* add a timeout to check again in case of some attached images have not been loaded */
        $this.scroll_id = setTimeout(function () {
            $this.scrollToBottom();
        }, 2000);
    }
    /* scroll the chat area to the bottom */
    $_charArea.scrollTop(scroll_height);
    //$_charArea.find('.ac-top-date').hide();
};

/* Add the menssage to chat area */
_proto_.showMsg = function (uid, nick, msg, date, is_new, requestOffset = 0)
{
    var $this = this;

    // The legacy response carries a fixed-offset wall time. Recover the UTC instant
    // using that request's offset, then format it with the site's timezone.
    var legacyDate = String(date);
    var timestamp = /^\d{4}-\d{2}-\d{2},\d{2}:\d{2}:\d{2}$/.test(legacyDate) ?
        Date.parse(legacyDate.replace(',', 'T') + 'Z') - Math.round(requestOffset * 3600) * 1000 : NaN;
    var validDate = Number.isFinite(timestamp);
    var formats = wpDate.getSettings().formats;
    var declined = acppauthorchat_config.declined_formats;
    var month = validDate && declined ? wpDate.date('n', timestamp) : null;
    var calendarDay = validDate ? wpDate.date('Y-m-d', timestamp) : '';
    uid = String(uid);
    var own_message = uid === String(acppauthorchat_config.user_id);

    /* add the date label of current message if it's different from the last */
    if (validDate && calendarDay !== $this.last_date)
    {
        $this.last_date = calendarDay;
        var show_date = wpDate.dateI18n((month && declined.date[month]) || formats.date, timestamp);

        /* change the recent dates to weekday names */
        if ($this.show_weekdays)
        {
            var messageDay = Date.parse(calendarDay + 'T00:00:00Z');
            var today = Date.parse(wpDate.date('Y-m-d', Date.now()) + 'T00:00:00Z');
            var days = (today - messageDay) / 86400000;
            if (days === 0)
            {
                show_date = acppauthorchat_config.today;
            } else if (days === 1)
            {
                show_date = acppauthorchat_config.yesterday;
            } else if (days > 1 && days < 7)
            {
                var weekday = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][new Date(messageDay).getUTCDay()];
                show_date = acppauthorchat_config[weekday];
            }
        }

        jQuery('#author-chat-area ul').append(jQuery('<li>').addClass('ac-date').text(show_date));
    }

    /* Nick Classes */
    var nick_class = 'ac-nick';
    if (own_message) {
        if (!$this.show_my_name) {
            nick_class += ' ac-hide';
        }
    } else {
        /* Nick Colors */
        if ($this.uid_list.indexOf(uid) === -1)
        {
            $this.uid_list.push(uid);
            $this.uid_colors[ uid ] = $this.uid_last_color++;
            if ($this.uid_last_color > 20) {
                $this.uid_last_color = 1;
            }
        }
        nick_class += ' color-' + $this.uid_colors[ uid ];
    }

    /* Data-derived values enter the DOM as text or validated attributes. */
    // Prevent core's mutation observer from fetching remote emoji artwork.
    var $_bubble = jQuery('<li>').addClass('wp-exclude-emoji');
    $_bubble.append(jQuery('<div>').addClass('ac-time').text(validDate ? wpDate.dateI18n((month && declined.time[month]) || formats.time, timestamp) : ''));
    $_bubble.append(jQuery('<div>').addClass(nick_class).append(jQuery('<span>').addClass('wp-exclude-emoji').text(String(nick))));
    $_bubble.append(jQuery('<div>').addClass('ac-msg wp-exclude-emoji').append(jQuery('<span>').addClass('wp-exclude-emoji').append($this.processMsg(msg))));
    $_bubble.append(jQuery('<div>').addClass('ac-arrow'));
    if (own_message) { $_bubble.addClass('ac-me'); }
    /* set init values for the Animation FX for new messages */
    if (is_new) {
        var translateX = (own_message) ? '100%' : '-100%';
        $_bubble.css({transform: 'scaleX(0) scaleY(0) translateX(' + translateX + ')', opacity: 0});
    }

    /* add the message bubble to the chat area */
    jQuery('#author-chat-area ul').append($_bubble);

    /* run the animation FX for new messages */
    if (is_new) {
        var generation = $this.request_generation;
        setTimeout(function () {
            if (generation === $this.request_generation) {
                $_bubble.css({transform: 'scaleX(1) scaleY(1) translateX(0%)', opacity: 1});
            }
        }, 150);
    }
};

/* Process the menssage text to add the features */
_proto_.processMsg = function (message)
{
    return this.setLinks(String(message));
};

/* Emoticons and line breaks are fixed elements, never parsed HTML. */
_proto_.setEmoticons = function (text)
{
    var fragment = document.createDocumentFragment();
    var parts = String(text).split(/(\r\n|\r|\n|\t|\f)/);
    var emoticons = this.emoticons;
    parts.forEach(function (part) {
        if (/^(\r\n|\r|\n|\t|\f)$/.test(part)) {
            fragment.appendChild(document.createElement('br'));
            return;
        }
        while (part.length) {
            var best = null;
            emoticons.forEach(function (entry) {
                entry[0].lastIndex = 0;
                var match = entry[0].exec(part);
                if (match && (!best || match.index < best.match.index)) {
                    best = {match: match, name: entry[1]};
                }
            });
            if (!best) {
                fragment.appendChild(document.createTextNode(part));
                break;
            }
            var prefix = best.match[1] || '';
            fragment.appendChild(document.createTextNode(part.slice(0, best.match.index) + prefix));
            var icon = document.createElement('i');
            icon.className = 'ac-emo ' + best.name;
            var symbols = {angel:'😇',angry:'😠',confused:'😕',cry:'😢',devil:'😈',disappont:'😐',doubt:'😕',happy:'😃','happy-x':'😆',pain:'😖',sad:'🙁',smiley:'🙂','smiley-x':'😊',suprice:'😮',tongle:'😛','tongle-x':'😝',wink:'😉'};
            icon.appendChild(document.createTextNode(symbols[best.name] || '🙂'));
            fragment.appendChild(icon);
            part = part.slice(best.match.index + best.match[0].length);
        }
    });
    return fragment;
};

_proto_.setLinks = function (text)
{
    var fragment = document.createDocumentFragment();
    var regex = /\b(?:https?:\/\/|www\.)[^\s<>"']+/gi;
    var match, last = 0;
    while ((match = regex.exec(text)) !== null) {
        fragment.appendChild(this.setEmoticons(text.slice(last, match.index)));
        var value = match[0].replace(/[.,!?;:)\]}]+$/, '');
        var link = document.createElement('a');
        link.href = /^www\./i.test(value) ? 'https://' + value : value;
        if (/^https?:$/.test(link.protocol) && link.hostname) {
            link.title = value;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.appendChild(document.createTextNode(acppauthorchat_truncate_link(value, this.truncate[0], this.truncate[1])));
            fragment.appendChild(link);
        } else {
            fragment.appendChild(document.createTextNode(value));
        }
        fragment.appendChild(document.createTextNode(match[0].slice(value.length)));
        last = regex.lastIndex;
    }
    fragment.appendChild(this.setEmoticons(text.slice(last)));
    return fragment;
};

/* Add empty chat room */
_proto_.addRoom = function ()
{
    var chat = this;
    if (chat.creating_room || chat.session_ended) { return; }
    if (!chat.room_request_id) {
        if (!window.crypto || !window.crypto.getRandomValues) {
            jQuery('#author-chat .ac-request-status').text(__("Secure request IDs are unavailable. Please use a current browser.", 'author-chat'));
            return;
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        chat.room_request_id = Array.prototype.map.call(bytes, function (value) { return ('0' + value.toString(16)).slice(-2); }).join('');
    }
    chat.creating_room = true;
    chat.request({
        data: {'function': 'addRoom', request_id: chat.room_request_id},
        dataType: 'json',
        settled: function (outcome, current) {
            chat.creating_room = false;
            if (outcome.ok) {
                chat.room_request_id = null;
                if (current) {
                    chat.selectRoom(String(outcome.data.chat_room_id));
                    chat.getRoomsForUser();
                }
            }
            // Ambiguous network failure: retain the SAME request ID for retry.
        }
    });
};

/* Get chat rooms ids for current uset */
_proto_.getRoomsForUser = function ()
{
    var $this = this;
    $this.request({
        type: 'POST',
        data: {'function': 'getRoomsForUser'},
        dataType: 'json',
        success: function (data) {
            var rooms = jQuery('#author-chat #ac-rooms').empty();
            rooms.append(jQuery('<button>').attr('type', 'button').attr('id', '0').text($this.roomLabel('0')));
            var addRoomLabel = __('Add room', 'author-chat');
            jQuery('#author-chat #ac-private-conversation').empty().append(
                jQuery('<button>').attr('type', 'button').addClass('ac-add-room-button').attr('title', addRoomLabel).attr('aria-label', addRoomLabel).attr('disabled', data.can_create_rooms ? null : 'disabled').text('+')
                    .on('click', function () { $this.addRoom(); })
            );
            jQuery('#author-chat .ac-schema-status').text(data.private_chat_available === false ?
                __("Private chat is unavailable. The main room is still available.", 'author-chat') : '');
            data.chat_room_id.forEach(function (id, index) {
                id = String(id);
                if (/^[1-9][0-9]*$/.test(id)) {
                    $this.room_numbers[id] = index + 1;
                    rooms.append(jQuery('<button>').attr('type', 'button').attr('id', id).text($this.roomLabel(id)));
                }
            });
            var current = $this.room_pressed_button_id;
            if (current !== '0' && data.chat_room_id.map(String).indexOf(current) === -1) {
                $this.selectRoom('0');
            }
            rooms.find('button').each(function () {
                if (this.id === $this.room_pressed_button_id) { jQuery(this).addClass('active'); }
            });
        }
    });
};

/* Show user search bar and start searching while user start typing (powered by jQuery autocomplete) */
_proto_.showSearchUserBar = function ()
{
    var $this = this;
    var generation = $this.request_generation;

    jQuery('#author-chat #ac-search-user').empty().append(jQuery('<label>').attr('for', 'search-user-bar').text(__('Add participant', 'author-chat')), jQuery('<input>').attr('type','text').attr('id','search-user-bar').attr('placeholder', __('Type a nickname...', 'author-chat')));
    var search = jQuery('#search-user-bar').autocomplete({
        appendTo: '#author-chat #ac-search-user',
        messages: {
            noResults: __('No matching participants.', 'author-chat'),
            results: function (count) {
                /* translators: %d: Number of matching participants in the autocomplete list. */
                return sprintf(_n(
                    '%d matching participant. Use the up and down arrow keys to navigate.',
                    '%d matching participants. Use the up and down arrow keys to navigate.',
                    count, 'author-chat'
                ), count);
            }
        },

        source: function (request, response) {
            if (generation !== $this.request_generation) { response([]); return; }
            $this.request(
                    {
						url: acppauthorchat_ajax.url,
                        type: 'POST',
                        data:
                                {
                                    'function': 'searchUser',
                                    'search_user': request.term,
                                    'room_id': $this.room_pressed_button_id,
									'nonce': acppauthorchat_ajax.nonce
                                },
                        dataType: 'json',
                        success: function (data)
                        {
                            if (data !== null && data.user_id.length)
                            {
                                var suggestions = [];
                                
                                var rows = data.user_id.length;
                                for (var i = 0; i < rows; i++)
                                {
                                   suggestions.push({
                                       label: data.nickname[i],
                                       id: data.user_id[i]
                                   });
                                }
                                response(suggestions);
                            } else { response([]); }
                        }
                    });
        },
        minLength: 2,
        select: function (event, ui) {
            if (generation !== $this.request_generation) { return false; }
            $this.addUserToRoom(ui.item.id);
            // after success clear text box and change placeholder
            this.value = "";
            this.placeholder = __('Type a nickname...', 'author-chat');
            return false;
        }
    });
    var instance = search.autocomplete('instance');
    if (instance) {
        instance.liveRegion.addClass('screen-reader-text');
        instance._renderItem = function (list, item) {
            return jQuery('<li>').addClass('wp-exclude-emoji').append(
                jQuery('<div>').addClass('wp-exclude-emoji').append(
                    jQuery('<span>').addClass('wp-exclude-emoji').text(String(item.label))
                )
            ).appendTo(list);
        };
    }
};

/* Add user to conversation */
_proto_.addUserToRoom = function (user_id)
{
    var $this = this;
    
    $this.request(
            {
				url: acppauthorchat_ajax.url,
                type: 'POST',
                data:
                        {
                            'function': 'addUser',
                            'room_id': $this.room_pressed_button_id,
                            'user_id': user_id,
							'nonce': acppauthorchat_ajax.nonce
                        },
                dataType: 'json',
                success: function (data)
                {
                    $this.update();
                }
            });
};

/* Get participating users list for currently viewed room/channel */
_proto_.getUsersForRoom = function ()
{
    var $this = this;
    var currentRoom = $this.room_pressed_button_id;
    var generation = $this.request_generation;
    $this.request({
        type: 'POST',
        data: {'function': 'getUsersForRoom', 'room_id': currentRoom},
        dataType: 'json',
        success: function (data) {
            if (currentRoom !== $this.room_pressed_button_id) { return; }
            var list = jQuery('#author-chat #ac-room-users-list').empty();
            data.user_id.forEach(function (id, index) {
                id = String(id);
                if (!/^[1-9][0-9]*$/.test(id)) { return; }
                var name = jQuery('<span>').addClass('room-user-name wp-exclude-emoji').attr('id', id).append(jQuery('<span>').addClass('wp-exclude-emoji').text(String(data.nickname[index])));
                /* translators: %s: Participant's display name or username. */
                var removeLabel = sprintf(__('Remove participant: %s', 'author-chat'), String(data.nickname[index]));
                var remove = jQuery('<button>').attr('type','button').addClass('rem-user-button').attr('id', 'rem-user-' + id).attr('title', removeLabel).attr('aria-label', removeLabel).text('×');
                remove.on('click', function () {
                    if (generation !== $this.request_generation || currentRoom !== $this.room_pressed_button_id) { return; }
                    if ($this.is_room_owner === true) {
                        $this.removeUserFromRoom(id, currentRoom);
                    } else {
                        jQuery('#author-chat .ac-request-status').text(__('Only the room owner can remove participants.', 'author-chat'));
                    }
                });
                name.append(remove);
                list.append(jQuery('<span>').addClass('room-users-name').append(name));
            });
        }
    });
};

/* Remove user from conversation */
_proto_.removeUserFromRoom = function (user_id, room_id)
{
    var $this = this;
    
    $this.request(
            {
				url: acppauthorchat_ajax.url,
                type: 'POST',
                data:
                        {
                            'function': 'removeUser',
                            'room_id': room_id,
                            'user_id': user_id,
							'nonce': acppauthorchat_ajax.nonce
                        },
                dataType: 'json',
                success: function (data)
                {
                    if (String(user_id) === String(acppauthorchat_config.user_id)) {
                        $this.selectRoom('0');
                        $this.getRoomsForUser();
                    } else {
                        $this.getUsersForRoom();
                        $this.whoIsChannelOwner();
                    }
                }
            });
};

/* Who is room moderator(owner)? */
_proto_.whoIsChannelOwner = function ()
{
    var $this = this;
    var currentRoom = $this.room_pressed_button_id;
    $this.request({
        type: 'POST',
        data: {'function': 'whoIsChannelOwner', 'room_id': currentRoom},
        dataType: 'json',
        success: function (data) {
            if (currentRoom !== $this.room_pressed_button_id) { return; }
            var wasOwner = $this.is_room_owner;
            $this.is_room_owner = currentRoom !== '0' && data.user_id.length === 1 &&
                String(data.user_id[0]) === String(acppauthorchat_config.user_id);
            if ($this.is_room_owner && !wasOwner) { $this.showSearchUserBar(); }
            if (!$this.is_room_owner) { jQuery('#author-chat #ac-search-user').empty(); }
        }
    });
};

/** Keep both ends of long link labels without changing their destination. */
function acppauthorchat_truncate_link(text, leading, trailing) {
    return text.length > leading + trailing + 3 ? text.slice(0, leading) + '...' + text.slice(-trailing) : text;
}

window.acppauthorchat_Chat = acppauthorchat_Chat;
jQuery(function () {
    if (!document.querySelector('#author-chat')) { return; }
    var chat = new acppauthorchat_Chat();
    chat.initiate();
    jQuery('#acppauthorchat-toggle').on('click', function () {
        var panel = document.getElementById('acppauthorchat-panel');
        panel.hidden = !panel.hidden;
        jQuery(this).attr('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) { chat.clearCount(); chat.scrollToBottom(); }
    });
});
})();
