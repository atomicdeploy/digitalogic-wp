'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const {
	commandLogRecord,
	channelFlagEnabled,
	endpointAllowed,
	explicitRequestedChannels,
	notify,
	payloadRequestsChannel,
	routeDecision,
} = require('../lib/office-automation-sip-notifier.cjs');

function preferences(overrides = {}) {
	return {
		channels: {
			sip: {
				categories: ['error', 'important'],
				mutedCategories: [],
				endpoints: {},
				...(overrides.sip || {}),
			},
			telegram: { categories: ['all'], mutedCategories: [], endpoints: {} },
			ntfy: { categories: ['all'], mutedCategories: [], endpoints: {} },
		},
	};
}

test('explicit ntfy and Telegram request is a hard SIP deny with zero sends', async () => {
	let sends = 0;
	const result = await notify({
		event_type: 'php_fpm_slowlog',
		category: 'web_health',
		severity: 'warning',
		notify_channels: ['ntfy', 'telegram'],
		extensions: ['synthetic-subscriber'],
	}, {
		preferences: preferences(),
		subscribers: new Set(['synthetic-subscriber']),
		defaultExtensions: ['synthetic-default'],
		sendSipMessage: async () => {
			sends += 1;
			return { route: 'local' };
		},
	});

	assert.deepEqual(result, {
		ok: true,
		skipped: true,
		reason: 'sip_channel_not_requested',
		endpoints: [],
		categories: ['error', 'important', 'web_health'],
		results: [],
	});
	assert.equal(sends, 0);
});

test('route policy denies SIP before the n8n SIP gate for an explicit non-SIP allow-list', () => {
	const result = routeDecision({
		category: 'web_health',
		severity: 'warning',
		channels: ['ntfy', 'telegram'],
		notify_channels: ['ntfy', 'telegram'],
	}, { preferences: preferences() });

	assert.deepEqual(result.channels, { sip: false, telegram: true, ntfy: true });
});

test('explicit SIP request sends only to eligible subscriber and default endpoints', async () => {
	const sent = [];
	const result = await notify({
		event_type: 'service_warning',
		category: 'error',
		severity: 'warning',
		channels: ['sip'],
		extensions: ['synthetic-subscriber', 'synthetic-unsubscribed', 'synthetic-default'],
	}, {
		preferences: preferences(),
		subscribers: new Set(['synthetic-subscriber']),
		defaultExtensions: ['synthetic-default'],
		sendSipMessage: async endpoint => {
			sent.push(endpoint);
			return { route: 'local', rewritten_target: endpoint };
		},
	});

	assert.deepEqual(sent, ['synthetic-subscriber', 'synthetic-default']);
	assert.equal(result.ok, true);
	assert.equal(result.endpoint_count, 2);
	assert.equal(result.sent_count, 2);
	assert.equal(result.failed_count, 0);
	assert.equal(result.results.length, 2);
	assert.equal(result.results.some(item => Object.prototype.hasOwnProperty.call(item, 'endpoint')), false);
	assert.doesNotMatch(JSON.stringify(result), /synthetic-(?:subscriber|default|unsubscribed)/);
});

test('SIP send failures return a stable code without destination or transport details', async () => {
	const result = await notify({
		category: 'error',
		severity: 'warning',
		channels: ['sip'],
		extensions: ['synthetic-private-endpoint'],
	}, {
		preferences: preferences(),
		subscribers: new Set(['synthetic-private-endpoint']),
		defaultExtensions: [],
		sendSipMessage: async endpoint => {
			throw new Error(`transport failed for ${endpoint}`);
		},
	});

	assert.equal(result.ok, false);
	assert.equal(result.endpoint_count, 1);
	assert.equal(result.failed_count, 1);
	assert.deepEqual(result.results, [{ ok: false, error: 'sip_send_failed' }]);
	assert.doesNotMatch(JSON.stringify(result), /synthetic-private-endpoint|transport failed/);
});

test('endpoint category and mute preferences still apply after the channel gate', () => {
	const prefs = preferences({
		sip: {
			categories: ['all'],
			endpoints: {
				'synthetic-muted': { categories: ['error'], mutedCategories: ['error'] },
				'synthetic-allowed': { categories: ['error'], mutedCategories: [] },
			},
		},
	});
	const payload = { category: 'error', severity: 'warning', channels: ['sip'] };

	assert.equal(endpointAllowed(prefs, 'synthetic-muted', payload, new Set(), []), false);
	assert.equal(endpointAllowed(prefs, 'synthetic-allowed', payload, new Set(), []), true);
	assert.equal(endpointAllowed(prefs, 'synthetic-unknown', payload, new Set(), []), false);
});

test('legacy payloads retain the historical channel behavior', () => {
	assert.equal(explicitRequestedChannels({ event_type: 'legacy' }), null);
	assert.equal(payloadRequestsChannel({ event_type: 'legacy' }, 'sip'), true);
	assert.equal(payloadRequestsChannel({ channels: { sip: false, ntfy: true } }, 'sip'), false);
	assert.equal(payloadRequestsChannel({ channels: { sip: 'false', ntfy: 'true' } }, 'sip'), false);
	assert.equal(payloadRequestsChannel({ channels: { sip: true } }, 'sip'), true);
	assert.equal(payloadRequestsChannel({ channels: ['sip'], notify_channels: ['ntfy'] }, 'sip'), false);
	assert.equal(payloadRequestsChannel({ channels: ['all'], notify_channels: ['sip'] }, 'sip'), true);
});

test('object channel flags use explicit boolean semantics', () => {
	assert.equal(channelFlagEnabled(true), true);
	assert.equal(channelFlagEnabled('yes'), true);
	assert.equal(channelFlagEnabled('false'), false);
	assert.equal(channelFlagEnabled('synthetic-value'), false);
});

test('command audit records contain metadata rather than identities or message bodies', () => {
	const record = commandLogRecord({
		direction: 'inbound',
		from: 'synthetic-sender-identity',
		endpoint: 'synthetic-private-endpoint',
		body: 'synthetic-private-message',
		error: 'failure mentioning synthetic-private-endpoint',
	}, '2026-07-21T00:00:00.000Z');
	const serialized = JSON.stringify(record);

	assert.equal(record.endpoint_present, true);
	assert.equal(record.sender_present, true);
	assert.equal(record.body_bytes, Buffer.byteLength('synthetic-private-message'));
	assert.equal(record.error, 'command_failed');
	assert.doesNotMatch(serialized, /synthetic-(?:sender|private)/);
});
