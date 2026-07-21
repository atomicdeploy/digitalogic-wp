'use strict';

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const templatePath = path.join(root, 'n8n', 'office-automation-events.template.json');
const normalizerPath = path.join(root, 'n8n', 'normalize-office-event.code.js');

const environmentByPlaceholder = Object.freeze({
	__ERROR_WORKFLOW_ID__: 'OFFICE_N8N_ERROR_WORKFLOW_ID',
	__LOCAL_EVENT_LOG_FILE__: 'OFFICE_N8N_LOCAL_EVENT_LOG_FILE',
	__N8N_WORKFLOW_ID__: 'OFFICE_N8N_WORKFLOW_ID',
	__NTFY_URL__: 'OFFICE_N8N_NTFY_URL',
	__OFFICE_AUTOMATION_WEBHOOK_ID__: 'OFFICE_N8N_WEBHOOK_ID',
	__OFFICE_AUTOMATION_WEBHOOK_PATH__: 'OFFICE_N8N_WEBHOOK_PATH',
	__REDIS_CHANNEL__: 'OFFICE_N8N_REDIS_CHANNEL',
	__REDIS_CREDENTIAL_ID__: 'OFFICE_N8N_REDIS_CREDENTIAL_ID',
	__REDIS_CREDENTIAL_NAME__: 'OFFICE_N8N_REDIS_CREDENTIAL_NAME',
	__SHEETS_WEBHOOK_URL__: 'OFFICE_N8N_SHEETS_WEBHOOK_URL',
	__SIP_NOTIFY_URL__: 'OFFICE_N8N_SIP_NOTIFY_URL',
	__SIP_ROUTE_URL__: 'OFFICE_N8N_SIP_ROUTE_URL',
	__SIP_ROUTING_CREDENTIAL_ID__: 'OFFICE_N8N_SIP_ROUTING_CREDENTIAL_ID',
	__SIP_ROUTING_CREDENTIAL_NAME__: 'OFFICE_N8N_SIP_ROUTING_CREDENTIAL_NAME',
	__TELEGRAM_CHAT_ID__: 'OFFICE_N8N_TELEGRAM_CHAT_ID',
	__TELEGRAM_CREDENTIAL_ID__: 'OFFICE_N8N_TELEGRAM_CREDENTIAL_ID',
	__TELEGRAM_CREDENTIAL_NAME__: 'OFFICE_N8N_TELEGRAM_CREDENTIAL_NAME',
});

function requiredValues(environment = process.env) {
	const missing = [];
	const values = {};
	for (const [placeholder, variable] of Object.entries(environmentByPlaceholder)) {
		const value = String(environment[variable] || '').trim();
		if (!value) missing.push(variable);
		values[placeholder] = value;
	}
	if (missing.length) throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
	return values;
}

function replacePlaceholders(value, replacements) {
	if (Array.isArray(value)) return value.map(item => replacePlaceholders(item, replacements));
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, replacePlaceholders(item, replacements)]));
	}
	if (typeof value !== 'string') return value;
	let rendered = value;
	for (const [placeholder, replacement] of Object.entries(replacements)) {
		rendered = rendered.split(placeholder).join(replacement);
	}
	return rendered;
}

function assertUrl(value, label) {
	let url;
	try {
		url = new URL(value);
	} catch {
		throw new Error(`${label} must be a valid URL`);
	}
	if (!['http:', 'https:'].includes(url.protocol)) throw new Error(`${label} must use http or https`);
}

function validateRendered(workflow, serialized) {
	if (workflow.active !== false) throw new Error('Rendered workflow must remain inactive');
	const unresolved = [...new Set(serialized.match(/__[A-Z0-9_]+__/g) || [])];
	if (unresolved.length) throw new Error(`Unresolved workflow placeholders: ${unresolved.join(', ')}`);
	for (const [field, label] of [
		[workflow.nodes.find(node => node.name === 'Notify ntfy Office Topic')?.parameters?.url, 'ntfy URL'],
		[workflow.nodes.find(node => node.name === 'Notify SIP Extensions')?.parameters?.url, 'SIP notify URL'],
		[workflow.nodes.find(node => node.name === 'Get Notification Routing Policy')?.parameters?.url, 'SIP route URL'],
		[workflow.nodes.find(node => node.name === 'Append Event to Central Google Sheet')?.parameters?.url, 'Sheets webhook URL'],
	]) assertUrl(field, label);

	const routeOutputs = workflow.connections?.['Get Notification Routing Policy']?.main?.[0] || [];
	if (routeOutputs.some(edge => edge.node === 'Notify SIP Extensions')) throw new Error('SIP notify node bypasses its route gate');
	if (!routeOutputs.some(edge => edge.node === 'Gate SIP Notification')) throw new Error('SIP route gate is not connected');
	const gateOutputs = workflow.connections?.['Gate SIP Notification']?.main?.[0] || [];
	if (!gateOutputs.some(edge => edge.node === 'Notify SIP Extensions')) throw new Error('SIP route gate does not reach the notify node');
	const normalizerCode = workflow.nodes.find(node => node.name === 'Normalize Office Automation Event')?.parameters?.jsCode || '';
	if (!normalizerCode.includes('channels: body.channels') || !normalizerCode.includes('notify_channels: body.notify_channels')) {
		throw new Error('Normalized SIP payload does not preserve explicit channel fields');
	}
}

function renderWorkflow(environment = process.env) {
	const template = JSON.parse(fs.readFileSync(templatePath, 'utf8'));
	const replacements = {
		...requiredValues(environment),
		__NORMALIZE_OFFICE_EVENT_CODE__: fs.readFileSync(normalizerPath, 'utf8'),
	};
	const workflow = replacePlaceholders(template, replacements);
	const serialized = `${JSON.stringify(workflow, null, 2)}\n`;
	validateRendered(workflow, serialized);
	return { workflow, serialized };
}

function parseArguments(argv) {
	const args = new Set(argv);
	const outputIndex = argv.indexOf('--output');
	return {
		check: args.has('--check'),
		force: args.has('--force'),
		output: outputIndex >= 0 ? argv[outputIndex + 1] : '',
	};
}

function outputIsInsideRepository(output) {
	const relative = path.relative(root, output);
	return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
}

function main() {
	const options = parseArguments(process.argv.slice(2));
	const { serialized } = renderWorkflow();
	if (options.check) {
		process.stdout.write('{"ok":true}\n');
		return;
	}
	if (!options.output || !path.isAbsolute(options.output)) throw new Error('--output must be an absolute path outside the repository');
	if (outputIsInsideRepository(options.output)) throw new Error('Refusing to write rendered workflow inside the repository');
	fs.mkdirSync(path.dirname(options.output), { recursive: true, mode: 0o700 });
	fs.writeFileSync(options.output, serialized, { encoding: 'utf8', flag: options.force ? 'w' : 'wx', mode: 0o600 });
	fs.chmodSync(options.output, 0o600);
	process.stdout.write('{"ok":true,"written":true}\n');
}

module.exports = {
	environmentByPlaceholder,
	outputIsInsideRepository,
	renderWorkflow,
	replacePlaceholders,
	requiredValues,
	validateRendered,
};

if (require.main === module) {
	try {
		main();
	} catch (error) {
		process.stderr.write(`${error.message}\n`);
		process.exit(1);
	}
}
