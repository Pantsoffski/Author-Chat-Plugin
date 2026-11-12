// Explicit distribution allowlist. Never include repository/test dependencies.
const fs = require('node:fs');
const path = require('node:path');
const AdmZip = require('adm-zip');
const root = path.resolve(__dirname, '..');
const out = path.join(root, 'dist');
const version = '2.1.0';
const zip = path.join(out, `author-chat-${version}.zip`);
if (fs.existsSync(zip)) throw new Error('Release ZIP already exists; preserve it for comparison.');
const files = [
    'acppauthorchat-author-chat.php', 'acppauthorchat-process.php',
    'acppauthorchat-options.php', 'acppauthorchat-schema.php',
    'acppauthorchat_chat.js', 'acppauthorchat_author_chat_style.css',
    'index.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'license-mit.txt', 'notifyauthorchat.wav',
    'assets/generate-notification.cjs',
    ...fs.readdirSync(path.join(root, 'lang')).filter(f => /\.(po|mo)$/.test(f)).map(f => 'lang/' + f)
];
const main = fs.readFileSync(path.join(root, files[0]), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
if (!main.includes(`Version: ${version}`) || !main.includes(`$acppauthorchat_version = '${version}'`) || !readme.includes(`Stable tag: ${version}`)) throw new Error('Version mismatch');
const destination = path.join(out, 'author-chat');
for (const file of files) {
    const target = path.join(destination, file);
    fs.mkdirSync(path.dirname(target), {recursive: true});
    fs.copyFileSync(path.join(root, file), target);
}
const archive = new AdmZip();
for (const file of files) archive.addFile('author-chat/' + file, fs.readFileSync(path.join(root, file)));
archive.writeZip(zip);
console.log('Built ' + zip + ': ' + files.length + ' files; basename author-chat/acppauthorchat-author-chat.php');
