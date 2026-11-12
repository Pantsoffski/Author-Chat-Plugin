// Dependency-free unit tests. The small DOM double is not a browser integration test.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
    constructor(tag, text = '') { this.tag = tag; this.children = []; this.value = ''; this.text = text; this.events = {}; this.attrs = {}; }
    appendChild(node) {
        if (node.tag === '#fragment') { this.children.push(...node.children); }
        else { this.children.push(node); }
        return node;
    }
    set href(value) {
        this.attrs.href = value;
        try { const parsed = new URL(value); this.protocol = parsed.protocol; this.hostname = parsed.hostname; }
        catch { this.protocol = ''; this.hostname = ''; }
    }
    get textContent() { return this.text + this.children.map(node => node.textContent).join(''); }
}

function harness(flags = {}) {
    const selections = new Map();
    const requests = [];
    class Selection {
        constructor(nodes) { this.nodes = nodes; this.length = nodes.length; }
        on(name, selector, handler) { this.nodes.forEach(node => { node.events[name] = handler || selector; }); return this; }
        keydown(fn) { return this.on('keydown', fn); }
        keyup(fn) { return this.on('keyup', fn); }
        mouseup(fn) { return this.on('mouseup', fn); }
        blur(fn) { return this.on('blur', fn); }
        focus(fn) { return fn ? this.on('focus', fn) : this; }
        addClass(name) { this.nodes.forEach(node => { node.className = name; }); return this; }
        text(value) { this.nodes.forEach(node => { node.text = value; node.children = []; }); return this; }
        attr(name, value) {
            if (value === undefined) { return this.nodes[0].attrs[name]; }
            this.nodes.forEach(node => { node.attrs[name] = value; node[name] = value; }); return this;
        }
        append(value) {
            assert.ok(typeof value !== 'string', 'Dynamic HTML must not be parsed');
            this.nodes.forEach(node => (value instanceof Selection ? value.nodes : [value]).forEach(child => node.appendChild(child)));
            return this;
        }
        empty() { this.nodes.forEach(node => { node.children = []; node.text = ''; }); return this; }
        val(value) {
            if (value === undefined) { return this.nodes[0].value; }
            this.nodes.forEach(node => { node.value = value; }); return this;
        }
        find(tag) { return new Selection(this.nodes.flatMap(node => walk(node).filter(child => child !== node && child.tag === tag))); }
        each(fn) { this.nodes.forEach(node => fn.call(node)); return this; }
        css() { return this; }
        is() { return false; }
        show() { return this; }
        dialog() { return this; }
        html() { throw new Error('HTML parsing is not allowed in these rendering paths'); }
    }
    function jQuery(value) {
        if (value instanceof Element) { return new Selection([value]); }
        if (typeof value === 'string' && value.startsWith('<')) {
            assert.match(value, /^<[a-z]+>$/, 'Only fixed element creation is allowed');
            return new Selection([new Element(value.slice(1, -1))]);
        }
        if (typeof value === 'string' && value.includes(', ')) {
            return new Selection(value.split(', ').flatMap(selector => jQuery(selector).nodes));
        }
        const key = typeof value === 'string' ? value : 'window';
        if (!selections.has(key)) { selections.set(key, new Selection([new Element('selection')])); }
        return selections.get(key);
    }
    jQuery.ajax = options => { requests.push(options); return options; };
    const document = {
        title: 'Chat',
        createElement: tag => new Element(tag),
        createTextNode: text => new Element('#text', text),
        createDocumentFragment: () => new Element('#fragment'),
        getElementById: () => null,
    };
    const context = vm.createContext({
        wp: {i18n: {__: text => text}}, document, jQuery, window: {localStorage: {}, crypto: require('node:crypto').webcrypto}, console,
        acppauthorchat_config: {user_id: '7', set_interval: '2', set_show_my_name: '0', set_weekdays: '0', ...flags},
        acppauthorchat_ajax: {url: '/wp-admin/admin-ajax.php', action: 'acppauthorchat', nonce: 'test-nonce'},
        setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'acppauthorchat_chat.js'), 'utf8'), context);
    const chat = new context.window.acppauthorchat_Chat();
    chat.scrollToBottom = () => {};
    return {chat, jQuery, requests, selections};
}

function walk(node) { return [node, ...node.children.flatMap(walk)]; }

test('historical HTML, entities, scripts and event attributes stay text', () => {
    const {chat} = harness();
    for (const payload of ['<img src=x onerror=alert(1)>', '<svg onload=alert(1)>', '</span><script>alert(1)</script>', '&lt;img src=x onerror=alert(1)&gt;', '" onclick="alert(1)']) {
        const fragment = chat.processMsg(payload);
        assert.equal(fragment.textContent, payload);
        assert.ok(walk(fragment).every(node => ['#fragment', '#text'].includes(node.tag)));
    }
});

test('Unicode, emoji, line breaks and fixed emoticons survive', () => {
    const {chat} = harness();
    const fragment = chat.processMsg('Zażółć 😀 مرحبا\r\nDruga :)');
    assert.equal(walk(fragment).filter(node => node.tag === 'br').length, 1);
    assert.equal(walk(fragment).filter(node => node.tag === 'i').length, 1);
    assert.match(fragment.textContent, /Zażółć 😀 مرحباDruga /);
});

test('links allow HTTP(S), escape attributes by construction and never load previews', () => {
    const {chat} = harness();
    const fragment = chat.processMsg('https://example.test/a?q=1&b=2 www.example.test javascript:alert(1) data:text/html,boom https://example.test/"onmouseover="alert(1)');
    const nodes = walk(fragment);
    const links = nodes.filter(node => node.tag === 'a');
    assert.equal(links.length, 3);
    links.forEach(link => {
        assert.match(link.attrs.href, /^https?:\/\//);
        assert.equal(link.rel, 'noopener noreferrer');
        assert.equal(link.target, '_blank');
        assert.equal(link.events.click, undefined);
    });
    assert.ok(!nodes.some(node => ['img', 'script', 'iframe'].includes(node.tag)));
});

test('message nickname and date cannot create HTML elements', () => {
    const {chat, jQuery} = harness();
    chat.showMsg('8', '<img onerror=evil()>', '<svg onload=evil()>', '2026-11-11,<script>evil()</script>');
    const nodes = walk(jQuery('#author-chat-area ul').nodes[0]);
    assert.ok(!nodes.some(node => ['script', 'svg', 'img'].includes(node.tag)));
    assert.ok(nodes.some(node => node.text === '<img onerror=evil()>'));
});

test('participant handlers use their own user ID and reject stale room clicks', () => {
    const {chat, requests, jQuery} = harness();
    chat.room_pressed_button_id = '12';
    chat.is_room_owner = true;
    chat.getUsersForRoom();
    requests.pop().success({user_id: ['7', '8'], nickname: ['<svg onload=x>', 'Second']});
    const buttons = walk(jQuery('#author-chat #ac-room-users-list').nodes[0]).filter(node => node.className === 'rem-user-button');
    const removed = [];
    chat.removeUserFromRoom = (id, room) => removed.push([id, room]);
    buttons.forEach(button => button.events.click());
    assert.deepEqual(removed, [['7', '12'], ['8', '12']]);
    chat.room_pressed_button_id = '13';
    buttons[0].events.click();
    assert.equal(removed.length, 2);
});

test('all requests carry the hook action and nonce; failed sends keep the draft', () => {
    const {chat, requests, jQuery} = harness();
    jQuery('#author-chat .ac-textarea').val('Draft 😀\n');
    chat.send();
    const request = requests.pop();
    assert.equal(request.data.action, 'acppauthorchat');
    assert.equal(request.data.nonce, 'test-nonce');
    assert.equal(request.data.user_id, undefined);
    assert.equal(request.data.nickname, undefined);
    request.error({status: 503}); request.complete();
    assert.equal(chat.outbox[0].draft, 'Draft 😀\n');
    assert.equal(chat.outbox[0].status, 'failed');
    assert.equal(jQuery('#author-chat .ac-textarea').val(), '');
    const retryButton = walk(jQuery('#author-chat .ac-outbox').nodes[0]).find(node => node.tag === 'button' && node.text === 'Retry');
    retryButton.events.click();
    const retry = requests.pop();
    chat.update = () => {};
    retry.success({}); retry.complete();
    assert.equal(jQuery('#author-chat .ac-textarea').val(), '');
    assert.equal(chat.outbox.length, 0);
});

test('authorization failure clears data and ignores already in-flight successes', () => {
    const {chat, requests} = harness();
    let delivered = false;
    chat.request({data: {'function': 'initiate'}, success() { delivered = true; }});
    const stale = requests.pop();
    chat.request({data: {'function': 'getState'}});
    requests.pop().error({status: 403});
    stale.success({id: ['1']});
    assert.equal(delivered, false);
    assert.equal(chat.is_room_owner, false);
});

test('a room switch invalidates ownership and loads even in a secondary tab', () => {
    const {chat} = harness();
    const calls = [];
    ['initiate', 'getRoomsForUser', 'whoIsChannelOwner', 'getUsersForRoom'].forEach(name => { chat[name] = () => calls.push(name); });
    chat.is_room_owner = true;
    chat.selectRoom('19');
    assert.equal(chat.is_room_owner, false);
    assert.deepEqual(calls, ['initiate', 'getRoomsForUser', 'whoIsChannelOwner', 'getUsersForRoom']);
    chat.selectRoom('19\" onclick=evil()');
    assert.equal(calls.length, 4);
});

test('an old history response is ignored after changing rooms', () => {
    const {chat, requests} = harness();
    chat.room_pressed_button_id = '12';
    chat.update();
    const request = requests.pop();
    chat.room_pressed_button_id = '13';
    chat.showMsg = () => assert.fail('stale message rendered');
    request.success({id: ['1'], uid: ['7'], nick: ['x'], msg: ['x'], date: ['2026-11-11,10:00:00']});
});

test('untrusted room IDs cannot inject buttons or selector syntax', () => {
    const {chat, requests, jQuery} = harness();
    chat.getRoomsForUser();
    requests.pop().success({chat_room_id: ['12', '<svg onload=x>', '1\" onclick=x']});
    const buttons = walk(jQuery('#author-chat #ac-rooms').nodes[0]).filter(node => node.tag === 'button');
    assert.deepEqual(buttons.map(node => node.id), ['0', '12']);
});

test('server error messages are rendered as text', () => {
    const {chat, requests, jQuery} = harness();
    chat.request({data: {'function': 'send'}});
    requests.pop().error({status: 413, responseJSON: {message: '<img src=x onerror=evil()>'}});
    const status = jQuery('#author-chat .ac-request-status').nodes[0];
    assert.match(status.textContent, /<img src=x onerror=evil\(\)>/);
    assert.equal(status.children.length, 0);
});


function reply(request, data) { request.success(data); request.complete(); }
function reject(request, status, responseJSON, responseText = '') {
    request.error({status, responseJSON, responseText}, 'error'); request.complete();
}
function page(ids, more = false) {
    return {id: ids.map(String), uid: ids.map(() => '8'), nick: ids.map(() => 'User'),
        msg: ids.map(String), date: ids.map(() => '2026-11-11,10:00:00'),
        next_id: ids.length ? String(ids.at(-1)) : '0', has_more: more};
}

test('A-B-A rejects old history, ownership and participant responses', () => {
    const {chat, requests, jQuery} = harness();
    chat.room_pressed_button_id = '12';
    chat.initiate(); chat.whoIsChannelOwner(); chat.getUsersForRoom();
    const old = requests.slice();
    chat.selectRoom('13'); chat.selectRoom('12');
    assert.equal(chat.request_generation, 2);
    const latest = requests.filter(r => r.data.function === 'initiate' && r.data.room_pressed_button_id === '12').at(-1);
    reply(latest, page([20]));
    old[0].success(page([10])); old[0].complete();
    old[1].success({user_id: ['7']}); old[1].complete();
    old[2].success({user_id: ['99'], nickname: ['Old']}); old[2].complete();
    assert.equal(chat.last_id, '20');
    assert.equal(chat.is_room_owner, false);
    assert.ok(!jQuery('#author-chat #ac-room-users-list').nodes[0].textContent.includes('Old'));
});

test('late 403 from a previous view keeps current history and polling', () => {
    const {chat, requests, jQuery} = harness();
    chat.room_pressed_button_id = '12';
    chat.getUsersForRoom(); const old = requests[0];
    chat.selectRoom('13');
    jQuery('#author-chat-area ul').append(jQuery('<li>').text('current history'));
    let stopped = 0; chat.stop = () => { stopped++; };
    reject(old, 403, {code: 'author_chat_forbidden'});
    assert.equal(stopped, 0);
    assert.equal(chat.session_ended, false);
    assert.equal(chat.room_pressed_button_id, '13');
    assert.equal(jQuery('#author-chat-area ul').nodes[0].textContent, 'current history');
});

test('WP 400/0 ends an expired session, clears history, and keeps draft/outbox', () => {
    const {chat, requests, jQuery} = harness();
    jQuery('#author-chat-area ul').append(jQuery('<li>').text('private history'));
    jQuery('#ac-private-conversation').append(jQuery('<button>').text('+'));
    jQuery('#author-chat .ac-textarea').val('waiting\n'); chat.send();
    jQuery('#author-chat .ac-textarea').val('next draft');
    chat.getRoomsForUser();
    reject(requests.at(-1), 400, undefined, '0');
    assert.equal(chat.session_ended, true);
    assert.equal(jQuery('#author-chat-area ul').nodes[0].textContent, '');
    assert.equal(jQuery('#ac-private-conversation').nodes[0].textContent, '');
    assert.equal(jQuery('#author-chat .ac-textarea').val(), 'next draft');
    assert.equal(chat.outbox[0].message, 'waiting');
    const count = requests.length; chat.getState(); chat.send();
    assert.equal(requests.length, count);
});

test('room denial returns to main room without ending the session; nonce denial ends it', () => {
    const {chat, requests, jQuery} = harness();
    chat.room_pressed_button_id = '12';
    jQuery('#author-chat .ac-textarea').val('private draft');
    chat.getUsersForRoom(); reject(requests[0], 403, {code: 'author_chat_forbidden'});
    assert.equal(chat.room_pressed_button_id, '0');
    assert.equal(chat.session_ended, false);
    assert.equal(chat.drafts['12'], 'private draft');
    chat.selectRoom('13');
    reject(requests.at(-1), 403, {code: 'author_chat_invalid_nonce'});
    assert.equal(chat.session_ended, true);
});

test('only one history request runs per view and empty updates keep the cursor', () => {
    const {chat, requests} = harness();
    chat.last_id = '6';
    chat.update(); chat.update(); chat.initiate();
    assert.equal(requests.length, 1);
    reply(requests[0], page([]));
    assert.equal(chat.last_id, '6');
    assert.equal(chat.last_date, '');
    chat.update();
    assert.equal(requests.length, 2);
    assert.equal(requests[1].data.after_id, '6');
});

for (const size of [0, 1, 5000, 5001, 50000]) {
    test('cursor traversal renders every record exactly once for history size ' + size, () => {
        const {chat, requests} = harness();
        const seen = [];
        chat.showMsg = (uid, nick, message) => seen.push(Number(message));
        chat.initiate();
        for (let i = 0; i < requests.length; i++) {
            const request = requests[i];
            const after = Number(request.data.after_id);
            const end = Math.min(after + 200, size);
            const ids = Array.from({length: end - after}, (_, index) => after + index + 1);
            reply(request, page(ids, end < size));
        }
        assert.equal(seen.length, size);
        assert.equal(new Set(seen).size, size);
        seen.forEach((id, index) => assert.equal(id, index + 1));
        assert.equal(chat.last_id, String(size));
        assert.equal(requests.length, Math.max(1, Math.ceil(size / 200)));
        assert.equal(chat.history_request, null);
    });
}

test('an interrupted history resumes from the last delivered page', () => {
    const {chat, requests} = harness();
    const seen = []; chat.showMsg = (uid, nick, msg) => seen.push(msg);
    chat.initiate();
    reply(requests[0], page([1, 2], true));
    reject(requests[1], 503, {message: 'Temporary failure'});
    assert.equal(chat.last_id, '2');
    assert.equal(chat.history_request, null);
    chat.update();
    assert.equal(requests[2].data.after_id, '2');
    reply(requests[2], page([3]));
    assert.deepEqual(seen, ['1', '2', '3']);
});

test('BIGINT cursors remain exact and malformed or old pages cannot rewind them', () => {
    const {chat, requests} = harness();
    chat.last_id = '9007199254740992';
    chat.update(); reply(requests[0], page(['9007199254740993']));
    assert.equal(chat.last_id, '9007199254740993');
    chat.update(); reply(requests[1], page(['20', '10']));
    assert.equal(chat.last_id, '9007199254740993');
    requests[0].success(page(['1']));
    assert.equal(chat.last_id, '9007199254740993');
});

test('typing during send keeps the next draft and never resends the old prefix', () => {
    const {chat, requests, jQuery} = harness();
    chat.update = () => {};
    const editor = jQuery('#author-chat .ac-textarea');
    editor.val('first\n'); chat.send();
    assert.equal(editor.val(), '');
    editor.val('second');
    reply(requests[0], {});
    assert.equal(editor.val(), 'second');
    editor.val('second\n'); chat.send();
    assert.deepEqual(requests.map(r => r.data.message), ['first', 'second']);
});

test('rapid sends form a serial queue without clearing a newer draft', () => {
    const {chat, requests, jQuery} = harness();
    chat.update = () => {};
    const editor = jQuery('#author-chat .ac-textarea');
    for (const message of ['one', 'two', 'three']) { editor.val(message + '\n'); chat.send(); }
    editor.val('fourth draft');
    assert.equal(requests.length, 1);
    reply(requests[0], {}); assert.equal(requests.length, 2);
    reply(requests[1], {}); assert.equal(requests.length, 3);
    reply(requests[2], {});
    assert.deepEqual(requests.map(r => r.data.message), ['one', 'two', 'three']);
    assert.equal(editor.val(), 'fourth draft');
    assert.equal(chat.outbox.length, 0);
});

test('failed sends retain both texts, pause that room, and retry only by explicit action', () => {
    const {chat, requests, jQuery} = harness();
    chat.update = () => {};
    const editor = jQuery('#author-chat .ac-textarea');
    editor.val('one\n'); chat.send();
    editor.val('two\n'); chat.send();
    editor.val('next draft');
    reject(requests[0], 0);
    assert.equal(requests.length, 1);
    assert.equal(chat.outbox[0].message, 'one');
    assert.equal(chat.outbox[1].message, 'two');
    assert.equal(editor.val(), 'next draft');
    assert.match(jQuery('#author-chat .ac-outbox').nodes[0].textContent, /Delivery unconfirmed/);
    const retry = walk(jQuery('#author-chat .ac-outbox').nodes[0]).find(node => node.tag === 'button' && node.text === 'Retry');
    retry.events.click(); reply(requests[1], {}); reply(requests[2], {});
    assert.deepEqual(requests.map(r => r.data.message), ['one', 'one', 'two']);
    assert.equal(chat.outbox.length, 0);
    assert.equal(editor.val(), 'next draft');
});

test('acknowledging a send after a room switch settles the outbox without touching the new draft', () => {
    const {chat, requests, jQuery} = harness();
    const editor = jQuery('#author-chat .ac-textarea');
    editor.val('to main\n'); chat.send(); const sending = requests[0];
    chat.selectRoom('12'); editor.val('draft in twelve');
    reply(sending, {});
    assert.equal(chat.outbox.length, 0);
    assert.equal(editor.val(), 'draft in twelve');
    assert.equal(chat.room_pressed_button_id, '12');
});

test('drafts survive room changes and failed-message editing preserves the next draft', () => {
    const {chat, requests, jQuery} = harness();
    const editor = jQuery('#author-chat .ac-textarea');
    editor.val('old message\n'); chat.send(); reject(requests[0], 503);
    editor.val('new main draft');
    chat.selectRoom('12'); editor.val('private draft'); chat.selectRoom('0');
    assert.equal(editor.val(), 'new main draft');
    const edit = walk(jQuery('#author-chat .ac-outbox').nodes[0]).find(node => node.tag === 'button' && node.text === 'Move to draft');
    edit.events.click();
    assert.equal(editor.val(), 'old message\nnew main draft');
    assert.equal(chat.outbox.length, 0);
    chat.selectRoom('12'); assert.equal(editor.val(), 'private draft');
});

test('localized flags as WordPress strings control nickname visibility and weekday labels', () => {
    const now = new Date();
    const date = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    for (const flag of ['1', 1]) {
        const {chat, jQuery} = harness({set_weekdays: flag, set_show_my_name: '0', today: 'Localized today'});
        chat.showMsg('7', 'Nickname', 'Message', date + ',12:00:00');
        const nodes = walk(jQuery('#author-chat-area ul').nodes[0]);
        assert.ok(nodes.some(node => node.className === 'ac-nick ac-hide'));
        assert.ok(nodes.some(node => node.text === 'Localized today'));
    }
    const {chat, jQuery} = harness({set_show_my_name: '1'});
    chat.showMsg('7', 'Nickname', 'Message', date + ',12:00:00');
    assert.ok(walk(jQuery('#author-chat-area ul').nodes[0]).some(node => node.className === 'ac-nick'));
});

test('URL encoding, Unicode, quotes, newlines and slashes survive sending and safe rendering', () => {
    const {chat, requests, jQuery} = harness();
    const message = "https://example.test/a%20b%2Fc?q=%25\nZażółć 😀 O'Reilly C:\\path\\file";
    jQuery('#author-chat .ac-textarea').val(message + '\n'); chat.send();
    assert.equal(requests[0].data.message, message);
    const nodes = walk(chat.processMsg(message));
    assert.equal(nodes.find(node => node.tag === 'a').attrs.href, 'https://example.test/a%20b%2Fc?q=%25');
    assert.ok(nodes.some(node => node.text.includes("Zażółć 😀 O'Reilly C:\\path\\file")));
});

test('missing private schema keeps the main room and displays a controlled notice', () => {
    const {chat, requests, jQuery} = harness();
    chat.getRoomsForUser();
    reply(requests[0], {chat_room_id: [], private_chat_available: false, can_create_rooms: false});
    assert.equal(chat.room_pressed_button_id, '0');
    assert.match(jQuery('#author-chat .ac-schema-status').nodes[0].textContent, /main room is still available/);
    const button = walk(jQuery('#author-chat #ac-private-conversation').nodes[0]).find(node => node.tag === 'button');
    assert.equal(button.attrs.disabled, 'disabled');
    assert.equal(chat.session_ended, false);
});


test('global chat exclusion ends the session even when a private-room operation fails', () => {
    const {chat, requests} = harness();
    chat.room_pressed_button_id = '12';
    chat.getUsersForRoom();
    reject(requests[0], 403, {code: 'author_chat_access_denied'});
    assert.equal(chat.session_ended, true);
});

test('old completions cannot release the new views history request', () => {
    const {chat, requests} = harness();
    chat.initiate(); const old = requests[0];
    chat.selectRoom('12');
    const pending = chat.history_request;
    old.complete();
    assert.equal(chat.history_request, pending);
    const count = requests.length; chat.update();
    assert.equal(requests.length, count);
});

test('repeated polling coalesces outstanding room-list and member requests', () => {
    const {chat, requests} = harness();
    chat.room_pressed_button_id = '12';
    for (let i = 0; i < 10; i++) { chat.getState(); }
    assert.equal(requests.length, 4);
    assert.equal(requests.filter(r => r.data.function === 'update').length, 1);
    assert.equal(requests.filter(r => r.data.function === 'getRoomsForUser').length, 1);
    assert.equal(requests.filter(r => r.data.function === 'getUsersForRoom').length, 1);
    assert.equal(requests.filter(r => r.data.function === 'whoIsChannelOwner').length, 1);
});

test('send errors in an old room retain recoverable text and cannot erase the active history', () => {
    const {chat, requests, jQuery} = harness();
    jQuery('#author-chat .ac-textarea').val('<img src=x onerror=evil()>\n'); chat.send();
    const sending = requests[0];
    chat.selectRoom('12');
    jQuery('#author-chat-area ul').append(jQuery('<li>').text('new room history'));
    jQuery('#author-chat .ac-textarea').val('new draft');
    reject(sending, 403, {code: 'author_chat_forbidden'});
    assert.equal(chat.session_ended, false);
    assert.equal(chat.room_pressed_button_id, '12');
    assert.equal(jQuery('#author-chat-area ul').nodes[0].textContent, 'new room history');
    assert.equal(jQuery('#author-chat .ac-textarea').val(), 'new draft');
    const outbox = walk(jQuery('#author-chat .ac-outbox').nodes[0]);
    assert.ok(outbox.some(node => node.text === '<img src=x onerror=evil()>'));
    assert.ok(!outbox.some(node => ['img', 'script', 'svg'].includes(node.tag)));
});


test('session expiry leaves drafts from other rooms visible for recovery', () => {
    const {chat, requests, jQuery} = harness();
    jQuery('#author-chat .ac-textarea').val('draft in main');
    chat.selectRoom('12');
    jQuery('#author-chat .ac-textarea').val('draft in twelve');
    const request = requests.filter(r => r.data.function === 'getRoomsForUser').at(-1);
    reject(request, 400, undefined, '0');
    assert.match(jQuery('#author-chat .ac-outbox').nodes[0].textContent, /draft in main/);
    assert.equal(jQuery('#author-chat .ac-textarea').val(), 'draft in twelve');
});


test('room creation uses one durable request token across ambiguous network retries', () => {
    const {chat, requests} = harness();
    chat.addRoom(); chat.addRoom();
    assert.equal(requests.length, 1);
    const token = requests[0].data.request_id;
    assert.match(token, /^[a-f0-9]{32}$/);
    reject(requests[0], 0);
    chat.addRoom();
    assert.equal(requests[1].data.request_id, token);
    reply(requests[1], {chat_room_id: '901', request_id: token});
    assert.equal(chat.room_pressed_button_id, '901');
    assert.equal(chat.room_request_id, null);
});

test('room creation acknowledged in a newer view does not switch that view', () => {
    const {chat, requests} = harness();
    chat.addRoom(); const create = requests[0];
    chat.selectRoom('12');
    reply(create, {chat_room_id: '901'});
    assert.equal(chat.room_pressed_button_id, '12');
    assert.equal(chat.creating_room, false);
});

test('ready registry enables the add-room button without premium gates', () => {
    const {chat, requests, jQuery} = harness();
    chat.getRoomsForUser();
    reply(requests[0], {chat_room_id: [], private_chat_available: true, can_create_rooms: true});
    const add = walk(jQuery('#author-chat #ac-private-conversation').nodes[0]).find(node => node.tag === 'button');
    assert.equal(add.attrs.disabled, null);
    add.events.click();
    assert.equal(requests.at(-1).data.function, 'addRoom');
});

test('nickname colors distinguish user IDs with matching digit suffixes', () => {
    const {chat} = harness();
    chat.showMsg('18', 'First', 'Message', '2026-11-12,12:00:00');
    chat.showMsg('8', 'Second', 'Message', '2026-11-12,12:00:00');
    assert.ok(chat.uid_colors['18']);
    assert.ok(chat.uid_colors['8']);
    assert.notEqual(chat.uid_colors['18'], chat.uid_colors['8']);
});
