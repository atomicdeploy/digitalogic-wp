'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.join(__dirname, '..');
const plugin = fs.readFileSync(path.join(root, 'digitalogic.php'), 'utf8');
const integration = fs.readFileSync(path.join(root, 'includes', 'integrations', 'class-digitalogic-storefront-design-system.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets', 'css', 'storefront-design-system.css'), 'utf8');
const ledger = fs.readFileSync(path.join(root, 'docs', 'STOREFRONT-MEDIA-GAP-LEDGER.md'), 'utf8');

test('plugin boots one late, cache-busted storefront design stylesheet', () => {
	assert.match(plugin, /class-digitalogic-storefront-design-system\.php/);
	assert.match(plugin, /Digitalogic_Storefront_Design_System::init\(\)/);
	assert.match(integration, /wp_enqueue_scripts[\s\S]*120/);
	assert.match(integration, /storefront-design-system\.css/);
	assert.match(integration, /filemtime/);
});

test('design system provides semantic colors, stable controls, focus, and reduced motion', () => {
	for (const token of ['--dgl-color-primary', '--dgl-color-success', '--dgl-color-warning', '--dgl-color-danger']) {
		assert.match(css, new RegExp(token));
	}
	assert.match(css, /font-family:\s*YekanBakh/);
	assert.match(css, /:focus-visible/);
	assert.match(css, /prefers-reduced-motion:\s*reduce/);
	assert.match(css, /transform:\s*none/);
	assert.match(css, /\.dgl-prime-showcase,[\s\S]*\.dgl-request-form/);
});

test('header stacking context keeps Woodmart search results above storefront content', () => {
	assert.match(css, /\.whb-header\s*\{[\s\S]*position:\s*relative;[\s\S]*z-index:\s*400;/);
});

test('mobile overlays never cover catalog, SKU, checkout, or purchase controls', () => {
	assert.match(css, /@media \(max-width:\s*767px\)[\s\S]*\.chaty-widget[\s\S]*display:\s*none\s*!important/);
	assert.match(css, /\.grecaptcha-badge[\s\S]*visibility:\s*hidden\s*!important/);
	assert.match(css, /\.single-product[\s\S]*padding-bottom:\s*calc\(122px/);
	assert.match(integration, /render_recaptcha_disclosure/);
	assert.match(integration, /This site is protected by reCAPTCHA/);
});

test('media ledger preserves product-code authority without proposing images', () => {
	assert.match(ledger, /Published parent products \| 986/);
	assert.match(ledger, /Missing featured image \| 962/);
	assert.match(ledger, /Missing image with authoritative code \| 909/);
	assert.match(ledger, /No image assignment was made/);
});
