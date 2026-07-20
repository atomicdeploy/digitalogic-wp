const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const sourcePath = path.join(__dirname, '..', 'assets', 'integrations', 'google-apps-script', 'Code.gs');
const source = fs.readFileSync(sourcePath, 'utf8');
const sandbox = { module: { exports: {} }, exports: {} };
vm.runInNewContext(source, sandbox, { filename: sourcePath });

test('key-based merge updates matches, appends new rows, and removes stale rows', () => {
  const mergeRows = sandbox.module.exports.mergeRows_;
  const actual = mergeRows(
    [['A', 'old A'], ['B', 'old B']],
    [['B', 'new B'], ['C', 'new C']],
    0
  );

  assert.deepEqual(JSON.parse(JSON.stringify(actual)), [['B', 'new B'], ['C', 'new C']]);
});

test('key-based merge rejects missing and duplicate sync keys', () => {
  const mergeRows = sandbox.module.exports.mergeRows_;
  assert.throws(() => mergeRows([], [['', 'missing']], 0), /missing sync_key/);
  assert.throws(() => mergeRows([], [['A', 1], ['A', 2]], 0), /Duplicate catalog sync_key/);
});

test('API base accepts HTTPS roots and complete REST namespace URLs only', () => {
  const normalize = sandbox.module.exports.normalizeApiBase_;
  assert.equal(normalize('https://digitalogic.test/'), 'https://digitalogic.test/wp-json/digitalogic/v1');
  assert.equal(normalize('https://digitalogic.test/wp-json/digitalogic/v1'), 'https://digitalogic.test/wp-json/digitalogic/v1');
  assert.equal(normalize('http://digitalogic.test'), '');
});

test('catalog pages are validated by their living response structure', () => {
  const validate = sandbox.module.exports.validateCatalogPage_;
  const page = {
    dataset: 'products',
    columns: [{ key: 'sync_key' }],
    rows: [{ sync_key: '00123' }],
    pagination: { has_more: false },
  };

  assert.equal(validate(page, 'products'), page);
  assert.throws(
    () => validate({ dataset: 'products', columns: [], rows: [] }, 'products'),
    /Malformed products catalog response/
  );
  assert.throws(
    () => validate({ ...page, dataset: 'categories' }, 'products'),
    /Malformed products catalog response/
  );
  assert.throws(
    () => validate({ ...page, pagination: [] }, 'products'),
    /Malformed products catalog response/
  );
});

test('sparse rows render missing and explicit null as blank without changing real values', () => {
  const render = sandbox.module.exports.rowToSheetValues_;
  const keys = ['sync_key', 'missing', 'explicit_null', 'zero', 'disabled'];

  assert.deepEqual(
    JSON.parse(JSON.stringify(render({ sync_key: 'woo:42', explicit_null: null, zero: 0, disabled: false }, keys))),
    ['woo:42', '', '', 0, false]
  );
});

test('Apps Script keeps secrets in properties and manages distinct tabs', () => {
  assert.match(source, /getScriptProperties\(\)/);
  assert.doesNotMatch(source, /setValue\([^\n]*(?:CONSUMER_KEY|CONSUMER_SECRET)/);
  assert.match(source, /sheetName: 'Products'/);
  assert.match(source, /sheetName: 'Categories'/);
  assert.match(source, /setNumberFormat\('@'\)/);
  assert.match(source, /newTrigger\('syncCatalog'\)/);
  assert.match(source, /Authorization: 'Basic '/);
});
