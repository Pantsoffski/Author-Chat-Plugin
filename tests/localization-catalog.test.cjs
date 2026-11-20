const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('the release manifest includes all seven complete runtime catalogs and retains Persian', () => {
    const source = fs.readFileSync(path.join(__dirname, 'build-release.cjs'), 'utf8');
    const entries = [];
    vm.runInNewContext(source, {
        __dirname,
        process: {env: {}},
        console: {log() {}},
        require(module) {
            if (module === 'node:path') { return path; }
            if (module === 'node:fs') {
                return {...fs, existsSync: () => false, mkdirSync() {}, copyFileSync() {}, writeFileSync() {}};
            }
            assert.equal(module, 'adm-zip');
            return class {
                addFile(name) { entries.push(name); }
                toBuffer() { return Buffer.alloc(0); }
            };
        },
    });
    const files = entries.filter(name => name.startsWith('author-chat/lang/'));
    for (const locale of ['de_DE', 'es_ES', 'fr_FR', 'it_IT', 'nl_NL', 'pl_PL', 'pt_BR']) {
        assert.ok(files.includes('author-chat/lang/author-chat-' + locale + '-acppauthorchat-author-chat-script.json'));
        assert.ok(files.includes('author-chat/lang/author-chat-' + locale + '.mo'));
        assert.ok(files.includes('author-chat/lang/author-chat-' + locale + '.po'));
    }
    assert.ok(files.includes('author-chat/lang/author-chat-fa_IR.mo'));
    assert.ok(files.includes('author-chat/lang/author-chat-fa_IR.po'));
    assert.equal(entries.filter(name => name.endsWith('/readme.txt')).length, 1);
});
