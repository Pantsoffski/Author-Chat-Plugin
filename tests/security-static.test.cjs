// Architectural regression guards. These do not execute or validate PHP syntax.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.join(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8').replace(/\r\n/g, '\n');
const main = read('acppauthorchat-author-chat.php');
const process = read('acppauthorchat-process.php');
const js = read('acppauthorchat_chat.js');

test('web chat uses AJAX without registering a credential REST endpoint', () => {
    assert.doesNotMatch(main + process, /register_rest_route|wp_authenticate|wp_signon|wp_set_auth_cookie/);
    assert.doesNotMatch(js, /author-chat\/v2|rest_url/);
});

test('AJAX uses one authenticated hook and no include-time POST dispatcher', () => {
    assert.match(process, /add_action\('wp_ajax_acppauthorchat', 'acppauthorchat_ajax'\)/);
    assert.doesNotMatch(process, /wp_ajax_nopriv_|^if\s*\(isset\(\$_POST/m);
    assert.equal((js.match(/jQuery\.ajax\(/g) || []).length, 1, 'traffic must use the shared request method');
    assert.match(js, /options\.data\.action = acppauthorchat_ajax\.action/);
});

test('active code contains no FCM key, outgoing notification request or SearchPreview', () => {
    assert.doesNotMatch(process, /AAA[A-Za-z0-9_-]{10}|wp_remote_post|fcm\.googleapis|\/topics\//);
    assert.doesNotMatch(js, /searchpreview|ac-preview/i);
});

test('rendering cannot invoke retention or full-history deletion', () => {
    const rendering = main.slice(main.indexOf('function acppauthorchat_author_chat()'), main.indexOf('function acppauthorchat_chat_on_top()'));
    assert.doesNotMatch(rendering, /acppauthorchat_clean_up_(chat_history|database)\(/);
    assert.doesNotMatch(main + process, /\bTRUNCATE\b|DELETE FROM .*author_chat_table/);
    assert.doesNotMatch(process, /DELETE acrp|LEFT JOIN.*author_chat_table/);
});

test('web history opts into cursor pages and no permanent record cap', () => {
    assert.match(process, /m\.id > %d/);
    assert.match(process, /LIMIT 201/);
    assert.doesNotMatch(process, /LIMIT 5001|'status' => 413|count\(\$rows\) > 5000/);
    assert.match(js, /after_id: cursor/);
    assert.match(process, /LIKE %s ORDER BY user_id ASC LIMIT 20/);
});

test('schema upgrade retains legacy option names and release version', () => {
    const baseline = execFileSync('git', ['show', '022653435bf9378aaa6ca5a16875db53ab76e757:acppauthorchat-author-chat.php'], {cwd: root, encoding: 'utf8'}).replace(/\r\n/g, '\n');
    const optionNames = text => [...new Set(text.match(/author_chat_(?:db_version|settings[a-z_]*)/g))].sort();
    optionNames(main).filter(name => name !== 'author_chat_settings_').forEach(name => assert.ok(optionNames(baseline).includes(name), name));
    assert.doesNotMatch(main, /DROP TABLE|delete_option\(/);
    assert.match(main, /\$acppauthorchat_db_version = '2\.0'/);
    assert.match(main, /\$acppauthorchat_version = '2\.1\.0'/);
});
