'use strict';

const fs = require('node:fs');
const path = require('node:path');

const SERVICE_NAME = 'digitalogic-wordpress-cron.service';
const TIMER_NAME = 'digitalogic-wordpress-cron.timer';

function safeAbsolutePath(value, label) {
  if (typeof value !== 'string' || value.length < 2 || value.length > 512) {
    throw new Error(`${label} must be a bounded absolute path`);
  }
  if (!value.startsWith('/') || value.includes('//') || value.includes('/../') || value.endsWith('/..')) {
    throw new Error(`${label} must be a normalized absolute path`);
  }
  if (value.endsWith('/') || !/^\/[A-Za-z0-9._+\/-]+$/.test(value)) {
    throw new Error(`${label} contains unsupported characters`);
  }
  return value;
}

function safeIdentity(value, label) {
  if (typeof value !== 'string' || !/^[a-z_][a-z0-9_-]{0,31}$/.test(value)) {
    throw new Error(`${label} must be a system user or group name`);
  }
  if (value === 'root') {
    throw new Error(`${label} must be an unprivileged runtime identity`);
  }
  return value;
}

function renderUnits(options) {
  const wordpressRoot = safeAbsolutePath(options.wordpressRoot, 'WordPress root');
  const wpCli = safeAbsolutePath(options.wpCli, 'WP-CLI path');
  const runtimeUser = safeIdentity(options.runtimeUser, 'Runtime user');
  const runtimeGroup = safeIdentity(options.runtimeGroup, 'Runtime group');
  const templateRoot = path.join(__dirname, 'systemd');
  const serviceTemplate = fs.readFileSync(path.join(templateRoot, `${SERVICE_NAME}.in`), 'utf8');
  const timer = fs.readFileSync(path.join(templateRoot, TIMER_NAME), 'utf8');
  const service = serviceTemplate
    .replaceAll('__WORDPRESS_ROOT__', wordpressRoot)
    .replaceAll('__WP_CLI__', wpCli)
    .replaceAll('__RUNTIME_USER__', runtimeUser)
    .replaceAll('__RUNTIME_GROUP__', runtimeGroup);

  if (/__[A-Z0-9_]+__/.test(service) || /__[A-Z0-9_]+__/.test(timer)) {
    throw new Error('Rendered units contain unresolved placeholders');
  }
  return { [SERVICE_NAME]: service, [TIMER_NAME]: timer };
}

function parseArguments(argv) {
  const values = {};
  for (let index = 0; index < argv.length; index += 2) {
    const key = argv[index];
    const value = argv[index + 1];
    if (!key || !key.startsWith('--') || value === undefined) {
      throw new Error('Arguments must be exact --name value pairs');
    }
    if (Object.hasOwn(values, key)) {
      throw new Error(`Duplicate argument: ${key}`);
    }
    values[key] = value;
  }
  const allowed = new Set(['--wordpress-root', '--wp-cli', '--runtime-user', '--runtime-group', '--output-dir']);
  for (const key of Object.keys(values)) {
    if (!allowed.has(key)) {
      throw new Error(`Unsupported argument: ${key}`);
    }
  }
  for (const key of allowed) {
    if (!Object.hasOwn(values, key)) {
      throw new Error(`Missing argument: ${key}`);
    }
  }
  return values;
}

function main(argv) {
  const values = parseArguments(argv);
  const outputDir = safeAbsolutePath(values['--output-dir'], 'Output directory');
  const units = renderUnits({
    wordpressRoot: values['--wordpress-root'],
    wpCli: values['--wp-cli'],
    runtimeUser: values['--runtime-user'],
    runtimeGroup: values['--runtime-group'],
  });
  fs.mkdirSync(outputDir, { recursive: true, mode: 0o750 });
  for (const [name, content] of Object.entries(units)) {
    fs.writeFileSync(path.join(outputDir, name), content, { encoding: 'utf8', mode: 0o640, flag: 'wx' });
  }
  process.stdout.write('Rendered verified WordPress cron units.\n');
}

if (require.main === module) {
  try {
    main(process.argv.slice(2));
  } catch (error) {
    process.stderr.write(`Render failed: ${error.message}\n`);
    process.exitCode = 1;
  }
}

module.exports = { parseArguments, renderUnits, safeAbsolutePath, safeIdentity };
