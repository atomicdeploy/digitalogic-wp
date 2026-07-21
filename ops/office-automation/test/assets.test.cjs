'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');

function filesUnder(directory) {
	return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
		const item = path.join(directory, entry.name);
		return entry.isDirectory() ? filesUnder(item) : [item];
	});
}

test('versioned assets contain placeholders instead of private routing values', () => {
	const files = filesUnder(root).filter(file => !file.includes(`${path.sep}test${path.sep}`));
	const content = files.map(file => fs.readFileSync(file, 'utf8')).join('\n');

	assert.doesNotMatch(content, /sip:\d{2,}@/i);
	assert.doesNotMatch(content, /PJSIP\/\d{2,}@/i);
	assert.doesNotMatch(content, /\b(?:10\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])|192\.168)\.\d{1,3}\.\d{1,3}\b/);
	assert.doesNotMatch(content, /(?<!\d)(?:\+98|0098|98|0)?9\d{9}(?!\d)/);
	assert.doesNotMatch(content, /[A-Z0-9._%+-]+@(?:gmail|outlook|yahoo)\.[A-Z]{2,}/i);
	assert.doesNotMatch(content, /https?:\/\/[^\s"']+\/webhook\/[A-Za-z0-9_-]+/i);
	assert.doesNotMatch(content, /(?:apiToken|amiSecret|chatId)\s*[:=]\s*["'][^_$"'][^"']*["']/i);
	assert.doesNotMatch(content, /\b(?:gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}\b/);
	assert.doesNotMatch(content, /\bAKIA[A-Z0-9]{16}\b/);
	assert.doesNotMatch(content, /\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/);
	assert.doesNotMatch(content, /-----BEGIN [A-Z ]*PRIVATE KEY-----/);
	assert.doesNotMatch(content, /\bBearer\s+[A-Za-z0-9._~+/-]{24,}\b/i);
});

test('sensitive example environment keys stay blank', () => {
	const example = fs.readFileSync(path.join(root, 'config', 'office-automation-sip-notifier.env.example'), 'utf8');
	for (const key of [
		'API_TOKEN',
		'DEFAULT_EXTENSIONS',
		'MESSAGE_FROM',
		'AMI_PORT',
		'AMI_USER',
		'AMI_SECRET',
		'INTEROFFICE_MESSAGE_ENDPOINT',
		'INTEROFFICE_MESSAGE_FROM',
		'INTEROFFICE_FORWARD_TARGET',
		'INTEROFFICE_ALIAS_REGEX',
		'INTEROFFICE_TARGET_PREFIX',
		'INTEROFFICE_SENDER_ALIAS_REGEX',
		'INTEROFFICE_SENDER_ALIAS_PREFIX',
	]) {
		assert.match(example, new RegExp(`^${key}=$`, 'm'));
	}
});

test('new operational scripts use shell or CommonJS only', () => {
	const scriptFiles = filesUnder(path.join(root, 'scripts'));
	assert.equal(scriptFiles.every(file => /\.(?:cjs|sh)$/.test(file)), true);
	for (const file of scriptFiles) assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /\bpython(?:3)?\b/i);
});
