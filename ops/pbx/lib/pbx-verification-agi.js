#!/usr/bin/env node
'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');

const EXIT = Object.freeze({
	VERIFIED: 0,
	INVALID_CALL: 10,
	INVALID_CODE: 11,
	CONFIGURATION: 20,
	NETWORK: 30,
	REMOTE_REJECTED: 40,
	PROTOCOL: 50,
	HANGUP: 60,
});

class AgiHangupError extends Error {}
class ProtocolError extends Error {}

function normalizeIranNumber(value, options = {}) {
	if (typeof value !== 'string') {
		return null;
	}

	const compact = value.trim().replace(/[\s().-]/g, '');
	if (!/^\+?\d+$/.test(compact)) {
		return null;
	}

	let normalized;
	if (/^\+98\d{10}$/.test(compact)) {
		normalized = compact;
	} else if (/^0098\d{10}$/.test(compact)) {
		normalized = `+${compact.slice(2)}`;
	} else if (options.allowZeroCountryPrefix === true && /^098\d{10}$/.test(compact)) {
		normalized = `+${compact.slice(1)}`;
	} else if (/^98\d{10}$/.test(compact)) {
		normalized = `+${compact}`;
	} else if (/^0\d{10}$/.test(compact)) {
		normalized = `+98${compact.slice(1)}`;
	} else if (options.allowBareNsn === true && /^[1-9]\d{9}$/.test(compact)) {
		normalized = `+98${compact}`;
	} else if (typeof options.localAreaCode === 'string'
		&& /^[1-9]\d$/.test(options.localAreaCode) && /^\d{8}$/.test(compact)) {
		normalized = `+98${options.localAreaCode}${compact}`;
	} else {
		return null;
	}

	return /^\+98[1-9]\d{9}$/.test(normalized) ? normalized : null;
}

function parseAreaCode(value) {
	if (value === undefined || value === '') {
		return null;
	}
	if (!/^[1-9]\d$/.test(value)) {
		throw new Error('Invalid local area code');
	}
	return value;
}

function validateSixDigitCode(value) {
	return typeof value === 'string' && /^[1-9]\d{5}$/.test(value);
}

function validateEventId(value) {
	return typeof value === 'string'
		&& /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

function validateCallId(value) {
	return typeof value === 'string'
		&& value.length >= 1
		&& value.length <= 128
		&& /^[A-Za-z0-9_.:-]+$/.test(value);
}

function validateNonce(value) {
	return typeof value === 'string'
		&& value.length >= 32
		&& value.length <= 128
		&& /^[A-Za-z0-9_-]+$/.test(value);
}

function parseBoolean(value, defaultValue = false) {
	if (value === undefined || value === '') {
		return defaultValue;
	}
	if (value === '1' || value === 'true') {
		return true;
	}
	if (value === '0' || value === 'false') {
		return false;
	}
	throw new Error('Invalid boolean configuration');
}

function parseInteger(value, name, minimum, maximum, defaultValue) {
	const source = value === undefined || value === '' ? String(defaultValue) : value;
	if (!/^\d+$/.test(source)) {
		throw new Error(`Invalid ${name}`);
	}
	const parsed = Number(source);
	if (!Number.isSafeInteger(parsed) || parsed < minimum || parsed > maximum) {
		throw new Error(`Invalid ${name}`);
	}
	return parsed;
}

function requireEnv(env, name) {
	const value = env[name];
	if (typeof value !== 'string' || value.length === 0) {
		throw new Error(`Missing ${name}`);
	}
	return value;
}

function validatePromptName(value) {
	return typeof value === 'string'
		&& value.length <= 200
		&& !value.includes('..')
		&& /^[A-Za-z0-9_./-]+$/.test(value);
}

function validateCallbackPath(value) {
	return typeof value === 'string'
		&& /^\/wp-json\/[A-Za-z0-9._~!$&'()*+,;=:@%/-]+$/.test(value)
		&& !value.includes('..')
		&& !value.includes('//');
}

function validateContextName(value) {
	return typeof value === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(value);
}

function loadConfig(env = process.env) {
	const callbackOrigin = new URL(requireEnv(env, 'PBX_CALLBACK_ORIGIN'));
	if (callbackOrigin.protocol !== 'https:'
		&& !(callbackOrigin.protocol === 'http:' && ['127.0.0.1', '::1', 'localhost'].includes(callbackOrigin.hostname))) {
		throw new Error('Callback origin must use HTTPS or loopback HTTP');
	}
	if (callbackOrigin.username || callbackOrigin.password || callbackOrigin.search || callbackOrigin.hash
		|| (callbackOrigin.pathname !== '/' && callbackOrigin.pathname !== '')) {
		throw new Error('Callback origin must contain only a scheme and authority');
	}

	const callbackPath = requireEnv(env, 'PBX_CALLBACK_PATH');
	const pendingPath = requireEnv(env, 'PBX_PENDING_PATH');
	if (!validateCallbackPath(callbackPath) || !validateCallbackPath(pendingPath)
		|| callbackPath === pendingPath) {
		throw new Error('Invalid callback path');
	}

	const siteId = requireEnv(env, 'PBX_SITE_ID');
	const keyId = requireEnv(env, 'PBX_KEY_ID');
	const expectedContext = requireEnv(env, 'PBX_EXPECTED_AGI_CONTEXT');
	const expectedPendingContext = requireEnv(env, 'PBX_EXPECTED_PENDING_CONTEXT');
	if (!/^[a-z0-9.-]{1,100}$/i.test(siteId)
		|| !/^[A-Za-z0-9._:-]{1,100}$/.test(keyId)
		|| !validateContextName(expectedContext)
		|| !validateContextName(expectedPendingContext)
		|| expectedContext === expectedPendingContext) {
		throw new Error('Invalid site, key, or context identifier');
	}

	const expectedDid = normalizeIranNumber(requireEnv(env, 'PBX_EXPECTED_DID'));
	if (!expectedDid) {
		throw new Error('Invalid expected DID');
	}

	const prompts = {
		enterCode: requireEnv(env, 'PBX_PROMPT_ENTER_CODE'),
		pendingCode: requireEnv(env, 'PBX_PROMPT_PENDING_CODE'),
		verified: requireEnv(env, 'PBX_PROMPT_VERIFIED'),
		invalid: requireEnv(env, 'PBX_PROMPT_INVALID'),
		temporaryFailure: requireEnv(env, 'PBX_PROMPT_TEMPORARY_FAILURE'),
	};
	if (!Object.values(prompts).every(validatePromptName)) {
		throw new Error('Invalid prompt name');
	}

	const configuredSecretFile = requireEnv(env, 'PBX_HMAC_SECRET_FILE');
	if (!path.isAbsolute(configuredSecretFile)) {
		throw new Error('Secret file path must be absolute');
	}
	const secretFile = path.normalize(configuredSecretFile);

	return Object.freeze({
		callbackOrigin: callbackOrigin.origin,
		callbackPath,
		callbackUrl: new URL(callbackPath, callbackOrigin.origin).toString(),
		pendingPath,
		pendingUrl: new URL(pendingPath, callbackOrigin.origin).toString(),
		siteId,
		keyId,
		expectedContext,
		expectedPendingContext,
		expectedDid,
		secretFile,
		allowBareAni: parseBoolean(env.PBX_ALLOW_BARE_ANI, false),
		allowBareDid: parseBoolean(env.PBX_ALLOW_BARE_DID, false),
		allowZeroCountryPrefixAni: parseBoolean(env.PBX_ALLOW_ZERO_COUNTRY_PREFIX_ANI, false),
		allowZeroCountryPrefixDid: parseBoolean(env.PBX_ALLOW_ZERO_COUNTRY_PREFIX_DID, false),
		aniLocalAreaCode: parseAreaCode(env.PBX_ANI_LOCAL_AREA_CODE),
		didLocalAreaCode: parseAreaCode(env.PBX_DID_LOCAL_AREA_CODE),
		httpTimeoutMs: parseInteger(env.PBX_HTTP_TIMEOUT_MS, 'HTTP timeout', 1000, 10000, 4000),
		pendingHttpTimeoutMs: parseInteger(env.PBX_PENDING_HTTP_TIMEOUT_MS, 'pending HTTP timeout', 500, 3000, 1500),
		dtmfTimeoutMs: parseInteger(env.PBX_DTMF_TIMEOUT_MS, 'DTMF timeout', 3000, 30000, 12000),
		dtmfAttempts: parseInteger(env.PBX_DTMF_ATTEMPTS, 'DTMF attempts', 1, 3, 3),
		maxResponseBytes: parseInteger(env.PBX_MAX_RESPONSE_BYTES, 'response limit', 256, 16384, 4096),
		prompts: Object.freeze(prompts),
	});
}

function loadSecret(secretFile) {
	const stat = fs.lstatSync(secretFile);
	if (!stat.isFile() || stat.isSymbolicLink()) {
		throw new Error('Secret path must be a regular file');
	}
	if (process.platform !== 'win32') {
		if (stat.uid !== 0 || (stat.mode & 0o027) !== 0) {
			throw new Error('Secret file must be root-owned, not writable by group, and inaccessible to other users');
		}
	}

	const encoded = fs.readFileSync(secretFile, 'utf8').trim();
	if (encoded.length < 44 || encoded.length > 256 || encoded.length % 4 !== 0
		|| !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded)) {
		throw new Error('Secret file must contain canonical base64');
	}
	const secret = Buffer.from(encoded, 'base64');
	if (secret.length < 32 || secret.toString('base64') !== encoded) {
		throw new Error('Secret must contain at least 256 bits');
	}
	return secret;
}

function canonicalRequest(callbackPath, timestamp, nonce, rawBody) {
	if (typeof callbackPath !== 'string' || !callbackPath.startsWith('/')
		|| !/^\d{10}$/.test(String(timestamp)) || !validateNonce(nonce)) {
		throw new Error('Invalid canonical request input');
	}
	const body = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(rawBody, 'utf8');
	const digest = crypto.createHash('sha256').update(body).digest('hex');
	return `v1\nPOST\n${callbackPath}\n${timestamp}\n${nonce}\n${digest}`;
}

function signCanonical(secret, canonical) {
	if (!Buffer.isBuffer(secret) || secret.length < 32 || typeof canonical !== 'string') {
		throw new Error('Invalid signing input');
	}
	return crypto.createHmac('sha256', secret).update(canonical, 'utf8').digest('hex');
}

function buildSignedRequest(config, secret, payload, options = {}) {
	const timestamp = String(options.timestamp || Math.floor(Date.now() / 1000));
	const nonce = options.nonce || crypto.randomBytes(24).toString('base64url');
	const requestPath = options.path || config.callbackPath;
	if (!validateCallbackPath(requestPath)) {
		throw new Error('Invalid signed request path');
	}
	const rawBody = JSON.stringify(payload);
	const canonical = canonicalRequest(requestPath, timestamp, nonce, rawBody);
	const signature = signCanonical(secret, canonical);
	return {
		rawBody,
		canonical,
		headers: Object.freeze({
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'User-Agent': 'pbx-call-verification/1.1',
			'X-PBX-Key-Id': config.keyId,
			'X-PBX-Timestamp': timestamp,
			'X-PBX-Nonce': nonce,
			'X-PBX-Signature': signature,
		}),
	};
}

function buildPayload(config, call) {
	if (!validateEventId(call.eventId) || !validateCallId(call.callId)
		|| !validateSixDigitCode(call.code)
		|| normalizeIranNumber(call.calledNumber) !== config.expectedDid
		|| !normalizeIranNumber(call.callerNumber)) {
		throw new Error('Invalid callback payload input');
	}
	return {
		schema: 'phone-verification.v1',
		site_id: config.siteId,
		event_id: call.eventId,
		call_id: call.callId,
		called_number: call.calledNumber,
		caller_number: call.callerNumber,
		code: call.code,
		occurred_at: call.occurredAt || new Date().toISOString(),
	};
}

function buildPendingPayload(config, call) {
	if (!validateEventId(call.eventId) || !validateCallId(call.callId)
		|| normalizeIranNumber(call.calledNumber) !== config.expectedDid
		|| !normalizeIranNumber(call.callerNumber)) {
		throw new Error('Invalid pending payload input');
	}
	return {
		schema: 'phone-verification-pending.v1',
		site_id: config.siteId,
		event_id: call.eventId,
		call_id: call.callId,
		called_number: call.calledNumber,
		caller_number: call.callerNumber,
		occurred_at: call.occurredAt || new Date().toISOString(),
	};
}

function classifyResponse(status, json) {
	if (status >= 200 && status < 300) {
		if (json && (json.verified === true || json.status === 'verified'
			|| json.status === 'already_verified')) {
			return 'verified';
		}
		if (json && (json.verified === false || json.success === false
			|| ['invalid', 'expired', 'locked', 'rejected'].includes(json.status))) {
			return 'rejected';
		}
		return 'protocol';
	}
	if ([400, 404, 409, 410, 422].includes(status)) {
		return 'rejected';
	}
	if (status === 401 || status === 403) {
		return 'remote_auth';
	}
	if (status === 429 || status >= 500) {
		return 'temporary';
	}
	return 'protocol';
}

function classifyPendingResponse(status, json) {
	if (status >= 200 && status < 300) {
		if (json && !Array.isArray(json)
			&& Object.keys(json).length === 1
			&& Object.prototype.hasOwnProperty.call(json, 'pending')
			&& typeof json.pending === 'boolean') {
			return json.pending ? 'pending' : 'none';
		}
		return 'protocol';
	}
	if (status === 401 || status === 403) {
		return 'remote_auth';
	}
	if (status === 429 || status >= 500) {
		return 'temporary';
	}
	return 'protocol';
}

async function readBoundedResponse(response, maximumBytes) {
	const declaredLength = Number(response.headers.get('content-length') || 0);
	if (Number.isFinite(declaredLength) && declaredLength > maximumBytes) {
		throw new ProtocolError('Response exceeds configured limit');
	}

	if (!response.body || typeof response.body.getReader !== 'function') {
		const fallback = await response.text();
		if (Buffer.byteLength(fallback, 'utf8') > maximumBytes) {
			throw new ProtocolError('Response exceeds configured limit');
		}
		return fallback;
	}

	const reader = response.body.getReader();
	const chunks = [];
	let total = 0;
	while (true) {
		const { done, value } = await reader.read();
		if (done) {
			break;
		}
		total += value.byteLength;
		if (total > maximumBytes) {
			await reader.cancel();
			throw new ProtocolError('Response exceeds configured limit');
		}
		chunks.push(Buffer.from(value));
	}
	return Buffer.concat(chunks, total).toString('utf8');
}

async function postSignedJson(config, secret, payload, endpoint, fetchImpl = globalThis.fetch) {
	if (typeof fetchImpl !== 'function') {
		return { error: 'temporary' };
	}
	const request = buildSignedRequest(config, secret, payload, { path: endpoint.path });
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), endpoint.timeoutMs);
	let response;
	let text;
	try {
		response = await fetchImpl(endpoint.url, {
			method: 'POST',
			headers: request.headers,
			body: request.rawBody,
			signal: controller.signal,
			redirect: 'error',
		});
		text = await readBoundedResponse(response, config.maxResponseBytes);
	} catch (error) {
		return { error: error instanceof ProtocolError ? 'protocol' : 'temporary' };
	} finally {
		clearTimeout(timer);
	}

	let json = null;
	if (text.length > 0) {
		try {
			json = JSON.parse(text);
		} catch (_) {
			return { error: 'protocol' };
		}
	}
	return { status: response.status, json };
}

async function postVerification(config, secret, payload, fetchImpl = globalThis.fetch) {
	const result = await postSignedJson(config, secret, payload, {
		path: config.callbackPath,
		url: config.callbackUrl,
		timeoutMs: config.httpTimeoutMs,
	}, fetchImpl);
	return result.error || classifyResponse(result.status, result.json);
}

async function postPending(config, secret, payload, fetchImpl = globalThis.fetch) {
	const result = await postSignedJson(config, secret, payload, {
		path: config.pendingPath,
		url: config.pendingUrl,
		timeoutMs: config.pendingHttpTimeoutMs,
	}, fetchImpl);
	return result.error || classifyPendingResponse(result.status, result.json);
}

class LineReader {
	constructor(input) {
		this.lines = [];
		this.waiters = [];
		this.closed = false;
		this.interface = readline.createInterface({ input, crlfDelay: Infinity });
		this.interface.on('line', (line) => this.push(line));
		this.interface.on('close', () => this.close());
	}

	push(line) {
		const waiter = this.waiters.shift();
		if (waiter) {
			clearTimeout(waiter.timer);
			waiter.resolve(line);
		} else {
			this.lines.push(line);
		}
	}

	close() {
		this.closed = true;
		for (const waiter of this.waiters.splice(0)) {
			clearTimeout(waiter.timer);
			waiter.reject(new ProtocolError('AGI input closed'));
		}
	}

	next(timeoutMs) {
		if (this.lines.length > 0) {
			return Promise.resolve(this.lines.shift());
		}
		if (this.closed) {
			return Promise.reject(new ProtocolError('AGI input closed'));
		}
		return new Promise((resolve, reject) => {
			const waiter = { resolve, reject, timer: null };
			waiter.timer = setTimeout(() => {
				const index = this.waiters.indexOf(waiter);
				if (index >= 0) {
					this.waiters.splice(index, 1);
				}
				reject(new ProtocolError('AGI response timed out'));
			}, timeoutMs);
			this.waiters.push(waiter);
		});
	}
}

class AgiSession {
	constructor(reader, output, environment) {
		this.reader = reader;
		this.output = output;
		this.environment = environment;
	}

	static async open(input = process.stdin, output = process.stdout) {
		const reader = new LineReader(input);
		const environment = {};
		while (true) {
			const line = await reader.next(5000);
			if (line === '') {
				break;
			}
			const match = /^agi_([a-z0-9_]+):\s?(.*)$/i.exec(line);
			if (!match) {
				throw new ProtocolError('Invalid AGI environment');
			}
			environment[`agi_${match[1].toLowerCase()}`] = match[2];
		}
		return new AgiSession(reader, output, environment);
	}

	async command(command, timeoutMs = 5000) {
		this.output.write(`${command}\n`);
		const response = await this.reader.next(timeoutMs);
		if (response === 'HANGUP') {
			throw new AgiHangupError('Channel hung up');
		}
		// Asterisk appends an allowlisted endpos field to STREAM FILE responses.
		// Keep the grammar deliberately narrow so unexpected AGI output fails closed.
		const match = /^200 result=(-?\d{1,20}|[*#]?)(?: \(([^()\r\n]{0,1024})\))?(?: endpos=(\d{1,20}))?$/.exec(response);
		if (!match) {
			throw new ProtocolError('Invalid AGI response');
		}
		if (match[1] === '-1') {
			throw new AgiHangupError('Channel hung up');
		}
		return { result: match[1], value: match[2], endpos: match[3] };
	}

	async getVariable(name) {
		if (!/^[A-Za-z0-9_()]+$/.test(name)) {
			throw new ProtocolError('Invalid variable name');
		}
		const response = await this.command(`GET VARIABLE ${name}`);
		return response.result === '1' ? (response.value || '') : '';
	}

	async setResult(value) {
		if (!/^[a-z_]+$/.test(value)) {
			throw new ProtocolError('Invalid result value');
		}
		await this.command(`SET VARIABLE PBX_VERIFY_RESULT ${value}`);
	}

	async setPending(value) {
		if (typeof value !== 'boolean') {
			throw new ProtocolError('Invalid pending value');
		}
		await this.command(`SET VARIABLE PBX_VERIFY_PENDING ${value ? '1' : '0'}`);
	}

	async getData(prompt, timeoutMs) {
		const response = await this.command(`GET DATA ${prompt} ${timeoutMs} 6`, timeoutMs + 5000);
		return response.result;
	}

	decodeDtmfResult(result) {
		if (result === '0' || result === '') {
			return null;
		}
		const ascii = Number(result);
		if (!Number.isSafeInteger(ascii)) {
			return null;
		}
		if (ascii === 42) {
			return '*';
		}
		if (ascii >= 48 && ascii <= 57) {
			return String.fromCharCode(ascii);
		}
		return null;
	}

	async streamForShortcutKey(prompt) {
		const response = await this.command(`STREAM FILE ${prompt} "0123456789*"`, 30000);
		return this.decodeDtmfResult(response.result);
	}

	async waitForShortcutKey(timeoutMs) {
		const response = await this.command(`WAIT FOR DIGIT ${timeoutMs}`, timeoutMs + 5000);
		return this.decodeDtmfResult(response.result);
	}

	async getShortcutCode(prompt, timeoutMs) {
		let key = await this.streamForShortcutKey(prompt);
		const deadline = Date.now() + timeoutMs;
		if (key === null) {
			key = await this.waitForShortcutKey(timeoutMs);
		}
		if (key === '*' || !/^\d$/.test(key || '')) {
			return null;
		}

		let code = key;
		while (code.length < 6) {
			const remainingMs = deadline - Date.now();
			if (remainingMs <= 0) {
				return null;
			}
			key = await this.waitForShortcutKey(remainingMs);
			if (key === '*' || !/^\d$/.test(key || '')) {
				return null;
			}
			code += key;
		}
		return code;
	}

	async stream(prompt) {
		await this.command(`STREAM FILE ${prompt} ""`, 30000);
	}
}

async function safeSetResult(agi, result) {
	try {
		await agi.setResult(result);
	} catch (_) {
		// Exit status remains authoritative if the channel is already gone.
	}
}

async function safeSetPending(agi, pending) {
	try {
		await agi.setPending(pending);
	} catch (_) {
		// The dialplan initializes the variable to zero before invoking AGI.
	}
}

function parseMode(value) {
	const mode = value === undefined || value === '' ? 'verify' : value;
	return ['verify', 'preflight', 'shortcut'].includes(mode) ? mode : null;
}

async function runAgi(dependencies = {}) {
	const mode = parseMode(dependencies.mode);
	if (mode === null) {
		return EXIT.CONFIGURATION;
	}
	let config;
	let secret;
	try {
		config = loadConfig(dependencies.env || process.env);
		secret = (dependencies.loadSecret || loadSecret)(config.secretFile);
	} catch (_) {
		return EXIT.CONFIGURATION;
	}

	let agi;
	try {
		agi = await AgiSession.open(dependencies.input || process.stdin, dependencies.output || process.stdout);
		const expectedContext = mode === 'verify' ? config.expectedContext : config.expectedPendingContext;
		if (agi.environment.agi_context !== expectedContext) {
			await safeSetResult(agi, 'invalid_call');
			return EXIT.INVALID_CALL;
		}

		const rawDid = (await agi.getVariable('PBX_VERIFY_DID')) || agi.environment.agi_dnid || '';
		const rawAni = (await agi.getVariable('PBX_VERIFY_ANI')) || agi.environment.agi_callerid || '';
		const calledNumber = normalizeIranNumber(rawDid, {
			allowBareNsn: config.allowBareDid,
			allowZeroCountryPrefix: config.allowZeroCountryPrefixDid,
			localAreaCode: config.didLocalAreaCode,
		});
		const callerNumber = normalizeIranNumber(rawAni, {
			allowBareNsn: config.allowBareAni,
			allowZeroCountryPrefix: config.allowZeroCountryPrefixAni,
			localAreaCode: config.aniLocalAreaCode,
		});
		const callId = agi.environment.agi_uniqueid || '';
		if (calledNumber !== config.expectedDid || !callerNumber || !validateCallId(callId)) {
			await safeSetResult(agi, 'invalid_call');
			return EXIT.INVALID_CALL;
		}

		const fetchImpl = dependencies.fetchImpl || globalThis.fetch;
		if (mode === 'preflight') {
			const payload = buildPendingPayload(config, {
				eventId: crypto.randomUUID(),
				callId,
				calledNumber,
				callerNumber,
				occurredAt: new Date().toISOString(),
			});
			const outcome = await postPending(config, secret, payload, fetchImpl);
			await safeSetPending(agi, outcome === 'pending');
			if (outcome === 'pending' || outcome === 'none') {
				return EXIT.VERIFIED;
			}
			return outcome === 'temporary' ? EXIT.NETWORK
				: outcome === 'remote_auth' ? EXIT.REMOTE_REJECTED : EXIT.PROTOCOL;
		}

		const verifyCode = async (code) => {
			const payload = buildPayload(config, {
				eventId: crypto.randomUUID(),
				callId,
				calledNumber,
				callerNumber,
				code,
				occurredAt: new Date().toISOString(),
			});
			return postVerification(config, secret, payload, fetchImpl);
		};

		if (mode === 'shortcut') {
			// The automatic shortcut should not trap an ordinary caller. Star, timeout,
			// partial input, or malformed input returns immediately. A real six-digit
			// typo gets one bounded retry before the historical call flow resumes.
			const shortcutAttempts = Math.min(config.dtmfAttempts, 2);
			for (let attempt = 1; attempt <= shortcutAttempts; attempt += 1) {
				const code = await agi.getShortcutCode(config.prompts.pendingCode, config.dtmfTimeoutMs);
				if (!validateSixDigitCode(code)) {
					await safeSetResult(agi, 'fallback');
					return EXIT.VERIFIED;
				}
				const outcome = await verifyCode(code);
				if (outcome === 'verified') {
					await agi.stream(config.prompts.verified);
					await safeSetResult(agi, 'verified');
					return EXIT.VERIFIED;
				}
				if (outcome === 'rejected') {
					await agi.stream(config.prompts.invalid);
					if (attempt < shortcutAttempts) {
						continue;
					}
					await safeSetResult(agi, 'fallback');
					return EXIT.INVALID_CODE;
				}
				await agi.stream(config.prompts.temporaryFailure);
				await safeSetResult(agi, outcome === 'temporary' ? 'temporary_failure' : 'remote_failure');
				return outcome === 'temporary' ? EXIT.NETWORK
					: outcome === 'remote_auth' ? EXIT.REMOTE_REJECTED : EXIT.PROTOCOL;
			}
		}

		for (let attempt = 1; attempt <= config.dtmfAttempts; attempt += 1) {
			const code = await agi.getData(config.prompts.enterCode, config.dtmfTimeoutMs);
			if (!validateSixDigitCode(code)) {
				if (attempt < config.dtmfAttempts) {
					await agi.stream(config.prompts.invalid);
				}
				continue;
			}

			const outcome = await verifyCode(code);

			if (outcome === 'verified') {
				await agi.stream(config.prompts.verified);
				await safeSetResult(agi, 'verified');
				return EXIT.VERIFIED;
			}
			if (outcome === 'rejected') {
				await agi.stream(config.prompts.invalid);
				continue;
			}

			await agi.stream(config.prompts.temporaryFailure);
			await safeSetResult(agi, outcome === 'temporary' ? 'temporary_failure' : 'remote_failure');
			return outcome === 'temporary' ? EXIT.NETWORK
				: outcome === 'remote_auth' ? EXIT.REMOTE_REJECTED : EXIT.PROTOCOL;
		}

		await safeSetResult(agi, 'invalid_code');
		return EXIT.INVALID_CODE;
	} catch (error) {
		if (error instanceof AgiHangupError) {
			return EXIT.HANGUP;
		}
		if (agi) {
			await safeSetResult(agi, 'protocol_failure');
		}
		return EXIT.PROTOCOL;
	} finally {
		secret?.fill(0);
	}
}

if (require.main === module) {
	runAgi({ mode: process.argv[2] }).then((exitCode) => {
		process.exitCode = exitCode;
	}).catch(() => {
		process.exitCode = EXIT.PROTOCOL;
	});
}

module.exports = {
	EXIT,
	buildPendingPayload,
	buildPayload,
	buildSignedRequest,
	canonicalRequest,
	classifyResponse,
	classifyPendingResponse,
	loadConfig,
	normalizeIranNumber,
	postVerification,
	postPending,
	runAgi,
	signCanonical,
	validateCallId,
	validateEventId,
	validateNonce,
	validateSixDigitCode,
};
