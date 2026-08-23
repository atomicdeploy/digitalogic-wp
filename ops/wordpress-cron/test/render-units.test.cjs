'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { parseArguments, renderUnits, safeAbsolutePath, safeIdentity } = require('../render-units.cjs');

test('renders a non-overlapping no-traffic WordPress cron runner', () => {
  const units = renderUnits({
    wordpressRoot: '/srv/example/wordpress',
    wpCli: '/usr/local/bin/wp',
    runtimeUser: 'www-data',
    runtimeGroup: 'www-data',
  });
  const service = units['digitalogic-wordpress-cron.service'];
  const timer = units['digitalogic-wordpress-cron.timer'];

  assert.match(service, /^Type=oneshot$/m);
  assert.match(service, /^User=www-data$/m);
  assert.match(service, /^Group=www-data$/m);
  assert.match(service, /^WorkingDirectory=\/srv\/example\/wordpress$/m);
  assert.match(service, /^ExecStartPre=\/usr\/local\/bin\/wp eval .*DISABLE_WP_CRON.*$/m);
  assert.match(service, /^ExecStart=\/usr\/local\/bin\/wp cron event run --due-now --quiet$/m);
  assert.match(service, /^TimeoutStartSec=35min$/m);
  assert.match(service, /^NoNewPrivileges=true$/m);
  assert.match(timer, /^OnActiveSec=2s$/m);
  assert.match(timer, /^OnUnitInactiveSec=10s$/m);
  assert.match(timer, /^Unit=digitalogic-wordpress-cron\.service$/m);
  assert.doesNotMatch(service + timer, /curl|wget|https?:|secret|token|--all|__[A-Z0-9_]+__/i);
  assert.doesNotMatch(service, /^TimeoutStartSec=(?:[0-9]{1,3}|1[0-9]{3})s$/m);
});

test('tracked assets contain placeholders instead of production identities or paths', () => {
  const root = path.resolve(__dirname, '..');
  const service = fs.readFileSync(path.join(root, 'systemd', 'digitalogic-wordpress-cron.service.in'), 'utf8');
  const timer = fs.readFileSync(path.join(root, 'systemd', 'digitalogic-wordpress-cron.timer'), 'utf8');
  const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');

  assert.match(service, /__WORDPRESS_ROOT__/);
  assert.match(service, /__WP_CLI__/);
  assert.match(service, /__RUNTIME_USER__/);
  assert.match(service, /__RUNTIME_GROUP__/);
  assert.doesNotMatch(service + timer, /https?:|X-Patris|credential|source_id|source_dataset/i);
  assert.match(readme, /DISABLE_WP_CRON=true/);
  assert.match(readme, /Only after the service is inactive/);
  assert.match(readme, /ExecMainStatus/);
});

test('renderer rejects unsafe paths, identities, duplicates, and missing arguments', () => {
  for (const value of ['/', 'relative/path', '/srv/../secret', '/srv path/wp', '/srv//wp', '/srv/wp/']) {
    assert.throws(() => safeAbsolutePath(value, 'fixture'));
  }
  for (const value of ['root', 'root;id', 'UPPER', 'www data', '../www-data', '']) {
    assert.throws(() => safeIdentity(value, 'fixture'));
  }
  assert.throws(() => parseArguments(['--wp-cli', '/usr/bin/wp', '--wp-cli', '/bin/wp']));
  assert.throws(() => parseArguments(['--unknown', 'value']));
  assert.throws(() => parseArguments(['--wp-cli']));
});
