'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const bridge = require('./event-product-codex-bridge.cjs');

const hash = (character) => `sha256:${character.repeat(64)}`;

function productSignal(overrides = {}) {
  return {
    type: 'product',
    cursor: 71,
    event: 'product.updated',
    data: {
      id: 850,
      product_id: 850,
      parent_id: 0,
      event_id: hash('a'),
      product_code: 'GL850',
      changed_fields: ['name', 'publication'],
      source_revision: hash('b'),
    },
    ...overrides,
  };
}

test('argument parser pins the target and rejects ambiguous inputs', () => {
  const parsed = bridge.parseArgs([
    '--thread', '01a0e806-6323-7f32-a7bf-82fc5875b566',
    '--workspace', 'C:\\work',
  ]);
  assert.equal(parsed.sshHost, 'digitalogic.ir');
  assert.throws(() => bridge.parseArgs(['--thread', 'bad', '--workspace', 'C:\\work']), /invalid thread id/);
  assert.throws(() => bridge.parseArgs(['--thread', '01a0e806-6323-7f32-a7bf-82fc5875b566', '--workspace', 'relative']), /absolute/);
});

test('signal validator accepts only the agreed sanitized committed-product envelope', () => {
  const signal = bridge.validateSignal(productSignal());
  assert.equal(signal.eventId, 'panel:71');
  assert.equal(signal.data.product_code, 'GL850');
  assert.deepEqual(signal.data.changed_fields, ['name', 'publication']);
  assert.throws(() => bridge.validateSignal(productSignal({ data: { ...productSignal().data, price: '12' } })), /unexpected/);
  assert.throws(() => bridge.validateSignal(productSignal({ data: { ...productSignal().data, changed_fields: ['description'] } })), /changed fields/);
  assert.throws(() => bridge.validateSignal(productSignal({ data: { ...productSignal().data, changed_fields: ['publication', 'name'] } })), /sorted/);
  assert.throws(() => bridge.validateSignal(productSignal({ data: { ...productSignal().data, event_id: 'not-a-hash' } })), /source event id/);
  const genericWooData = { id: 850, product_id: 850, parent_id: 0 };
  assert.throws(() => bridge.validateSignal(productSignal({ data: genericWooData })), /source event id/);
});

test('prompt treats all event data as wake-only and requires downstream proof', () => {
  const signal = bridge.validateSignal(productSignal());
  const prompt = bridge.eventPrompt(signal);
  assert.match(prompt, /untrusted wakeup hints/);
  assert.match(prompt, /Re-read current authoritative Patris export state and WordPress\/WooCommerce state/);
  assert.match(prompt, /identity, content, image, categorization, and commercial fields/);
  assert.match(prompt, /direct catalog search, and Woodmart AJAX search/);
  assert.match(prompt, /panel:71/);
  assert.match(prompt, /EVENT_HANDLED panel:71/);
});

test('completion requires an exact event-specific acknowledgement from an agent message', () => {
  const good = JSON.stringify({ type: 'item.completed', item: { type: 'agent_message', text: 'Evidence complete.\nEVENT_HANDLED panel:71' } });
  const wrong = JSON.stringify({ type: 'item.completed', item: { type: 'agent_message', text: 'EVENT_HANDLED panel:72' } });
  const toolOutput = JSON.stringify({ type: 'item.completed', item: { type: 'command_execution', text: 'EVENT_HANDLED panel:71' } });
  assert.equal(bridge.completionAcknowledged(good, 'panel:71'), true);
  assert.equal(bridge.completionAcknowledged(wrong, 'panel:71'), false);
  assert.equal(bridge.completionAcknowledged(toolOutput, 'panel:71'), false);
});

test('state write is durable-shaped and preserves an inflight reservation for crash recovery', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'digitalogic-product-bridge-'));
  const statePath = path.join(directory, 'state.json');
  const signal = bridge.validateSignal(productSignal());
  bridge.atomicWriteJson(statePath, { version: 1, cursor: 70, inflight: signal });
  const recovered = bridge.readState(statePath);
  assert.equal(recovered.cursor, 70);
  assert.equal(recovered.inflight.eventId, 'panel:71');
  fs.rmSync(directory, { recursive: true, force: true });
});

test('remote source subscribes before durable drain and contains no polling loop', () => {
  const source = bridge.remoteSource(70);
  const subscribeAt = source.indexOf("dg_command($stream, array('SUBSCRIBE'");
  const drainAt = source.indexOf('dg_drain($dg_cursor);', subscribeAt);
  assert.ok(subscribeAt > 0 && drainAt > subscribeAt);
  assert.match(source, /Digitalogic_Panel::get_events_since\(0\)/);
  assert.match(source, /Digitalogic_Panel::get_latest_event_id\(\)/);
	assert.match(source, /\$event\['name'\]/);
	assert.doesNotMatch(source, /\$event\['event'\]/);
  assert.match(source, /stream_set_timeout\(\$stream, PHP_INT_MAX\)/);
  assert.doesNotMatch(source, /\bsleep\s*\(/);
  assert.doesNotMatch(source, /\busleep\s*\(/);
  assert.doesNotMatch(source, /setInterval/);
  assert.doesNotMatch(source, /wp_schedule_event/);
});

test('gap signal has a stable deterministic idempotency key', () => {
  const signal = bridge.validateSignal({ type: 'gap', oldest: 901, cursor: 1100 });
  assert.equal(signal.eventId, 'panel-gap:901:1100');
  assert.match(bridge.eventPrompt(signal), /full authoritative reconciliation/);
});
