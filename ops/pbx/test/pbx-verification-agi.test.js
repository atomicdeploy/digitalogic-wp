'use strict';

const assert = require('node:assert/strict');
const { Readable } = require('node:stream');
const test = require('node:test');

const helper = require('../lib/pbx-verification-agi');

const ENV = Object.freeze({
	PBX_CALLBACK_ORIGIN: 'https://digitalogic.ir',
	PBX_CALLBACK_PATH: '/wp-json/digitalogic/v1/call-verification/pbx-confirm',
	PBX_SITE_ID: 'digitalogic.ir',
	PBX_KEY_ID: 'v1',
	PBX_EXPECTED_AGI_CONTEXT: 'pbx-call-verification-digitalogic',
	PBX_EXPECTED_DID: '+982166754123',
	PBX_HMAC_SECRET_FILE: '/etc/asterisk/secrets/digitalogic-pbx-verification.key',
	PBX_PROMPT_ENTER_CODE: 'custom/call-verification/digitalogic/current/enter-code',
	PBX_PROMPT_VERIFIED: 'custom/call-verification/digitalogic/current/verified',
	PBX_PROMPT_INVALID: 'custom/call-verification/digitalogic/current/invalid',
	PBX_PROMPT_TEMPORARY_FAILURE: 'custom/call-verification/digitalogic/current/temporary-failure',
});

function agiInput(responseLines) {
	return Readable.from([[
		`agi_context: ${ENV.PBX_EXPECTED_AGI_CONTEXT}`,
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
