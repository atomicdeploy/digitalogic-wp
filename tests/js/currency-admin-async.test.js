'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'assets', 'js', 'currency-admin-async.js'),
	'utf8'
);

function browserFixture(fetchResult, options = {}) {
	const requests = [];
	const appended = [];
	const field = {id: options.generatedId || 'acf-field_runtime', value: '29500'};
	const unrelated = {
		getAttribute() { return 'unrelated_setting'; },
		classList: {contains() { return false; }}
	};
	const wrapper = {
		getAttribute(name) { return name === 'data-name' ? 'yuan_price' : ''; },
		classList: {contains(name) { return name === 'acf-clone' ? false : false; }},
		appendChild(node) { appended.push(node); }
	};
	const nodes = options.noCurrencySurface ? [] : [unrelated, wrapper];
	const document = {
		readyState: 'complete',
		querySelectorAll(selector) {
			assert.equal(selector, '.acf-field[data-name]');
			return nodes;
		},
		getElementById(id) {
			assert.equal(id, 'digitalogic-currency-async-status');
			return null;
		},
		createElement() {
			return {
				id: '',
				className: '',
				dataset: {},
				hidden: false,
				textContent: '',
				attributes: {},
				setAttribute(name, value) { this.attributes[name] = value; }
			};
		},
		addEventListener() {
			throw new Error('DOMContentLoaded is not needed for a complete document.');
		}
	};
	const context = {
		AbortController,
		Array,
		Date,
		Number,
		Object,
		Promise,
		String,
		URLSearchParams,
		clearTimeout,
		console,
		document,
		fetch(url, requestOptions) {
			requests.push({url, options: requestOptions});
			const result = typeof fetchResult === 'function'
				? fetchResult(requests.length, requestOptions)
				: fetchResult;
			if (result instanceof Error) {
				return Promise.reject(result);
			}
			if (result && typeof result.json === 'function') {
				return Promise.resolve(result);
			}
			return Promise.resolve({json() { return Promise.resolve(result); }});
		},
		setTimeout,
		DigitalogicCurrencyAsync: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			fieldNames: ['dollar_price', 'yuan_price'],
			requestTimeoutMs: options.requestTimeoutMs || 50,
			terminalTimeoutMs: 1000,
			pollIntervalMs: 1,
			reconnectIntervalMs: 1
		}
	};
	context.window = context;
	vm.runInNewContext(source, context, {filename: 'currency-admin-async.js'});

	return {appended, field, requests};
}

test('semantic ACF discovery survives generated ID rotation and installs no submit interception', async () => {
	for (const generatedId of ['acf-field_rotated_a', 'acf-field_rotated_b']) {
		const fixture = browserFixture({success: true, data: {status: 'idle'}}, {generatedId});
		await new Promise((resolve) => setImmediate(resolve));
		assert.equal(fixture.appended.length, 1);
		assert.equal(fixture.requests.length, 1);
		assert.match(fixture.requests[0].options.body, /active_only=1/);
		assert.equal(fixture.field.value, '29500');
	}
	assert.equal(source.includes('acf-field_68e12344bb0e4'), false);
	assert.equal(source.includes('preventDefault'), false);
	assert.equal(source.includes('.value ='), false);
});

test('anonymous historical terminal job is ignored and cannot snap back a field', async () => {
	const fixture = browserFixture({
		success: true,
		data: {
			job_id: 'historical-job',
			generation: 7,
			status: 'confirmed',
			confirmed_currency: {yuan_price: 29000},
			message_fa: 'تأیید قدیمی'
		}
	});
	await new Promise((resolve) => setTimeout(resolve, 10));
	assert.equal(fixture.requests.length, 1);
	assert.equal(fixture.field.value, '29500');
	assert.equal(fixture.appended[0].textContent, '');
});

test('anonymous active job binds all later polls to exact identity without mutating fields', async () => {
	const fixture = browserFixture((requestNumber) => requestNumber === 1
		? {
			success: true,
			data: {
				job_id: 'queued-job',
				generation: 11,
				status: 'queued',
				deadline_at: Math.floor(Date.now() / 1000) + 30,
				message_fa: 'در صف'
			}
		}
		: {
			success: true,
			data: {
				job_id: 'queued-job',
				generation: 11,
				status: 'confirmed',
				confirmed_currency: {yuan_price: 29501},
				message_fa: 'تأیید شد'
			}
		});
	await new Promise((resolve) => setTimeout(resolve, 20));
	assert.equal(fixture.requests.length, 2);
	assert.match(fixture.requests[1].options.body, /job_id=queued-job/);
	assert.match(fixture.requests[1].options.body, /generation=11/);
	assert.doesNotMatch(fixture.requests[1].options.body, /active_only=1/);
	assert.equal(fixture.field.value, '29500');
	assert.equal(fixture.appended[0].textContent, 'تأیید شد');
});

test('a reloaded ACF screen resumes committed publication and exposes terminal operator failure', async () => {
	const fixture = browserFixture((requestNumber) => requestNumber === 1
		? {
			success: true,
			data: {
				job_id: 'publishing-job',
				generation: 12,
				status: 'publishing',
				deadline_at: Math.floor(Date.now() / 1000) - 30,
				message_fa: 'در حال انتشار'
			}
		}
		: {
			success: true,
			data: {
				job_id: 'publishing-job',
				generation: 12,
				status: 'publication_failed',
				message_fa: 'انتشار نیاز به بررسی مدیر دارد'
			}
		});
	await new Promise((resolve) => setTimeout(resolve, 20));
	assert.equal(fixture.requests.length, 2);
	assert.match(fixture.requests[1].options.body, /job_id=publishing-job/);
	assert.match(fixture.requests[1].options.body, /generation=12/);
	assert.equal(fixture.appended[0].dataset.status, 'publication_failed');
	assert.equal(fixture.appended[0].textContent, 'انتشار نیاز به بررسی مدیر دارد');
});

test('response-body timeout is bounded and leaves an actionable terminal message', async () => {
	const fixture = browserFixture((requestNumber, requestOptions) => ({
		json() {
			return new Promise((resolve, reject) => {
				requestOptions.signal.addEventListener('abort', () => reject(new Error('body timeout')), {once: true});
			});
		}
	}), {requestTimeoutMs: 5});
	await new Promise((resolve) => setTimeout(resolve, 20));
	assert.equal(fixture.requests.length, 1);
	assert.equal(fixture.appended[0].dataset.status, 'failed');
	assert.match(fixture.appended[0].textContent, /body timeout/);
});

test('an unsupported screen with no currency surface performs no request', async () => {
	const fixture = browserFixture({success: true, data: {status: 'idle'}}, {noCurrencySurface: true});
	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(fixture.requests.length, 0);
	assert.equal(fixture.appended.length, 0);
});
