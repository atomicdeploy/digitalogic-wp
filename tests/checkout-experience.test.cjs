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
    assert.match(script, /blockInvalidAttempt[\s\S]*event\.preventDefault\(\)[\s\S]*event\.stopImmediatePropagation\(\)/);
    assert.match(script, /button\.addEventListener\('click', blockInvalidAttempt, true\)/);
    assert.match(script, /form\.addEventListener\('submit', blockInvalidAttempt, true\)/);
    assert.match(script, /document\.addEventListener\('click'/);
    assert.match(script, /document\.addEventListener\('submit'/);
    assert.match(script, /updated_checkout/);
    assert.match(script, /MutationObserver/);
    assert.match(script, /feedbackSelector/);
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

test('delivery date and time requirements remain independent', () => {
    assert.match(script, /deliveryDateRequired/);
    assert.match(script, /deliveryTimeRequired/);
    assert.match(script, /deliveryRequirement\(control\) === false/);
    assert.match(script, /deliveryFields\.forEach/);
});

test('selected delivery dates keep Gregorian machine state and show a Persian Jalali overlay', () => {
    assert.match(script, /#jckwds-delivery-date-ymd/);
    assert.match(script, /gregorianToJalali/);
    assert.match(script, /formatJalaliYmd/);
    assert.match(script, /digitalogic-jalali-date-display/);
    assert.match(css, /\.digitalogic-has-jalali-display\s*\{[\s\S]*color:\s*transparent !important/);
    assert.match(css, /\.digitalogic-jalali-date-display\s*\{[\s\S]*pointer-events:\s*none/);
});

test('warning and quantity controls use branded accessible styling without orange', () => {
    assert.match(css, /\.digitalogic-checkout-notice\s*\{[\s\S]*border-inline-start:\s*4px solid var\(--digitalogic-checkout-brand\)/);
    assert.match(css, /\.woocommerce-error a:hover,[\s\S]*text-decoration:\s*none !important/);
    assert.match(css, /form\.checkout \.quantity \.minus,[\s\S]*form\.checkout \.quantity \.plus\s*\{[\s\S]*min-height:\s*42px/);
    assert.match(css, /form\.checkout \.quantity \.minus:hover,[\s\S]*background:\s*var\(--digitalogic-checkout-brand\) !important/);
    assert.doesNotMatch(css, /#f(?:f|9)[a-f0-9]*8[0-9a-f]*/i);
});
