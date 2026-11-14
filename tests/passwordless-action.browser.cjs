'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright-core');

const branding = path.join(__dirname, '..', 'assets', 'js', 'branding', 'admin-branding.js');
const styles = path.join(__dirname, '..', 'assets', 'css', 'branding', 'admin-branding.css');
const phoneLibrary = path.join(__dirname, '..', 'assets', 'vendor', 'libphonenumber-js', 'libphonenumber-max.js');

async function checkAction(page, labels, direction) {
    const action = page.locator('.dg-passwordless-action:visible');
    assert.equal(await action.count(), 1);
    const button = action.getByRole('button', { name: labels.skipPassword, exact: true });
    const caption = action.getByText(labels.skipPasswordCaption, { exact: true });
    assert.equal(await caption.evaluate(element => element.tagName === 'P' && !element.closest('button')), true);
    assert.equal(await caption.locator('svg').count(), 0);
    assert.equal(await button.getAttribute('aria-describedby'), await caption.getAttribute('id'));
    assert.equal(await page.getByRole('button', { name: labels.skipPasswordCaption, exact: true }).count(), 0);
    assert.equal(await page.getByRole('link', { name: labels.skipPasswordCaption, exact: true }).count(), 0);

    const layout = await caption.evaluate(element => {
        const button = element.nextElementSibling;
        const previousAction = element.parentElement.previousElementSibling;
        const captionRect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        const colorProbe = document.createElement('span');
        colorProbe.style.color = 'var(--dg-text)';
        element.appendChild(colorProbe);
        const mainTextColor = style.color === getComputedStyle(colorProbe).color;
        colorProbe.remove();
        return {
            aboveButton: button?.matches('.dg-skip-password') && captionRect.bottom < button.getBoundingClientRect().top,
            spaceAbove: captionRect.top - previousAction.getBoundingClientRect().bottom,
            fontSize: parseFloat(style.fontSize),
            mainTextColor
        };
    });
    assert.ok(layout.aboveButton, 'The caption must be a separate element visibly above the button');
    assert.ok(layout.spaceAbove >= 18, 'Give the caption room after the preceding action');
    assert.ok(layout.fontSize >= 15 && layout.mainTextColor, 'The caption should be clearly legible');
    await checkIconAndLabel(button, direction);
    return button;
}

async function checkIconAndLabel(button, direction) {
    const layout = await button.evaluate(element => {
        const icon = element.querySelector('svg').getBoundingClientRect();
        const label = element.querySelector(':scope > span').getBoundingClientRect();
        const button = element.getBoundingClientRect();
        return {
            gap: Math.max(icon.left - label.right, label.left - icon.right),
            centerDifference: Math.abs((icon.top + icon.bottom - label.top - label.bottom) / 2),
            iconOnRight: icon.left > label.left,
            iconWidth: icon.width,
            groupOffset: Math.abs((Math.min(icon.left, label.left) + Math.max(icon.right, label.right) - button.left - button.right) / 2)
        };
    });
    assert.ok(layout.iconWidth >= 16, 'The action icon must be visible');
    assert.ok(layout.gap >= 8 && layout.gap <= 12, `Icon and text must be 8–12px apart (got ${layout.gap}px)`);
    assert.ok(layout.centerDifference < 1, 'Icon and text must share a vertical center');
    assert.ok(layout.groupOffset < 1, 'Icon and text must be centered as a group');
    assert.equal(layout.iconOnRight, direction === 'rtl');
}

async function render(page, direction, labels, native, search = '') {
    await page.route('https://digitalogic.test/login/**', route => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html lang="${direction === 'rtl' ? 'fa' : 'en'}" dir="${direction}">
            <body class="login"><div id="login"><div class="dg-digits-login-shell">
            <form class="digloginpage digits-form ${native ? '' : 'digits_form_index_section'}">
              <div class="digits-form_login">
                <div class="digits-form_heading"></div>
                <div class="digits-form_tab_wrapper">
                  <div class="digits-form_tab_container" ${native ? 'hidden' : ''}>
                    <div class="digits-form_tab_body digits-tab_active">
                      <div class="digits-mobile_wrapper">
                        <div class="digits-form_countrycode"><input name="login_digt_countrycode" value="+98"></div>
                        <input name="digits_phone">
                      </div>
                    </div>
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
                <button class="digits-form_passwordless_login" type="button">Passkey</button>
                <div class="digits-social-logins-box">
                  <div class="digits-use_social-logins"><span>Or continue with</span></div>
                  <div class="digits-social-logins"><div id="digits_google_login"><span class="digits-social-text">Google</span></div></div>
                </div>
              </div>
            </form></div></div></body></html>`
    }));
    await page.goto('https://digitalogic.test/login/' + search);
    await page.evaluate(labels => {
        window.DigitalogicBranding = {
            labels,
            rememberedAccounts: [{ login: 'known-user', avatar: '/known-avatar.svg' }]
        };
        document.querySelector('[data-value="otp"]')?.addEventListener('click', () => {
            document.body.dataset.smsTabSelected = 'true';
        });
    }, labels);
    await page.route('https://digitalogic.test/known-avatar.svg', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="blue" d="M0 0h32v32H0z"/></svg>'
    }));
    await page.addStyleTag({ path: styles });
    await page.addScriptTag({ path: phoneLibrary });
    await page.addScriptTag({ path: branding });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

async function checkVerificationLabels(page, labels) {
    const phone = page.locator('input[name="digits_phone"]');
    const email = page.locator('input[name="digits_email"]');
    const primary = page.locator('.digits-form_login > .digits-form_submit-btn');
    for (const [field, value, expected] of [
        [phone, '', 'Continue'],
        [phone, 'sample-user', 'Continue'],
        [phone, '۰۹۱۲', 'Continue'],
        [phone, '۰۹۱۲۰۰۰۰۰۰۰', labels.sendSmsCode],
        [phone, '۰۲۱۱۲۳۴۵۶۷۸', 'Continue'],
        [phone, '+442079460018', 'Continue'],
        [phone, '+12025550123', 'Continue'],
        [phone, '+448001111', 'Continue'],
        [phone, '+989120000000', labels.sendSmsCode],
        [phone, 'demo@', 'Continue'],
        [email, 'demo@example.com', labels.sendEmailCode],
        [email, 'demo', 'Continue'],
        [phone, '', 'Continue']
    ]) {
        await field.fill(value);
        assert.equal(await primary.innerText(), expected, value || '(empty identity)');
        await checkIdentityPrompt(page, labels.contactPlaceholder);
    }
    await phone.fill('09120000000');
    assert.equal(await primary.innerText(), labels.sendSmsCode);
    await page.locator('.dg-country-trigger').click();
    await page.locator('.dg-country-option[data-code="+1"]').click();
    assert.equal(await primary.innerText(), 'Continue', 'The previous SMS label must clear on country changes');
}

async function checkIdentityPrompt(page, expected) {
    const visibleIdentity = page.locator('input:is([name="digits_phone"], [name="digits_email"]):visible');
    assert.equal(await visibleIdentity.getAttribute('placeholder'), expected);
    assert.equal(await visibleIdentity.getAttribute('aria-label'), expected);
}

async function checkAlternatives(page, visible) {
    for (const selector of ['.digits-use_social-logins', '#digits_google_login', '.dg-passkey-options summary']) {
        assert.equal(await page.locator(selector).isVisible(), visible, selector);
    }
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
            ['rtl', 390, 'ورود بدون رمز عبور', 'رمز خود را فراموش کرده اید؟'],
            ['ltr', 1024, 'Sign in without a password', 'Forgot your password?']
        ]) {
            const labels = {
                skipPassword, skipPasswordCaption,
                identityPlaceholder: direction === 'rtl' ? 'نام کاربری، ایمیل یا شماره تلفن' : 'Username, email, or phone number',
                contactPlaceholder: direction === 'rtl' ? 'ایمیل یا شماره تلفن' : 'Email address or phone number',
                passkeyLogin: direction === 'rtl' ? 'ورود با کلید عبور' : 'Sign in with a passkey',
                sendSmsCode: direction === 'rtl' ? 'ارسال کد پیامکی' : 'Send SMS code',
                sendEmailCode: direction === 'rtl' ? 'ارسال کد به ایمیل' : 'Send email code'
            };
            for (const native of [true, false]) {
                const page = await browser.newPage({ viewport: { width, height: 800 } });
                await render(page, direction, labels, native);
                await checkAlternatives(page, !native);
                if (!native) {
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    await page.locator('.dg-passkey-options summary').click();
                    await checkIconAndLabel(page.getByRole('button', { name: labels.passkeyLogin, exact: true }), direction);
                    await page.locator('input[name="digits_phone"]').fill('fixture-user');
                    await page.getByRole('button', { name: 'Continue', exact: true }).click();
                    await checkAlternatives(page, false);
                    const profile = page.locator('.dg-username-login-summary');
                    const avatar = profile.locator('.dg-username-login-avatar');
                    assert.equal(await avatar.locator('img').count(), 0);
                    assert.equal(await avatar.locator('svg').isVisible(), true);
                    await page.locator('.dg-username-login-back').click();
                    await checkAlternatives(page, true);
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: 'Continue', exact: true }).click();
                    await avatar.locator('img').waitFor();
                    assert.equal(await avatar.locator('svg').isVisible(), false);
                    const layout = await profile.evaluate(element => {
                        const avatar = element.querySelector('.dg-username-login-avatar');
                        const name = element.querySelector('.dg-username-login-value');
                        const rect = avatar.getBoundingClientRect();
                        const label = name.getBoundingClientRect();
                        return {
                            above: rect.bottom < label.top,
                            centered: Math.abs((rect.left + rect.right - label.left - label.right) / 2) < 1,
                            size: rect.width,
                            radius: getComputedStyle(avatar).borderRadius
                        };
                    });
                    assert.deepEqual(layout, { above: true, centered: true, size: 64, radius: '50%' });
                    const eye = page.locator('.dg-username-password-toggle');
                    const password = page.locator('.dg-username-password-input');
                    await password.fill('layout-test');
                    for (const type of ['text', 'password']) {
                        await eye.click();
                        await page.waitForFunction(value => document.querySelector('.dg-username-password-input').type === value, type);
                        assert.equal(await password.evaluate(e => getComputedStyle(e).direction), 'ltr');
                        const eyeLayout = await eye.evaluate(e => {
                            const button = e.getBoundingClientRect();
                            const icon = e.querySelector('svg').getBoundingClientRect();
                            const row = e.parentElement.getBoundingClientRect();
                            const center = rect => (rect.top + rect.bottom) / 2;
                            return [Math.abs(center(icon) - center(button)), Math.abs(center(button) - center(row)), getComputedStyle(e).color];
                        });
                        assert.ok(eyeLayout[0] < 1 && eyeLayout[1] < 1, 'Password eye is centered vertically');
                        assert.notEqual(eyeLayout[2], 'rgb(0, 0, 0)');
                    }
                }
                const button = await checkAction(page, labels, direction);
                await button.click();
                await checkAlternatives(page, false);
                if (native) {
                    assert.equal(await page.locator('body').getAttribute('data-sms-tab-selected'), 'true');
                    assert.equal(await page.locator('input[type="password"]').inputValue(), '');
                } else {
                    assert.equal(await page.locator('form').getAttribute('data-dg-sms-identity'), 'true');
                    assert.equal(await page.locator('input[name="digits_phone"]').evaluate(element => document.activeElement === element), true);
                    await checkIdentityPrompt(page, labels.contactPlaceholder);
                    await checkVerificationLabels(page, labels);
                    await page.locator('.dg-auth-back').click();
                    await checkAlternatives(page, true);
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    assert.equal(await page.locator('.dg-auth-back').isVisible(), false);
                    assert.equal(await page.locator('.digits-form_tab_container').count(), 1, 'Digits must have only the identity tab after returning');
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: 'Continue', exact: true }).click();
                    await page.locator('.dg-skip-password:visible').click();
                }
                await checkOtpEditor(page, native ? 'sms' : 'email');
                await checkAlternatives(page, false);
                await page.close();
            }
            const recovery = await browser.newPage({ viewport: { width, height: 800 } });
            await render(recovery, direction, labels, false, '?recover=1');
            await checkAlternatives(recovery, false);
            await checkIdentityPrompt(recovery, labels.contactPlaceholder);
            await recovery.locator('.dg-auth-back').click();
            await checkAlternatives(recovery, true);
            await checkIdentityPrompt(recovery, labels.identityPlaceholder);
            assert.equal(await recovery.locator('.dg-auth-back').isVisible(), false);
            assert.equal(new URL(recovery.url()).searchParams.has('recover'), false);
            await recovery.close();
        }
        console.log('Passwordless actions, OTP, and first-step-only sign-in options passed in RTL and LTR.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
