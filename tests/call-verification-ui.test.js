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
	assert.match(source, /catch \(consumeError\) \{[\s\S]*?resetForRetry\(consumeError\.message\);/);
	assert.match(source, /catch \(pollError\) \{[\s\S]*?resetForRetry\(pollError\.message\);/);
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

test('call widgets initialize idempotently and support dynamic storefront insertion', () => {
	assert.match(source, /function initializeWidget\(widget\)/);
	assert.match(source, /widget\.dataset\.digitalogicCallInitialized === 'true'/);
	assert.match(source, /widget\.dataset\.digitalogicCallInitialized = 'true'/);
	assert.match(source, /function initializeWidgets\(root = document\)/);
	assert.match(source, /new MutationObserver\(\(records\) =>/);
});

test('the call alternative is disclosure-safe and makes no request until start', () => {
	const toggleHandler = source.indexOf("toggle?.addEventListener('click'");
	const startHandler = source.indexOf("start?.addEventListener('click'");
	const requestCall = source.indexOf('await request(config.challengeUrl', startHandler);

	assert.ok(toggleHandler > -1);
	assert.ok(startHandler > toggleHandler);
	assert.ok(requestCall > startHandler);
	assert.doesNotMatch(source.slice(toggleHandler, startHandler), /request\(/);
	assert.match(source, /toggle\.setAttribute\('aria-expanded', panel\.hidden \? 'false' : 'true'\)/);
});

test('server-returned dial number and code populate shortcut instructions without an invented IVR digit', () => {
	assert.match(source, /dial\.textContent = display/);
	assert.match(source, /dial\.setAttribute\('href', `tel:\$\{tel\}`\)/);
	assert.match(source, /code\.textContent = data\.code/);
	assert.match(source, /body: JSON\.stringify\(\{ phone: phone\.value, purpose \}\)/);
	assert.doesNotMatch(source, /ivr_option|ivr_path|data-call-ivr/);
});

test('polling is fast, visibility-aware, and keeps an absolute countdown', () => {
	assert.match(source, /Number\(config\.pollMs\) \|\| 500/);
	assert.match(source, /document\.addEventListener\('visibilitychange'/);
	assert.match(source, /if \(document\.hidden\)/);
	assert.match(source, /schedulePoll\(attemptGeneration, 0\)/);
	assert.match(source, /expiresAt = Date\.now\(\) \+ \(Math\.max\(0, Number\(data\.expires_in\)/);
	assert.match(source, /Math\.ceil\(\(expiresAt - Date\.now\(\)\) \/ 1000\)/);
});

test('cancelling aborts the active status request as well as invalidating its generation', () => {
	assert.match(source, /pollController = new AbortController\(\)/);
	assert.match(source, /signal: pollController\.signal/);
	assert.match(source, /pollController\.abort\(\)/);
	assert.match(source, /pollError\.name === 'AbortError'/);
});

test('cancel invalidates in-flight polling before it can consume a stale proof', () => {
	const pollRequest = source.indexOf('const data = await request', source.indexOf('async function poll'));
	const postPollGuard = source.indexOf('expectedGeneration !== attemptGeneration', pollRequest);
	const consumeCall = source.indexOf('await consume(expectedGeneration', pollRequest);
	const cancelHandler = source.indexOf("cancel?.addEventListener('click'");
	const invalidate = source.indexOf('attemptGeneration += 1;', cancelHandler);
	const clearChallenge = source.indexOf("challengeId = '';", invalidate);
	const cancelRequest = source.indexOf('await request', clearChallenge);

	assert.ok(pollRequest > -1);
	assert.ok(postPollGuard > pollRequest);
	assert.ok(consumeCall > postPollGuard);
	assert.ok(cancelHandler > -1);
	assert.ok(invalidate > cancelHandler);
	assert.ok(clearChallenge > invalidate);
	assert.ok(cancelRequest > clearChallenge);
	assert.match(source, /async function consume\(expectedGeneration, expectedChallengeId, expectedCsrfToken\)/);
	assert.match(source, /expectedChallengeId !== challengeId/);
});

test('cancel is disabled once verified proof consumption becomes irreversible', () => {
	const verifiedBranch = source.indexOf("if (data.status === 'verified')");
	const disableCancel = source.indexOf('cancel.disabled = true;', verifiedBranch);
	const consumeCall = source.indexOf('await consume(expectedGeneration', verifiedBranch);
	const retryReset = source.indexOf('cancel.disabled = false;', source.indexOf('function resetForRetry'));

	assert.ok(verifiedBranch > -1);
	assert.ok(disableCancel > verifiedBranch);
	assert.ok(consumeCall > disableCancel);
	assert.ok(retryReset > -1);
	assert.match(source.slice(disableCancel, consumeCall), /widget\.setAttribute\('aria-busy', 'true'\)/);
});
