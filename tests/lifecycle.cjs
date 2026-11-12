// Runs destructive tests only after lifecycle.php verifies a disposable wp-env DB.
const {spawnSync} = require('node:child_process');
const docker = 'C:/Program Files/Docker/Docker/resources/bin/docker.exe';
const container = process.argv[2];
const multisite = container === 'wp-env-authorchat-releasemultisite-f63cd781-tests-cli-1';
if (!multisite && container !== 'wp-env-authorchat-release-dd961dd4-tests-cli-1') throw Error('Unknown disposable container');
function run(args) {
    const r = spawnSync(docker, args, {encoding:'utf8',maxBuffer:4e6});
    process.stdout.write(r.stdout); process.stderr.write(r.stderr);
    if (r.status !== 0) throw Error('Failed: '+args.join(' '));
}
const wp = (...args) => run(['exec',container,'wp',...args]);
const check = mode => wp('eval-file','wp-content/author-chat-test-harness/lifecycle.php',mode);
const activate = () => wp('plugin','activate','author-chat',...(multisite?['--network']:[]));
const deactivate = () => {
    if (multisite) wp('plugin','deactivate','author-chat','--network');
    const active = spawnSync(docker, ['exec',container,'wp','plugin','is-active','author-chat']);
    if (active.status === 0) wp('plugin','deactivate','author-chat');
    else if (active.status !== 1) throw Error('Cannot determine activation state');
};
run(['cp','dist/author-chat-2.1.0.zip',container+':/tmp/author-chat-2.1.0.zip']);
wp('plugin','install','/tmp/author-chat-2.1.0.zip','--force');
activate(); check('seed');
deactivate(); check('retained');
wp('plugin','install','/tmp/author-chat-2.1.0.zip','--force');
activate(); check('retained');
deactivate();
wp('plugin','uninstall','author-chat');
check('removed');
wp('plugin','install','/tmp/author-chat-2.1.0.zip');
activate(); check('reinstalled');
