'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright-core');

const branding = path.join(__dirname, '..', 'assets', 'js', 'branding', 'admin-branding.js');
const styles = path.join(__dirname, '..', 'assets', 'css', 'branding', 'admin-branding.css');

async function checkAction(page, labels, direction) {
    const action = page.locator('.dg-passwordless-action:visible');
    assert.equal(await action.count(), 1);
    const button = action.getByRole('button', { name: labels.skipPassword, exact: true });
    const caption = action.getByText(labels.skipPasswordCaption, { exact: true });
    assert.equal(await caption.evaluate(element => element.tagName), 'P');
    assert.equal(await button.getAttribute('aria-describedby'), await caption.getAttribute('id'));
    assert.equal(await page.getByRole('button', { name: labels.skipPasswordCaption, exact: true }).count(), 0);
    assert.equal(await page.getByRole('link', { name: labels.skipPasswordCaption, exact: true }).count(), 0);

    const layout = await button.evaluate(element => {
        const icon = element.querySelector('svg').getBoundingClientRect();
        const label = element.querySelector('.dg-passwordless-label').getBoundingClientRect();
        const caption = document.getElementById(element.getAttribute('aria-describedby')).getBoundingClientRect();
        const button = element.getBoundingClientRect();
        return {
            gap: Math.max(icon.left - label.right, label.left - icon.right),
            centerDifference: Math.abs((icon.top + icon.bottom - label.top - label.bottom) / 2),
            iconOnRight: icon.left > label.left,
            iconWidth: icon.width,
            captionBelow: caption.top > button.bottom
        };
    });
    assert.ok(layout.iconWidth >= 16, 'The SMS icon must be visible');
    assert.ok(layout.gap >= 8, `Icon and text must be at least 8px apart (got ${layout.gap}px)`);
    assert.ok(layout.centerDifference < 1, 'Icon and text must share a vertical center');
    assert.equal(layout.iconOnRight, direction === 'rtl');
    assert.equal(layout.captionBelow, true);
    return button;
}

async function render(page, direction, labels, native) {
    await page.route('https://digitalogic.test/login/', route => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html lang="${direction === 'rtl' ? 'fa' : 'en'}" dir="${direction}">
            <body class="login"><div id="login"><div class="dg-digits-login-shell">
            <form class="digloginpage digits-form ${native ? '' : 'digits_form_index_section'}">
              <div class="digits-form_login">
                <div class="digits-form_heading"></div>
                <div class="digits-form_tab_wrapper">
                  <div class="digits-form_tab_container" ${native ? 'hidden' : ''}>
                    <div class="digits-form_tab_body digits-tab_active"><input name="digits_phone"></div>
                    <div class="digits-form_tab_body"><input name="digits_email"></div>
                  </div>
                  ${native ? `<div class="digits-form_tab_container">
                    <input type="password" value="fixture">
                    <button class="digits-form_tab-item" data-value="otp" type="button">SMS tab</button>
                    <button class="digits-form_otp_selector" data-type="sms_otp" type="button">Request SMS</button>
                    <button class="digits-form_show_forgot_password" type="button">${labels.skipPasswordCaption}</button>
                  </div>` : ''}
                </div>
                <button class="digits-form_submit-btn" type="submit"><span class="digits-form_button-text">Continue</span></button>
              </div>
            </form></div></div></body></html>`
    }));
    await page.goto('https://digitalogic.test/login/');
    await page.evaluate(labels => {
        window.DigitalogicBranding = { labels };
        document.querySelector('[data-value="otp"]')?.addEventListener('click', () => {
            document.body.dataset.smsTabSelected = 'true';
        });
    }, labels);
    await page.addStyleTag({ path: styles });
    await page.addScriptTag({ path: branding });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

async function checkOtpEditor(page, channel) {
    await page.evaluate(name => {
        const wrapper = document.querySelector('.digits-form_tab_wrapper');
        wrapper.innerHTML = `<div class="digits-form_tab_container"><div class="digits-form_input_row"><div class="digits-form_input"><input name="${name}_otp" class="otp_input" maxlength="6"></div></div></div>`;
    }, channel);
    const cells = page.locator('.dg-otp-slot input');
    await cells.first().waitFor();
    assert.equal(await cells.count(), 6);
    assert.equal(await cells.first().evaluate(element => getComputedStyle(element.closest('.dg-otp-editor')).direction), 'ltr');
    const centers = await cells.evaluateAll(elements => elements.map(element => element.getBoundingClientRect().left));
    assert.ok(centers.every((left, index) => index === 0 || left - centers[index - 1] >= 20), 'All six digits have separate positions');
    assert.ok(await cells.evaluateAll(elements => elements.every(element => parseFloat(getComputedStyle(element).borderTopWidth) >= 1)), 'Every empty OTP cell has a visible outline');
    await cells.first().focus();
    await page.keyboard.insertText('۱۲۳۴۵۶');
    assert.equal(await page.locator(`input[name="${channel}_otp"]`).inputValue(), '123456');
}

(async () => {
    const browser = await chromium.launch(process.env.DG_BROWSER_CHANNEL ? { channel: process.env.DG_BROWSER_CHANNEL } : {});
    try {
        for (const [direction, width, skipPassword, skipPasswordCaption] of [
            ['rtl', 390, 'ورود بدون رمز عبور', 'فراموشی رمز عبور'],
            ['ltr', 1024, 'Sign in without a password', 'Forgot your password?']
        ]) {
            const labels = { skipPassword, skipPasswordCaption };
            for (const native of [true, false]) {
                const page = await browser.newPage({ viewport: { width, height: 800 } });
                await render(page, direction, labels, native);
                if (!native) {
                    await page.locator('input[name="digits_phone"]').fill('fixture-user');
                    await page.getByRole('button', { name: 'Continue', exact: true }).click();
                }
                const button = await checkAction(page, labels, direction);
                await button.click();
                if (native) {
                    assert.equal(await page.locator('body').getAttribute('data-sms-tab-selected'), 'true');
                    assert.equal(await page.locator('input[type="password"]').inputValue(), '');
                } else {
                    assert.equal(await page.locator('form').getAttribute('data-dg-sms-identity'), 'true');
                    assert.equal(await page.locator('input[name="digits_phone"]').evaluate(element => document.activeElement === element), true);
                    assert.equal(await page.getByRole('button', { name: 'Send SMS code', exact: true }).isVisible(), true);
                }
                await checkOtpEditor(page, native ? 'sms' : 'email');
                await page.close();
            }
        }
        console.log('Passwordless caption, icon spacing, and SMS entry passed in RTL and LTR.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
