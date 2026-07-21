'use strict';

const assert = require('node:assert/strict');
const { Readable } = require('node:stream');
const test = require('node:test');

const helper = require('../lib/pbx-verification-agi');

const ENV = Object.freeze({
	PBX_CALLBACK_ORIGIN: 'https://digitalogic.ir',
	PBX_CALLBACK_PATH: '/wp-json/digitalogic/v1/call-verification/pbx-confirm',
	PBX_PENDING_PATH: '/wp-json/digitalogic/v1/call-verification/pbx-pending',
	PBX_SITE_ID: 'digitalogic.ir',
	PBX_KEY_ID: 'v1',
	PBX_EXPECTED_AGI_CONTEXT: 'pbx-call-verification-digitalogic',
	PBX_EXPECTED_PENDING_CONTEXT: 'pbx-call-verification-pending-digitalogic',
	PBX_EXPECTED_DID: '+982166754123',
	PBX_HMAC_SECRET_FILE: '/etc/asterisk/secrets/digitalogic-pbx-verification.key',
	PBX_PROMPT_ENTER_CODE: 'custom/call-verification/digitalogic/current/enter-code',
	PBX_PROMPT_PENDING_CODE: 'custom/call-verification/digitalogic/current/pending-code',
	PBX_PROMPT_VERIFIED: 'custom/call-verification/digitalogic/current/verified',
	PBX_PROMPT_INVALID: 'custom/call-verification/digitalogic/current/invalid',
	PBX_PROMPT_TEMPORARY_FAILURE: 'custom/call-verification/digitalogic/current/temporary-failure',
});

function agiInput(responseLines, context = ENV.PBX_EXPECTED_AGI_CONTEXT) {
	return Readable.from([[
		`agi_context: ${context}`,
		'agi_uniqueid: 1721491200.42',
		'agi_dnid: 02166754123',
		'agi_callerid: 09123456789',
		'',
		...responseLines,
	].join('\n') + '\n']);
}

function agiOutput() {
	return {
		text: '',
		write(chunk) {
			this.text += String(chunk);
			return true;
		},
	};
}

function shortcutDtmfResponses(code, endpos = 4096) {
	const digits = [...code];
	return [
		`200 result=${digits[0].charCodeAt(0)} endpos=${endpos}`,
		...digits.slice(1).map((digit) => `200 result=${digit.charCodeAt(0)}`),
	];
}

test('normalizes supported Iranian mobile and landline forms', () => {
	for (const input of ['09123456789', '989123456789', '00989123456789', '+989123456789']) {
		assert.equal(helper.normalizeIranNumber(input), '+989123456789');
	}
	assert.equal(helper.normalizeIranNumber('02166754123'), '+982166754123');
	assert.equal(helper.normalizeIranNumber('2166754123'), null);
	assert.equal(helper.normalizeIranNumber('2166754123', { allowBareNsn: true }), '+982166754123');
	assert.equal(helper.normalizeIranNumber('66754123'), null);
	assert.equal(helper.normalizeIranNumber('66754123', { localAreaCode: '21' }), '+982166754123');
	assert.equal(helper.normalizeIranNumber('0989123456789'), null);
	assert.equal(helper.normalizeIranNumber('0989123456789', { allowZeroCountryPrefix: true }), '+989123456789');
	// The live prefix-tci-callerid context prepends 9 for operator routing. The AGI
	// must use the ANI snapshot taken before that mutation, never this value.
	assert.equal(helper.normalizeIranNumber('909123456789'), null);
	assert.equal(helper.normalizeIranNumber('9989123456789', { allowZeroCountryPrefix: true }), null);
	assert.equal(helper.normalizeIranNumber('966754123', { localAreaCode: '21' }), null);
});

test('rejects withheld, foreign, malformed, and short ANI', () => {
	for (const input of ['', 'anonymous', 'private', '+12025550123', '9123456789', '021-ABC-4123']) {
		assert.equal(helper.normalizeIranNumber(input), null);
	}
});

test('validates exactly six non-leading-zero DTMF digits and bounded identifiers', () => {
	assert.equal(helper.validateSixDigitCode('381624'), true);
	assert.equal(helper.validateSixDigitCode('081624'), false);
	assert.equal(helper.validateSixDigitCode('38162'), false);
	assert.equal(helper.validateCallId('1721491200.42'), true);
	assert.equal(helper.validateCallId('../bad'), false);
	assert.equal(helper.validateEventId('123e4567-e89b-42d3-a456-426614174000'), true);
	assert.equal(helper.validateEventId('123e4567-e89b-12d3-a456-426614174000'), false);
});

test('builds the exact canonical request and stable lowercase hex signature', () => {
	const rawBody = '{"schema":"phone-verification.v1","code":"381624"}';
	const timestamp = '1784567890';
	const nonce = 'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM';
	const canonical = helper.canonicalRequest(ENV.PBX_CALLBACK_PATH, timestamp, nonce, rawBody);
	assert.equal(canonical,
		'v1\nPOST\n/wp-json/digitalogic/v1/call-verification/pbx-confirm\n1784567890\nmF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM\nd0b213b3b504adbc647efc51a154b4f0c5d8e6be13e05c73f79a488ef109f44c');
	const secret = Buffer.from('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f', 'hex');
	assert.equal(helper.signCanonical(secret, canonical),
		'4df2b616eb4623e8387a9f3edeb5df2389cb9acd36f0fb0510cf70f61b76afd2');
});

test('signed headers cover the exact raw bytes and omit the secret', () => {
	const config = helper.loadConfig(ENV);
	const secret = Buffer.alloc(32, 0x5a);
	const payload = {
		schema: 'phone-verification.v1',
		site_id: 'digitalogic.ir',
		event_id: '123e4567-e89b-42d3-a456-426614174000',
		call_id: '1721491200.42',
		called_number: '+982166754123',
		caller_number: '+989123456789',
		code: '381624',
		occurred_at: '2026-07-21T00:00:00.000Z',
	};
	const signed = helper.buildSignedRequest(config, secret, payload, {
		timestamp: '1784567890',
		nonce: 'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM',
	});
	assert.equal(signed.rawBody, JSON.stringify(payload));
	assert.match(signed.headers['X-PBX-Signature'], /^[0-9a-f]{64}$/);
	assert.equal(signed.headers['X-PBX-Key-Id'], 'v1');
	assert.equal(JSON.stringify(signed).includes(secret.toString('hex')), false);
});

test('payload validation binds the expected DID, trusted ANI, code, and identifiers', () => {
	const config = helper.loadConfig(ENV);
	const valid = {
		eventId: '123e4567-e89b-42d3-a456-426614174000',
		callId: '1721491200.42',
		calledNumber: '+982166754123',
		callerNumber: '+989123456789',
		code: '381624',
		occurredAt: '2026-07-21T00:00:00.000Z',
	};
	assert.equal(helper.buildPayload(config, valid).called_number, '+982166754123');
	assert.throws(() => helper.buildPayload(config, { ...valid, calledNumber: '+982191002369' }));
	assert.throws(() => helper.buildPayload(config, { ...valid, callerNumber: 'anonymous' }));
	assert.throws(() => helper.buildPayload(config, { ...valid, code: '12345' }));
	assert.throws(() => helper.buildPayload(config, { ...valid, callId: '../bad' }));
});

test('config rejects path/origin confusion and unsafe prompt names', () => {
	const config = helper.loadConfig({
		...ENV,
		PBX_ANI_LOCAL_AREA_CODE: '21',
		PBX_ALLOW_ZERO_COUNTRY_PREFIX_ANI: '1',
	});
	assert.equal(config.aniLocalAreaCode, '21');
	assert.equal(config.allowZeroCountryPrefixAni, true);
	assert.throws(() => helper.loadConfig({ ...ENV, PBX_CALLBACK_ORIGIN: 'http://digitalogic.ir' }));
	assert.throws(() => helper.loadConfig({ ...ENV, PBX_CALLBACK_PATH: '//attacker.invalid/x' }));
	assert.throws(() => helper.loadConfig({ ...ENV, PBX_CALLBACK_PATH: '/wp-json/../admin' }));
	assert.throws(() => helper.loadConfig({ ...ENV, PBX_PROMPT_INVALID: '../../tmp/bad' }));
	assert.throws(() => helper.loadConfig({ ...ENV, PBX_ANI_LOCAL_AREA_CODE: '021' }));
});

test('response classification is fail-closed', () => {
	assert.equal(helper.classifyResponse(200, { verified: true }), 'verified');
	assert.equal(helper.classifyResponse(200, { success: true, verified: false }), 'rejected');
	assert.equal(helper.classifyResponse(200, { success: true }), 'protocol');
	assert.equal(helper.classifyResponse(200, { status: 'invalid' }), 'rejected');
	assert.equal(helper.classifyResponse(200, {}), 'protocol');
	assert.equal(helper.classifyResponse(401, null), 'remote_auth');
	assert.equal(helper.classifyResponse(429, null), 'temporary');
	assert.equal(helper.classifyResponse(503, null), 'temporary');
});

test('tests do not need a network transport', async () => {
	let called = false;
	const config = helper.loadConfig(ENV);
	const result = await helper.postVerification(config, Buffer.alloc(32, 1), {
		schema: 'phone-verification.v1',
	}, async () => {
		called = true;
		return {
			status: 200,
			headers: new Map(),
			text: async () => '{"verified":true}',
		};
	});
	assert.equal(called, true);
	assert.equal(result, 'verified');
});

test('oversized callback response fails closed without a real network', async () => {
	const config = helper.loadConfig(ENV);
	const result = await helper.postVerification(config, Buffer.alloc(32, 1), {}, async () => ({
		status: 200,
		headers: new Map([['content-length', String(config.maxResponseBytes + 1)]]),
		text: async () => '{"verified":true}',
	}));
	assert.equal(result, 'protocol');
});

test('runAgi accepts the normal Asterisk STREAM FILE endpos response', async () => {
	const output = agiOutput();
	let postedPayload;
	const exitCode = await helper.runAgi({
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x5a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=381624',
			'200 result=0 endpos=8192',
			'200 result=1',
		]),
		output,
		fetchImpl: async (_url, request) => {
			postedPayload = JSON.parse(request.body);
			return {
				status: 200,
				headers: new Map(),
				text: async () => '{"verified":true}',
			};
		},
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(postedPayload.called_number, ENV.PBX_EXPECTED_DID);
	assert.equal(postedPayload.caller_number, '+989123456789');
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_VERIFIED.replaceAll('/', '\\/')} ""`));
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT verified/);
});

test('runAgi continues after the invalid-code STREAM FILE endpos response', async () => {
	const output = agiOutput();
	let callbackCount = 0;
	const exitCode = await helper.runAgi({
		env: { ...ENV, PBX_DTMF_ATTEMPTS: '2' },
		loadSecret: () => Buffer.alloc(32, 0x6b),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=12345',
			'200 result=0 endpos=1024',
			'200 result=381624',
			'200 result=0 endpos=2048',
			'200 result=1',
		]),
		output,
		fetchImpl: async () => {
			callbackCount += 1;
			return {
				status: 200,
				headers: new Map(),
				text: async () => '{"verified":true}',
			};
		},
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(callbackCount, 1);
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_INVALID.replaceAll('/', '\\/')} ""`));
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_VERIFIED.replaceAll('/', '\\/')} ""`));
});


test('pending probe payload and signature use the exact path-bound contract', () => {
	const config = helper.loadConfig(ENV);
	const payload = helper.buildPendingPayload(config, {
		eventId: '123e4567-e89b-42d3-a456-426614174000',
		callId: '1721491200.42',
		calledNumber: ENV.PBX_EXPECTED_DID,
		callerNumber: '+989123456789',
		occurredAt: '2026-07-21T00:00:00.000Z',
	});
	assert.deepEqual(Object.keys(payload), [
		'schema', 'site_id', 'event_id', 'call_id', 'called_number', 'caller_number', 'occurred_at',
	]);
	assert.equal(payload.schema, 'phone-verification-pending.v1');
	assert.equal(Object.prototype.hasOwnProperty.call(payload, 'code'), false);

	const signed = helper.buildSignedRequest(config, Buffer.alloc(32, 0x7c), payload, {
		path: config.pendingPath,
		timestamp: '1784567890',
		nonce: 'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM',
	});
	assert.match(signed.canonical, new RegExp(`^v1\\nPOST\\n${config.pendingPath.replaceAll('/', '\\/')}\\n`));
	assert.equal(signed.canonical.includes(config.callbackPath), false);
});

test('pending response accepts only the exact one-boolean object', async () => {
	assert.equal(helper.classifyPendingResponse(200, { pending: true }), 'pending');
	assert.equal(helper.classifyPendingResponse(200, { pending: false }), 'none');
	assert.equal(helper.classifyPendingResponse(200, { success: true, pending: true }), 'protocol');
	assert.equal(helper.classifyPendingResponse(200, { pending: 1 }), 'protocol');
	assert.equal(helper.classifyPendingResponse(200, []), 'protocol');
	assert.equal(helper.classifyPendingResponse(503, null), 'temporary');

	const config = helper.loadConfig(ENV);
	let requestedUrl = '';
	const result = await helper.postPending(config, Buffer.alloc(32, 0x2a), {
		schema: 'phone-verification-pending.v1',
	}, async (url, request) => {
		requestedUrl = url;
		assert.equal(request.method, 'POST');
		return {
			status: 200,
			headers: new Map(),
			text: async () => '{"pending":true}',
		};
	});
	assert.equal(requestedUrl, config.pendingUrl);
	assert.equal(result, 'pending');
});

test('preflight sets only the pending flag for an exact ANI match', async () => {
	const output = agiOutput();
	let postedPayload;
	const exitCode = await helper.runAgi({
		mode: 'preflight',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x3a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async (url, request) => {
			assert.equal(url, `https://${new URL(ENV.PBX_CALLBACK_ORIGIN).host}${ENV.PBX_PENDING_PATH}`);
			postedPayload = JSON.parse(request.body);
			return {
				status: 200,
				headers: new Map(),
				text: async () => '{"pending":true}',
			};
		},
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(postedPayload.schema, 'phone-verification-pending.v1');
	assert.equal(postedPayload.caller_number, '+989123456789');
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_PENDING 1/);
	assert.equal(output.text.includes('GET DATA'), false);
});

test('preflight network failure explicitly remains fail-open', async () => {
	const output = agiOutput();
	const exitCode = await helper.runAgi({
		mode: 'preflight',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x4a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => { throw new Error('offline'); },
	});

	assert.equal(exitCode, helper.EXIT.NETWORK);
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_PENDING 0/);
	assert.equal(output.text.includes('STREAM FILE'), false);
});

test('shortcut captures star from the prompt and cancels without waiting or posting', async () => {
	const output = agiOutput();
	let callbackCount = 0;
	const exitCode = await helper.runAgi({
		mode: 'shortcut',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x5a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=42 endpos=512',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => { callbackCount += 1; },
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(callbackCount, 0);
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_PENDING_CODE.replaceAll('/', '\\/')} "0123456789\\*"`));
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT fallback/);
	assert.equal(output.text.includes('WAIT FOR DIGIT'), false);
	assert.equal(output.text.includes('GET DATA'), false);
});

test('shortcut cancels immediately when star is pressed between code digits', async () => {
	const output = agiOutput();
	let callbackCount = 0;
	const exitCode = await helper.runAgi({
		mode: 'shortcut',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x5c),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=49 endpos=640',
			'200 result=50',
			'200 result=42',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => { callbackCount += 1; },
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(callbackCount, 0);
	assert.equal((output.text.match(/WAIT FOR DIGIT /g) || []).length, 2);
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT fallback/);
});

test('shortcut waits a bounded interval after an uninterrupted prompt, then falls back', async () => {
	const output = agiOutput();
	let callbackCount = 0;
	const exitCode = await helper.runAgi({
		mode: 'shortcut',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x5b),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			'200 result=0 endpos=4096',
			'200 result=0',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => { callbackCount += 1; },
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(callbackCount, 0);
	assert.match(output.text, new RegExp(`WAIT FOR DIGIT ${ENV.PBX_DTMF_TIMEOUT_MS || 12000}`));
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT fallback/);
});

test('shortcut posts a valid code immediately and marks the call verified', async () => {
	const output = agiOutput();
	const exitCode = await helper.runAgi({
		mode: 'shortcut',
		env: ENV,
		loadSecret: () => Buffer.alloc(32, 0x6a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			...shortcutDtmfResponses('381624'),
			'200 result=0 endpos=4096',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => ({
			status: 200,
			headers: new Map(),
			text: async () => '{"verified":true}',
		}),
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_PENDING_CODE.replaceAll('/', '\\/')} "0123456789\\*"`));
	assert.equal((output.text.match(/WAIT FOR DIGIT /g) || []).length, 5);
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT verified/);
	assert.equal(output.text.includes('381624'), false);
});

test('shortcut gives a rejected six-digit typo one bounded retry', async () => {
	const output = agiOutput();
	let callbackCount = 0;
	const exitCode = await helper.runAgi({
		mode: 'shortcut',
		env: { ...ENV, PBX_DTMF_ATTEMPTS: '3' },
		loadSecret: () => Buffer.alloc(32, 0x7a),
		input: agiInput([
			`200 result=1 (${ENV.PBX_EXPECTED_DID})`,
			'200 result=1 (+989123456789)',
			...shortcutDtmfResponses('111111', 1024),
			'200 result=0 endpos=1024',
			...shortcutDtmfResponses('381624', 2048),
			'200 result=0 endpos=2048',
			'200 result=1',
		], ENV.PBX_EXPECTED_PENDING_CONTEXT),
		output,
		fetchImpl: async () => {
			callbackCount += 1;
			return {
				status: 200,
				headers: new Map(),
				text: async () => callbackCount === 1
					? '{"verified":false}' : '{"verified":true}',
			};
		},
	});

	assert.equal(exitCode, helper.EXIT.VERIFIED);
	assert.equal(callbackCount, 2);
	assert.equal((output.text.match(new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_PENDING_CODE.replaceAll('/', '\\/')}`, 'g')) || []).length, 2);
	assert.equal((output.text.match(/WAIT FOR DIGIT /g) || []).length, 10);
	assert.match(output.text, new RegExp(`STREAM FILE ${ENV.PBX_PROMPT_INVALID.replaceAll('/', '\\/')} ""`));
	assert.match(output.text, /SET VARIABLE PBX_VERIFY_RESULT verified/);
	assert.equal(output.text.includes('111111'), false);
	assert.equal(output.text.includes('381624'), false);
});
