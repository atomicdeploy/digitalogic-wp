const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const sourcePath = path.join(__dirname, '..', 'assets', 'integrations', 'google-apps-script', 'Code.gs');
const source = fs.readFileSync(sourcePath, 'utf8');
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
  assert.match(source, /headers\.Authorization = 'Basic '/);
  assert.doesNotMatch(source, /openById|DIGITALOGIC_SPREADSHEET_ID/);
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
      sync_key: '00123',
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
      sync_key: '00123',
      patris_code: '00123',
      expected_record_revision: revision,
      fields: {
        regular_price: '1250.5',
        sale_price: null,
        stock_quantity: 0,
        stock_status: 'instock',
        shipping_method_id: 'air',
        profit_percent: null,
      },
    }],
  });
});

test('writeback request requires idempotency, equal Patris keys, revisions, and literal values', () => {
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'b'.repeat(64)}`;
  const valid = {
    selected: true,
    sync_key: 'P-1',
    patris_code: 'P-1',
    expected_record_revision: revision,
    regular_price: 100,
  };

  assert.throws(() => build([valid], 'preview', '', 50), /idempotency key is required/);
  assert.throws(
    () => build([{ ...valid, patris_code: 'P-2' }], 'preview', 'digitalogic:preview:test', 50),
    /must be exactly equal/
  );
  assert.throws(
    () => build([{ ...valid, expected_record_revision: 'stale' }], 'preview', 'digitalogic:preview:test', 50),
    /sha256 record revision is required/
  );
  assert.throws(
    () => build([{ ...valid, _hasFormula: true }], 'preview', 'digitalogic:preview:test', 50),
    /contains a formula/
  );
  assert.throws(
    () => build([{ ...valid, regular_price: '<clear>' }], 'preview', 'digitalogic:preview:test', 50),
    /regular_price cannot be cleared/
  );
  assert.throws(
    () => build([{ ...valid, regular_price: 0 }], 'preview', 'digitalogic:preview:test', 50),
    /regular_price is outside its allowed numeric range/
  );
  assert.throws(
    () => build([{ ...valid, regular_price: 1.1234567 }], 'preview', 'digitalogic:preview:test', 50),
    /regular_price is outside its allowed numeric range/
  );
  assert.throws(
    () => build([{ ...valid, regular_price: '', shipping_method_id: 'Air Freight' }], 'preview', 'digitalogic:preview:test', 50),
    /shipping_method_id is empty, too long, or contains control characters/
  );
  assert.throws(
    () => build([{ ...valid }, { ...valid }], 'apply', 'digitalogic:apply:test', 50),
    /Duplicate selected sync_key/
  );
  assert.throws(
    () => build(Array.from({ length: 2 }, (_, index) => ({
      ...valid,
      sync_key: `P-${index}`,
      patris_code: `P-${index}`,
    })), 'preview', 'digitalogic:preview:test', 1),
    /bounded limit of 1 rows/
  );
});

test('writeback decimals remain exact canonical text near the 15-digit ceiling', () => {
  const build = sandbox.module.exports.buildWritebackRequest_;
  const revision = `sha256:${'9'.repeat(64)}`;
  const base = {
    selected: true,
    sync_key: 'P-EXACT',
    patris_code: 'P-EXACT',
    expected_record_revision: revision,
  };
  const exact = build([{
    ...base,
    regular_price: '999999999999998.000001',
    sale_price: '999999999999998.000000',
  }], 'preview', 'digitalogic:preview:exact-decimal', 50);

  assert.equal(exact.changes[0].fields.regular_price, '999999999999998.000001');
  assert.equal(exact.changes[0].fields.sale_price, '999999999999998');
  assert.throws(
    () => build([{
      ...base,
      regular_price: '999999999999999.000001',
    }], 'preview', 'digitalogic:preview:decimal-overflow', 50),
    /outside its allowed numeric range/
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
    sync_key: "'+CODE",
    patris_code: "'+CODE",
    expected_record_revision: revision,
    regular_price: 10,
  }], 'preview', 'digitalogic:preview:formula-safe', 50);
  assert.equal(request.changes[0].sync_key, '+CODE');
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
    'selected', 'sync_key', 'patris_code', 'expected_record_revision', 'regular_price',
    'sale_price', 'stock_quantity', 'stock_status', 'shipping_method_id', 'profit_percent',
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

test('n8n template is inactive, importable, credential-only, and keeps apply explicit', () => {
  assert.equal(n8nWorkflow.active, false);
  assert.equal(n8nWorkflow.name, 'Digitalogic Google Sheets - Safe Writeback');
  assert.equal(n8nWorkflow.settings.saveDataErrorExecution, 'none');
  assert.equal(n8nWorkflow.settings.saveManualExecutions, false);
  assert.equal(n8nWorkflow.nodes.length, 9);
  const byName = Object.fromEntries(n8nWorkflow.nodes.map((node) => [node.name, node]));
  assert.equal(byName['Preview Webhook'].parameters.path, 'digitalogic-google-sheets/preview');
  assert.equal(byName['Apply Webhook'].parameters.path, 'digitalogic-google-sheets/apply');
  assert.match(byName['Validate Explicit Apply'].parameters.jsCode, /X-Digitalogic-Confirm-Apply/);
  assert.match(byName['Digitalogic Preview'].parameters.url, /writeback\/preview$/);
  assert.match(byName['Digitalogic Apply'].parameters.url, /writeback\/apply$/);
  assert.equal(byName['Digitalogic Preview'].parameters.options.response.response.fullResponse, true);
  assert.equal(byName['Digitalogic Preview'].parameters.options.response.response.neverError, true);
  assert.equal(byName['Return Preview'].parameters.respondWith, 'json');
  assert.match(byName['Return Preview'].parameters.responseBody, /\$json\.body/);
  assert.match(byName['Return Preview'].parameters.options.responseCode, /statusCode/);
  assert.equal(byName['Digitalogic Apply'].parameters.options.response.response.fullResponse, true);
  assert.equal(byName['Return Apply'].parameters.respondWith, 'json');
  assert.match(byName['Return Apply'].parameters.responseBody, /\$json\.body/);
  assert.match(byName['Return Apply'].parameters.options.responseCode, /statusCode/);
  assert.equal(
    n8nWorkflow.connections['Apply Webhook'].main[0][0].node,
    'Validate Explicit Apply'
  );
  assert.equal(byName['Scheduled Refresh'], undefined);
  assert.equal(byName['Manual Refresh'], undefined);
  assert.equal(byName['Run Apps Script Catalog Refresh'], undefined);
  assert.doesNotMatch(n8nSource, /\bck_[A-Za-z0-9]+\b|\bcs_[A-Za-z0-9]+\b/);
  assert.match(n8nSource, /REPLACE_WITH_HEADER_AUTH_CREDENTIAL_ID/);
  assert.match(n8nSource, /REPLACE_WITH_WOOCOMMERCE_WRITE_CREDENTIAL_ID/);
  assert.doesNotMatch(n8nSource, /REPLACE_WITH_(?:GOOGLE_APPS_SCRIPT|APPS_SCRIPT_PROJECT)/);
});
