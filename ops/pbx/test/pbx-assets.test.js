'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

test('Digitalogic keeps verification dormant while the public DID routes directly', () => {
	const dialplan = read('asterisk/digitalogic-pending-shortcut.conf');
	assert.equal(fs.existsSync(path.join(root, 'asterisk/digitalogic-digit-2.conf')), false);
	assert.doesNotMatch(dialplan, /^exten\s*=>\s*2,/m);
	assert.doesNotMatch(dialplan, /menu-option-2/);
	const mergeInstructions = dialplan.split('[pbx-call-verification-pending-digitalogic]')[0];
	assert.doesNotMatch(mergeInstructions, /Answer\(\)/);
	assert.doesNotMatch(mergeInstructions, /Gosub\([^\n]*(preflight|shortcut)/);
	assert.match(mergeInstructions, /Dial\(PJSIP\/\$\{OPERATOR_EXT\},30,Tt\)/);
	assert.match(mergeInstructions, /OPERATOR_EXT=101/);
	assert.match(dialplan, /^exten => preflight,1,AGI\([^\n]+,preflight\)$/m);
	assert.match(dialplan, /^exten => shortcut,1,Answer\(\)\s*\n same => n,StopMixMonitor\(\)/m);
	assert.doesNotMatch(dialplan, /^\s*same => n\([^)]+\)/m);
});

test('private code collection stays inside AGI and wrapper forwards its mode', () => {
	const dialplan = read('asterisk/digitalogic-pending-shortcut.conf');
	const wrapper = read('bin/pbx-verification-digitalogic');
	assert.match(dialplan, /StopMixMonitor\(\)[\s\S]*AGI\([^\n]+,shortcut\)/);
	assert.doesNotMatch(dialplan, /Read\([^\n]*(CODE|VERIFY)/i);
	assert.match(wrapper, /exec \/usr\/bin\/node "\$helper" "\$@"/);
});

test('every dedicated verification prompt uses the filtered and limited BGM mix', () => {
	const generator = read('scripts/generate-prompts.sh');
	const installer = read('scripts/install-prompts.sh');
	assert.match(generator, /mixed_prompts=\(pending-code enter-code verified invalid temporary-failure\)/);
	assert.match(generator, /-stream_loop -1 -i "\$bgm_file"/);
	assert.match(generator, /highpass=f=120,lowpass=f=7000/);
	assert.match(generator, /\[1:a\]volume=0\.92/);
	assert.match(generator, /amix=inputs=2:duration=shortest/);
	assert.match(generator, /alimiter=limit=0\.80/);
	assert.match(installer, /for name in pending-code enter-code verified invalid temporary-failure/);
	assert.match(read('prompts/fa-IR/pending-code.txt'), /دوست عزیزم/);
});
