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
}

/** Pull both canonical datasets and upsert the Products and Categories tabs. */
function syncCatalog() {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);

  try {
    const config = getConfig_();
    const spreadsheet = getSpreadsheet_(config);
    const fetched = DIGITALOGIC_DATASETS.map(function (dataset) {
      return fetchDataset_(config, dataset);
    });
    const revision = calculateRevision_(fetched);
    const documentProperties = PropertiesService.getDocumentProperties();
    const previousRevision = documentProperties.getProperty('DIGITALOGIC_CATALOG_REVISION');

    if (previousRevision === revision) {
      documentProperties.setProperty('DIGITALOGIC_LAST_SYNC_AT', new Date().toISOString());
      spreadsheet.toast(localize_(config.locale, 'Catalog is already current.', 'فهرست محصولات به‌روز است.'), 'Digitalogic', 5);
      return { status: 'unchanged', revision: revision };
    }

    fetched.forEach(function (dataset) {
      upsertDataset_(spreadsheet, dataset, config.locale);
    });

    documentProperties.setProperties({
      DIGITALOGIC_CATALOG_REVISION: revision,
      DIGITALOGIC_LAST_SYNC_AT: new Date().toISOString(),
      DIGITALOGIC_LAST_SYNC_STATUS: 'ok',
      DIGITALOGIC_LAST_SYNC_ERROR: '',
    });
    spreadsheet.toast(localize_(config.locale, 'Products and categories synchronized.', 'محصولات و دسته‌بندی‌ها همگام شدند.'), 'Digitalogic', 7);

    return { status: 'updated', revision: revision };
  } catch (error) {
    PropertiesService.getDocumentProperties().setProperties({
      DIGITALOGIC_LAST_SYNC_AT: new Date().toISOString(),
      DIGITALOGIC_LAST_SYNC_STATUS: 'error',
      DIGITALOGIC_LAST_SYNC_ERROR: String(error && error.message ? error.message : error).slice(0, 500),
    });
    throw error;
  } finally {
    lock.releaseLock();
  }
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

/** Resolve a bound spreadsheet or an explicitly configured destination. */
function getSpreadsheet_(config) {
  if (config.spreadsheetId) {
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
    const response = fetchPage_(config, dataset.id, page);
    if (response.schema !== 'digitalogic.google-sheets-catalog' || Number(response.schema_version) !== 1) {
      throw new Error('Unsupported Digitalogic catalog schema.');
    }
    if (response.dataset !== dataset.id || !Array.isArray(response.columns) || !Array.isArray(response.rows)) {
      throw new Error('Malformed ' + dataset.id + ' catalog response.');
    }

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
    throw new Error(dataset.sheetName + ' schema must start with sync_key.');
  }
  const incomingRows = dataset.rows.map(function (row) {
    return keys.map(function (key) {
      return Object.prototype.hasOwnProperty.call(row, key) && row[key] !== null ? row[key] : '';
    });
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
  sheet.getRange(1, 1, 1, keys.length).setValues([keys]);
  sheet.getRange(2, 1, 1, keys.length).setValues([
    dataset.columns.map(function (column) { return column.header || column.label_en || column.key; }),
  ]);
  if (rows.length) {
    sheet.getRange(3, 1, rows.length, keys.length).setValues(rows);
  }

  styleDataset_(sheet, dataset, locale, rows.length);
}

/** Read managed rows only when the existing machine schema matches exactly. */
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

/**
 * Pure key-based upsert helper: preserve current order, update matches, append
 * new records, reject duplicate incoming keys, and omit stale managed records.
 */
function mergeRows_(existingRows, incomingRows, keyIndex) {
  const incomingByKey = Object.create(null);
  const incomingOrder = [];
  incomingRows.forEach(function (row) {
    const key = String(row[keyIndex] || '');
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
    const key = String(row[keyIndex] || '');
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
}

/** Return one of two supplied messages. */
function localize_(locale, english, persian) {
  return locale === 'fa' ? persian : english;
}

// Node-based repository tests load only the pure helpers below.
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    mergeRows_: mergeRows_,
    normalizeApiBase_: normalizeApiBase_,
  };
}
