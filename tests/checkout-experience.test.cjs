'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const script = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'checkout-experience.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'checkout-experience.css'), 'utf8');
const plugin = fs.readFileSync(path.join(__dirname, '..', 'digitalogic.php'), 'utf8');

test('plugin boots the checkout compatibility layer', () => {
    assert.match(plugin, /class-digitalogic-checkout-experience\.php/);
    assert.match(plugin, /Digitalogic_Checkout_Experience::init\(\);/);
});

test('client-side validation blocks both checkout activation paths', () => {
    assert.match(script, /document\.addEventListener\('click',[\s\S]*#place_order[\s\S]*event\.preventDefault\(\)[\s\S]*event\.stopImmediatePropagation\(\)/);
    assert.match(script, /document\.addEventListener\('submit',[\s\S]*event\.preventDefault\(\)[\s\S]*event\.stopImmediatePropagation\(\)/);
    assert.match(script, /updated_checkout/);
    assert.match(script, /MutationObserver/);
});

test('required controls and shipping methods are discovered from live checkout state', () => {
    assert.match(script, /\.validate-required input/);
    assert.match(script, /#jckwds-delivery-date/);
    assert.match(script, /#jckwds-delivery-time/);
    assert.match(script, /input\[name\^="shipping_method"\]/);
    assert.match(script, /input\[name="payment_method"\]/);
    assert.match(script, /#terms/);
    assert.match(script, /control\.required = true/);
    assert.match(script, /aria-required/);
    assert.match(script, /control\.checkValidity\(\)/);
    assert.match(script, /select2-hidden-accessible/);
});

test('warning and quantity controls use branded accessible styling without orange', () => {
    assert.match(css, /\.digitalogic-checkout-notice\s*\{[\s\S]*border-inline-start:\s*4px solid var\(--digitalogic-checkout-brand\)/);
    assert.match(css, /\.woocommerce-error a:hover,[\s\S]*text-decoration:\s*none !important/);
    assert.match(css, /\.quantity \.minus,[\s\S]*\.quantity \.plus\s*\{[\s\S]*min-height:\s*42px/);
    assert.match(css, /\.quantity \.minus:hover,[\s\S]*background:\s*var\(--digitalogic-checkout-brand\) !important/);
    assert.doesNotMatch(css, /#f(?:f|9)[a-f0-9]*8[0-9a-f]*/i);
});
