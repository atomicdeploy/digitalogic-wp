/**
 * Digitalogic catalog synchronization for Google Sheets.
 *
 * Required Script Properties (File -> Project properties -> Script properties):
 *   DIGITALOGIC_API_BASE
 *   DIGITALOGIC_CONSUMER_KEY
 *   DIGITALOGIC_CONSUMER_SECRET
 *
 * Optional Script Properties:
 *   DIGITALOGIC_LOCALE        en, fa, or bilingual (default: bilingual)
 *   DIGITALOGIC_SPREADSHEET_ID  Leave blank for a bound spreadsheet.
 *   DIGITALOGIC_SYNC_HOURS    1, 2, 4, 6, 8, or 12 (default: 6)
 *   DIGITALOGIC_WRITEBACK_CONSUMER_KEY     Read/write key; falls back to the catalog key.
 *   DIGITALOGIC_WRITEBACK_CONSUMER_SECRET  Matching secret; falls back to the catalog secret.
 *   DIGITALOGIC_WRITEBACK_MAX_CHANGES      1 through 50 (default: 50)
 *   DIGITALOGIC_N8N_WRITEBACK_BASE         Optional HTTPS webhook base; /preview or /apply is appended.
 *   DIGITALOGIC_N8N_WRITEBACK_TOKEN        Matching n8n Header Auth value, stored only here and in n8n.
 *
 * Never place credentials in spreadsheet cells or commit them to this file.
 */

const DIGITALOGIC_DATASETS = Object.freeze([
  Object.freeze({ id: 'products', sheetName: 'Products', tabColor: '#1a73e8' }),
  Object.freeze({ id: 'categories', sheetName: 'Categories', tabColor: '#34a853' }),
]);
const DIGITALOGIC_PAGE_SIZE = 100;
const DIGITALOGIC_MAX_PAGES = 10000;
const DIGITALOGIC_MANAGED_HEADER_ROWS = 2;
const DIGITALOGIC_WRITEBACK_PATH = '/google-sheets/writeback/';
const DIGITALOGIC_WRITEBACK_MAX_LIMIT = 50;
const DIGITALOGIC_SUPPORT_LAYOUTS = Object.freeze([
  Object.freeze({ id: 'professional', machineHeaderRow: 5, displayHeaderRow: 6, dataStartRow: 7 }),
  Object.freeze({ id: 'legacy', machineHeaderRow: 1, displayHeaderRow: 2, dataStartRow: 3 }),
]);
const DIGITALOGIC_CHANGE_COLUMNS = Object.freeze([
  Object.freeze({ key: 'selected', header: 'Selected', type: 'boolean' }),
  Object.freeze({ key: 'sync_key', header: 'Product key', type: 'text' }),
  Object.freeze({ key: 'patris_code', header: 'Patris Code', type: 'text' }),
  Object.freeze({ key: 'expected_record_revision', header: 'Expected record revision', type: 'text' }),
  Object.freeze({ key: 'regular_price', header: 'Regular price', type: 'number' }),
  Object.freeze({ key: 'sale_price', header: 'Sale price', type: 'number' }),
  Object.freeze({ key: 'stock_quantity', header: 'Stock quantity', type: 'integer' }),
  Object.freeze({ key: 'stock_status', header: 'Stock status', type: 'enum' }),
  Object.freeze({ key: 'shipping_method_id', header: 'Shipping method ID', type: 'text' }),
  Object.freeze({ key: 'profit_percent', header: 'Profit percent', type: 'number' }),
]);
const DIGITALOGIC_EDITABLE_FIELDS = Object.freeze({
  regular_price: Object.freeze({ type: 'number', minimum: '0.000001', maximum: '999999999999999', decimalPlaces: 6 }),
  sale_price: Object.freeze({ type: 'number', minimum: '0.000001', maximum: '999999999999999', decimalPlaces: 6 }),
  stock_quantity: Object.freeze({ type: 'integer', minimum: 0, maximum: 1000000000 }),
  stock_status: Object.freeze({ type: 'enum', values: Object.freeze(['instock', 'outofstock', 'onbackorder']) }),
  shipping_method_id: Object.freeze({ type: 'text', maximumLength: 64, pattern: /^[a-z][a-z0-9_]{1,63}$/ }),
  profit_percent: Object.freeze({ type: 'number', minimum: '0', maximum: '1000', decimalPlaces: 6 }),
});
const DIGITALOGIC_AUDIT_COLUMNS = Object.freeze([
  Object.freeze({ key: 'timestamp', header: 'Timestamp', type: 'datetime' }),
  Object.freeze({ key: 'mode', header: 'Mode', type: 'text' }),
  Object.freeze({ key: 'idempotency_key', header: 'Idempotency key', type: 'text' }),
  Object.freeze({ key: 'sheet_row', header: 'Changes row', type: 'integer' }),
  Object.freeze({ key: 'sync_key', header: 'Product key', type: 'text' }),
  Object.freeze({ key: 'status', header: 'Status', type: 'text' }),
  Object.freeze({ key: 'code', header: 'Result code', type: 'text' }),
  Object.freeze({ key: 'message', header: 'Message', type: 'text' }),
  Object.freeze({ key: 'expected_record_revision', header: 'Expected revision', type: 'text' }),
  Object.freeze({ key: 'record_revision', header: 'Current revision', type: 'text' }),
  Object.freeze({ key: 'changed_fields', header: 'Changed fields', type: 'text' }),
  Object.freeze({ key: 'http_status', header: 'HTTP status', type: 'integer' }),
  Object.freeze({ key: 'before', header: 'Before', type: 'text' }),
  Object.freeze({ key: 'after', header: 'After', type: 'text' }),
  Object.freeze({ key: 'recovery', header: 'Recovery / compensation', type: 'text' }),
  Object.freeze({ key: 'audit_id', header: 'Server audit ID', type: 'text' }),
]);
const DIGITALOGIC_SUPPORT_SHEETS = Object.freeze({
  changes: Object.freeze({ sheetName: 'Changes', tabColor: '#f9ab00' }),
  audit: Object.freeze({ sheetName: 'Audit', tabColor: '#a142f4' }),
  dashboard: Object.freeze({ sheetName: 'Dashboard', tabColor: '#00acc1' }),
});

/** Add localized manual and scheduling actions to the spreadsheet. */
function onOpen() {
  const locale = getConfig_().locale;
  const ui = SpreadsheetApp.getUi();
  const menu = ui.createMenu(locale === 'fa' ? 'همگام‌سازی دیجیتالوجیک' : 'Digitalogic Sync');
  menu.addItem(locale === 'fa' ? 'همگام‌سازی اکنون' : 'Sync now', 'syncCatalog');
  menu.addSeparator();
  menu.addItem(locale === 'fa' ? 'فعال‌سازی همگام‌سازی زمان‌بندی‌شده' : 'Enable scheduled sync', 'installScheduledSync');
  menu.addItem(locale === 'fa' ? 'حذف همگام‌سازی زمان‌بندی‌شده' : 'Disable scheduled sync', 'removeScheduledSync');
  menu.addToUi();
  ui.createMenu('Digitalogic Changes')
    .addItem('Set up editable workspace', 'setupEditableWorkspace')
    .addItem('Stage selected Products', 'stageSelectedProducts')
    .addSeparator()
    .addItem('Preview selected changes', 'previewSelectedChanges')
    .addItem('Apply last preview...', 'applySelectedChanges')
    .addToUi();
}

/** Pull both canonical datasets and upsert the Products and Categories tabs. */
function syncCatalog() {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  let stateProperties = null;

  try {
    const config = getConfig_();
    const spreadsheet = getSpreadsheet_(config);
    const dashboard = spreadsheet.getSheetByName(DIGITALOGIC_SUPPORT_SHEETS.dashboard.sheetName);
    const fetched = DIGITALOGIC_DATASETS.map(function (dataset) {
      return fetchDataset_(config, dataset);
    });
    const revision = calculateRevision_(fetched);
    stateProperties = getStateProperties_(config);
    const previousRevision = stateProperties.getProperty('DIGITALOGIC_CATALOG_REVISION');

    if (previousRevision === revision) {
      stateProperties.setProperty('DIGITALOGIC_LAST_SYNC_AT', new Date().toISOString());
      if (dashboard) {
        updateDashboard_(dashboard, stateProperties);
      }
      spreadsheet.toast(localize_(config.locale, 'Catalog is already current.', 'فهرست محصولات به‌روز است.'), 'Digitalogic', 5);
      return { status: 'unchanged', revision: revision };
    }

    fetched.forEach(function (dataset) {
      upsertDataset_(spreadsheet, dataset, config.locale);
    });

    stateProperties.setProperties({
      DIGITALOGIC_CATALOG_REVISION: revision,
      DIGITALOGIC_LAST_SYNC_AT: new Date().toISOString(),
      DIGITALOGIC_LAST_SYNC_STATUS: 'ok',
      DIGITALOGIC_LAST_SYNC_ERROR: '',
    });
    if (dashboard) {
      updateDashboard_(dashboard, stateProperties);
    }
    spreadsheet.toast(localize_(config.locale, 'Products and categories synchronized.', 'محصولات و دسته‌بندی‌ها همگام شدند.'), 'Digitalogic', 7);

    return { status: 'updated', revision: revision };
  } catch (error) {
    try {
      (stateProperties || getStateProperties_(null)).setProperties({
        DIGITALOGIC_LAST_SYNC_AT: new Date().toISOString(),
        DIGITALOGIC_LAST_SYNC_STATUS: 'error',
        DIGITALOGIC_LAST_SYNC_ERROR: String(error && error.message ? error.message : error).slice(0, 500),
      });
    } catch (stateError) {
      // Preserve the catalog failure when state persistence is also unavailable.
    }
    throw error;
  } finally {
    lock.releaseLock();
  }
}

/** Use workbook state when bound and script state for standalone destinations. */
function getStateProperties_(config) {
  if (!config || !config.spreadsheetId) {
    try {
      const documentProperties = PropertiesService.getDocumentProperties();
      if (documentProperties) {
        return documentProperties;
      }
    } catch (error) {
      // Standalone Apps Script projects do not expose DocumentProperties.
    }
  }

  const scriptProperties = PropertiesService.getScriptProperties();
  if (!scriptProperties) {
    throw new Error('Apps Script state properties are unavailable.');
  }
  return scriptProperties;
}

/** Install one idempotent time-driven trigger using DIGITALOGIC_SYNC_HOURS. */
function installScheduledSync() {
  const config = getConfig_();
  removeScheduledSync();
  ScriptApp.newTrigger('syncCatalog').timeBased().everyHours(config.syncHours).create();
  getSpreadsheet_(config).toast(
    localize_(config.locale, 'Scheduled sync enabled.', 'همگام‌سازی زمان‌بندی‌شده فعال شد.'),
    'Digitalogic',
    5
  );
}

/** Remove only this integration's scheduled triggers. */
function removeScheduledSync() {
  ScriptApp.getProjectTriggers().forEach(function (trigger) {
    if (trigger.getHandlerFunction() === 'syncCatalog') {
      ScriptApp.deleteTrigger(trigger);
    }
  });
}

/** Read and validate configuration without ever looking at spreadsheet cells. */
function getConfig_() {
  const properties = PropertiesService.getScriptProperties();
  const apiBase = normalizeApiBase_(properties.getProperty('DIGITALOGIC_API_BASE'));
  const consumerKey = String(properties.getProperty('DIGITALOGIC_CONSUMER_KEY') || '').trim();
  const consumerSecret = String(properties.getProperty('DIGITALOGIC_CONSUMER_SECRET') || '').trim();
  const requestedLocale = String(properties.getProperty('DIGITALOGIC_LOCALE') || 'bilingual').toLowerCase();
  const locale = ['en', 'fa', 'bilingual'].indexOf(requestedLocale) >= 0 ? requestedLocale : 'bilingual';
  const requestedHours = Number(properties.getProperty('DIGITALOGIC_SYNC_HOURS') || 6);
  const syncHours = [1, 2, 4, 6, 8, 12].indexOf(requestedHours) >= 0 ? requestedHours : 6;
  const writebackConsumerKey = String(properties.getProperty('DIGITALOGIC_WRITEBACK_CONSUMER_KEY') || consumerKey).trim();
  const writebackConsumerSecret = String(properties.getProperty('DIGITALOGIC_WRITEBACK_CONSUMER_SECRET') || consumerSecret).trim();
  const requestedWritebackLimit = Number(properties.getProperty('DIGITALOGIC_WRITEBACK_MAX_CHANGES') || DIGITALOGIC_WRITEBACK_MAX_LIMIT);
  const writebackMaxChanges = Number.isInteger(requestedWritebackLimit)
    && requestedWritebackLimit >= 1
    && requestedWritebackLimit <= DIGITALOGIC_WRITEBACK_MAX_LIMIT
    ? requestedWritebackLimit
    : DIGITALOGIC_WRITEBACK_MAX_LIMIT;
  const n8nWritebackBase = normalizeWebhookBase_(properties.getProperty('DIGITALOGIC_N8N_WRITEBACK_BASE'));
  const n8nWritebackToken = String(properties.getProperty('DIGITALOGIC_N8N_WRITEBACK_TOKEN') || '').trim();

  if (!apiBase) {
    throw new Error('DIGITALOGIC_API_BASE is required.');
  }
  if (!/^ck_[A-Za-z0-9]+$/.test(consumerKey) || !/^cs_[A-Za-z0-9]+$/.test(consumerSecret)) {
    throw new Error('Read-only WooCommerce consumer credentials are missing or malformed.');
  }

  return {
    apiBase: apiBase,
    consumerKey: consumerKey,
    consumerSecret: consumerSecret,
    locale: locale,
    spreadsheetId: String(properties.getProperty('DIGITALOGIC_SPREADSHEET_ID') || '').trim(),
    syncHours: syncHours,
    writebackConsumerKey: writebackConsumerKey,
    writebackConsumerSecret: writebackConsumerSecret,
    writebackMaxChanges: writebackMaxChanges,
    n8nWritebackBase: n8nWritebackBase,
    n8nWritebackToken: n8nWritebackToken,
  };
}

/** Normalize either a site root or a complete Digitalogic REST namespace URL. */
function normalizeApiBase_(value) {
  const input = String(value || '').trim().replace(/\/+$/, '');
  if (!/^https:\/\//i.test(input)) {
    return '';
  }
  if (/\/wp-json\/digitalogic\/v1$/i.test(input)) {
    return input;
  }
  return input + '/wp-json/digitalogic/v1';
}

/** Accept a query-free HTTPS n8n webhook base, or a blank value for direct mode. */
function normalizeWebhookBase_(value) {
  const input = String(value || '').trim().replace(/\/+$/, '');
  if (!input) {
    return '';
  }
  if (!/^https:\/\/[^?#]+$/i.test(input)) {
    throw new Error('DIGITALOGIC_N8N_WRITEBACK_BASE must be a query-free HTTPS URL.');
  }
  return input;
}

/** Resolve a bound spreadsheet or an explicitly configured destination. */
function getSpreadsheet_(config) {
  if (config && config.spreadsheetId) {
    return SpreadsheetApp.openById(config.spreadsheetId);
  }
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  if (!spreadsheet) {
    throw new Error('Bind this script to a spreadsheet or set DIGITALOGIC_SPREADSHEET_ID.');
  }
  return spreadsheet;
}

/** Fetch every bounded page for one dataset and union dynamic warehouse columns. */
function fetchDataset_(config, dataset) {
  const pages = [];
  const columns = [];
  const columnKeys = Object.create(null);
  const rows = [];
  let page = 1;
  let hasMore = true;

  while (hasMore) {
    if (page > DIGITALOGIC_MAX_PAGES) {
      throw new Error('Catalog pagination exceeded the safety limit for ' + dataset.id + '.');
    }
    const response = validateCatalogPage_(fetchPage_(config, dataset.id, page), dataset.id);

    response.columns.forEach(function (column) {
      if (!column || !column.key || columnKeys[column.key]) {
        return;
      }
      columnKeys[column.key] = true;
      columns.push(column);
    });
    response.rows.forEach(function (row) {
      rows.push(row);
    });
    pages.push(String(response.page_revision || ''));
    hasMore = Boolean(response.pagination && response.pagination.has_more);
    page += 1;
  }

  return {
    id: dataset.id,
    sheetName: dataset.sheetName,
    tabColor: dataset.tabColor,
    columns: columns,
    rows: rows,
    pageRevisions: pages,
  };
}

/** Validate the living response by its required structure. */
function validateCatalogPage_(response, dataset) {
  const isObject = response !== null && typeof response === 'object' && !Array.isArray(response);
  const hasPagination = isObject
    && response.pagination !== null
    && typeof response.pagination === 'object'
    && !Array.isArray(response.pagination);

  if (
    !isObject
    || response.dataset !== dataset
    || !Array.isArray(response.columns)
    || !Array.isArray(response.rows)
    || !hasPagination
  ) {
    throw new Error('Malformed ' + dataset + ' catalog response.');
  }

  return response;
}

/** Fetch and validate one REST page with Basic auth in an HTTP header. */
function fetchPage_(config, dataset, page) {
  const query = [
    'dataset=' + encodeURIComponent(dataset),
    'locale=' + encodeURIComponent(config.locale),
    'page=' + encodeURIComponent(page),
    'limit=' + encodeURIComponent(DIGITALOGIC_PAGE_SIZE),
  ].join('&');
  const response = UrlFetchApp.fetch(config.apiBase + '/google-sheets/catalog?' + query, {
    method: 'get',
    headers: {
      Authorization: 'Basic ' + Utilities.base64Encode(config.consumerKey + ':' + config.consumerSecret),
      Accept: 'application/json',
    },
    followRedirects: false,
    muteHttpExceptions: true,
  });
  const status = response.getResponseCode();
  let payload;

  try {
    payload = JSON.parse(response.getContentText());
  } catch (error) {
    throw new Error('Digitalogic returned non-JSON HTTP ' + status + '.');
  }
  if (status < 200 || status >= 300 || !payload || payload.success !== true || !payload.data) {
    const message = payload && (payload.message || payload.code) ? (payload.message || payload.code) : 'request failed';
    throw new Error('Digitalogic HTTP ' + status + ': ' + message);
  }

  return payload.data;
}

/** Compute one stable revision from machine columns and complete row data. */
function calculateRevision_(datasets) {
  const material = datasets.map(function (dataset) {
    return {
      id: dataset.id,
      columns: dataset.columns.map(function (column) { return column.key; }),
      rows: dataset.rows,
      pages: dataset.pageRevisions,
    };
  });
  const digest = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    JSON.stringify(material),
    Utilities.Charset.UTF_8
  );
  return 'sha256:' + digest.map(function (byte) {
    return ('0' + ((byte + 256) % 256).toString(16)).slice(-2);
  }).join('');
}

/** Upsert by sync_key, remove stale managed rows, and restyle one managed tab. */
function upsertDataset_(spreadsheet, dataset, locale) {
  let sheet = spreadsheet.getSheetByName(dataset.sheetName);
  if (!sheet) {
    sheet = spreadsheet.insertSheet(dataset.sheetName);
  }

  const keys = dataset.columns.map(function (column) { return column.key; });
  if (!keys.length || keys[0] !== 'sync_key') {
    throw new Error(dataset.sheetName + ' column layout must start with sync_key.');
  }
  const incomingRows = dataset.rows.map(function (row) {
    return rowToSheetValues_(row, keys);
  });
  const existingRows = readExistingRows_(sheet, keys);
  const rows = mergeRows_(existingRows, incomingRows, 0);
  const requiredRows = Math.max(DIGITALOGIC_MANAGED_HEADER_ROWS + rows.length, 3);
  const requiredColumns = Math.max(keys.length, 1);

  ensureGridSize_(sheet, requiredRows, requiredColumns);
  const existingFilter = sheet.getFilter();
  if (existingFilter) {
    existingFilter.remove();
  }
  sheet.getBandings().forEach(function (banding) { banding.remove(); });
  sheet.clear();
  sheet.getRange(1, 1, 1, keys.length).setValues([keys.map(neutralizeSheetText_)]);
  sheet.getRange(2, 1, 1, keys.length).setValues([
    dataset.columns.map(function (column) {
      return neutralizeSheetText_(column.header || column.label_en || column.key);
    }),
  ]);
  if (rows.length) {
    sheet.getRange(3, 1, rows.length, keys.length).setValues(rows);
  }

  styleDataset_(sheet, dataset, locale, rows.length);
}

/** Read managed rows only when the existing machine-key layout matches exactly. */
function readExistingRows_(sheet, keys) {
  if (sheet.getLastRow() < 3 || sheet.getLastColumn() < keys.length) {
    return [];
  }
  const existingKeys = sheet.getRange(1, 1, 1, keys.length).getValues()[0].map(String);
  if (JSON.stringify(existingKeys) !== JSON.stringify(keys)) {
    return [];
  }
  return sheet.getRange(3, 1, sheet.getLastRow() - 2, keys.length).getValues();
}

/** Render sparse API rows into the fixed tab layout; missing and null are blank cells. */
function rowToSheetValues_(row, keys) {
  return keys.map(function (key) {
    const value = Object.prototype.hasOwnProperty.call(row, key) && row[key] !== null ? row[key] : '';
    return neutralizeSheetText_(value);
  });
}

/** Prefix formula-like text reversibly before any Range.setValues call. */
function neutralizeSheetText_(value) {
  if (typeof value !== 'string' || !value) {
    return value;
  }
  return /^[=+\-@']/.test(value) ? "'" + value : value;
}

/** Recover exact text previously prefixed by neutralizeSheetText_. */
function restoreNeutralizedSheetText_(value) {
  if (typeof value !== 'string' || value.length < 2 || value.charAt(0) !== "'") {
    return value;
  }
  return /^[=+\-@']/.test(value.charAt(1)) ? value.slice(1) : value;
}

/**
 * Pure key-based upsert helper: preserve current order, update matches, append
 * new records, reject duplicate incoming keys, and omit stale managed records.
 */
function mergeRows_(existingRows, incomingRows, keyIndex) {
  const incomingByKey = Object.create(null);
  const incomingOrder = [];
  incomingRows.forEach(function (row) {
    const key = String(restoreNeutralizedSheetText_(row[keyIndex]) || '');
    if (!key) {
      throw new Error('A catalog row is missing sync_key.');
    }
    if (Object.prototype.hasOwnProperty.call(incomingByKey, key)) {
      throw new Error('Duplicate catalog sync_key: ' + key);
    }
    incomingByKey[key] = row;
    incomingOrder.push(key);
  });

  const result = [];
  const used = Object.create(null);
  existingRows.forEach(function (row) {
    const key = String(restoreNeutralizedSheetText_(row[keyIndex]) || '');
    if (key && Object.prototype.hasOwnProperty.call(incomingByKey, key) && !used[key]) {
      result.push(incomingByKey[key]);
      used[key] = true;
    }
  });
  incomingOrder.forEach(function (key) {
    if (!used[key]) {
      result.push(incomingByKey[key]);
      used[key] = true;
    }
  });

  return result;
}

/** Grow a sheet without deleting unrelated workbook tabs. */
function ensureGridSize_(sheet, rows, columns) {
  if (sheet.getMaxRows() < rows) {
    sheet.insertRowsAfter(sheet.getMaxRows(), rows - sheet.getMaxRows());
  }
  if (sheet.getMaxColumns() < columns) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), columns - sheet.getMaxColumns());
  }
}

/** Apply bilingual-friendly table styling and exact text/number formats. */
function styleDataset_(sheet, dataset, locale, rowCount) {
  const columnCount = dataset.columns.length;
  const dataHeight = Math.max(rowCount, 1);
  const isRtl = locale === 'fa';

  sheet.setRightToLeft(isRtl);
  sheet.setFrozenRows(2);
  sheet.setFrozenColumns(1);
  sheet.setTabColor(dataset.tabColor);
  sheet.hideRows(1);
  sheet.setRowHeight(2, 38);
  if (rowCount) {
    sheet.getRange(2, 1, rowCount + 1, columnCount).createFilter();
    sheet.getRange(2, 1, rowCount + 1, columnCount)
      .applyRowBanding(SpreadsheetApp.BandingTheme.BLUE, true, false);
  }
  sheet.getRange(2, 1, 1, columnCount)
    .setBackground('#17365d')
    .setFontColor('#ffffff')
    .setFontWeight('bold')
    .setFontFamily('Vazirmatn')
    .setHorizontalAlignment(isRtl ? 'right' : 'left')
    .setVerticalAlignment('middle')
    .setWrap(false);
  sheet.getRange(3, 1, dataHeight, columnCount)
    .setFontFamily('Vazirmatn')
    .setVerticalAlignment('middle')
    .setWrap(false);

  dataset.columns.forEach(function (column, index) {
    const range = sheet.getRange(3, index + 1, dataHeight, 1);
    if (column.type === 'text' || column.type === 'url' || column.type === 'datetime') {
      range.setNumberFormat('@');
    } else if (column.type === 'integer') {
      range.setNumberFormat('0');
    } else if (column.type === 'number') {
      range.setNumberFormat('#,##0.########');
    }
  });

  sheet.autoResizeColumns(1, columnCount);
  for (let column = 1; column <= columnCount; column += 1) {
    const width = sheet.getColumnWidth(column);
    sheet.setColumnWidth(column, Math.max(90, Math.min(width, 260)));
  }

  const statusColumn = dataset.columns.findIndex(function (column) { return column.key === 'sync_status'; }) + 1;
  if (statusColumn > 0 && rowCount) {
    const statusRange = sheet.getRange(3, statusColumn, rowCount, 1);
    const rules = [
      SpreadsheetApp.newConditionalFormatRule().whenTextEqualTo('ok').setBackground('#e6f4ea').setRanges([statusRange]).build(),
      SpreadsheetApp.newConditionalFormatRule().whenTextEqualTo('warning').setBackground('#fef7e0').setRanges([statusRange]).build(),
      SpreadsheetApp.newConditionalFormatRule().whenTextEqualTo('error').setBackground('#fce8e6').setRanges([statusRange]).build(),
    ];
    sheet.setConditionalFormatRules(rules);
  } else {
    sheet.setConditionalFormatRules([]);
  }

  protectManagedSheet_(sheet);
}

/** Create the user-editable Changes tab and the integration-managed Audit and Dashboard tabs. */
function setupEditableWorkspace() {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);

  try {
    const config = getConfig_();
    const spreadsheet = getSpreadsheet_(config);
    const workspace = ensureWritebackWorkspace_(spreadsheet, config.locale);
    const stateProperties = getStateProperties_(config);
    if (config.n8nWritebackBase
      && !stateProperties.getProperty('DIGITALOGIC_LAST_WRITEBACK_TRANSPORT')) {
      stateProperties.setProperties({
        DIGITALOGIC_LAST_WRITEBACK_STATUS: 'ready',
        DIGITALOGIC_LAST_WRITEBACK_TRANSPORT: 'n8n',
        DIGITALOGIC_LAST_WRITEBACK_MESSAGE: 'Preview/apply bridge configured; no request has run yet.',
      });
    }
    updateDashboard_(workspace.dashboard, stateProperties);
    spreadsheet.toast('Changes, Audit, and Dashboard are ready.', 'Digitalogic', 6);
    return {
      changes: workspace.changes.getName(),
      audit: workspace.audit.getName(),
      dashboard: workspace.dashboard.getName(),
    };
  } finally {
    lock.releaseLock();
  }
}

/** Ensure the three writeback tabs exist without replacing any staged or audited rows. */
function ensureWritebackWorkspace_(spreadsheet, locale) {
  const changesStructure = ensureStructuredSheet_(
    spreadsheet,
    DIGITALOGIC_SUPPORT_SHEETS.changes,
    DIGITALOGIC_CHANGE_COLUMNS,
    500
  );
  const auditStructure = ensureStructuredSheet_(
    spreadsheet,
    DIGITALOGIC_SUPPORT_SHEETS.audit,
    DIGITALOGIC_AUDIT_COLUMNS,
    500
  );
  const dashboard = ensureDashboardSheet_(spreadsheet);

  styleChangesSheet_(changesStructure.sheet, changesStructure.layout, locale);
  styleAuditSheet_(auditStructure.sheet, auditStructure.layout, locale);
  DIGITALOGIC_DATASETS.forEach(function (dataset) {
    const sheet = spreadsheet.getSheetByName(dataset.sheetName);
    if (sheet) {
      protectManagedSheet_(sheet);
    }
  });

  return {
    changes: changesStructure.sheet,
    changesLayout: changesStructure.layout,
    audit: auditStructure.sheet,
    auditLayout: auditStructure.layout,
    dashboard: dashboard,
  };
}

/** Detect the professional row-5/6 layout first, with legacy row-1/2 support. */
function detectStructuredLayout_(candidateRows, expectedKeys) {
  for (let index = 0; index < DIGITALOGIC_SUPPORT_LAYOUTS.length; index += 1) {
    const layout = DIGITALOGIC_SUPPORT_LAYOUTS[index];
    const row = candidateRows[String(layout.machineHeaderRow)] || [];
    const values = Array.prototype.slice.call(row, 0, expectedKeys.length).map(String);
    if (JSON.stringify(values) === JSON.stringify(expectedKeys)) {
      return layout;
    }
  }
  return null;
}

/** Create or validate a known support-tab header without clearing existing rows. */
function ensureStructuredSheet_(spreadsheet, definition, columns, minimumRows) {
  let sheet = spreadsheet.getSheetByName(definition.sheetName);
  let created = false;
  if (!sheet) {
    sheet = spreadsheet.insertSheet(definition.sheetName);
    created = true;
  }

  const defaultLayout = DIGITALOGIC_SUPPORT_LAYOUTS[0];
  const keys = columns.map(function (column) { return column.key; });
  const candidateRows = {};
  let layout = null;
  if (!created) {
    DIGITALOGIC_SUPPORT_LAYOUTS.forEach(function (candidate) {
      candidateRows[String(candidate.machineHeaderRow)] = sheet.getMaxRows() >= candidate.machineHeaderRow
        && sheet.getMaxColumns() >= keys.length
        ? sheet.getRange(candidate.machineHeaderRow, 1, 1, keys.length).getDisplayValues()[0]
        : [];
    });
    layout = detectStructuredLayout_(candidateRows, keys);
  }

  if (!layout && !created) {
    throw new Error(definition.sheetName + ' has an unexpected machine-header layout; no cells were changed.');
  }
  if (!layout) {
    layout = defaultLayout;
  }
  ensureGridSize_(sheet, Math.max(minimumRows, layout.dataStartRow), columns.length);
  if (created) {
    sheet.getRange(layout.machineHeaderRow, 1, 1, keys.length).setValues([keys]);
    sheet.getRange(layout.displayHeaderRow, 1, 1, keys.length).setValues([
      columns.map(function (column) { return column.header; }),
    ]);
  }

  if (created) {
    sheet.setTabColor(definition.tabColor);
    sheet.setFrozenRows(layout.displayHeaderRow || layout.machineHeaderRow);
    sheet.setFrozenColumns(1);
    sheet.hideRows(layout.machineHeaderRow);
    sheet.getRange(layout.displayHeaderRow || layout.machineHeaderRow, 1, 1, keys.length)
      .setBackground('#17365d')
      .setFontColor('#ffffff')
      .setFontWeight('bold')
      .setFontFamily('Vazirmatn')
      .setVerticalAlignment('middle');
    sheet.autoResizeColumns(1, keys.length);
  }
  sheet.getRange(layout.dataStartRow, 1, Math.max(sheet.getMaxRows() - layout.dataStartRow + 1, 1), keys.length)
    .setFontFamily('Vazirmatn')
    .setVerticalAlignment('middle');

  return { sheet: sheet, layout: layout };
}

/** Apply data types and validation to the staging tab without changing staged values. */
function styleChangesSheet_(sheet, layout, locale) {
  const rowCount = Math.max(sheet.getMaxRows() - layout.dataStartRow + 1, 1);
  const columnKeys = DIGITALOGIC_CHANGE_COLUMNS.map(function (column) { return column.key; });
  const selectedColumn = columnKeys.indexOf('selected') + 1;
  const statusColumn = columnKeys.indexOf('stock_status') + 1;
  const checkboxRule = SpreadsheetApp.newDataValidation().requireCheckbox().setAllowInvalid(false).build();
  const stockRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(['instock', 'outofstock', 'onbackorder'], true)
    .setAllowInvalid(false)
    .build();

  sheet.setRightToLeft(locale === 'fa');
  styleProfessionalSupportSheet_(sheet, layout, {
    title: 'CHANGES | SAFE PRODUCT UPDATE QUEUE',
    subtitle: 'Stage from Products, edit only the intended commercial fields, preview, then explicitly confirm apply.',
    section: 'EDITABLE CHANGESET  /  SELECT ONLY REVIEWED ROWS',
    tabColor: '#16a34a',
    headerColor: '#0f766e',
    columnWidths: [90, 140, 140, 285, 120, 120, 120, 140, 160, 130],
    helpTitle: 'CONTROLLED WRITEBACK',
    helpText: [
      '1  Select current records in Products.',
      '2  Digitalogic Changes > Stage selected Products.',
      '3  Edit price, stock, shipping, or profit fields here.',
      '4  Select reviewed rows and run Preview selected changes.',
      '5  Review Audit, then Apply last preview with confirmation.',
      '',
      'Preview is non-publishing. Apply is bounded, revision-checked, and idempotent.',
    ].join('\n'),
  });
  sheet.getRange(layout.dataStartRow, selectedColumn, rowCount, 1).setDataValidation(checkboxRule);
  sheet.getRange(layout.dataStartRow, statusColumn, rowCount, 1).setDataValidation(stockRule);
  DIGITALOGIC_CHANGE_COLUMNS.forEach(function (column, index) {
    const range = sheet.getRange(layout.dataStartRow, index + 1, rowCount, 1);
    if (column.type === 'number') {
      range.setNumberFormat('@');
    } else if (column.type === 'integer') {
      range.setNumberFormat('0');
    } else if (column.type === 'text' || column.type === 'enum') {
      range.setNumberFormat('@');
    }
  });
  protectHeaderRange_(sheet, 'Digitalogic Changes headers', layout.dataStartRow - 1);
}

/** Keep the audit log append-only for normal sheet editing. */
function styleAuditSheet_(sheet, layout, locale) {
  const rowCount = Math.max(sheet.getMaxRows() - layout.dataStartRow + 1, 1);
  sheet.setRightToLeft(locale === 'fa');
  styleProfessionalSupportSheet_(sheet, layout, {
    title: 'AUDIT | WRITEBACK ACTIVITY & RECOVERY',
    subtitle: 'Append-only evidence for every preview and apply response, including revisions and rollback metadata.',
    section: 'TRANSACTION LOG  /  INTEGRATION MANAGED',
    tabColor: '#d97706',
    headerColor: '#92400e',
    columnWidths: [160, 90, 250, 70, 140, 100, 140, 260, 285, 285, 180, 100, 220, 220, 260, 100],
  });
  DIGITALOGIC_AUDIT_COLUMNS.forEach(function (column, index) {
    const range = sheet.getRange(layout.dataStartRow, index + 1, rowCount, 1);
    if (column.type === 'integer') {
      range.setNumberFormat('0');
    } else if (column.type === 'datetime') {
      range.setNumberFormat('yyyy-mm-dd hh:mm:ss');
    } else {
      range.setNumberFormat('@');
    }
  });
  protectAppendOnlySheet_(sheet);
}

/** Style only the reserved presentation rows of the professional support-tab layout. */
function styleProfessionalSupportSheet_(sheet, layout, options) {
  if (!layout || layout.id !== 'professional') {
    return false;
  }

  const columnCount = options.columnWidths.length;
  const rowCount = Math.max(sheet.getMaxRows() - layout.dataStartRow + 1, 1);
  const navy = '#0f172a';
  const slate = '#475569';
  const graySoft = '#f1f5f9';
  const white = '#ffffff';

  if (options.helpTitle) {
    ensureGridSize_(sheet, 12, 18);
  }
  // Google Sheets cannot merge a title across an existing frozen-column boundary.
  sheet.setFrozenRows(0);
  sheet.setFrozenColumns(0);
  sheet.getRange(1, 1, 4, columnCount).breakApart();
  sheet.getRange(1, 1, 2, columnCount).merge()
    .setValue(options.title)
    .setBackground(navy)
    .setFontColor(white)
    .setFontFamily('Arial')
    .setFontSize(18)
    .setFontWeight('bold')
    .setHorizontalAlignment('left')
    .setVerticalAlignment('middle');
  sheet.getRange(3, 1, 1, columnCount).merge()
    .setValue(options.subtitle)
    .setBackground(graySoft)
    .setFontColor(slate)
    .setFontFamily('Arial')
    .setFontSize(10)
    .setFontStyle('italic')
    .setVerticalAlignment('middle');
  sheet.getRange(4, 1, 1, columnCount).merge()
    .setValue(options.section)
    .setBackground(options.headerColor)
    .setFontColor(white)
    .setFontFamily('Arial')
    .setFontSize(10)
    .setFontWeight('bold')
    .setVerticalAlignment('middle');

  sheet.getRange(layout.machineHeaderRow, 1, 1, columnCount)
    .setBackground(navy)
    .setFontColor(white)
    .setFontFamily('Arial')
    .setFontWeight('bold');
  sheet.getRange(layout.displayHeaderRow, 1, 1, columnCount)
    .setBackground(options.headerColor)
    .setFontColor(white)
    .setFontFamily('Vazirmatn')
    .setFontWeight('bold')
    .setHorizontalAlignment('center')
    .setVerticalAlignment('middle')
    .setWrap(true);
  sheet.getRange(layout.dataStartRow, 1, rowCount, columnCount)
    .setFontColor('#334155')
    .setVerticalAlignment('middle');

  if (options.helpTitle) {
    sheet.getRange('L4:R12').breakApart();
    sheet.getRange('L4:R4').merge()
      .setValue(options.helpTitle)
      .setBackground('#0284c7')
      .setFontColor(white)
      .setFontWeight('bold')
      .setHorizontalAlignment('left');
    sheet.getRange('L7:R12').merge()
      .setValue(options.helpText)
      .setBackground('#e0f2fe')
      .setFontColor(navy)
      .setFontFamily('Arial')
      .setFontSize(10)
      .setWrap(true)
      .setVerticalAlignment('top');
    sheet.setColumnWidth(11, 24);
    sheet.setColumnWidths(12, 7, 88);
  }

  options.columnWidths.forEach(function (width, index) {
    sheet.setColumnWidth(index + 1, width);
  });
  sheet.setHiddenGridlines(true);
  sheet.setTabColor(options.tabColor);
  sheet.setFrozenRows(layout.displayHeaderRow);
  sheet.hideRows(layout.machineHeaderRow);
  sheet.setRowHeights(1, 2, 28);
  sheet.setRowHeight(3, 26);
  sheet.setRowHeight(4, 24);
  sheet.setRowHeight(layout.displayHeaderRow, 34);
  sheet.setRowHeights(layout.dataStartRow, rowCount, 24);
  return true;
}

/** Return the workbook-owned Dashboard without rewriting its design or cells. */
function ensureDashboardSheet_(spreadsheet) {
  const definition = DIGITALOGIC_SUPPORT_SHEETS.dashboard;
  let sheet = spreadsheet.getSheetByName(definition.sheetName);
  if (!sheet) {
    sheet = spreadsheet.insertSheet(definition.sheetName);
    sheet.setTabColor(definition.tabColor);
  }

  return sheet;
}

/** Update only the designed workbook's reserved integration-status cells. */
function updateDashboard_(sheet, properties) {
  if (!sheet
    || sheet.getRange('A1').getDisplayValue() !== 'DIGITALOGIC | PRODUCT & PRICING CONTROL CENTER') {
    return false;
  }
  const requestSummary = [
    properties.getProperty('DIGITALOGIC_LAST_WRITEBACK_IDEMPOTENCY_KEY') || '',
    properties.getProperty('DIGITALOGIC_LAST_WRITEBACK_SUMMARY') || '',
  ].filter(Boolean).join(' | ');
  sheet.getRange('J13:K15').setValues([
    [
      properties.getProperty('DIGITALOGIC_LAST_SYNC_STATUS') || 'not run',
      properties.getProperty('DIGITALOGIC_LAST_SYNC_AT') || 'not run',
    ],
    [
      properties.getProperty('DIGITALOGIC_LAST_WRITEBACK_STATUS') || 'not run',
      requestSummary,
    ],
    [
      properties.getProperty('DIGITALOGIC_LAST_WRITEBACK_TRANSPORT') || 'not configured',
      properties.getProperty('DIGITALOGIC_LAST_WRITEBACK_MESSAGE') || '',
    ],
  ].map(function (row) { return row.map(neutralizeSheetText_); }));
  return true;
}

/** Enforce protection on canonical reference tabs. */
function protectManagedSheet_(sheet) {
  const description = 'Digitalogic managed read-only reference';
  let protection = sheet.getProtections(SpreadsheetApp.ProtectionType.SHEET).find(function (candidate) {
    return candidate.getDescription() === description;
  });
  if (!protection) {
    protection = sheet.protect().setDescription(description);
  }
  restrictProtectionToOperator_(protection);
  sheet.getRange(1, 1).setNote('Integration-managed reference data. Stage edits in Changes.');
}

/** Protect the machine/display header rows while leaving data rows editable. */
function protectHeaderRange_(sheet, description, machineHeaderRow) {
  let protection = sheet.getProtections(SpreadsheetApp.ProtectionType.RANGE).find(function (candidate) {
    return candidate.getDescription() === description;
  });
  if (!protection) {
    protection = sheet.getRange(1, 1, machineHeaderRow, sheet.getLastColumn())
      .protect().setDescription(description);
  } else {
    protection.setRange(sheet.getRange(1, 1, machineHeaderRow, sheet.getLastColumn()));
  }
  restrictProtectionToOperator_(protection);
}

/** Mark Audit as integration-managed while still allowing the owner to recover it. */
function protectAppendOnlySheet_(sheet) {
  const description = 'Digitalogic append-only audit';
  let protection = sheet.getProtections(SpreadsheetApp.ProtectionType.SHEET).find(function (candidate) {
    return candidate.getDescription() === description;
  });
  if (!protection) {
    protection = sheet.protect().setDescription(description);
  }
  restrictProtectionToOperator_(protection);
}

/** Restrict protected integration surfaces to the executing workbook owner. */
function restrictProtectionToOperator_(protection) {
  const operator = Session.getEffectiveUser();
  const operatorEmail = String(operator && operator.getEmail ? operator.getEmail() : '').trim().toLowerCase();
  if (!operatorEmail) {
    throw new Error('The executing workbook owner could not be identified for protection setup.');
  }
  protection.addEditor(operator);
  const removable = protection.getEditors().filter(function (editor) {
    return String(editor.getEmail() || '').trim().toLowerCase() !== operatorEmail;
  });
  if (removable.length) {
    protection.removeEditors(removable);
  }
  if (protection.canDomainEdit()) {
    protection.setDomainEdit(false);
  }
  protection.setWarningOnly(false);

  return protection;
}

/** Copy identifiers and revisions from selected Products rows into Changes. */
function stageSelectedProducts() {
  const config = getConfig_();
  const spreadsheet = getSpreadsheet_(config);
  const workspace = ensureWritebackWorkspace_(spreadsheet, config.locale);
  const source = spreadsheet.getActiveSheet();
  const selection = source.getActiveRange();

  if (source.getName() !== 'Products' || !selection || selection.getRow() < 3) {
    throw new Error('Select one or more data rows on Products before staging.');
  }

  const sourceKeys = source.getRange(1, 1, 1, source.getLastColumn()).getDisplayValues()[0].map(String);
  const sourceIndexes = {
    sync_key: sourceKeys.indexOf('sync_key'),
    patris_code: sourceKeys.indexOf('patris_code'),
    expected_record_revision: sourceKeys.indexOf('record_revision'),
  };
  if (sourceIndexes.sync_key < 0 || sourceIndexes.patris_code < 0 || sourceIndexes.expected_record_revision < 0) {
    throw new Error('Products is missing sync_key, patris_code, or record_revision. Sync the catalog first.');
  }

  const sourceRows = source.getRange(selection.getRow(), 1, selection.getNumRows(), source.getLastColumn()).getValues();
  const staged = readExistingStructuredRows_(
    workspace.changes,
    DIGITALOGIC_CHANGE_COLUMNS,
    workspace.changesLayout
  );
  const stagedByKey = Object.create(null);
  staged.forEach(function (entry) {
    const key = String(restoreNeutralizedSheetText_(entry.values[1]) || '').trim();
    if (key) {
      stagedByKey[key] = entry;
    }
  });
  let stagedCount = 0;

  sourceRows.forEach(function (row) {
    const syncKey = String(restoreNeutralizedSheetText_(row[sourceIndexes.sync_key]) || '').trim();
    const patrisCode = String(restoreNeutralizedSheetText_(row[sourceIndexes.patris_code]) || '').trim();
    const revision = String(restoreNeutralizedSheetText_(row[sourceIndexes.expected_record_revision]) || '').trim();
    if (!syncKey || syncKey !== patrisCode || !/^sha256:[a-f0-9]{64}$/i.test(revision)) {
      return;
    }
    if (stagedByKey[syncKey]) {
      workspace.changes.getRange(stagedByKey[syncKey].rowNumber, 2, 1, 3)
        .setValues([[syncKey, patrisCode, revision].map(neutralizeSheetText_)]);
    } else {
      const rowNumber = findFirstBlankStructuredRow_(
        workspace.changes,
        workspace.changesLayout,
        2
      );
      ensureGridSize_(workspace.changes, rowNumber, DIGITALOGIC_CHANGE_COLUMNS.length);
      const values = DIGITALOGIC_CHANGE_COLUMNS.map(function (column) {
        if (column.key === 'selected') { return false; }
        if (column.key === 'sync_key') { return syncKey; }
        if (column.key === 'patris_code') { return patrisCode; }
        if (column.key === 'expected_record_revision') { return revision; }
        return '';
      });
      workspace.changes.getRange(rowNumber, 1, 1, values.length)
        .setValues([values.map(neutralizeSheetText_)]);
    }
    stagedCount += 1;
  });

  if (!stagedCount) {
    throw new Error('No selected Product row had equal Patris/sync keys and a valid record revision.');
  }
  spreadsheet.setActiveSheet(workspace.changes);
  spreadsheet.toast(stagedCount + ' product row(s) staged. Edit fields, then select rows to preview.', 'Digitalogic', 7);
  return stagedCount;
}

/** Find the first blank managed row by its key column, ignoring side-panel content. */
function findFirstBlankStructuredRow_(sheet, layout, keyColumn) {
  const availableRows = Math.max(sheet.getMaxRows() - layout.dataStartRow + 1, 0);
  if (availableRows > 0) {
    const keys = sheet.getRange(layout.dataStartRow, keyColumn, availableRows, 1).getDisplayValues();
    for (let index = 0; index < keys.length; index += 1) {
      const key = restoreNeutralizedSheetText_(keys[index][0]);
      if (!String(key || '').trim()) {
        return layout.dataStartRow + index;
      }
    }
  }

  return Math.max(sheet.getMaxRows() + 1, layout.dataStartRow);
}

/** Read structured data rows while preserving their one-based sheet row numbers. */
function readExistingStructuredRows_(sheet, columns, layout) {
  if (sheet.getLastRow() < layout.dataStartRow) {
    return [];
  }
  const rowCount = sheet.getLastRow() - layout.dataStartRow + 1;
  const columnCount = columns.length;
  const values = sheet.getRange(layout.dataStartRow, 1, rowCount, columnCount).getValues();
  const formulas = sheet.getRange(layout.dataStartRow, 1, rowCount, columnCount).getFormulas();
  return values.map(function (row, index) {
    const rowNumber = index + layout.dataStartRow;
    const object = { _rowNumber: rowNumber, _hasFormula: formulas[index].some(Boolean) };
    columns.forEach(function (column, columnIndex) {
      object[column.key] = row[columnIndex];
    });
    return { rowNumber: rowNumber, values: row, object: object };
  });
}

/** Preview is the default and performs no product mutation. */
function previewSelectedChanges() {
  return executeWriteback_('preview');
}

/** Apply only the exact, successful last preview after a separate confirmation click. */
function applySelectedChanges() {
  const prepared = prepareWritebackRequest_('apply');
  const properties = PropertiesService.getDocumentProperties();
  if (properties.getProperty('DIGITALOGIC_LAST_PREVIEW_SIGNATURE') !== prepared.signature) {
    throw new Error('The selected changes differ from the last preview. Preview them again before applying.');
  }
  if (properties.getProperty('DIGITALOGIC_LAST_PREVIEW_READY') !== 'true') {
    throw new Error('The last preview contains conflicts, invalid rows, or failures. Resolve them before applying.');
  }

  const answer = SpreadsheetApp.getUi().alert(
    'Apply Digitalogic changes',
    'Apply ' + prepared.request.changes.length + ' previewed product change(s)? This writes to WooCommerce.',
    SpreadsheetApp.getUi().ButtonSet.YES_NO
  );
  if (answer !== SpreadsheetApp.getUi().Button.YES) {
    return { status: 'cancelled' };
  }

  const result = executeWriteback_('apply');
  if (result.summary.applied > 0) {
    try {
      syncCatalog();
      result.catalog_refresh = { status: 'ok' };
    } catch (error) {
      const message = 'Changes were applied, but catalog refresh failed: '
        + String(error && error.message ? error.message : error).slice(0, 400);
      const properties = PropertiesService.getDocumentProperties();
      properties.setProperty('DIGITALOGIC_LAST_WRITEBACK_MESSAGE', message);
      const config = getConfig_();
      const spreadsheet = getSpreadsheet_(config);
      const workspace = ensureWritebackWorkspace_(spreadsheet, config.locale);
      updateDashboard_(workspace.dashboard, properties);
      spreadsheet.toast(message, 'Digitalogic', 10);
      result.catalog_refresh = { status: 'failed', message: message };
    }
  }
  return result;
}

/** Validate, send, audit, and summarize one bounded writeback request. */
function executeWriteback_(mode) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  let prepared = null;
  let audited = false;

  try {
    prepared = prepareWritebackRequest_(mode);
    const config = prepared.config;
    const properties = PropertiesService.getDocumentProperties();
    if (mode === 'apply') {
      if (properties.getProperty('DIGITALOGIC_LAST_PREVIEW_SIGNATURE') !== prepared.signature) {
        throw new Error('The selected changes changed after confirmation. Preview them again.');
      }
      if (properties.getProperty('DIGITALOGIC_LAST_PREVIEW_READY') !== 'true') {
        throw new Error('Apply is blocked until every selected row passes preview.');
      }
    }

    const response = fetchWriteback_(config, prepared.request);
    appendAuditResults_(prepared.workspace.audit, prepared, response);
    audited = true;
    if (mode === 'apply') {
      markCompletedChangeRows_(prepared.workspace.changes, prepared, response);
    }

    const ready = response.summary.conflicts === 0
      && response.summary.invalid === 0
      && response.summary.failed === 0
      && response.summary.ready + response.summary.unchanged === response.summary.received;
    const summaryText = writebackSummaryText_(response.summary);
    properties.setProperties({
      DIGITALOGIC_LAST_WRITEBACK_STATUS: mode + ':ok',
      DIGITALOGIC_LAST_WRITEBACK_AT: new Date().toISOString(),
      DIGITALOGIC_LAST_WRITEBACK_IDEMPOTENCY_KEY: response.idempotency_key,
      DIGITALOGIC_LAST_WRITEBACK_SUMMARY: summaryText,
      DIGITALOGIC_LAST_WRITEBACK_MESSAGE: response.replayed ? 'Idempotent replay; no duplicate write was performed.' : '',
      DIGITALOGIC_LAST_WRITEBACK_TRANSPORT: config.n8nWritebackBase ? 'n8n' : 'direct',
    });
    properties.setProperty('DIGITALOGIC_' + mode.toUpperCase() + '_REQUEST_COMPLETED', 'true');
    if (mode === 'preview') {
      properties.setProperties({
        DIGITALOGIC_LAST_PREVIEW_SIGNATURE: prepared.signature,
        DIGITALOGIC_LAST_PREVIEW_READY: ready ? 'true' : 'false',
      });
    } else {
      properties.setProperties({
        DIGITALOGIC_LAST_PREVIEW_SIGNATURE: '',
        DIGITALOGIC_LAST_PREVIEW_READY: 'false',
      });
    }
    updateDashboard_(prepared.workspace.dashboard, properties);
    prepared.spreadsheet.toast(summaryText, 'Digitalogic ' + mode, 8);
    return response;
  } catch (error) {
    const properties = PropertiesService.getDocumentProperties();
    const message = String(error && error.message ? error.message : error).slice(0, 500);
    properties.setProperties({
      DIGITALOGIC_LAST_WRITEBACK_STATUS: String(mode || 'preview') + ':error',
      DIGITALOGIC_LAST_WRITEBACK_AT: new Date().toISOString(),
      DIGITALOGIC_LAST_WRITEBACK_MESSAGE: message,
      DIGITALOGIC_LAST_WRITEBACK_TRANSPORT: prepared && prepared.config.n8nWritebackBase ? 'n8n' : 'direct',
    });
    if (prepared) {
      if (!audited) {
        try {
          const failure = error && error.digitalogicWritebackFailure
            ? error.digitalogicWritebackFailure
            : null;
          appendAuditResults_(
            prepared.workspace.audit,
            prepared,
            writebackFailureResponse_(prepared.request, message, failure)
          );
        } catch (auditError) {
          // Preserve the original request error; Dashboard still records it.
        }
      }
      updateDashboard_(prepared.workspace.dashboard, properties);
    }
    throw error;
  } finally {
    lock.releaseLock();
  }
}

/** Read selected rows and produce a stable, idempotent preview/apply request. */
function prepareWritebackRequest_(mode) {
  const config = getConfig_();
  validateWritebackCredentials_(config);
  const spreadsheet = getSpreadsheet_(config);
  const workspace = ensureWritebackWorkspace_(spreadsheet, config.locale);
  const entries = readExistingStructuredRows_(
    workspace.changes,
    DIGITALOGIC_CHANGE_COLUMNS,
    workspace.changesLayout
  );
  const rows = entries.map(function (entry) { return entry.object; });
  const placeholder = 'digitalogic:' + (mode || 'preview') + ':00000000';
  const draft = buildWritebackRequest_(rows, mode, placeholder, config.writebackMaxChanges);
  const signature = calculateObjectRevision_(draft.changes);
  const idempotencyKey = getOrCreateIdempotencyKey_(draft.mode, signature);
  const request = buildWritebackRequest_(rows, draft.mode, idempotencyKey, config.writebackMaxChanges);
  const selectedEntries = entries.filter(function (entry) { return isSelected_(entry.object.selected); });

  return {
    config: config,
    spreadsheet: spreadsheet,
    workspace: workspace,
    request: request,
    signature: signature,
    rowNumbers: selectedEntries.map(function (entry) { return entry.rowNumber; }),
  };
}

/** Reuse a key after an uncertain failure, but start a fresh explicit attempt after completion. */
function getOrCreateIdempotencyKey_(mode, signature) {
  const properties = PropertiesService.getDocumentProperties();
  const prefix = 'DIGITALOGIC_' + String(mode).toUpperCase() + '_REQUEST_';
  const previousSignature = properties.getProperty(prefix + 'SIGNATURE');
  const previousKey = properties.getProperty(prefix + 'KEY');
  const completed = properties.getProperty(prefix + 'COMPLETED') === 'true';
  if (previousSignature === signature
    && !completed
    && /^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(String(previousKey || ''))) {
    return previousKey;
  }

  const digestPart = signature.slice('sha256:'.length, 'sha256:'.length + 16);
  const attemptPart = Utilities.getUuid().replace(/-/g, '').slice(0, 24);
  const key = 'digitalogic:' + mode + ':' + digestPart + ':' + attemptPart;
  properties.setProperties((function () {
    const update = {};
    update[prefix + 'SIGNATURE'] = signature;
    update[prefix + 'KEY'] = key;
    update[prefix + 'COMPLETED'] = 'false';
    return update;
  }()));
  return key;
}

/** Pure request builder used by Apps Script and repository tests. */
function buildWritebackRequest_(rows, mode, idempotencyKey, maximumChanges) {
  const normalizedMode = String(mode || 'preview').toLowerCase();
  const requestKey = String(idempotencyKey || '').trim();
  const limit = Number(maximumChanges || DIGITALOGIC_WRITEBACK_MAX_LIMIT);
  if (['preview', 'apply'].indexOf(normalizedMode) < 0) {
    throw new Error('Writeback mode must be preview or apply.');
  }
  if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(requestKey)) {
    throw new Error('A valid 8-128 character idempotency key is required.');
  }
  if (!Number.isInteger(limit) || limit < 1 || limit > DIGITALOGIC_WRITEBACK_MAX_LIMIT) {
    throw new Error('Writeback row limit must be between 1 and ' + DIGITALOGIC_WRITEBACK_MAX_LIMIT + '.');
  }

  const selected = rows.filter(function (row) { return isSelected_(row.selected); });
  if (!selected.length) {
    throw new Error('Select at least one Changes row.');
  }
  if (selected.length > limit) {
    throw new Error('Selected changes exceed the bounded limit of ' + limit + ' rows.');
  }

  const seen = Object.create(null);
  const changes = selected.map(function (row) {
    if (row._hasFormula) {
      throw new Error('Changes row ' + (row._rowNumber || '?') + ' contains a formula; use literal values only.');
    }
    const syncKey = normalizeIdentifier_(row.sync_key, 'sync_key');
    const patrisCode = normalizeIdentifier_(row.patris_code, 'patris_code');
    const expectedRevision = String(row.expected_record_revision || '').trim().toLowerCase();
    if (syncKey !== patrisCode) {
      throw new Error('sync_key and patris_code must be exactly equal for ' + syncKey + '.');
    }
    if (!/^sha256:[a-f0-9]{64}$/.test(expectedRevision)) {
      throw new Error('A sha256 record revision is required for ' + syncKey + '.');
    }
    if (seen[syncKey]) {
      throw new Error('Duplicate selected sync_key: ' + syncKey);
    }
    seen[syncKey] = true;

    const fields = {};
    Object.keys(DIGITALOGIC_EDITABLE_FIELDS).forEach(function (field) {
      const normalized = normalizeEditableField_(field, row[field], DIGITALOGIC_EDITABLE_FIELDS[field]);
      if (normalized.present) {
        fields[field] = normalized.value;
      }
    });
    if (!Object.keys(fields).length) {
      throw new Error('No editable field was supplied for ' + syncKey + '.');
    }

    return {
      sync_key: syncKey,
      patris_code: patrisCode,
      expected_record_revision: expectedRevision,
      fields: fields,
    };
  });

  return { idempotency_key: requestKey, changes: changes, mode: normalizedMode };
}

/** Normalize one allowed field; blank omits it and <clear> explicitly becomes null. */
function normalizeEditableField_(field, rawValue, specification) {
  const text = typeof rawValue === 'string' ? rawValue.trim() : rawValue;
  if (text === '' || text === null || typeof text === 'undefined') {
    return { present: false };
  }
  if (typeof text === 'string' && text.toLowerCase() === '<clear>') {
    if (['sale_price', 'shipping_method_id', 'profit_percent'].indexOf(field) < 0) {
      throw new Error(field + ' cannot be cleared.');
    }
    return { present: true, value: null };
  }
  if (specification.type === 'enum') {
    const enumValue = String(text).trim().toLowerCase();
    if (specification.values.indexOf(enumValue) < 0) {
      throw new Error(field + ' has an unsupported value.');
    }
    return { present: true, value: enumValue };
  }
  if (specification.type === 'text') {
    const stringValue = String(text).trim();
    if (!stringValue
      || stringValue.length > specification.maximumLength
      || /[\u0000-\u001f\u007f]/.test(stringValue)
      || (specification.pattern && !specification.pattern.test(stringValue))) {
      throw new Error(field + ' is empty, too long, or contains control characters.');
    }
    return { present: true, value: stringValue };
  }

  if (specification.type === 'number') {
    const decimalValue = normalizeSheetDecimal_(text);
    if (decimalValue === null
      || decimalPlaces_(decimalValue) > specification.decimalPlaces
      || compareSheetDecimals_(decimalValue, specification.minimum) < 0
      || compareSheetDecimals_(decimalValue, specification.maximum) > 0) {
      throw new Error(field + ' is outside its allowed numeric range.');
    }
    return { present: true, value: decimalValue };
  }

  const numberValue = normalizeSheetNumber_(text);
  if (!Number.isFinite(numberValue)
    || (specification.type === 'integer' && !Number.isInteger(numberValue))
    || (typeof specification.minimum === 'number' && numberValue < specification.minimum)
    || (typeof specification.maximum === 'number' && numberValue > specification.maximum)) {
    throw new Error(field + ' is outside its allowed numeric range.');
  }
  return { present: true, value: numberValue };
}

/** Count canonical decimal places, including numbers represented with an exponent. */
function decimalPlaces_(value) {
  const parts = String(value).toLowerCase().split('e');
  const fraction = (parts[0].split('.')[1] || '').length;
  const exponent = parts.length > 1 ? Number(parts[1]) : 0;
  return Math.max(0, fraction - exponent);
}

/** Parse Latin, Persian, or Arabic sheet numerals without silently accepting junk. */
function normalizeSheetNumber_(value) {
  const normalized = normalizeSheetNumericText_(value);
  return normalized === null ? NaN : Number(normalized);
}

/** Preserve a validated decimal as canonical text, avoiding IEEE-754 loss. */
function normalizeSheetDecimal_(value) {
  const normalized = normalizeSheetNumericText_(value);
  if (normalized === null || !/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,6})?$/.test(normalized)) {
    return null;
  }
  const parts = normalized.split('.');
  const fraction = (parts[1] || '').replace(/0+$/, '');
  return fraction ? parts[0] + '.' + fraction : parts[0];
}

/** Normalize locale digits/grouping to an unsigned plain-decimal string. */
function normalizeSheetNumericText_(value) {
  if (typeof value === 'number' && !Number.isFinite(value)) {
    return null;
  }
  let normalized = String(value).trim()
    .replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
    .replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); })
    .replace(/٬/g, ',')
    .replace(/٫/g, '.');
  if (normalized.indexOf(',') >= 0) {
    if (!/^-?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/.test(normalized)) {
      return null;
    }
    normalized = normalized.replace(/,/g, '');
  }
  normalized = normalized.replace(/\s/g, '');
  if (!/^(?:\d+|\d*\.\d+)$/.test(normalized)) {
    return null;
  }
  return normalized;
}

/** Compare canonical unsigned decimals without converting them to Number. */
function compareSheetDecimals_(left, right) {
  const leftParts = String(left).split('.');
  const rightParts = String(right).split('.');
  if (leftParts[0].length !== rightParts[0].length) {
    return leftParts[0].length < rightParts[0].length ? -1 : 1;
  }
  if (leftParts[0] !== rightParts[0]) {
    return leftParts[0] < rightParts[0] ? -1 : 1;
  }
  const scale = Math.max((leftParts[1] || '').length, (rightParts[1] || '').length);
  const leftFraction = (leftParts[1] || '').padEnd(scale, '0');
  const rightFraction = (rightParts[1] || '').padEnd(scale, '0');
  return leftFraction === rightFraction ? 0 : (leftFraction < rightFraction ? -1 : 1);
}

/** Normalize a required, case-sensitive product identifier. */
function normalizeIdentifier_(value, label) {
  const identifier = String(restoreNeutralizedSheetText_(value) || '').trim();
  if (!identifier || identifier.length > 191 || /[\u0000-\u001f\u007f]/.test(identifier)) {
    throw new Error(label + ' is required and must be at most 191 characters.');
  }
  return identifier;
}

/** Treat only explicit checkbox-like values as selected. */
function isSelected_(value) {
  return value === true || value === 1 || String(value || '').trim().toLowerCase() === 'true';
}

/** Produce a stable digest used for preview/apply equality and idempotency. */
function calculateObjectRevision_(value) {
  const digest = Utilities.computeDigest(
    Utilities.DigestAlgorithm.SHA_256,
    JSON.stringify(value),
    Utilities.Charset.UTF_8
  );
  return 'sha256:' + digest.map(function (byte) {
    return ('0' + ((byte + 256) % 256).toString(16)).slice(-2);
  }).join('');
}

/** Require write-scope WooCommerce credentials without exposing them in cells or URLs. */
function validateWritebackCredentials_(config) {
  if (config.n8nWritebackBase) {
    if (config.n8nWritebackToken.length < 16 || config.n8nWritebackToken.length > 512) {
      throw new Error('A 16-512 character n8n Header Auth token is required in Script Properties.');
    }
    return;
  }
  if (!/^ck_[A-Za-z0-9]+$/.test(config.writebackConsumerKey)
    || !/^cs_[A-Za-z0-9]+$/.test(config.writebackConsumerSecret)) {
    throw new Error('Write-scope WooCommerce credentials are missing or malformed in Script Properties.');
  }
}

/** POST one preview/apply request and validate the typed response envelope. */
function fetchWriteback_(config, request) {
  const useN8n = Boolean(config.n8nWritebackBase);
  const headers = {
    Accept: 'application/json',
    'Idempotency-Key': request.idempotency_key,
  };
  if (useN8n) {
    headers['X-Digitalogic-Bridge-Token'] = config.n8nWritebackToken;
    if (request.mode === 'apply') {
      headers['X-Digitalogic-Confirm-Apply'] = 'APPLY';
    }
  } else {
    headers.Authorization = 'Basic ' + Utilities.base64Encode(
      config.writebackConsumerKey + ':' + config.writebackConsumerSecret
    );
  }
  const endpoint = useN8n
    ? config.n8nWritebackBase + '/' + request.mode
    : config.apiBase + DIGITALOGIC_WRITEBACK_PATH + request.mode;
  const response = UrlFetchApp.fetch(endpoint, {
    method: 'post',
    contentType: 'application/json',
    headers: headers,
    payload: JSON.stringify({
      idempotency_key: request.idempotency_key,
      changes: request.changes,
    }),
    followRedirects: false,
    muteHttpExceptions: true,
  });
  const httpStatus = response.getResponseCode();
  let payload;
  try {
    payload = JSON.parse(response.getContentText());
  } catch (error) {
    throw createWritebackHttpError_(httpStatus, null, 'non_json_response');
  }
  if (httpStatus < 200 || httpStatus >= 300 || !payload || payload.success !== true || !payload.data) {
    throw createWritebackHttpError_(httpStatus, payload, 'writeback_http_error');
  }

  return validateWritebackResponse_(payload.data, request, httpStatus);
}

/** Build a safe structured HTTP failure without retaining arbitrary payload data. */
function createWritebackHttpError_(httpStatus, payload, fallbackCode) {
  const status = Number.isInteger(Number(httpStatus)) ? Number(httpStatus) : 0;
  const rawCode = payload && typeof payload.code === 'string' ? payload.code : fallbackCode;
  const code = String(rawCode || 'writeback_http_error')
    .toLowerCase()
    .replace(/[^a-z0-9_.-]/g, '_')
    .slice(0, 96) || 'writeback_http_error';
  const details = payload && payload.details && typeof payload.details === 'object'
    && !Array.isArray(payload.details) ? payload.details : {};
  const failure = {
    code: code,
    http_status: status,
    retryable: details.retryable === true,
    may_have_applied: details.may_have_applied === true,
  };
  const error = new Error('Digitalogic writeback failed with HTTP ' + status + ' (' + code + ').');
  error.digitalogicWritebackFailure = failure;
  return error;
}

/** Validate and normalize every response field used by Audit and Dashboard. */
function validateWritebackResponse_(data, request, httpStatus) {
  if (!data || typeof data !== 'object' || Array.isArray(data)
    || data.schema !== 'digitalogic.google-sheets-writeback'
    || data.mode !== request.mode
    || data.idempotency_key !== request.idempotency_key
    || !data.summary || typeof data.summary !== 'object' || Array.isArray(data.summary)
    || !Array.isArray(data.results)) {
    throw new Error('Malformed Digitalogic writeback response.');
  }
  const summaryKeys = ['received', 'ready', 'unchanged', 'applied', 'conflicts', 'invalid', 'failed'];
  const summary = {};
  summaryKeys.forEach(function (key) {
    const value = Number(data.summary[key]);
    if (!Number.isInteger(value) || value < 0) {
      throw new Error('Malformed writeback summary field: ' + key + '.');
    }
    summary[key] = value;
  });
  if (summary.received !== request.changes.length || data.results.length !== request.changes.length) {
    throw new Error('Writeback result count does not match the request.');
  }

  const statuses = ['ready', 'unchanged', 'applied', 'conflict', 'invalid', 'failed'];
  const results = data.results.map(function (result, position) {
    if (!result || typeof result !== 'object' || Array.isArray(result)) {
      throw new Error('Malformed writeback result at index ' + position + '.');
    }
    const index = Number(result.index);
    const expected = request.changes[position];
    if (!Number.isInteger(index) || index !== position
      || result.sync_key !== expected.sync_key
      || result.patris_code !== expected.patris_code
      || statuses.indexOf(String(result.status || '')) < 0
      || !Array.isArray(result.changed_fields)) {
      throw new Error('Writeback result does not match request index ' + position + '.');
    }
    return {
      index: index,
      sync_key: result.sync_key,
      patris_code: result.patris_code,
      woocommerce_id: result.woocommerce_id === null ? null : Number(result.woocommerce_id),
      status: String(result.status),
      code: String(result.code || ''),
      message: String(result.message || '').slice(0, 500),
      changed_fields: result.changed_fields.map(String),
      before: result.before && typeof result.before === 'object' ? result.before : {},
      after: result.after && typeof result.after === 'object' ? result.after : {},
      record_revision: String(result.record_revision || ''),
      rollback: result.rollback && typeof result.rollback === 'object' ? result.rollback : null,
      audit_id: typeof result.audit_id === 'string' || typeof result.audit_id === 'number'
        ? String(result.audit_id).slice(0, 191)
        : '',
      http_status: Number(httpStatus || 200),
    };
  });

  const computedSummary = {
    received: results.length,
    ready: 0,
    unchanged: 0,
    applied: 0,
    conflicts: 0,
    invalid: 0,
    failed: 0,
  };
  results.forEach(function (result) {
    const key = result.status === 'conflict' ? 'conflicts' : result.status;
    computedSummary[key] += 1;
  });
  summaryKeys.forEach(function (key) {
    if (summary[key] !== computedSummary[key]) {
      throw new Error('Writeback summary does not match its row results.');
    }
  });

  return {
    schema: data.schema,
    mode: data.mode,
    idempotency_key: data.idempotency_key,
    replayed: data.replayed === true,
    summary: summary,
    results: results,
  };
}

/** Create typed per-row failures for transport or structural response errors. */
function writebackFailureResponse_(request, message, failure) {
  const safeFailure = failure && typeof failure === 'object' ? failure : {};
  const code = typeof safeFailure.code === 'string' && safeFailure.code
    ? safeFailure.code
    : 'transport_or_response_error';
  const httpStatus = Number.isInteger(Number(safeFailure.http_status))
    ? Number(safeFailure.http_status)
    : 0;
  const recovery = safeFailure.retryable === true || safeFailure.may_have_applied === true
    ? {
      available: false,
      retryable: safeFailure.retryable === true,
      may_have_applied: safeFailure.may_have_applied === true,
      upstream_code: code,
      http_status: httpStatus,
    }
    : null;
  return {
    schema: 'digitalogic.google-sheets-writeback',
    mode: request.mode,
    idempotency_key: request.idempotency_key,
    replayed: false,
    summary: {
      received: request.changes.length,
      ready: 0,
      unchanged: 0,
      applied: 0,
      conflicts: 0,
      invalid: 0,
      failed: request.changes.length,
    },
    results: request.changes.map(function (change, index) {
      return {
        index: index,
        sync_key: change.sync_key,
        patris_code: change.patris_code,
        woocommerce_id: null,
        status: 'failed',
        code: code,
        message: message,
        changed_fields: Object.keys(change.fields),
        before: {},
        after: {},
        record_revision: change.expected_record_revision,
        rollback: recovery,
        audit_id: '',
        http_status: httpStatus,
      };
    }),
  };
}

/** Append one typed Audit row per server result. */
function appendAuditResults_(sheet, prepared, response) {
  const timestamp = new Date();
  const rows = auditRowsFromResponse_(prepared.request, response, prepared.rowNumbers, timestamp);
  const startRow = Math.max(
    sheet.getLastRow() + 1,
    prepared.workspace.auditLayout.dataStartRow
  );
  ensureGridSize_(sheet, startRow + rows.length - 1, DIGITALOGIC_AUDIT_COLUMNS.length);
  sheet.getRange(startRow, 1, rows.length, DIGITALOGIC_AUDIT_COLUMNS.length).setValues(rows);
}

/** Pure Audit-row renderer used by Apps Script and repository tests. */
function auditRowsFromResponse_(request, response, rowNumbers, timestamp) {
  return response.results.map(function (result, index) {
    const requested = request.changes[index];
    return [
      timestamp,
      response.mode,
      response.idempotency_key,
      Number(rowNumbers[index] || 0),
      result.sync_key,
      result.status,
      result.code,
      result.message,
      requested.expected_record_revision,
      result.record_revision,
      result.changed_fields.join(', '),
      result.http_status,
      boundedJson_(result.before, 2000),
      boundedJson_(result.after, 2000),
      boundedJson_(result.rollback, 2000),
      result.audit_id || '',
    ].map(neutralizeSheetText_);
  });
}

/** Serialize an audit value deterministically and cap its cell size. */
function boundedJson_(value, maximumLength) {
  if (value === null || typeof value === 'undefined') {
    return '';
  }
  let encoded;
  try {
    encoded = JSON.stringify(value);
  } catch (error) {
    encoded = JSON.stringify({ status: 'unserializable' });
  }
  if (typeof encoded !== 'string') {
    return '';
  }
  return encoded.length <= maximumLength
    ? encoded
    : encoded.slice(0, Math.max(0, maximumLength - 1)) + '…';
}

/** Unselect successfully applied/no-op rows and advance their revision for safe restaging. */
function markCompletedChangeRows_(sheet, prepared, response) {
  response.results.forEach(function (result, index) {
    if (['applied', 'unchanged'].indexOf(result.status) < 0) {
      return;
    }
    const rowNumber = prepared.rowNumbers[index];
    sheet.getRange(rowNumber, 1).setValue(false);
    if (/^sha256:[a-f0-9]{64}$/i.test(result.record_revision)) {
      sheet.getRange(rowNumber, 4).setValue(result.record_revision);
    }
  });
}

/** Convert typed summary counts into a concise dashboard message. */
function writebackSummaryText_(summary) {
  return [
    'received=' + summary.received,
    'ready=' + summary.ready,
    'unchanged=' + summary.unchanged,
    'applied=' + summary.applied,
    'conflicts=' + summary.conflicts,
    'invalid=' + summary.invalid,
    'failed=' + summary.failed,
  ].join(', ');
}

/** Return one of two supplied messages. */
function localize_(locale, english, persian) {
  return locale === 'fa' ? persian : english;
}

// Node-based repository tests load only the pure helpers below.
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    auditRowsFromResponse_: auditRowsFromResponse_,
    boundedJson_: boundedJson_,
    buildWritebackRequest_: buildWritebackRequest_,
    createWritebackHttpError_: createWritebackHttpError_,
    detectStructuredLayout_: detectStructuredLayout_,
    ensureDashboardSheet_: ensureDashboardSheet_,
    findFirstBlankStructuredRow_: findFirstBlankStructuredRow_,
    getSpreadsheet_: getSpreadsheet_,
    getStateProperties_: getStateProperties_,
    getOrCreateIdempotencyKey_: getOrCreateIdempotencyKey_,
    isSelected_: isSelected_,
    mergeRows_: mergeRows_,
    normalizeApiBase_: normalizeApiBase_,
    normalizeEditableField_: normalizeEditableField_,
    normalizeSheetDecimal_: normalizeSheetDecimal_,
    neutralizeSheetText_: neutralizeSheetText_,
    normalizeSheetNumber_: normalizeSheetNumber_,
    normalizeWebhookBase_: normalizeWebhookBase_,
    readExistingStructuredRows_: readExistingStructuredRows_,
    restrictProtectionToOperator_: restrictProtectionToOperator_,
    restoreNeutralizedSheetText_: restoreNeutralizedSheetText_,
    rowToSheetValues_: rowToSheetValues_,
    syncCatalog: syncCatalog,
    validateCatalogPage_: validateCatalogPage_,
    validateWritebackResponse_: validateWritebackResponse_,
    updateDashboard_: updateDashboard_,
    writebackFailureResponse_: writebackFailureResponse_,
    writebackSummaryText_: writebackSummaryText_,
  };
}
