const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
if (spawnSync('php', ['--version'], {encoding: 'utf8'}).error) {
    process.stderr.write('NOT TESTED: PHP CLI is unavailable.\n');
    process.exit(2);
}
function files(dir) {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap(item => {
        if (['.git', 'node_modules', 'vendor'].includes(item.name)) return [];
        const file = path.join(dir, item.name);
        return item.isDirectory() ? files(file) : (file.endsWith('.php') ? [file] : []);
    });
}
for (const file of files(path.resolve(__dirname, '..'))) {
    const result = spawnSync('php', ['-l', file], {stdio: 'inherit'});
    if (result.status !== 0) process.exit(result.status || 1);
}
