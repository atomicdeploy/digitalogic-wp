'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { test } = require('node:test');
const { chromium, devices } = require('playwright-core');

const live = process.env.WOODMART_SEARCH_LIVE === '1';
const origin = live ? (process.env.WOODMART_SEARCH_ORIGIN || 'https://digitalogic.ir') : 'https://digitalogic.test';
const assetRoot = path.join(__dirname, '../assets');
const fixtureRoot = path.join(__dirname, 'fixtures/woodmart-search');
const soldOut = live ? (process.env.WOODMART_SEARCH_SOLD_OUT || 'ADS1256') : 'DG-BROWSER-SOLD-OUT';
const inStock = live ? (process.env.WOODMART_SEARCH_IN_STOCK || 'HC-SR04') : 'DG-BROWSER-IN-STOCK';
const priceSelector = '.price, .woocommerce-Price-amount, .digitalogic-price-updated, [data-digitalogic-contextual-price-update], time';

async function openFixture(page) {
    const assets = new Map([
        ['/', path.join(fixtureRoot, 'index.html')],
        ['/jquery.js', require.resolve('jquery/dist/jquery.js')],
        ['/autocomplete.js', path.join(fixtureRoot, 'autocomplete.js')],
        ['/ajaxSearch.js', path.join(fixtureRoot, 'ajaxSearch.js')],
        ['/searchFullScreen.js', path.join(fixtureRoot, 'searchFullScreen.js')],
        ['/frontend-search-ws.js', path.join(assetRoot, 'js/frontend-search-ws.js')],
        ['/frontend-search.css', path.join(assetRoot, 'css/frontend-search.css')]
    ]);
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        const asset = assets.get(url.pathname);
        if (asset) {
            return route.fulfill({ path: asset });
        }
        if (url.pathname !== '/wp-admin/admin-ajax.php' || url.searchParams.get('action') !== 'woodmart_ajax_search') {
            return route.abort();
        }
        const query = url.searchParams.get('query');
        const available = query === inStock;
        const suggestions = (available || query === soldOut) ? [{
            value: `ماژول آزمایشی ${query}`,
            permalink: new URL('/product/search-browser-fixture/', origin).href,
            sku: 'کد کالا: DEMO',
            price: available ? '<span class="woocommerce-Price-amount amount">120,000 تومان</span>' : '',
            is_in_stock: available,
            group: 'product'
        }] : [];
        return route.fulfill({ json: { suggestions } });
    });
}

async function search(field, query) {
    await field.fill('');
    await field.pressSequentially(query, { delay: 35 });
}

async function checkDropdown(page, mobile) {
    if (!live) {
        await openFixture(page);
    }
    await page.goto(origin, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForFunction(() => typeof window.jQuery?.fn.devbridgeAutocomplete === 'function');

    let searchArea = page.locator('.wd-header-search-form:visible');
    if (mobile) {
        await page.locator('.wd-header-search-form-mobile:visible').click();
        searchArea = page.locator('.wd-search-full-screen-2.wd-opened');
    }
    const field = searchArea.locator('form.woodmart-ajax-search input.s:visible').first();
    await field.click();

    await search(field, soldOut);
    const soldOutRow = searchArea.locator('.wd-suggestion:visible').filter({ hasText: soldOut }).first();
    const badge = soldOutRow.getByText('ناموجود', { exact: true });
    await badge.waitFor();
    const layout = await badge.evaluate(element => {
        const content = element.closest('.wd-suggestion-content');
        const previous = element.previousElementSibling;
        const style = getComputedStyle(element);
        const bounds = element.getBoundingClientRect();
        const previousBounds = previous?.getBoundingClientRect();
        return {
            inProductDetails: Boolean(content?.querySelector('.wd-entities-title')),
            gap: previousBounds ? bounds.top - previousBounds.bottom : 0,
            rtlAlignment: previousBounds ? Math.abs(bounds.right - previousBounds.right) : Infinity,
            background: style.backgroundColor,
            sidePadding: parseFloat(style.paddingInlineStart),
            radius: parseFloat(style.borderTopRightRadius)
        };
    });
    assert.ok(layout.inProductDetails, 'Unavailable badge belongs inside the product details');
    assert.ok(layout.gap >= 4, `Badge needs its own line with space above it (got ${layout.gap}px)`);
    assert.ok(layout.rtlAlignment <= 1, 'Badge should align to the right with the Persian product details');
    assert.ok(layout.background !== 'rgba(0, 0, 0, 0)' && layout.sidePadding >= 6 && layout.radius > 0,
        'Unavailable text should have a badge background, padding and rounded corners');
    assert.equal(await soldOutRow.locator(priceSelector).count(), 0, 'Sold-out results cannot expose a price or price update');
    assert.ok(await soldOutRow.getByRole('link').getAttribute('href'), 'Sold-out product remains clickable');

    await search(field, inStock);
    const availableRow = searchArea.locator('.wd-suggestion:visible').filter({ hasText: inStock }).first();
    await availableRow.locator('.woocommerce-Price-amount').first().waitFor();
    assert.equal(await availableRow.getByText('ناموجود', { exact: true }).count(), 0, 'Available results only show the price');
}

test('Woodmart product search dropdown', async t => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
    try {
        for (const [name, options] of [
            ['desktop', { viewport: { width: 1440, height: 900 } }],
            ['mobile', devices['Pixel 7']]
        ]) {
            await t.test(name, async () => {
                const page = await browser.newPage(options);
                page.setDefaultTimeout(20000);
                try {
                    await checkDropdown(page, name === 'mobile');
                } finally {
                    await page.close();
                }
            });
        }
    } finally {
        await browser.close();
    }
});
