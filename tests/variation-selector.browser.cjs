// Run with Playwright available in NODE_PATH and JQUERY_PATH pointing to jQuery.
// Uses an isolated browser and local fixtures; no storefront/cart requests.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
    const page = await browser.newPage({ viewport: { width: 820, height: 860 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect x="12" y="18" width="72" height="60" rx="5" fill="#205948"/><path d="M18 24h32v12H30v12h20" fill="none" stroke="#eac875" stroke-width="4"/><rect x="48" y="46" width="20" height="20" fill="#242c32"/></svg>'
    }));
    await page.setContent('<html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>@media(max-width:768px){form.variations_form{display:grid;grid-template-columns:132px 1fr;gap:10px}}</style></head><body class="single-product" style="margin:50px auto;max-width:580px;padding:20px;font-family:Tahoma,sans-serif;background:#f6f8f8"><main style="background:white;padding:28px;border-radius:16px"><h1 style="font-size:24px">ماژول فرستنده و گیرنده NRF24L01</h1><p style="color:#68747b">مدل مورد نظر را انتخاب کنید</p><form class="variations_form"><table class="variations"><tbody><tr><th class="label"><label for="source_model">مدل</label></th><td class="value cell"><div id="fixture"></div></td></tr></tbody></table><div class="single_variation_wrap"><button class="reset" type="reset">پاک کردن</button></div></form></main></body></html>');
    await page.addScriptTag({ path: process.env.JQUERY_PATH });
    await page.evaluate(() => {
        const root = document.createElement('div');
        root.className = 'digitalogic-model-selector';
        root.dataset.digitalogicModelSelector = JSON.stringify({
            label: 'مدل', placeholder: 'انتخاب مدل', search: 'جستجوی مدل، توضیحات یا کد کالا…', empty: 'مدلی با این مشخصات پیدا نشد.', skuLabel: 'کد کالا', unavailable: 'ناموجود', imageLabel: 'تصویر مدل',
            items: [
                { value: 'standard', attributes: {}, title: 'ماژول NRF24L01 استاندارد', description: 'ماژول بی‌سیم با آنتن روی برد\nفرکانس ۲٫۴ گیگاهرتز', productCode: '113008001', priceRaw: '120000', priceText: '120,000 تومان', image: 'https://fixture.test/module.svg', available: true },
                { value: 'mini', attributes: {}, title: 'ماژول NRF24L01 MINI', description: 'ابعاد کوچک برای پروژه‌های فشرده', productCode: '113008002', priceRaw: '125000', priceText: '125,000 تومان', image: 'https://fixture.test/module.svg', available: true },
                { value: 'pa', attributes: {}, title: 'ماژول NRF24L01+PA+LNA', description: 'مدل تقویت‌شده با اتصال آنتن خارجی', productCode: '113008003', priceRaw: null, priceText: '', image: '', available: false }
            ]
        });
        root.innerHTML = '<select id="source_model" name="attribute_source_model"><option value="">انتخاب مدل</option><option value="standard">NRF24L01</option><option value="mini">NRF24L01 MINI</option><option value="pa">NRF24L01+PA+LNA</option></select>';
        document.querySelector('#fixture').append(root);
        window.changes = 0;
        jQuery('select').on('change', () => { window.changes++; });
    });
    await page.addStyleTag({ path: path.join(__dirname, '../assets/css/variation-selector.css') });
    await page.addScriptTag({ path: path.join(__dirname, '../assets/js/variation-selector.js') });
    const trigger = page.locator('.digitalogic-model-trigger');
    const search = page.locator('.digitalogic-model-search');
    await trigger.waitFor();
    assert.equal(await page.locator('select').count(), 1);
    await trigger.click();
    assert.equal(await search.getAttribute('aria-expanded'), 'true');
	assert.match(await page.locator('[role=option]').nth(1).textContent(), /125,000 تومان/);
	assert.equal(await page.locator('[role=option]').last().getAttribute('aria-disabled'), 'true');
	assert.match(await page.locator('[role=option]').last().textContent(), /ناموجود/);
	assert.doesNotMatch(await page.locator('[role=option]').last().textContent(), /180,000/);
	assert.equal(await page.locator('[role=option]').last().locator('.digitalogic-model-image--placeholder').count(), 1);
    await search.fill('۱۱۳۰۰۸۰۰۲');
    assert.equal(await page.locator('[role=option]:visible').count(), 1);
    await search.press('Enter');
    assert.equal(await page.locator('select').inputValue(), 'mini');
    assert.equal(await page.evaluate(() => window.changes), 1);
    assert.match(await trigger.textContent(), /113008002/);
    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
	await page.evaluate(() => document.dispatchEvent(new CustomEvent('digitalogic:select-product-code', { detail: { code: '113008001' } })));
	assert.equal(await page.locator('select').inputValue(), 'standard');
    await trigger.press('ArrowDown');
    await search.press('ArrowDown');
    await search.press('Enter');
    assert.equal(await page.locator('select').inputValue(), 'mini');
    await trigger.click();
    await search.fill('no-match');
    assert.equal(await page.locator('.digitalogic-model-empty').isVisible(), true);
    await search.press('Escape');
    assert.equal(await trigger.evaluate(node => node === document.activeElement), true);
    await page.evaluate(() => {
        document.querySelector('option[value=pa]').disabled = true;
        jQuery('form').trigger('woocommerce_update_variation_values');
    });
    await trigger.click();
    assert.equal(await page.locator('[role=option]').last().getAttribute('aria-disabled'), 'true');
    await page.locator('[role=option]').last().click({ force: true });
    assert.equal(await page.locator('select').inputValue(), 'mini');
    await search.press('Escape');
    await page.locator('.reset').click();
    await page.waitForFunction(() => document.querySelector('.digitalogic-model-trigger').textContent === 'انتخاب مدل');
    await page.evaluate(() => {
        for (let i = 0; i < 3; i++) jQuery('form').trigger('wc_variation_form');
        document.querySelector('option[value=pa]').disabled = false;
        jQuery('form').trigger('woocommerce_update_variation_values');
    });
    assert.equal(await trigger.count(), 1);
    await trigger.click();
    if (process.env.SCREENSHOT_PATH) await page.screenshot({ path: process.env.SCREENSHOT_PATH, fullPage: true });
    await page.setViewportSize({ width: 540, height: 828 });
    await search.press('Escape');
    const intermediateLayout = await page.evaluate(() => ({
        form: document.querySelector('form.variations_form').getBoundingClientRect().width,
        selector: document.querySelector('.digitalogic-model-selector').getBoundingClientRect().width,
        triggerHeight: document.querySelector('.digitalogic-model-trigger').getBoundingClientRect().height
    }));
    assert.ok(intermediateLayout.selector >= intermediateLayout.form * 0.95, JSON.stringify(intermediateLayout));
    assert.ok(intermediateLayout.triggerHeight < 150, JSON.stringify(intermediateLayout));
    await page.setViewportSize({ width: 375, height: 812 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    if (process.env.SCREENSHOT_PATH) await page.screenshot({ path: process.env.SCREENSHOT_PATH.replace('.png', '-mobile.png'), fullPage: true });
    // The metadata is text even if a hostile supplier title reaches this layer.
    await page.evaluate(() => {
        const form = document.createElement('form');
        form.className = 'variations_form';
        const root = document.createElement('div');
        root.className = 'digitalogic-model-selector';
        root.dataset.digitalogicModelSelector = JSON.stringify({ label: 'Model', placeholder: 'Choose', search: 'Search', empty: 'None', skuLabel: 'SKU', items: [
            { value: 'x', attributes: { attribute_color: 'red' }, title: '<img src=x onerror=alert(1)>', image: 'javascript:alert(1)', sku: 'RED' },
            { value: 'x', attributes: { attribute_color: 'blue' }, title: 'Blue child', sku: 'BLUE' }
        ] });
        root.innerHTML = '<select name="attribute_source_model"><option value="x" selected>X</option></select>';
        const attributes = document.createElement('div');
        attributes.className = 'variations';
        attributes.innerHTML = '<select name="attribute_color"><option value="">Choose color</option><option value="red">Red</option><option value="blue">Blue</option></select>';
        attributes.append(root);
        form.append(attributes);
        document.body.append(form);
    });
    await page.waitForFunction(() => document.querySelectorAll('.digitalogic-model-trigger').length === 2);
    assert.equal(await trigger.last().textContent(), 'X');
    await page.evaluate(() => jQuery('select[name=attribute_color]').val('red').trigger('change'));
    assert.match(await trigger.last().textContent(), /<img/);
    assert.match(await trigger.last().textContent(), /RED/);
    assert.equal(await trigger.last().locator('img').count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: filtering, Persian digits, keyboard, native change, availability, reset, repeat init, dynamic forms, text/URL safety, RTL mobile width.');
    await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
