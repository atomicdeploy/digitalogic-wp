/**
 * Professional workbook presentation for the Digitalogic / Patris control center.
 *
 * This file intentionally contains no credentials. Run
 * initializeDigitalogicControlCenter() after the required Script Properties have
 * been configured. The function is idempotent and preserves the managed catalog,
 * editable change queue, and audit contracts implemented by Code.gs.
 */

const DIGITALOGIC_DASHBOARD_COLORS = Object.freeze({
  navy: '#0f172a',
  navyLight: '#1e293b',
  blue: '#0284c7',
  teal: '#0f766e',
  green: '#16a34a',
  greenSoft: '#dcfce7',
  amber: '#d97706',
  amberSoft: '#fef3c7',
  red: '#dc2626',
  redSoft: '#fee2e2',
  cyanSoft: '#e0f2fe',
  slate: '#475569',
  gray: '#e2e8f0',
  graySoft: '#f1f5f9',
  white: '#ffffff',
});

/** Build, synchronize, protect, and schedule the complete workbook. */
function initializeDigitalogicControlCenter() {
  syncCatalog();
  buildProfessionalDashboard();
  setupEditableWorkspace();
  installScheduledSync();

  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  const dashboard = spreadsheet.getSheetByName('Dashboard');
  const state = getStateProperties_(getConfig_());
  updateDashboard_(dashboard, state);
  spreadsheet.setActiveSheet(dashboard);
  spreadsheet.toast(
    'Control center is live. Use the Changes queue to preview and apply reviewed edits.',
    'Digitalogic & Patris',
    10
  );
  return {
    status: 'ready',
    products: Math.max((spreadsheet.getSheetByName('Products') || dashboard).getLastRow() - 2, 0),
    categories: Math.max((spreadsheet.getSheetByName('Categories') || dashboard).getLastRow() - 2, 0),
  };
}

/** Rebuild only the designed, user-facing workbook tabs. */
function buildProfessionalDashboard() {
  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  const dashboard = digitalogicPrepareDesignedSheet_(spreadsheet, 'Dashboard', 50, 16, '#00acc1');
  const calculator = digitalogicPrepareDesignedSheet_(spreadsheet, 'Pricing Calculator', 24, 10, '#16a34a');
  const settings = digitalogicPrepareDesignedSheet_(spreadsheet, 'Settings', 28, 8, '#64748b');
  const help = digitalogicPrepareDesignedSheet_(spreadsheet, 'Help', 24, 8, '#7c3aed');

  digitalogicBuildDashboard_(dashboard);
  digitalogicBuildCalculator_(calculator);
  digitalogicBuildSettings_(settings);
  digitalogicBuildHelp_(help);
  const placeholder = spreadsheet.getSheetByName('Sheet1');
  if (placeholder
    && spreadsheet.getSheets().length > 1
    && placeholder.getLastRow() <= 1
    && placeholder.getLastColumn() <= 1
    && placeholder.getRange('A1').isBlank()) {
    spreadsheet.deleteSheet(placeholder);
  }
  SpreadsheetApp.flush();
  return true;
}

function digitalogicPrepareDesignedSheet_(spreadsheet, name, minimumRows, minimumColumns, tabColor) {
  let sheet = spreadsheet.getSheetByName(name);
  if (!sheet) {
    sheet = spreadsheet.insertSheet(name);
  }
  if (sheet.getMaxRows() < minimumRows) {
    sheet.insertRowsAfter(sheet.getMaxRows(), minimumRows - sheet.getMaxRows());
  }
  if (sheet.getMaxColumns() < minimumColumns) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), minimumColumns - sheet.getMaxColumns());
  }
  sheet.getCharts().forEach(function (chart) { sheet.removeChart(chart); });
  sheet.getRange(1, 1, sheet.getMaxRows(), sheet.getMaxColumns()).breakApart();
  sheet.clear();
  sheet.setConditionalFormatRules([]);
  sheet.setHiddenGridlines(true);
  sheet.setTabColor(tabColor);
  sheet.setFrozenRows(0);
  sheet.setFrozenColumns(0);
  return sheet;
}

function digitalogicTitle_(sheet, lastColumn, title, subtitle) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  sheet.getRange(1, 1, 2, lastColumn).merge()
    .setValue(title)
    .setBackground(colors.navy)
    .setFontColor(colors.white)
    .setFontFamily('Arial')
    .setFontSize(20)
    .setFontWeight('bold')
    .setHorizontalAlignment('left')
    .setVerticalAlignment('middle');
  sheet.setRowHeights(1, 2, 28);
  sheet.getRange(3, 1, 1, lastColumn).merge()
    .setValue(subtitle)
    .setBackground(colors.graySoft)
    .setFontColor(colors.slate)
    .setFontSize(10)
    .setFontStyle('italic')
    .setVerticalAlignment('middle');
  sheet.setRowHeight(3, 26);
}

function digitalogicSection_(sheet, a1, label, fill) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  sheet.getRange(a1).merge()
    .setValue(label)
    .setBackground(fill || colors.teal)
    .setFontColor(colors.white)
    .setFontWeight('bold')
    .setFontSize(11)
    .setVerticalAlignment('middle');
}

function digitalogicHeader_(range, fill) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  range.setBackground(fill || colors.navyLight)
    .setFontColor(colors.white)
    .setFontWeight('bold')
    .setFontSize(10)
    .setHorizontalAlignment('center')
    .setVerticalAlignment('middle')
    .setWrap(true)
    .setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);
}

function digitalogicKpi_(sheet, titleA1, valueA1, title, formula, accent) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  sheet.getRange(titleA1).merge()
    .setValue(title)
    .setBackground(colors.graySoft)
    .setFontColor(colors.slate)
    .setFontWeight('bold')
    .setFontSize(9)
    .setHorizontalAlignment('center')
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, false, false, colors.gray, SpreadsheetApp.BorderStyle.SOLID);
  sheet.getRange(valueA1).merge()
    .setFormula(formula)
    .setBackground(colors.white)
    .setFontColor(accent)
    .setFontWeight('bold')
    .setFontSize(20)
    .setHorizontalAlignment('center')
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, false, false, colors.gray, SpreadsheetApp.BorderStyle.SOLID);
}

function digitalogicBuildDashboard_(sheet) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  digitalogicTitle_(
    sheet,
    16,
    'DIGITALOGIC | PRODUCT & PRICING CONTROL CENTER',
    'Patris Export + Digitalogic WordPress + Google Sheets + n8n | مرکز مدیریت کالا، قیمت و موجودی'
  );

  digitalogicKpi_(sheet, 'A5:D5', 'A6:D8', 'TOTAL PRODUCTS | کل کالاها', '=COUNTA(Products!$A$3:$A)', colors.blue);
  digitalogicKpi_(sheet, 'E5:H5', 'E6:H8', 'PRICED PRODUCTS | کالاهای قیمت‌دار', '=COUNTIFS(Products!$A$3:$A,"<>",Products!$O$3:$O,">0")', colors.teal);
  digitalogicKpi_(sheet, 'I5:L5', 'I6:L8', 'AVG EFFECTIVE PRICE | میانگین قیمت', '=IFERROR(AVERAGEIF(Products!$O$3:$O,">0"),0)', colors.green);
  digitalogicKpi_(sheet, 'M5:P5', 'M6:P8', 'MISSING PATRIS CODE | کد پاتریس ناقص', '=COUNTIFS(Products!$A$3:$A,"<>",Products!$B$3:$B,"")', colors.amber);
  sheet.getRange('I6:L8').setNumberFormat('#,##0 "IRT"');

  digitalogicSection_(sheet, 'A10:D10', 'CATALOG HEALTH | سلامت فهرست', colors.blue);
  sheet.getRange('A11:B16').setValues([
    ['Metric', 'Count'],
    ['Priced products', ''],
    ['Missing Patris code', ''],
    ['Draft products', ''],
    ['Published products', ''],
    ['Catalog errors', ''],
  ]);
  digitalogicHeader_(sheet.getRange('A11:B11'));
  sheet.getRange('B12:B16').setFormulas([
    ['=COUNTIFS(Products!$A$3:$A,"<>",Products!$O$3:$O,">0")'],
    ['=COUNTIFS(Products!$A$3:$A,"<>",Products!$B$3:$B,"")'],
    ['=COUNTIF(Products!$F$3:$F,"draft")'],
    ['=COUNTIF(Products!$F$3:$F,"publish")'],
    ['=COUNTIFS(Products!$A$3:$A,"<>",Products!$AL$3:$AL,"<>")'],
  ]).setNumberFormat('#,##0');
  sheet.getRange('A11:B16').setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);

  digitalogicSection_(sheet, 'E10:H10', 'STOCK POSITION | وضعیت موجودی', colors.teal);
  sheet.getRange('E11:F15').setValues([
    ['Metric', 'Count'],
    ['In stock', ''],
    ['Out of stock', ''],
    ['On backorder', ''],
    ['Patris total stock', ''],
  ]);
  digitalogicHeader_(sheet.getRange('E11:F11'));
  sheet.getRange('F12:F15').setFormulas([
    ['=COUNTIF(Products!$S$3:$S,"instock")'],
    ['=COUNTIF(Products!$S$3:$S,"outofstock")'],
    ['=COUNTIF(Products!$S$3:$S,"onbackorder")'],
    ['=SUM(Products!$T$3:$T)'],
  ]).setNumberFormat('#,##0');
  sheet.getRange('E11:F15').setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);

  digitalogicSection_(sheet, 'J10:P10', 'LIVE WORKFLOW STATUS | وضعیت گردش کار', colors.navyLight);
  sheet.getRange('J11:P17').setValues([
    ['Component', 'Status / detail', 'Next action', '', '', '', ''],
    ['Google owner', 'mahdielector@gmail.com', 'Owner-editable workbook; credentials are not stored in cells', '', '', '', ''],
    ['Catalog sync', 'not run', 'not run', '', '', '', ''],
    ['Writeback', 'not run', 'not run', '', '', '', ''],
    ['Transport', 'not configured', 'No secrets in this workbook', '', '', '', ''],
    ['Patris identity', 'Exact Code', 'SKU is display-only and never used as fallback identity', '', '', '', ''],
    ['Owner review', 'Pending', 'Merged and deployed work remains open for owner review', '', '', '', ''],
  ]);
  digitalogicHeader_(sheet.getRange('J11:P11'));
  sheet.getRange('J12:P17')
    .setBackground(colors.graySoft)
    .setFontColor(colors.navyLight)
    .setFontSize(9)
    .setWrap(true)
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);

  sheet.setRowHeights(11, 7, 34);
  sheet.setRowHeight(11, 28);
  sheet.getRange('A11:F16').setFontSize(9);

  const healthChart = sheet.newChart()
    .asPieChart()
    .addRange(sheet.getRange('A11:B16'))
    .setPosition(20, 1, 0, 0)
    .setOption('title', 'Catalog quality gates')
    .setOption('pieHole', 0.55)
    .setOption('legend.position', 'right')
    .setOption('backgroundColor', colors.white)
    .setOption('colors', [colors.green, colors.amber, colors.slate, colors.blue, colors.red])
    .build();
  sheet.insertChart(healthChart);

  const stockChart = sheet.newChart()
    .asColumnChart()
    .addRange(sheet.getRange('E11:F14'))
    .setPosition(20, 9, 0, 0)
    .setOption('title', 'Current stock status')
    .setOption('legend.position', 'none')
    .setOption('backgroundColor', colors.white)
    .setOption('colors', [colors.teal])
    .build();
  sheet.insertChart(stockChart);

  digitalogicSection_(sheet, 'A38:P38', 'SAFE DAILY WORKFLOW | روند امن روزانه', colors.blue);
  sheet.getRange('A39:P45').merge()
    .setValue(
      '1. Refresh Products and Categories from Digitalogic Sync.   2. Review catalog and stock KPIs.   ' +
      '3. Select exact Product rows and stage them into Changes.   4. Edit only allowlisted fields and tick Selected.   ' +
      '5. Preview the revision-checked diff through n8n.   6. Apply only after review, then verify Audit and refresh.\n\n' +
      'Products and Categories are protected reference snapshots. Changes, Pricing Calculator, and non-secret Settings remain editable. ' +
      'Incomplete products remain draft; the workflow never mass-publishes them.'
    )
    .setBackground(colors.cyanSoft)
    .setFontColor('#075985')
    .setFontSize(11)
    .setWrap(true)
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, false, false, colors.blue, SpreadsheetApp.BorderStyle.SOLID);

  sheet.setColumnWidths(1, 16, 96);
  sheet.setColumnWidths(1, 4, 105);
  sheet.setColumnWidths(5, 4, 115);
  sheet.setColumnWidths(9, 8, 98);
  sheet.setFrozenRows(3);
}

function digitalogicBuildCalculator_(sheet) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  digitalogicTitle_(
    sheet,
    10,
    'DIGITALOGIC | LANDED PRICE CALCULATOR',
    'Auditable Patris pricing scenario | محاسبه قیمت نهایی با ورودی‌های شفاف و قابل بررسی'
  );
  digitalogicSection_(sheet, 'A5:J5', 'SCENARIO INPUTS | ورودی‌های سناریو', colors.green);
  sheet.getRange('A6:B15').setValues([
    ['Input', 'Value'],
    ['Sync Key', ''],
    ['Product', ''],
    ['Weight (g)', ''],
    ['Foreign price (CNY)', ''],
    ['Shipping / kg', ''],
    ['Shipping currency', ''],
    ['Profit margin', ''],
    ['CNY to IRT', ''],
    ['Calculated final price', ''],
  ]);
  digitalogicHeader_(sheet.getRange('A6:B6'));
  sheet.getRange('B8').setFormula('=IFERROR(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("name",Products!$1:$1,0)),"")');
  sheet.getRange('B9').setFormula('=IFERROR(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("weight_grams",Products!$1:$1,0)),"")');
  sheet.getRange('B10').setFormula('=IFERROR(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("foreign_price",Products!$1:$1,0)),"")');
  sheet.getRange('B11').setFormula('=IFERROR(IF(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("shipping_price_per_kg",Products!$1:$1,0))="",Settings!$B$8,INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("shipping_price_per_kg",Products!$1:$1,0))),Settings!$B$8)');
  sheet.getRange('B12').setFormula('=IFERROR(IF(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("shipping_price_per_kg_currency",Products!$1:$1,0))="",Settings!$B$9,UPPER(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("shipping_price_per_kg_currency",Products!$1:$1,0)))),Settings!$B$9)');
  sheet.getRange('B13').setFormula('=IFERROR(IF(INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("profit_percent",Products!$1:$1,0))="",Settings!$B$10,INDEX(Products!$A:$ZZ,MATCH($B$7,Products!$A:$A,0),MATCH("profit_percent",Products!$1:$1,0))/100),Settings!$B$10)');
  sheet.getRange('B14').setFormula('=Settings!$B$7');
  sheet.getRange('B15').setFormula('=IF(OR(B9<=0,B10<=0,B11<=0,B13<0,B14<=0,AND(B12<>"CNY",B12<>"IRR")),"INPUT REQUIRED",ROUND((B10*B14+(B9/1000)*IF(B12="CNY",B11*B14,B11/10))*(1+B13),0))');
  sheet.getRange('B7:B12').setNumberFormat('@');
  sheet.getRange('B9:B11').setNumberFormat('#,##0.########');
  sheet.getRange('B13').setNumberFormat('0.0%');
  sheet.getRange('B14:B15').setNumberFormat('#,##0 "IRT"');
  sheet.getRange('B15')
    .setBackground(colors.greenSoft)
    .setFontColor(colors.green)
    .setFontSize(16)
    .setFontWeight('bold')
    .setHorizontalAlignment('center')
    .setBorder(true, true, true, true, false, false, colors.green, SpreadsheetApp.BorderStyle.SOLID_MEDIUM);
  sheet.getRange('D6:J15').merge()
    .setValue(
      'FORMULA\n\nROUND((Foreign price × CNY→IRT + Weight kg × freight in IRT) × (1 + Profit %), 0)\n\n' +
      'CNY freight is converted by the FX rate. IRR freight is divided by 10 to reach IRT. ' +
      'One final rounding step is applied after freight and profit. Missing or invalid inputs return INPUT REQUIRED.'
    )
    .setBackground(colors.cyanSoft)
    .setFontColor('#075985')
    .setFontSize(11)
    .setWrap(true)
    .setHorizontalAlignment('center')
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, false, false, colors.blue, SpreadsheetApp.BorderStyle.SOLID);
  sheet.setColumnWidth(1, 210);
  sheet.setColumnWidth(2, 230);
  sheet.setColumnWidth(3, 24);
  sheet.setColumnWidths(4, 7, 100);
  sheet.setFrozenRows(5);
}

function digitalogicBuildSettings_(sheet) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  digitalogicTitle_(
    sheet,
    8,
    'DIGITALOGIC | CONTROL CENTER SETTINGS',
    'Editable business assumptions only | اطلاعات محرمانه فقط در Script Properties و n8n Credentials'
  );
  digitalogicSection_(sheet, 'A5:H5', 'LIVE CONNECTIONS & BUSINESS ASSUMPTIONS | تنظیمات و اتصال‌ها', colors.slate);
  sheet.getRange('A6:B14').setValues([
    ['Setting', 'Value'],
    ['CNY to IRT (editable)', 25300],
    ['Scenario shipping / kg', ''],
    ['Scenario shipping currency', 'CNY'],
    ['Scenario profit margin', 0.30],
    ['Sync locale (managed)', 'bilingual'],
    ['Schedule (managed)', 'Every 6 hours'],
    ['Writeback policy', 'Preview then explicit Apply'],
    ['Publishing policy', 'Quality-gated; never automatic'],
  ]);
  digitalogicHeader_(sheet.getRange('A6:B6'));
  sheet.getRange('B7:B8').setNumberFormat('#,##0.########');
  sheet.getRange('B9').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(['CNY', 'IRR'], true).setAllowInvalid(false).build());
  sheet.getRange('B10').setNumberFormat('0.0%');
  sheet.getRange('D6:H13').setValues([
    ['Connection', 'Endpoint / identity', 'Mode', 'Owner', 'Status'],
    ['Digitalogic WordPress', 'https://digitalogic.ir', 'Living catalog', 'Digitalogic', 'Connected'],
    ['Catalog API', '/google-sheets/catalog', 'Read', 'WordPress', 'Live'],
    ['Writeback API', 'n8n → guarded WordPress endpoint', 'Preview / Apply', 'Digitalogic', 'Guarded'],
    ['Patris Export', 'Exact Code identity', 'Source + pricing', 'Patris', 'Connected'],
    ['n8n', 'https://automation.digitalogic.ir', 'Approval bridge', 'Digitalogic', 'Active'],
    ['Google owner', 'mahdielector@gmail.com', 'Editor', 'Mahdi Shokri', 'Signed in'],
    ['Secrets', 'Never stored in cells', 'Protected', 'Apps Script / n8n', 'Configured'],
  ]);
  digitalogicHeader_(sheet.getRange('D6:H6'));
  sheet.getRange('D7:H13')
    .setFontSize(9)
    .setWrap(true)
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);
  sheet.getRange('A16:H20').merge()
    .setValue(
      'Safety rule: Products and Categories are synchronized reference snapshots. Stage exact Products rows into Changes, ' +
      'edit only allowlisted fields, tick Selected, run Preview, review the diff, then explicitly Apply. ' +
      'Shipping assignment never implies catalog readiness, and incomplete products remain draft.'
    )
    .setBackground(colors.amberSoft)
    .setFontColor('#92400e')
    .setFontWeight('bold')
    .setWrap(true)
    .setVerticalAlignment('middle')
    .setBorder(true, true, true, true, false, false, colors.amber, SpreadsheetApp.BorderStyle.SOLID);
  sheet.setColumnWidth(1, 220);
  sheet.setColumnWidth(2, 190);
  sheet.setColumnWidth(3, 24);
  sheet.setColumnWidth(4, 170);
  sheet.setColumnWidth(5, 300);
  sheet.setColumnWidths(6, 3, 130);
  sheet.setFrozenRows(5);
}

function digitalogicBuildHelp_(sheet) {
  const colors = DIGITALOGIC_DASHBOARD_COLORS;
  digitalogicTitle_(
    sheet,
    8,
    'DIGITALOGIC | OPERATING GUIDE',
    'Safe daily operation, recovery, identity, and pricing reference | راهنمای بهره‌برداری'
  );
  const rows = [
    ['Topic', 'Guidance'],
    ['1. Refresh', 'Use Digitalogic Sync → Sync now. Products and Categories are replaced from the living sparse catalog and remain protected reference tabs.'],
    ['2. Propose', 'Select exact rows in Products, use Digitalogic Changes → Stage selected Products, and edit only allowlisted fields in Changes.'],
    ['3. Review', 'Tick Selected and run Preview selected changes. Check identity, current and proposed values, warnings, and expected record revision.'],
    ['4. Apply', 'Run Apply previewed changes only after review and confirm the dialog. The request is bounded, idempotent, revision-checked, and audited.'],
    ['5. Verify', 'Read Audit, refresh Products, and confirm the new record revision. On conflict, refresh and create a new proposal instead of forcing it.'],
    ['6. Recover', 'If Google authorization expires, run syncCatalog and approve it again. Reinstall a stale trigger with removeScheduledSync then installScheduledSync.'],
    ['Identity', 'Patris matching uses exact, case-sensitive Code. A woo:<id> display key keeps unmatched WooCommerce rows visible; SKU is never fallback identity.'],
    ['Pricing', 'Final price combines foreign price, weight-based freight, FX conversion, and profit, then applies one final rounding step. Missing inputs stay visibly blocked.'],
    ['Publishing', 'Shipping assignment does not mean readiness. Missing Code, weight, price, images, descriptions, categories, or variation review remains a stop condition.'],
    ['Support', 'Digitalogic: https://digitalogic.ir  |  Automation: https://automation.digitalogic.ir  |  GitHub issue: atomicdeploy/digitalogic-wp#99'],
  ];
  digitalogicSection_(sheet, 'A5:H5', 'QUICK REFERENCE | راهنمای سریع', colors.blue);
  sheet.getRange(6, 1, rows.length, 2).setValues(rows);
  digitalogicHeader_(sheet.getRange('A6:B6'));
  sheet.getRange(7, 1, rows.length - 1, 2)
    .setFontColor(colors.navyLight)
    .setFontSize(10)
    .setWrap(true)
    .setVerticalAlignment('top')
    .setBorder(true, true, true, true, true, true, colors.gray, SpreadsheetApp.BorderStyle.SOLID);
  sheet.getRange(7, 1, rows.length - 1, 1).setFontWeight('bold').setBackground(colors.graySoft);
  sheet.setColumnWidth(1, 150);
  sheet.setColumnWidth(2, 760);
  sheet.setRowHeights(7, rows.length - 1, 52);
  sheet.setFrozenRows(6);
}
