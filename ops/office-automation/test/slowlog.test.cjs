'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const {
	buildEventPayload,
	isPhpFpmSlowLogHeader,
	readNewPhpFpmSlowLog,
	redactText,
} = require('../lib/apache-site-watchdog-to-n8n.cjs');

function fixture(t) {
	const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'digitalogic-slowlog-'));
	t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
	const file = path.join(directory, 'slow.log');
	fs.writeFileSync(file, 'existing content is intentionally anchored\n');
	return file;
}

test('slowlog ingestion counts request headers rather than stack-trace lines', t => {
	const file = fixture(t);
	const state = {};
	assert.equal(readNewPhpFpmSlowLog(state, 'slowlog', file).requestCount, 0);

	fs.appendFileSync(file, [
		'[21-Jul-2026 10:00:00] [pool www] pid 101',
		'script_filename = /var/www/html/index.php',
		'[0x0001] function() /var/www/html/index.php:1',
		'[21-Jul-2026 10:00:01] [pool www] pid 102',
		'[0x0002] other() /var/www/html/index.php:2',
		'',
	].join('\n'));
	const batch = readNewPhpFpmSlowLog(state, 'slowlog', file, 16);

	assert.equal(batch.requestCount, 2);
	assert.equal(batch.observedLineCount, 5);
	assert.ok(batch.observedLineCount > batch.requestCount);
});

test('a partial slowlog header is counted only after its newline arrives', t => {
	const file = fixture(t);
	const state = {};
	readNewPhpFpmSlowLog(state, 'slowlog', file);
	const header = '[21-Jul-2026 10:01:00] [pool www] pid 103';

	fs.appendFileSync(file, header);
	assert.equal(readNewPhpFpmSlowLog(state, 'slowlog', file).requestCount, 0);
	fs.appendFileSync(file, '\n');
	assert.equal(readNewPhpFpmSlowLog(state, 'slowlog', file).requestCount, 1);
	assert.equal(isPhpFpmSlowLogHeader(header), true);
});

test('watchdog payload preserves the explicit allow-list in both channel fields', () => {
	const payload = buildEventPayload({
		event_type: 'php_fpm_slowlog',
		severity: 'warning',
		message: 'Synthetic slow request fixture',
		channels: ['sip'],
		notify_channels: ['sip'],
	}, {
		office: 'synthetic-office',
		host: 'synthetic-host',
		createdAt: '2026-07-21T00:00:00.000Z',
		notifyChannels: ['ntfy', 'telegram'],
	});

	assert.deepEqual(payload.channels, ['ntfy', 'telegram']);
	assert.deepEqual(payload.notify_channels, ['ntfy', 'telegram']);
	assert.equal(payload.category, 'web_health');
	assert.equal(payload.channels.includes('sip'), false);
});

test('diagnostic redaction removes secret-like values', () => {
	const redacted = redactText('token=synthetic-value password=another {"authorization":"third"}');
	assert.equal(redacted, 'token=*** password=*** {"authorization":"***"}');
});
