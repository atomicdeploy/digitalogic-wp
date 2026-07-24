const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'product-supplier-links-admin.js'),
    'utf8'
);

test('private supplier repeater is scoped to its admin metabox', () => {
    assert.match(source, /getElementById\('digitalogic-private-supplier-links'\)/);
    assert.match(source, /getElementById\('tmpl-digitalogic-supplier-link-row'\)/);
    assert.match(source, /replaceAll\('__INDEX__', String\(index\)\)/);
    assert.match(source, /closest\('\.digitalogic-supplier-link'\)/);
});

test('private supplier repeater performs no network or browser-storage writes', () => {
    assert.doesNotMatch(source, /\bfetch\s*\(/);
    assert.doesNotMatch(source, /\bXMLHttpRequest\b/);
    assert.doesNotMatch(source, /\blocalStorage\b|\bsessionStorage\b/);
    assert.doesNotMatch(source, /window\.location/);
});
