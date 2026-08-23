'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const { renderUnits } = require('./render-units.cjs');

if (process.platform === 'win32') {
  process.stdout.write('systemd unit verification is deferred to Linux CI.\n');
  process.exit(0);
}

const probe = spawnSync('systemd-analyze', ['--version'], { encoding: 'utf8' });
if (probe.status !== 0) {
  process.stderr.write('systemd-analyze is required on Linux.\n');
  process.exit(1);
}

const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'digitalogic-wordpress-cron-'));
try {
  const units = renderUnits({
    wordpressRoot: '/tmp',
    wpCli: '/usr/bin/true',
    runtimeUser: 'www-data',
    runtimeGroup: 'www-data',
  });
  const paths = [];
  for (const [name, content] of Object.entries(units)) {
    const target = path.join(directory, name);
    fs.writeFileSync(target, content, { encoding: 'utf8', mode: 0o640, flag: 'wx' });
    paths.push(target);
  }
  const result = spawnSync('systemd-analyze', ['verify', ...paths], { encoding: 'utf8' });
  if (result.status !== 0) {
    process.stderr.write(result.stderr || result.stdout || 'systemd unit verification failed.\n');
    process.exitCode = 1;
  } else {
    process.stdout.write('systemd unit verification passed.\n');
  }
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
