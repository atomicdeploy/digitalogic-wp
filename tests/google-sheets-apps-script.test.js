const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const sourcePath = path.join(__dirname, '..', 'assets', 'integrations', 'google-apps-script', 'Code.gs');
const source = fs.readFileSync(sourcePath, 'utf8');
const professionalDashboardPath = path.join(
  __dirname,
  '..',
  'assets',
  'integrations',
  'google-apps-script',
  'ProfessionalDashboard.gs'
);
const professionalDashboardSource = fs.readFileSync(professionalDashboardPath, 'utf8');
const n8nPath = path.join(
  __dirname,
  '..',
  'assets',
  'integrations',
  'n8n',
  'digitalogic-google-sheets-writeback.json'
);
const n8nSource = fs.readFileSync(n8nPath, 'utf8');
const n8nWorkflow = JSON.parse(n8nSource);
const appsScriptManifest = JSON.parse(fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'integrations', 'google-apps-script', 'appsscript.json'),
  'utf8'
));
const sandbox = { module: { exports: {} }, exports: {} };
vm.runInNewContext(source, sandbox, { filename: sourcePath });

test('pricing refresh clears stale numeric validation before writing exact shipping text', () => {
  assert.match(
    source,
    /DIGITALOGIC_PRICING_SETTINGS_CELLS\.shippingPrice\)[\s\S]*?\.clearDataValidations\(\)[\s\S]*?\.setNumberFormat\('@'\)[\s\S]*?\.setValue\(String\(settings\.air_express_price_per_kg\)\)/
  );
});

test('catalog fetch retries bounded transient failures without retrying client errors', () => {
  assert.match(source, /function fetchCatalogResponseWithRetry_\(url, options\)/);
  assert.match(source, /attempt <= 3/);
  assert.match(source, /status !== 429 && status < 500/);
  assert.match(source, /Utilities\.sleep\(250 \* attempt\)/);
  assert.match(source, /UrlFetchApp\.fetchAll/);
  assert.match(source, /fetchCatalogResponseWithRetry_\(request\.url, request\.options\)/);
});

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

test('fast catalog revision accepts only the typed authoritative contract', () => {
  const revision = `sha256:${'7'.repeat(64)}`;
  let body = JSON.stringify({
    success: true,
    data: { schema: 'digitalogic.google-sheets-catalog-revision/v1', revision },
  });
  sandbox.Utilities = { base64Encode(value) { return value; } };
  sandbox.UrlFetchApp = {
    fetch(url, options) {
      assert.equal(url, 'https://digitalogic.test/google-sheets/catalog-revision');
      assert.equal(options.method, 'get');
      return {
        getResponseCode() { return 200; },
        getContentText() { return body; },
      };
    },
  };
  const fetchRevision = sandbox.module.exports.fetchCatalogRevision_;
  const config = { apiBase: 'https://digitalogic.test', consumerKey: 'key', consumerSecret: 'secret' };

  assert.equal(fetchRevision(config), revision);
  body = JSON.stringify({ success: true, data: { schema: 'wrong', revision } });
  assert.throws(() => fetchRevision(config), /catalog revision HTTP 200/);
  body = JSON.stringify({
    success: true,
    data: { schema: 'digitalogic.google-sheets-catalog-revision/v1', revision: 'stale' },
  });
  assert.throws(() => fetchRevision(config), /catalog revision HTTP 200/);
});

test('canonical pricing settings require the complete composite contract', () => {
  const validate = sandbox.module.exports.validatePricingSettingsState_;
  const state = {
    schema: 'digitalogic.pricing-sync-state/v1',
    state_revision: `sha256:${'a'.repeat(64)}`,
    settings: {
      dollar_price: '187891',
      yuan_price: '29500',
      effective_date: '2026-07-27',
      usd_effective_date: '2026-07-26',
      cny_effective_date: '2026-07-27',
      profit_margin_percent: '30',
      air_express_price_per_kg: '120',
      air_express_currency: 'CNY',
      shipping_catalog_revision: `sha256:${'c'.repeat(64)}`,
      price_rounding_digits: 2,
      price_rounding_mode: 'nearest_half_up',
    },
    freshness: {
      effective_date: '2026-07-27',
      age_days: 0,
      stale: false,
      stale_after: 7,
    },
  };

  assert.equal(validate(state), state);
  const zeroProfit = { ...state, settings: { ...state.settings, profit_margin_percent: 0 } };
  assert.equal(validate(zeroProfit), zeroProfit);
  const zeroRounding = { ...state, settings: { ...state.settings, price_rounding_digits: 0 } };
  assert.equal(validate(zeroRounding), zeroRounding);
  assert.throws(
    () => validate({ ...state, settings: { ...state.settings, yuan_price: '' } }),
    /Malformed Digitalogic pricing settings response/
  );
  assert.throws(
    () => validate({ ...state, state_revision: 'stale-local-copy' }),
    /Malformed Digitalogic pricing settings response/
  );
  assert.throws(
    () => validate({ ...state, settings: { ...state.settings, price_rounding_digits: 10 } }),
    /Malformed Digitalogic pricing settings response/
  );
  assert.throws(
    () => validate({ ...state, settings: { ...state.settings, price_rounding_mode: 'bankers' } }),
    /Malformed Digitalogic pricing settings response/
  );
});

test('reconciled catalog pages require one immutable dataset revision, schema, total, and unique union', () => {
  const validatePage = sandbox.module.exports.validateCatalogSnapshotPage_;
  const validateComplete = sandbox.module.exports.validateCompleteCatalogSnapshot_;
  const revision = `sha256:${'d'.repeat(64)}`;
  const first = {
    dataset_revision: revision,
    columns: [{ key: 'sync_key' }, { key: 'reconciliation_status' }],
    pagination: { page: 1, total: 2 },
  };
  const expected = validatePage(first, 'reconciled_products', 1, null);
  assert.equal(validatePage({
    ...first,
    pagination: { page: 2, total: 2 },
  }, 'reconciled_products', 2, expected), expected);
  assert.equal(validateComplete([
    { sync_key: 'woo:1' },
    { sync_key: 'patris:0002' },
  ], expected, 'reconciled_products'), true);

  assert.throws(
    () => validatePage({ ...first, dataset_revision: `sha256:${'e'.repeat(64)}`, pagination: { page: 2, total: 2 } }, 'reconciled_products', 2, expected),
    /changed while pages were being fetched/
  );
  assert.throws(
    () => validatePage({ ...first, columns: [{ key: 'sync_key' }], pagination: { page: 2, total: 2 } }, 'reconciled_products', 2, expected),
    /changed while pages were being fetched/
  );
  assert.throws(
    () => validateComplete([{ sync_key: 'woo:1' }, { sync_key: 'woo:1' }], expected, 'reconciled_products'),
    /missing or duplicate sync_key/
  );
});

test('fast source fingerprint is fail-closed for incomplete unversioned datasets', () => {
  sandbox.Utilities = {
    DigestAlgorithm: { SHA_256: 'SHA_256' },
    Charset: { UTF_8: 'UTF_8' },
    computeDigest() { return Array.from({ length: 32 }, (_, index) => index); },
  };
  const calculate = sandbox.module.exports.calculateCatalogSourceRevision_;
  const heads = [
    {
      dataset: { id: 'reconciled_products' },
      snapshot: { datasetRevision: `sha256:${'a'.repeat(64)}`, total: 1131, columns: '["sync_key"]' },
      response: { rows: [{ sync_key: 'a' }], page_revision: `sha256:${'b'.repeat(64)}`, pagination: { total: 1131, has_more: true } },
    },
    {
      dataset: { id: 'categories' },
      snapshot: { datasetRevision: '', total: 1, columns: '["sync_key"]' },
      response: { rows: [{ sync_key: 'category:1' }], page_revision: `sha256:${'c'.repeat(64)}`, pagination: { total: 1, has_more: false } },
    },
  ];

  assert.match(calculate(heads), /^sha256:[a-f0-9]{64}$/);
  heads[1].response.pagination = { total: 101, has_more: true };
  assert.equal(calculate(heads), '');
});

test('managed destination fingerprint rejects duplicate stable row identities', () => {
  sandbox.Utilities = {
    DigestAlgorithm: { SHA_256: 'SHA_256' },
    Charset: { UTF_8: 'UTF_8' },
    computeDigest() { return Array(32).fill(1); },
  };
  const sheet = {
    getLastRow() { return 4; },
    getLastColumn() { return 1; },
    getRange() {
      return { getValues() { return [['sync_key'], ['Sync Key'], ['same'], ['same']]; } };
    },
  };
  const spreadsheet = { getSheetByName() { return sheet; } };
  assert.throws(
    () => sandbox.module.exports.calculateManagedSheetRevision_(spreadsheet),
    /missing or duplicate sync_key/
  );
});

test('Settings edits build one optimistic full-state request without float price math', () => {
  const values = {
    B7: '29,500',
    B8: '120',
    B9: 'CNY',
    B10: '30',
    B15: '187,891',
    B16: '2026-07-26',
    B17: '2026-07-27',
    B18: `sha256:${'b'.repeat(64)}`,
    B19: `sha256:${'c'.repeat(64)}`,
    B21: '2',
    B22: 'nearest_half_up',
  };
  const sheet = {
    getRange(cell) {
      return {
        getDisplayValue() { return values[cell] || ''; },
      };
    },
  };

  assert.deepEqual(
    JSON.parse(JSON.stringify(sandbox.module.exports.buildPricingSettingsRequest_(sheet))),
    {
      expected_state_revision: `sha256:${'b'.repeat(64)}`,
      settings: {
        dollar_price: '187891',
        yuan_price: '29500',
        effective_date: '2026-07-27',
        usd_effective_date: '2026-07-26',
        cny_effective_date: '2026-07-27',
        profit_margin_percent: '30',
        air_express_price_per_kg: '120',
        air_express_currency: 'CNY',
        shipping_catalog_revision: `sha256:${'c'.repeat(64)}`,
        price_rounding_digits: 2,
        price_rounding_mode: 'nearest_half_up',
      },
    }
  );

  values.B21 = '10';
  assert.throws(
    () => sandbox.module.exports.buildPricingSettingsRequest_(sheet),
    /Price rounding digits must be a whole number from 0 through 9/
  );
  values.B21 = '2';
  values.B22 = 'bankers';
  assert.throws(
    () => sandbox.module.exports.buildPricingSettingsRequest_(sheet),
    /Price rounding mode must be nearest_half_up/
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
  assert.match(source, /headers\.Authorization = 'Basic '/);
  assert.match(source, /DIGITALOGIC_SPREADSHEET_ID/);
  assert.match(source, /SpreadsheetApp\.openById\(config\.spreadsheetId\)/);
  assert.ok(appsScriptManifest.oauthScopes.includes('https://www.googleapis.com/auth/spreadsheets'));
  assert.ok(!appsScriptManifest.oauthScopes.includes('https://www.googleapis.com/auth/spreadsheets.currentonly'));
});

test('Apps Script renders customer-facing Product Code labels without source branding', () => {
  assert.match(source, /key: 'patris_code', header: 'Product Code'/);
  assert.doesNotMatch(source, /header: 'Patris Code'/);
  assert.match(professionalDashboardSource, /MISSING PRODUCT CODE \| کد کالا ناقص/);
  assert.doesNotMatch(professionalDashboardSource, /Patris|پاتریس/);
});

test('Dashboard warnings use sync status and cannot be changed by shipping metadata', () => {
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('sync_status'\)/);
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('effective_price'\)/);
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('publication_status'\)/);
  assert.match(professionalDashboardSource, /MATCH\("' \+ key \+ '",Products!\$1:\$1,0\)/);
  assert.doesNotMatch(professionalDashboardSource, /Products!\$[A-Z]+\$3:\$[A-Z]+/);
});

test('localized catalog views refresh dynamic header-resolved bounds on full and unchanged syncs', () => {
  assert.equal(sandbox.module.exports.columnNumberToA1_(1), 'A');
  assert.equal(sandbox.module.exports.columnNumberToA1_(26), 'Z');
  assert.equal(sandbox.module.exports.columnNumberToA1_(27), 'AA');
  assert.throws(() => sandbox.module.exports.columnNumberToA1_(0), /positive integer/);
  assert.equal((source.match(/refreshLocalizedCatalogViews_\(spreadsheet\);/g) || []).length, 2);
  assert.match(source, /getDisplayValues\(\)\[0\]/);
  assert.match(source, /const lastRow = Math\.max\(products\.getLastRow\(\), 3\)/);
  assert.match(source, /woo_id: \['woocommerce_id', 'woo_id'\]/);
  assert.match(source, /storage_location: \['patris_location', 'storage_location'\]/);
  assert.match(source, /product_url: \['permalink', 'product_url'\]/);
  assert.match(source, /identity_source: \['reconciliation_status', 'identity_source'\]/);
  assert.match(source, /sync_warning: \['sync_error', 'sync_warning'\]/);
  assert.match(source, /const resolvedColumns = \{\}/);
  assert.match(source, /D6: '=COUNTIF\(' \+ dataRange\('price_status'\) \+ ',"priced"\)'/);
  assert.match(source, /\['E6', 'B12'\]/);
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('price_status'\)/);
  assert.match(professionalDashboardSource, /'=COUNTIF\(' \+ priceStatusColumn \+ ',"priced"\)'/);
  assert.doesNotMatch(source, /Products!\$A\$3:\$A\$1103/);
});

test('explicit spreadsheet destinations remain supported for scheduled standalone sync', () => {
  const expected = { id: 'sheet-123' };
  sandbox.SpreadsheetApp = {
    openById(id) {
      assert.equal(id, 'sheet-123');
      return expected;
    },
    getActiveSpreadsheet() {
      throw new Error('active spreadsheet fallback must not run');
    },
  };

  assert.equal(sandbox.module.exports.getSpreadsheet_({ spreadsheetId: 'sheet-123' }), expected);
});

test('standalone scheduled sync uses script state and leaves writeback workspace opt-in', () => {
  const standalone = { module: { exports: {} }, exports: {} };
  vm.runInNewContext(source, standalone, { filename: sourcePath });
  const state = {};
  const properties = {
    getProperty(key) { return state[key] ?? null; },
    setProperty(key, value) { state[key] = value; },
    setProperties(update) { Object.assign(state, update); },
  };
  let documentPropertyCalls = 0;
  let workspaceCalls = 0;
  let released = false;
  const upserts = [];
  const sourceRevision = `sha256:${'4'.repeat(64)}`;
  const projectionRevision = `sha256:${'5'.repeat(64)}`;
  const spreadsheet = {
    getSheetByName(name) {
      assert.equal(name, 'Dashboard');
      return null;
    },
    toast() {},
  };

  standalone.PropertiesService = {
    getDocumentProperties() {
      documentPropertyCalls += 1;
      throw new Error('DocumentProperties are unavailable to standalone scripts.');
    },
    getScriptProperties() { return properties; },
  };
  standalone.LockService = {
    getScriptLock() {
      return {
        waitLock(timeout) { assert.equal(timeout, 30000); },
        releaseLock() { released = true; },
      };
    },
  };
  standalone.getConfig_ = () => ({ spreadsheetId: 'sheet-123', locale: 'en' });
  standalone.getSpreadsheet_ = () => spreadsheet;
  standalone.fetchCatalogRevision_ = () => sourceRevision;
  standalone.fetchCatalogHeads_ = () => [
    { dataset: { id: 'reconciled_products' }, response: { page: 1 } },
    { dataset: { id: 'categories' }, response: { page: 1 } },
  ];
  standalone.calculateCatalogSourceRevision_ = () => sourceRevision;
  standalone.fetchDataset_ = (config, dataset) => ({
    id: dataset.id,
    columns: [{ key: 'sync_key' }],
    rows: [],
    pageRevisions: [],
  });
  standalone.fetchPricingSettings_ = () => ({
    schema: 'digitalogic.pricing-sync-state/v1',
    state_revision: `sha256:${'2'.repeat(64)}`,
    settings: {
      dollar_price: '187891',
      yuan_price: '29500',
      effective_date: '2026-07-27',
      usd_effective_date: '2026-07-26',
      cny_effective_date: '2026-07-27',
      profit_margin_percent: '30',
      air_express_price_per_kg: '120',
      air_express_currency: 'CNY',
      shipping_catalog_revision: `sha256:${'3'.repeat(64)}`,
    },
    freshness: { effective_date: '2026-07-27', age_days: 0, stale: false, stale_after: 7 },
  });
  let pricingUpserts = 0;
  standalone.upsertPricingSettings_ = () => { pricingUpserts += 1; };
  standalone.calculateRevision_ = () => `sha256:${'1'.repeat(64)}`;
  standalone.calculateManagedSheetRevision_ = () => projectionRevision;
  standalone.upsertDataset_ = (target, dataset) => upserts.push([target, dataset.id]);
  standalone.refreshLocalizedCatalogViews_ = () => false;
  standalone.ensureWritebackWorkspace_ = () => {
    workspaceCalls += 1;
    throw new Error('catalog sync must not create writeback tabs');
  };

  const result = standalone.module.exports.syncCatalog();

  assert.equal(result.status, 'updated');
  assert.equal(result.revision, `sha256:${'1'.repeat(64)}`);
  assert.equal(documentPropertyCalls, 0);
  assert.equal(workspaceCalls, 0);
  assert.equal(upserts.length, 2);
  assert.equal(pricingUpserts, 1);
  assert.equal(state.DIGITALOGIC_CATALOG_REVISION, result.revision);
  assert.equal(state.DIGITALOGIC_CATALOG_SOURCE_REVISION, sourceRevision);
  assert.equal(state.DIGITALOGIC_CATALOG_PROJECTION_REVISION, projectionRevision);
  assert.equal(state.DIGITALOGIC_PRICING_STATE_REVISION, `sha256:${'2'.repeat(64)}`);
  assert.equal(state.DIGITALOGIC_LAST_SYNC_STATUS, 'ok');
  assert.equal(released, true);
});

test('unchanged catalog readback clears an earlier sync error and records pricing revision', () => {
  const standalone = { module: { exports: {} }, exports: {} };
  vm.runInNewContext(source, standalone, { filename: sourcePath });
  const catalogRevision = `sha256:${'1'.repeat(64)}`;
  const pricingRevision = `sha256:${'2'.repeat(64)}`;
  const sourceRevision = `sha256:${'3'.repeat(64)}`;
  const projectionRevision = `sha256:${'4'.repeat(64)}`;
  const state = {
    DIGITALOGIC_CATALOG_REVISION: catalogRevision,
    DIGITALOGIC_CATALOG_SOURCE_REVISION: sourceRevision,
    DIGITALOGIC_CATALOG_PROJECTION_REVISION: projectionRevision,
    DIGITALOGIC_PRICING_STATE_REVISION: pricingRevision,
    DIGITALOGIC_LAST_SYNC_STATUS: 'error',
    DIGITALOGIC_LAST_SYNC_ERROR: 'earlier failure',
  };
  const properties = {
    getProperty(key) { return state[key] ?? null; },
    setProperties(update) { Object.assign(state, update); },
  };
  let released = false;
  standalone.PropertiesService = {
    getScriptProperties() { return properties; },
  };
  standalone.LockService = {
    getScriptLock() {
      return {
        waitLock(timeout) { assert.equal(timeout, 30000); },
        releaseLock() { released = true; },
      };
    },
  };
  standalone.getConfig_ = () => ({ spreadsheetId: 'sheet-123', locale: 'en' });
  standalone.getSpreadsheet_ = () => ({
    getSheetByName(name) {
      assert.equal(name, 'Dashboard');
      return null;
    },
    toast() {},
  });
  standalone.fetchCatalogRevision_ = () => sourceRevision;
  standalone.fetchCatalogHeads_ = () => [
    { dataset: { id: 'reconciled_products' }, response: { page: 1 } },
    { dataset: { id: 'categories' }, response: { page: 1 } },
  ];
  standalone.calculateCatalogSourceRevision_ = () => sourceRevision;
  standalone.calculateManagedSheetRevision_ = () => projectionRevision;
  standalone.fetchDataset_ = () => { throw new Error('unchanged sync must not fetch remaining pages'); };
  standalone.fetchPricingSettings_ = () => ({ state_revision: pricingRevision });
  standalone.upsertPricingSettings_ = () => {};
  standalone.upsertDataset_ = () => { throw new Error('unchanged sync must not rewrite managed tabs'); };
  standalone.refreshLocalizedCatalogViews_ = () => false;

  const result = standalone.module.exports.syncCatalog();

  assert.equal(result.status, 'unchanged');
  assert.equal(result.revision, catalogRevision);
  assert.equal(state.DIGITALOGIC_CATALOG_REVISION, catalogRevision);
  assert.equal(state.DIGITALOGIC_PRICING_STATE_REVISION, pricingRevision);
  assert.equal(state.DIGITALOGIC_LAST_SYNC_STATUS, 'ok');
  assert.equal(state.DIGITALOGIC_LAST_SYNC_ERROR, '');
  assert.match(state.DIGITALOGIC_LAST_SYNC_AT, /^\d{4}-\d{2}-\d{2}T/);
  assert.equal(released, true);
});

test('managed protections retain only the executing owner and disable domain edits', () => {
  const owner = { getEmail() { return 'owner@example.com'; } };
  const collaborator = { getEmail() { return 'editor@example.com'; } };
  const removed = [];
  let domainDisabled = false;
  let warningOnly = true;
  sandbox.Session = { getEffectiveUser() { return owner; } };
  const protection = {
    addEditor(editor) { assert.equal(editor, owner); },
    getEditors() { return [owner, collaborator]; },
    removeEditors(editors) { removed.push(...editors); },
    canDomainEdit() { return true; },
    setDomainEdit(value) { domainDisabled = value === false; },
    setWarningOnly(value) { warningOnly = value; },
  };

  assert.equal(sandbox.module.exports.restrictProtectionToOperator_(protection), protection);
  assert.deepEqual(removed, [collaborator]);
  assert.equal(domainDisabled, true);
  assert.equal(warningOnly, false);
});

test('writeback request defaults to preview and emits only bounded typed fields', () => {
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'a'.repeat(64)}`;
  const request = build([
    {
      _rowNumber: 7,
      selected: true,
      sync_key: 'woo:123',
      patris_code: '00123',
      expected_record_revision: revision,
      regular_price: '۱٬۲۵۰٫۵',
      sale_price: '<clear>',
      stock_quantity: 0,
      stock_status: 'INSTOCK',
      shipping_method_id: 'air',
      profit_percent: '<clear>',
      publication_status: 'publish',
      name: 'must not be written',
    },
    { selected: false, sync_key: 'ignored' },
  ], '', 'digitalogic:preview:test-001', 50);

  assert.deepEqual(JSON.parse(JSON.stringify(request)), {
    idempotency_key: 'digitalogic:preview:test-001',
    mode: 'preview',
    changes: [{
      sync_key: 'woo:123',
      patris_code: '00123',
      expected_record_revision: revision,
      fields: {
        shipping_method_id: 'air',
      },
    }],
  });
});

test('writeback request requires idempotency, durable Woo keys, revisions, and literal values', () => {
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'b'.repeat(64)}`;
  const valid = {
    selected: true,
    sync_key: 'woo:1',
    patris_code: 'P-1',
    expected_record_revision: revision,
    shipping_method_id: 'air',
  };

  assert.throws(() => build([valid], 'preview', '', 50), /idempotency key is required/);
  assert.throws(
    () => build([{ ...valid, sync_key: 'OTHER' }], 'preview', 'digitalogic:preview:test', 50),
    /deprecated exact-code compatibility key/
  );
  const legacy = build([{ ...valid, sync_key: 'P-1' }], 'preview', 'digitalogic:preview:legacy', 50);
  assert.equal(legacy.changes[0].sync_key, 'P-1');
  assert.throws(
    () => build([{ ...valid, expected_record_revision: 'stale' }], 'preview', 'digitalogic:preview:test', 50),
    /sha256 record revision is required/
  );
  assert.throws(
    () => build([{ ...valid, _hasFormula: true }], 'preview', 'digitalogic:preview:test', 50),
    /contains a formula/
  );
  assert.throws(
    () => build([{ ...valid, shipping_method_id: 'Air Freight' }], 'preview', 'digitalogic:preview:test', 50),
    /shipping_method_id is empty, too long, or contains control characters/
  );
  assert.throws(
    () => build([{ ...valid, shipping_method_id: '' }], 'preview', 'digitalogic:preview:test', 50),
    /No editable field was supplied/
  );
  assert.throws(
    () => build([{ ...valid }, { ...valid }], 'apply', 'digitalogic:apply:test', 50),
    /Duplicate selected sync_key/
  );
  assert.throws(
    () => build(Array.from({ length: 2 }, (_, index) => ({
      ...valid,
      sync_key: `woo:${index + 1}`,
      patris_code: `P-${index}`,
    })), 'preview', 'digitalogic:preview:test', 1),
    /bounded limit of 1 rows/
  );
});

test('writeback excludes feed-owned stock fields and keeps site-owned shipping', () => {
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'9'.repeat(64)}`;
  const base = {
    selected: true,
    sync_key: 'woo:99',
    patris_code: 'P-EXACT',
    expected_record_revision: revision,
  };
  const exact = build([{
    ...base,
    stock_quantity: '999999999',
    shipping_method_id: 'air_express',
  }], 'preview', 'digitalogic:preview:exact-stock', 50);

  assert.deepEqual(JSON.parse(JSON.stringify(exact.changes[0].fields)), { shipping_method_id: 'air_express' });
  assert.throws(
    () => build([{
      ...base,
      stock_quantity: '1000000001',
    }], 'preview', 'digitalogic:preview:stock-overflow', 50),
    /No editable field was supplied/
  );
});

test('writeback response validation preserves typed per-row audit data', () => {
  const validate = sandbox.module.exports.validateWritebackResponse_;
  const renderAudit = sandbox.module.exports.auditRowsFromResponse_;
  const revision = `sha256:${'c'.repeat(64)}`;
  const nextRevision = `sha256:${'d'.repeat(64)}`;
  const request = {
    mode: 'preview',
    idempotency_key: 'digitalogic:preview:test-002',
    changes: [{
      sync_key: 'P-1',
      patris_code: 'P-1',
      expected_record_revision: revision,
      fields: { sale_price: 90 },
    }],
  };
  const data = {
    schema: 'digitalogic.google-sheets-writeback',
    mode: 'preview',
    idempotency_key: request.idempotency_key,
    replayed: false,
    summary: { received: 1, ready: 1, unchanged: 0, applied: 0, conflicts: 0, invalid: 0, failed: 0 },
    results: [{
      index: 0,
      sync_key: 'P-1',
      patris_code: 'P-1',
      woocommerce_id: 42,
      status: 'ready',
      code: 'ready',
      message: 'Validated',
      changed_fields: ['sale_price'],
      before: { sale_price: 100 },
      after: { sale_price: 90 },
      record_revision: nextRevision,
      rollback: { available: true, fields: { sale_price: 100 } },
      audit_id: 87,
    }],
  };

  const response = validate(data, request, 200);
  const timestamp = new Date('2026-07-21T10:00:00.000Z');
  const rows = renderAudit(request, response, [7], timestamp);
  assert.equal(response.summary.ready, 1);
  assert.equal(rows[0][0], timestamp);
  assert.deepEqual(JSON.parse(JSON.stringify(rows[0].slice(1))), [
    'preview', request.idempotency_key, 7, 'P-1', 'ready', 'ready', 'Validated',
    revision, nextRevision, 'sale_price', 200, '{"sale_price":100}', '{"sale_price":90}',
    '{"available":true,"fields":{"sale_price":100}}', '87',
  ]);
  assert.throws(
    () => validate({ ...data, mode: 'apply' }, request, 200),
    /Malformed Digitalogic writeback response/
  );
  assert.throws(
    () => validate({ ...data, results: [{ ...data.results[0], sync_key: 'P-2' }] }, request, 200),
    /does not match request index 0/
  );
  assert.throws(
    () => validate({
      ...data,
      summary: { ...data.summary, ready: 0, invalid: 1 },
    }, request, 200),
    /summary does not match its row results/
  );
});

test('non-2xx writeback failures preserve safe status and may-have-applied recovery', () => {
  const createError = sandbox.module.exports.createWritebackHttpError_;
  const renderFailure = sandbox.module.exports.writebackFailureResponse_;
  const request = {
    mode: 'apply',
    idempotency_key: 'digitalogic:apply:uncertain',
    changes: [{
      sync_key: 'P-1',
      patris_code: 'P-1',
      expected_record_revision: `sha256:${'8'.repeat(64)}`,
      fields: { regular_price: '120' },
    }],
  };
  const error = createError(500, {
    code: 'idempotency_result_store_failed',
    message: 'arbitrary upstream text must not be retained',
    details: { retryable: true, may_have_applied: true, internal: 'discard me' },
  }, 'fallback');
  const response = renderFailure(request, error.message, error.digitalogicWritebackFailure);

  assert.equal(response.results[0].code, 'idempotency_result_store_failed');
  assert.equal(response.results[0].http_status, 500);
  assert.deepEqual(JSON.parse(JSON.stringify(response.results[0].rollback)), {
    available: false,
    retryable: true,
    may_have_applied: true,
    upstream_code: 'idempotency_result_store_failed',
    http_status: 500,
  });
  assert.doesNotMatch(JSON.stringify(response), /arbitrary upstream|discard me/);
});

test('catalog and audit text neutralize formulas while exact identifiers round-trip', () => {
  const render = sandbox.module.exports.rowToSheetValues_;
  const neutralize = sandbox.module.exports.neutralizeSheetText_;
  const restore = sandbox.module.exports.restoreNeutralizedSheetText_;
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'f'.repeat(64)}`;
  const dangerous = ['=IMPORTDATA("https://evil.invalid")', '+SUM(1,1)', '-1+1', '@cmd', "'=literal"];
  const rendered = render(
    { sync_key: dangerous[0], plus: dangerous[1], minus: dangerous[2], at: dangerous[3], quote: dangerous[4] },
    ['sync_key', 'plus', 'minus', 'at', 'quote']
  );

  assert.deepEqual(JSON.parse(JSON.stringify(rendered)), dangerous.map((value) => `'${value}`));
  assert.deepEqual(JSON.parse(JSON.stringify(rendered.map(restore))), dangerous);
  dangerous.forEach((value) => assert.equal(restore(neutralize(value)), value));

  const request = build([{
    selected: true,
    sync_key: 'woo:42',
    patris_code: "'+CODE",
    expected_record_revision: revision,
    shipping_method_id: 'air',
  }], 'preview', 'digitalogic:preview:formula-safe', 50);
  assert.equal(request.changes[0].sync_key, 'woo:42');
  assert.equal(request.changes[0].patris_code, '+CODE');

  const audit = sandbox.module.exports.auditRowsFromResponse_(
    request,
    {
      mode: 'preview',
      idempotency_key: request.idempotency_key,
      results: [{
        sync_key: '=IMPORTDATA("https://evil.invalid")',
        status: 'failed',
        code: '@danger',
        message: '+danger',
        record_revision: revision,
        changed_fields: ['regular_price'],
        http_status: 500,
        before: { value: '-danger' },
        after: {},
        rollback: { success: false },
        audit_id: '=42',
      }],
    },
    [7],
    new Date('2026-07-21T10:00:00.000Z')
  );
  assert.equal(audit[0][4], "'=IMPORTDATA(\"https://evil.invalid\")");
  assert.equal(audit[0][6], "'@danger");
  assert.equal(audit[0][7], "'+danger");
  assert.equal(audit[0][15], "'=42");
});

test('Apps Script exposes only an explicit preview-then-apply workflow on separate tabs', () => {
  assert.match(source, /sheetName: 'Changes'/);
  assert.match(source, /sheetName: 'Audit'/);
  assert.match(source, /sheetName: 'Dashboard'/);
  assert.match(source, /Preview selected changes/);
  assert.match(source, /Apply last preview/);
  assert.match(source, /DIGITALOGIC_WRITEBACK_PATH \+ request\.mode/);
  assert.match(source, /'Idempotency-Key': request\.idempotency_key/);
  assert.match(source, /DIGITALOGIC_LAST_PREVIEW_SIGNATURE/);
  assert.match(source, /X-Digitalogic-Bridge-Token/);
  assert.match(source, /X-Digitalogic-Confirm-Apply/);
  assert.doesNotMatch(source, /function onEdit\s*\(/);
  assert.doesNotMatch(source, /DIGITALOGIC_EDITABLE_FIELDS[\s\S]*?publication_status:/);
});

test('support tabs detect machine row 5, display row 6, and data row 7 without losing legacy support', () => {
  const detect = sandbox.module.exports.detectStructuredLayout_;
  const keys = [
    'selected', 'sync_key', 'patris_code', 'expected_record_revision', 'shipping_method_id',
  ];
  const professional = detect({ 5: keys, 1: ['workbook title'] }, keys);
  const legacy = detect({ 5: [], 1: keys }, keys);

  assert.deepEqual(JSON.parse(JSON.stringify(professional)), {
    id: 'professional', machineHeaderRow: 5, displayHeaderRow: 6, dataStartRow: 7,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(legacy)), {
    id: 'legacy', machineHeaderRow: 1, displayHeaderRow: 2, dataStartRow: 3,
  });
  assert.equal(detect({ 5: ['wrong'], 1: ['wrong'] }, keys), null);
});

test('retired Changes schema migrates only when every staged row is empty', () => {
  const canMigrate = sandbox.module.exports.canMigrateEmptyLegacyChanges_;
  const retiredHeader = [
    'selected',
    'sync_key',
    'patris_code',
    'expected_record_revision',
    'regular_price',
    'sale_price',
    'stock_quantity',
    'stock_status',
    'shipping_method_id',
    'profit_percent',
  ];

  assert.equal(canMigrate(retiredHeader, [[false, '', '', '', '', '', '', '', '', '']]), true);
  assert.equal(canMigrate(retiredHeader, [[true, '', '', '', '', '', '', '', '', '']]), false);
  assert.equal(canMigrate(retiredHeader, [[false, 'woo:11079', '', '', '', '', '', '', '', '']]), false);
  assert.equal(canMigrate([...retiredHeader.slice(0, 9), 'unexpected'], []), false);
  assert.match(source, /migrateEmptyLegacyChanges_\(sheet, defaultLayout\)/);
});

test('Changes styling does not target fields removed from the editable contract', () => {
  const start = source.indexOf('function styleChangesSheet_');
  const end = source.indexOf('function styleAuditSheet_', start);
  const styleChangesSource = source.slice(start, end);
  const ensureStart = source.indexOf('function ensureStructuredSheet_');
  const ensureEnd = source.indexOf('function styleChangesSheet_', ensureStart);
  const ensureStructuredSource = source.slice(ensureStart, ensureEnd);

  assert.ok(start >= 0 && end > start);
  assert.doesNotMatch(styleChangesSource, /stock_status|requireValueInList/);
  assert.match(styleChangesSource, /site-owned shipping method field/);
  assert.doesNotMatch(ensureStructuredSource, /setFrozenColumns\(1\)/);
});

test('professional support tabs have reserved titles, workflow guidance, and legacy-safe styling', () => {
  assert.match(source, /CHANGES \| SAFE PRODUCT UPDATE QUEUE/);
  assert.match(source, /AUDIT \| WRITEBACK ACTIVITY & RECOVERY/);
  assert.match(source, /CONTROLLED WRITEBACK/);
  assert.match(source, /layout\.id !== 'professional'/);
  assert.match(source, /sheet\.setFrozenColumns\(0\)/);
  assert.match(source, /getRange\(1, 1, 4, sheet\.getMaxColumns\(\)\)\.breakApart\(\)/);
  assert.doesNotMatch(source, /getRange\(1, 1, 4, columnCount\)\.breakApart\(\)/);
  assert.match(source, /protection\.setRange\(sheet\.getRange\(1, 1, machineHeaderRow, sheet\.getLastColumn\(\)\)\)/);
});

test('support-tab reader begins at the detected data row and preserves real sheet row numbers', () => {
  const readRows = sandbox.module.exports.readExistingStructuredRows_;
  const calls = [];
  const sheet = {
    getLastRow() { return 8; },
    getRange(row, column, rowCount, columnCount) {
      calls.push([row, column, rowCount, columnCount]);
      return {
        getValues() { return [['first', 10], ['second', 20]]; },
        getFormulas() { return [['', ''], ['', '=A1']]; },
      };
    },
  };
  const entries = readRows(
    sheet,
    [{ key: 'sync_key' }, { key: 'regular_price' }],
    { dataStartRow: 7 }
  );

  assert.deepEqual(calls, [[7, 1, 2, 2], [7, 1, 2, 2]]);
  assert.equal(entries[0].rowNumber, 7);
  assert.equal(entries[0].object._rowNumber, 7);
  assert.equal(entries[1].rowNumber, 8);
  assert.equal(entries[1].object._hasFormula, true);
});

test('staging finds the first blank table row even when a side help panel extends getLastRow', () => {
  const findBlank = sandbox.module.exports.findFirstBlankStructuredRow_;
  const calls = [];
  const sheet = {
    getLastRow() { return 13; }, // L5:R12 help panel content must be irrelevant.
    getMaxRows() { return 12; },
    getRange(row, column, rowCount, columnCount) {
      calls.push([row, column, rowCount, columnCount]);
      return {
        getDisplayValues() {
          return [
            ['P-1'],
            ['P-2'],
            [''],
            [''],
            [''],
            [''],
          ];
        },
      };
    },
  };

  assert.equal(findBlank(sheet, { dataStartRow: 7 }, 2), 9);
  assert.deepEqual(calls, [[7, 2, 6, 1]]);
});

test('Dashboard integration preserves design and writes only its reserved status cells', () => {
  const ensureDashboardSheet = sandbox.module.exports.ensureDashboardSheet_;
  const updateDashboard = sandbox.module.exports.updateDashboard_;
  const dashboard = { name: 'existing designed dashboard' };
  const spreadsheet = {
    getSheetByName(name) {
      assert.equal(name, 'Dashboard');
      return dashboard;
    },
    insertSheet() {
      throw new Error('Existing Dashboard must not be replaced.');
    },
  };
  const ensureDashboardSource = source.match(
    /function ensureDashboardSheet_\(spreadsheet\) \{[\s\S]*?\n\}/
  );
  const writes = [];
  const designedDashboard = {
    getRange(a1) {
      if (a1 === 'A1') {
        return { getDisplayValue() { return 'DIGITALOGIC | PRODUCT & PRICING CONTROL CENTER'; } };
      }
      assert.equal(a1, 'J13:K15');
      return { setValues(values) { writes.push(values); } };
    },
  };
  const state = {
    DIGITALOGIC_LAST_SYNC_STATUS: 'ok',
    DIGITALOGIC_LAST_SYNC_AT: '2026-07-21T12:00:00Z',
    DIGITALOGIC_LAST_WRITEBACK_STATUS: 'preview:ok',
    DIGITALOGIC_LAST_WRITEBACK_IDEMPOTENCY_KEY: 'digitalogic:preview:test',
    DIGITALOGIC_LAST_WRITEBACK_SUMMARY: 'received=1, ready=1',
    DIGITALOGIC_LAST_WRITEBACK_TRANSPORT: 'n8n',
    DIGITALOGIC_LAST_WRITEBACK_MESSAGE: '',
  };
  const properties = { getProperty(key) { return state[key] || null; } };

  assert.equal(ensureDashboardSheet(spreadsheet), dashboard);
  assert.ok(ensureDashboardSource);
  assert.doesNotMatch(ensureDashboardSource[0], /\.clear\(|getRange\(|setValue\(|setValues\(|setFormulas\(/);
  assert.equal(updateDashboard(designedDashboard, properties), true);
  assert.deepEqual(JSON.parse(JSON.stringify(writes)), [[
    ['ok', '2026-07-21T12:00:00Z'],
    ['preview:ok', 'digitalogic:preview:test | received=1, ready=1'],
    ['n8n', ''],
  ]]);

  const incompatible = {
    getRange(a1) {
      assert.equal(a1, 'A1');
      return { getDisplayValue() { return 'Different dashboard'; } };
    },
  };
  assert.equal(updateDashboard(incompatible, properties), false);
});

test('optional n8n base accepts only query-free HTTPS URLs', () => {
  const normalize = sandbox.module.exports.normalizeWebhookBase_;
  assert.equal(normalize(''), '');
  assert.equal(
    normalize('https://automation.example/webhook/digitalogic-google-sheets/'),
    'https://automation.example/webhook/digitalogic-google-sheets'
  );
  assert.throws(() => normalize('http://automation.example/hook'), /query-free HTTPS URL/);
  assert.throws(() => normalize('https://automation.example/hook?token=secret'), /query-free HTTPS URL/);
});

test('idempotency keys survive uncertain retries and rotate after a completed attempt', () => {
  const state = {};
  let uuid = 0;
  sandbox.PropertiesService = {
    getDocumentProperties() {
      return {
        getProperty(key) { return state[key] ?? null; },
        setProperties(update) { Object.assign(state, update); },
      };
    },
  };
  sandbox.Utilities = {
    getUuid() {
      uuid += 1;
      return `${String(uuid).padStart(8, '0')}-aaaa-bbbb-cccc-dddddddddddd`;
    },
  };
  const getKey = sandbox.module.exports.getOrCreateIdempotencyKey_;
  const signature = `sha256:${'e'.repeat(64)}`;
  const first = getKey('apply', signature);
  const retry = getKey('apply', signature);
  assert.equal(retry, first);

  state.DIGITALOGIC_APPLY_REQUEST_COMPLETED = 'true';
  const nextAttempt = getKey('apply', signature);
  assert.notEqual(nextAttempt, first);
  assert.match(nextAttempt, /^digitalogic:apply:[a-f0-9]{16}:[a-f0-9]{24}$/);
});

test('professional control center is credential-free, editable, and idempotently initialized', () => {
  assert.match(professionalDashboardSource, /function initializeDigitalogicControlCenter\(\)/);
  assert.match(professionalDashboardSource, /syncCatalog\(\);/);
  assert.match(professionalDashboardSource, /setupEditableWorkspace\(\);/);
  assert.match(professionalDashboardSource, /installScheduledSync\(\);/);
  assert.match(professionalDashboardSource, /'Pricing Calculator'/);
  assert.match(professionalDashboardSource, /'Settings'/);
  assert.match(professionalDashboardSource, /'Help'/);
  assert.match(professionalDashboardSource, /newChart\(\)[\s\S]*?\.asPieChart\(\)/);
  assert.match(professionalDashboardSource, /newChart\(\)[\s\S]*?\.asColumnChart\(\)/);
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('sync_key'\)/);
  assert.match(professionalDashboardSource, /digitalogicProductsColumn_\('sync_status'\)/);
  assert.match(professionalDashboardSource, /placeholder\.getRange\('A1'\)\.isBlank\(\)/);
  assert.match(professionalDashboardSource, /Preview then explicit Apply/);
  assert.match(professionalDashboardSource, /getRange\('A24:H28'\)\.merge\(\)/);
  assert.doesNotMatch(professionalDashboardSource, /getRange\('A22:H26'\)\.merge\(\)/);
  assert.match(professionalDashboardSource, /ROUND\([\s\S]*?,-Settings!\$B\$21\)/);
  assert.match(professionalDashboardSource, /Settings!\$B\$22<>"nearest_half_up"/);
  assert.match(source, /price_rounding_digits: Number\(roundingDigits\)/);
  assert.match(source, /price_rounding_mode: roundingMode/);
  assert.doesNotMatch(professionalDashboardSource, /\b25300\b|\b0\.30\b/);
  assert.match(source, /\/google-sheets\/pricing-settings/);
  assert.match(source, /function applyPricingSettings\(\)/);
  assert.match(professionalDashboardSource, /محاسبه قیمت نهایی/);
  assert.doesNotMatch(
    professionalDashboardSource,
    /\bck_[A-Za-z0-9]+\b|\bcs_[A-Za-z0-9]+\b|DIGITALOGIC_N8N_WRITEBACK_TOKEN\s*[:=]\s*['"][^'"]+/
  );

  assert.match(
    source,
    /if \(config\.n8nWritebackBase[\s\S]*?!stateProperties\.getProperty\('DIGITALOGIC_LAST_WRITEBACK_TRANSPORT'\)/
  );
  assert.match(source, /DIGITALOGIC_LAST_WRITEBACK_STATUS: 'ready'/);
  assert.match(source, /DIGITALOGIC_LAST_WRITEBACK_TRANSPORT: 'n8n'/);
});

test('n8n template is inactive, importable, credential-only, and keeps apply explicit', () => {
  assert.equal(n8nWorkflow.active, false);
  assert.equal(n8nWorkflow.name, 'Digitalogic Google Sheets - Safe Writeback');
  assert.equal(n8nWorkflow.settings.saveDataErrorExecution, 'none');
  assert.equal(n8nWorkflow.settings.saveManualExecutions, false);
  assert.equal(n8nWorkflow.nodes.length, 11);
  const byName = Object.fromEntries(n8nWorkflow.nodes.map((node) => [node.name, node]));
  assert.equal(byName['Preview Webhook'].parameters.path, 'digitalogic-google-sheets/preview');
  assert.equal(byName['Apply Webhook'].parameters.path, 'digitalogic-google-sheets/apply');
  assert.equal(byName['Preview Webhook'].parameters.responseMode, 'responseNode');
  assert.equal(byName['Preview Webhook'].parameters.responseData, undefined);
  assert.equal(byName['Apply Webhook'].parameters.responseMode, 'responseNode');
  assert.equal(byName['Apply Webhook'].parameters.responseData, undefined);
  assert.match(byName['Validate Explicit Apply'].parameters.jsCode, /X-Digitalogic-Confirm-Apply/);
  assert.match(byName['Validate Preview Envelope'].parameters.jsCode, /validation_ok: false/);
  assert.match(byName['Validate Explicit Apply'].parameters.jsCode, /validation_ok: false/);
  assert.doesNotMatch(byName['Validate Preview Envelope'].parameters.jsCode, /throw new Error/);
  assert.doesNotMatch(byName['Validate Explicit Apply'].parameters.jsCode, /throw new Error/);
  assert.equal(byName['Preview Envelope Valid?'].type, 'n8n-nodes-base.if');
  assert.equal(byName['Apply Envelope Valid?'].type, 'n8n-nodes-base.if');
  assert.match(byName['Digitalogic Preview'].parameters.url, /writeback\/preview$/);
  assert.match(byName['Digitalogic Apply'].parameters.url, /writeback\/apply$/);
  assert.deepEqual(
    byName['Digitalogic Preview'].parameters.headerParameters.parameters.find(
      (header) => header.name === 'Accept-Encoding',
    ),
    { name: 'Accept-Encoding', value: 'identity' },
  );
  assert.deepEqual(
    byName['Digitalogic Apply'].parameters.headerParameters.parameters.find(
      (header) => header.name === 'Accept-Encoding',
    ),
    { name: 'Accept-Encoding', value: 'identity' },
  );
  assert.equal(byName['Digitalogic Preview'].parameters.contentType, 'json');
  assert.equal(byName['Digitalogic Preview'].parameters.specifyBody, 'json');
  assert.equal(byName['Digitalogic Preview'].parameters.jsonBody, '={{ $json.request }}');
  assert.equal(byName['Digitalogic Preview'].parameters.rawContentType, undefined);
  assert.equal(byName['Digitalogic Preview'].parameters.body, undefined);
  assert.equal(byName['Digitalogic Apply'].parameters.contentType, 'json');
  assert.equal(byName['Digitalogic Apply'].parameters.specifyBody, 'json');
  assert.equal(byName['Digitalogic Apply'].parameters.jsonBody, '={{ $json.request }}');
  assert.equal(byName['Digitalogic Apply'].parameters.rawContentType, undefined);
  assert.equal(byName['Digitalogic Apply'].parameters.body, undefined);
  assert.equal(byName['Digitalogic Preview'].parameters.options.response.response.fullResponse, true);
  assert.equal(byName['Digitalogic Preview'].parameters.options.response.response.neverError, true);
  assert.equal(byName['Return Preview'].type, 'n8n-nodes-base.respondToWebhook');
  assert.equal(byName['Return Preview'].parameters.respondWith, 'json');
  assert.match(byName['Return Preview'].parameters.responseBody, /\$json\.body/);
  assert.match(byName['Return Preview'].parameters.options.responseCode, /statusCode/);
  assert.equal(byName['Digitalogic Apply'].parameters.options.response.response.fullResponse, true);
  assert.equal(byName['Return Apply'].type, 'n8n-nodes-base.respondToWebhook');
  assert.equal(byName['Return Apply'].parameters.respondWith, 'json');
  assert.match(byName['Return Apply'].parameters.responseBody, /\$json\.body/);
  assert.match(byName['Return Apply'].parameters.options.responseCode, /statusCode/);
  assert.equal(
    n8nWorkflow.connections['Apply Webhook'].main[0][0].node,
    'Validate Explicit Apply'
  );
  assert.equal(
    n8nWorkflow.connections['Preview Envelope Valid?'].main[0][0].node,
    'Digitalogic Preview'
  );
  assert.equal(
    n8nWorkflow.connections['Preview Envelope Valid?'].main[1][0].node,
    'Return Preview'
  );
  assert.equal(
    n8nWorkflow.connections['Apply Envelope Valid?'].main[0][0].node,
    'Digitalogic Apply'
  );
  assert.equal(
    n8nWorkflow.connections['Apply Envelope Valid?'].main[1][0].node,
    'Return Apply'
  );
  assert.equal(byName['Scheduled Refresh'], undefined);
  assert.equal(byName['Manual Refresh'], undefined);
  assert.equal(byName['Run Apps Script Catalog Refresh'], undefined);
  assert.doesNotMatch(n8nSource, /\bck_[A-Za-z0-9]+\b|\bcs_[A-Za-z0-9]+\b/);
  assert.match(n8nSource, /REPLACE_WITH_HEADER_AUTH_CREDENTIAL_ID/);
  assert.match(n8nSource, /REPLACE_WITH_WOOCOMMERCE_WRITE_CREDENTIAL_ID/);
  assert.doesNotMatch(n8nSource, /REPLACE_WITH_(?:GOOGLE_APPS_SCRIPT|APPS_SCRIPT_PROJECT)/);
});
