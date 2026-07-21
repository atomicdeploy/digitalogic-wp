'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const productQuery = require('../assets/js/product-query.js');

test('builds a bounded server query from persisted grid state', () => {
    const filters = {
        sku: 'SKU-42',
        regular_price: {min: '100', max: '200'},
        empty: ''
    };
    const payload = productQuery.buildPayload({
        page: 4,
        pageSize: 500,
        search: '  raspberry  ',
        filters,
        image: 'without',
        sorts: [
            {field: 'regular_price', direction: 'asc'},
            {field: 'name', direction: 'desc'}
        ]
    });

    assert.deepEqual(payload, {
        page: 4,
        limit: 100,
        search: 'raspberry',
        filters: {
            sku: 'SKU-42',
            regular_price: {min: '100', max: '200'}
        },
        image: 'without',
        sorts: [{field: 'regular_price', direction: 'asc'}]
    });
    assert.deepEqual(filters.regular_price, {min: '100', max: '200'});
});

test('autosave reconciliation preserves edits made while a request is in flight', () => {
    const remaining = productQuery.reconcileEdits(
        {regular_price: '125', sale_price: '90', sku: 'NEW-SKU'},
        {regular_price: '100', sale_price: '90'}
    );

    assert.deepEqual(remaining, {regular_price: '125', sku: 'NEW-SKU'});
});

test('pending manual edits survive a server-page reload', () => {
    const rows = productQuery.applyPendingEdits(
        [{id: 42, sku: 'SERVER-SKU'}, {id: 43, sku: 'UNCHANGED'}],
        {42: {sku: 'LOCAL-SKU', regular_price: '125'}}
    );

    assert.deepEqual(rows, [
        {id: 42, sku: 'LOCAL-SKU', regular_price: '125'},
        {id: 43, sku: 'UNCHANGED'}
    ]);
});

test('page window stays bounded around the current page', () => {
    assert.deepEqual(productQuery.pageWindow(6, 12, 2), [4, 5, 6, 7, 8]);
    assert.deepEqual(productQuery.pageWindow(1, 2, 2), [1, 2]);
    assert.deepEqual(productQuery.pageWindow(1, 0, 2), []);
});

test('persisted column layouts receive new defaults next to their logical neighbors', () => {
    const defaults = [
        {key: 'name', width: 340, visible: true, editable: true},
        {key: 'regular_price', width: 132, visible: true, editable: true},
        {key: 'sale_price', width: 132, visible: true, editable: true},
        {key: 'min_price', width: 132, visible: true, editable: false},
        {key: 'max_price', width: 132, visible: true, editable: false},
        {key: 'stock_quantity', width: 104, visible: true, editable: true}
    ];
    const saved = [
        {key: 'name', width: 420, visible: true},
        {key: 'regular_price', width: 160, visible: false},
        {key: 'sale_price', width: 170, visible: true},
        {key: 'stock_quantity', width: 110, visible: true}
    ];

    const merged = productQuery.mergeColumns(saved, defaults);

    assert.deepEqual(merged.map((column) => column.key), [
        'name', 'regular_price', 'sale_price', 'min_price', 'max_price', 'stock_quantity'
    ]);
    assert.equal(merged[0].width, 420);
    assert.equal(merged[1].visible, false);
    assert.equal(merged[3].editable, false);
    assert.equal(merged[4].editable, false);
});

test('panel uses the shared server query and a persisted safe edit lock', () => {
    const panelSource = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8');
    const managerSource = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-product-manager.php'), 'utf8');
    const viewSource = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'views', 'app.php'), 'utf8');
    const panelPhpSource = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'class-panel.php'), 'utf8');
    const panelCss = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'panel.css'), 'utf8');
    const adminCss = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'admin.css'), 'utf8');

    assert.doesNotMatch(panelSource, /limit:\s*1000/);
    assert.doesNotMatch(panelSource, /productMatchesFilters/);
    assert.match(panelSource, /digitalogic_panel_product_edit_mode/);
    assert.match(panelSource, /productQuery\.buildPayload/);
    assert.match(panelSource, /productQuery\.reconcileEdits/);
    assert.match(panelSource, /productQuery\.applyPendingEdits/);
    assert.match(panelSource, /key: 'min_price',[^\n]+sortable: false, editable: false, numeric: true, filter: false/);
    assert.match(panelSource, /key: 'max_price',[^\n]+sortable: false, editable: false, numeric: true, filter: false/);
    assert.match(panelSource, /key: 'regular_price',[^\n]+editable: true/);
    assert.match(panelSource, /key: 'sale_price',[^\n]+editable: true/);
    assert.match(panelSource, /key: 'weight',[^\n]+editable: true/);
    assert.match(panelSource, /digitalogic_update_product/);
    assert.match(managerSource, /-1 === intval\(\s*\$args\['limit'\]\s*\)/);
    assert.match(managerSource, /\$batch_args\['limit'\]\s*=\s*100;/);
    assert.match(managerSource, /digitalogic_part_number_taxonomy\.taxonomy = 'pa_model'/);
    assert.match(viewSource, /:aria-colcount="visibleProductColumns\.length \+ 2"/);
    assert.match(viewSource, /<th scope="col" v-for="\(column, columnIndex\) in visibleProductColumns"/);
    assert.match(viewSource, /:readonly="!productEditMode"/);
    assert.match(viewSource, /<template v-if="!column\.filter"><\/template>/);
    assert.match(viewSource, /:aria-disabled="!column\.sortable"/);
    assert.match(panelPhpSource, /'minPrice' => 'Minimum price'/);
    assert.match(panelPhpSource, /'maxPrice' => 'Maximum price'/);
    assert.match(panelPhpSource, /'minPrice' => 'حداقل قیمت'/);
    assert.match(panelPhpSource, /'maxPrice' => 'حداکثر قیمت'/);
    assert.match(panelCss, /\.dlp-table \.dlp-cell-numeric[\s\S]*?direction:\s*ltr;[\s\S]*?text-align:\s*left;/);
    assert.match(adminCss, /\.wrap\[class\*="digitalogic-"\] input\[type="number"\][\s\S]*?direction:\s*ltr !important;/);
});

test('product table can freeze the first visible column in RTL and LTR', () => {
    const panelSource = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8');
    const viewSource = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'views', 'app.php'), 'utf8');
    const panelPhpSource = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'class-panel.php'), 'utf8');
    const panelCss = fs.readFileSync(path.join(__dirname, '..', 'assets', 'css', 'panel.css'), 'utf8');

    assert.match(panelSource, /digitalogic_panel_freeze_first_product_column/);
    assert.match(panelSource, /freezeFirstProductColumn:\s*function\(value\)/);
    assert.match(viewSource, /v-model="freezeFirstProductColumn"/);
    assert.match(viewSource, /'is-first-column-sticky': freezeFirstProductColumn/);
    assert.match(viewSource, /'is-sticky-first-data-column': columnIndex === 0/);
    assert.match(panelPhpSource, /'freezeFirstColumn' => 'Freeze first column'/);
    assert.match(panelPhpSource, /'freezeFirstColumn' => 'ثابت نگه داشتن ستون اول'/);
    assert.match(panelCss, /\.is-first-column-sticky[\s\S]*?inset-inline-start:\s*0/);
    assert.match(panelCss, /\.is-sticky-first-data-column[\s\S]*?inset-inline-start:\s*var\(--dlp-selection-column-width\)/);
    assert.doesNotMatch(panelCss, /\.is-sticky-first-data-column[\s\S]{0,180}?\bleft\s*:/);
    assert.doesNotMatch(panelCss, /\.is-sticky-first-data-column[\s\S]{0,180}?\bright\s*:/);
});
