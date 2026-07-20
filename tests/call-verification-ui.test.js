'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'assets', 'js', 'call-verification.js'),
	'utf8'
);

test('poll and consume failures clear browser-held proof and permit a safe retry', () => {
	assert.match(source, /function resetForRetry\(message\)/);
	assert.match(source, /challengeId = '';/);
	assert.match(source, /csrfToken = '';/);
	assert.match(source, /start\.disabled = false;/);
	assert.match(source, /catch \(consumeError\) \{\s*resetForRetry\(consumeError\.message\);/);
	assert.match(source, /catch \(pollError\) \{\s*resetForRetry\(pollError\.message\);/);
});

test('contact consent writes are serialized, complete snapshots, and reload on failure', () => {
	assert.match(source, /window\.alert\(contactError\.message\);/);
	assert.match(source, /row\.dataset\.contactSaving === '1'/);
	assert.match(source, /control\.disabled = true/);
	assert.match(source, /voice_opt_in: Boolean/);
	assert.match(source, /voice_events: \{\}/);
	assert.match(source, /window\.location\.reload\(\);/);
	assert.doesNotMatch(source, /event\.target\.checked = !event\.target\.checked/);
});
