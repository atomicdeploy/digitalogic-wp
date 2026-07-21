'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const {
	environmentByPlaceholder,
	outputIsInsideRepository,
	renderWorkflow,
} = require('../scripts/render-n8n-workflow.cjs');

const root = path.resolve(__dirname, '..');
const template = JSON.parse(fs.readFileSync(path.join(root, 'n8n', 'office-automation-events.template.json'), 'utf8'));

function syntheticEnvironment() {
	const environment = {};
	for (const variable of Object.values(environmentByPlaceholder)) environment[variable] = `synthetic-${variable.toLowerCase()}`;
	Object.assign(environment, {
		OFFICE_N8N_LOCAL_EVENT_LOG_FILE: '/var/lib/office-automation/synthetic-events.jsonl',
		OFFICE_N8N_NTFY_URL: 'https://example.invalid/synthetic-topic',
		OFFICE_N8N_SHEETS_WEBHOOK_URL: 'https://example.invalid/synthetic-sheets',
		OFFICE_N8N_SIP_NOTIFY_URL: 'http://127.0.0.1:9999/v1/notify',
		OFFICE_N8N_SIP_ROUTE_URL: 'http://127.0.0.1:9999/v1/route',
		OFFICE_N8N_WEBHOOK_PATH: 'synthetic-office-events',
	});
	return environment;
}

test('versioned workflow is inactive and SIP has no route-gate bypass', () => {
	assert.equal(template.active, false);
	const routeEdges = template.connections['Get Notification Routing Policy'].main[0];
	assert.equal(routeEdges.some(edge => edge.node === 'Notify SIP Extensions'), false);
	assert.equal(routeEdges.some(edge => edge.node === 'Gate SIP Notification'), true);
	const gate = template.nodes.find(node => node.name === 'Gate SIP Notification');
	assert.match(gate.parameters.jsCode, /route\.channels\?\.sip/);
	assert.equal(template.connections['Gate SIP Notification'].main[0][0].node, 'Notify SIP Extensions');
});

test('workflow rendering resolves placeholders and keeps the artifact inactive', () => {
	const { workflow, serialized } = renderWorkflow(syntheticEnvironment());
	assert.equal(workflow.active, false);
	assert.doesNotMatch(serialized, /__[A-Z0-9_]+__/);
	assert.match(serialized, /synthetic-office-events/);
	const code = workflow.nodes.find(node => node.name === 'Normalize Office Automation Event').parameters.jsCode;
	assert.match(code, /channels: body\.channels/);
	assert.match(code, /notify_channels: body\.notify_channels/);
	assert.doesNotMatch(code, /\b(?:10\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])|192\.168)\.\d{1,3}\.\d{1,3}\b/);
	assert.match(code, /const hostMap = \{\};/);
});

test('normalization redacts credentials and private phone-routing fields before audit outputs', () => {
	const { workflow } = renderWorkflow(syntheticEnvironment());
	const code = workflow.nodes.find(node => node.name === 'Normalize Office Automation Event').parameters.jsCode;
	const execute = new Function('$input', '$getWorkflowStaticData', code);
	const [item] = execute({
		first: () => ({
			json: {
				body: {
					event_type: 'synthetic.warning',
					severity: 'warning',
					message: 'Bearer synthetic-private-credential',
					api_token: 'synthetic-private-token',
					billing_phone: 'synthetic-private-phone',
					destination: 'synthetic-private-destination',
					notify_channels: ['ntfy', 'telegram'],
				},
			},
		}),
	}, () => ({}));
	const serialized = JSON.stringify(item.json);
	const sipPayload = JSON.parse(item.json.sipPayload);

	assert.equal(item.json.eventRecord.raw.api_token, '[redacted]');
	assert.equal(item.json.eventRecord.raw.billing_phone, '[redacted]');
	assert.equal(item.json.eventRecord.raw.destination, '[redacted]');
	assert.deepEqual(sipPayload.notify_channels, ['ntfy', 'telegram']);
	assert.doesNotMatch(serialized, /synthetic-private-(?:credential|token|phone|destination)/);
});

test('renderer rejects repository output paths', () => {
	assert.equal(outputIsInsideRepository(path.join(root, 'rendered.json')), true);
	assert.equal(outputIsInsideRepository(path.join(os.tmpdir(), 'digitalogic-rendered.json')), false);
});
