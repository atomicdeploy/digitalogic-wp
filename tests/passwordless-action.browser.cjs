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
                    <div class="digits-form_tab_body"><div class="digits-form_input_row"><input name="digits_email"></div></div>
                  </div>
                  ${native ? `<template id="native-password-step"><div class="digits-form_tab_container">
                    <div class="digits-form_input_row">
                      <div class="digits-form_input digits_password_inp_row">
                        <input name="password" type="password" required>
                        <button class="digits_password_eye" type="button">Vendor eye</button>
                      </div>
                    </div>
                    <button class="digits-form_tab-item" data-value="otp" type="button">SMS tab</button>
                    <button class="digits-form_otp_selector" data-type="sms_otp" type="button">Request SMS</button>
                    <button class="digits-form_show_forgot_password" type="button">${labels.skipPasswordCaption}</button>
                  </div></template>` : ''}
                </div>
                <div class="digits_form-init_step_data">
                  <label class="digits-form_rememberme"><input class="digits_login_remember_me" name="rememberme" value="1" type="checkbox">Remember me</label>
                </div>
                <button class="digits-form_submit-btn" type="submit"><span class="digits-form_button-text">Continue</span></button>
                <button class="digits-form_passwordless_login" type="button">Passkey</button>
                <div class="digits-social-logins-box">
                  <div class="digits-use_social-logins"><span>Or continue with</span></div>
                  <div class="digits-social-logins"><div id="digits_google_login"><span class="digits-social-text">Google</span></div></div>
                </div>
              </div>
            </form><div class="dg-call-unified" data-call-unified data-call-available="true"><div data-call-instructions hidden><a data-call-dial href="tel:+982112345678">Call</a></div></div></div></div></body></html>`
    }));
    await page.goto('https://digitalogic.test/login/' + search);
    await page.evaluate(labels => {
        window.DigitalogicBranding = {
            labels,
            otpCursor: 'bar',
            ajaxUrl: '/email-domain',
            emailDomainNonce: 'fixture',
            rememberedAccounts: [{ login: 'known-user', avatar: '/known-avatar.svg' }]
        };
    }, labels);
    await page.route('https://digitalogic.test/known-avatar.svg', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="blue" d="M0 0h32v32H0z"/></svg>'
    }));
    await page.addStyleTag({ path: styles });
    await page.addScriptTag({ path: phoneLibrary });
    await page.addScriptTag({ path: branding });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    if (native) {
        await page.evaluate(() => {
            const template = document.getElementById('native-password-step');
            template.replaceWith(template.content);
            document.querySelector('[data-value="otp"]').addEventListener('click', () => {
                document.body.dataset.smsTabSelected = 'true';
            });
        });
    }
}

async function checkEmailGate(page, labels) {
    const phone = page.locator('input[name="digits_phone"]');
    const email = page.locator('input[name="digits_email"]');
    const form = page.locator('form.digloginpage');
    const primary = form.locator('.digits-form_login > .digits-form_submit-btn');
    const status = form.locator('.dg-email-domain-status');
    const colors = new Set();
    let releaseSlow;
    let serviceCalls = 0;
    await page.route('https://digitalogic.test/email-domain', async route => {
        const domain = new URLSearchParams(route.request().postData()).get('domain');
        if (domain === 'slow.example') {
            await new Promise(resolve => { releaseSlow = resolve; });
            await route.fulfill({ json: { success: true, data: { valid: true } } });
            return;
        }
        if (domain === 'retry.example' && ++serviceCalls === 1) {
            await route.fulfill({ status: 503 });
            return;
        }
        await route.fulfill({ json: { success: true, data: { valid: domain !== 'invalid.example' } } });
    });
    async function expectState(state, enabled) {
        await status.and(page.locator(`[data-state="${state}"]`)).waitFor();
        assert.equal(await primary.isEnabled(), enabled, state);
        colors.add(await status.evaluate(element => getComputedStyle(element).color));
    }
    assert.equal(await primary.isDisabled(), true);
    await phone.fill('0912');
    assert.equal(await primary.isDisabled(), true);
    await phone.fill('09120000000');
    assert.equal(await primary.isEnabled(), true);
    assert.equal(await primary.innerText(), labels.sendSmsCode);
    await phone.fill('+12025550123');
    assert.equal(await primary.innerText(), labels.continueBySms);
    await page.locator('input[name="dg_phone_channel"][value="call"]').check();
    assert.equal(await primary.innerText(), labels.getCallCode);
    await page.evaluate(() => {
        window.DigitalogicCallLogin = { start(number) {
            document.body.dataset.callNumber = number;
            document.querySelector('[data-call-instructions]').hidden = false;
            document.querySelector('.dg-digits-login-shell').classList.add('dg-call-code-active');
            return true;
        } };
    });
    await primary.click();
    assert.equal(await page.locator('body').getAttribute('data-call-number'), '+12025550123');
    await page.locator('[data-call-instructions] input[name="rememberme"]').waitFor();
    await page.evaluate(() => {
        document.querySelector('[data-call-instructions]').hidden = true;
        document.querySelector('.dg-digits-login-shell').classList.remove('dg-call-code-active');
    });
    assert.equal(await page.getByRole('checkbox', { name: 'Remember me' }).count(), 0);
    await phone.fill('+982112345678');
    assert.equal(await primary.innerText(), labels.getCallCode);
    await phone.fill('preview@');
    assert.equal(await primary.isDisabled(), true);
    await email.blur();
    await expectState('format', false);
    await email.fill('preview@slow.example');
    await expectState('checking', false);
    const slowRequest = page.waitForRequest(request => request.postData()?.includes('slow.example'));
    await email.blur();
    await slowRequest;
    await form.evaluate(element => element.requestSubmit());
    assert.equal(await form.locator('.dg-username-password-step').count(), 0);
    await email.fill('preview@invalid.example');
    await expectState('invalid', false);
    releaseSlow();
    await expectState('invalid', false);
    assert.equal(await email.getAttribute('aria-invalid'), 'true');
    await email.fill('preview@retry.example');
    await expectState('unavailable', false);
    await status.getByRole('button').click();
    await expectState('valid', true);
    assert.equal(await primary.innerText(), labels.enterPassword);
    assert.equal(await email.getAttribute('aria-invalid'), 'false');
    assert.equal(colors.size, 4);
    await email.fill('preview');
    assert.equal(await primary.isEnabled(), true);
    await phone.fill('');
    assert.equal(await primary.isDisabled(), true);
}

async function checkSmsAction(page, labels) {
    await page.route('https://digitalogic.test/wp-admin/admin-ajax.php', route => {
        if (route.request().postData().includes('name="sms_otp"')) {
            assert.match(route.request().postData(), /name="rememberme"\r\n\r\n1/);
            return route.fulfill({ json: { success: true, data: { redirect: '/complete' } } });
        }
        assert.doesNotMatch(route.request().postData(), /name="rememberme"/);
        return route.fulfill({ json: {
            success: true,
            data: { html: `<div class="digits-form_tab_container"><div class="digits-form_tab_body digits-tab_active">
            <button type="button" class="digits-form_otp_selector" data-type="email_otp">Email</button>
            <button type="button" class="digits-form_otp_selector" data-type="sms_otp">SMS</button>
        </div></div>` }
        } });
    });
    await page.route('https://digitalogic.test/complete', route => route.fulfill({ body: 'Signed in' }));
    await page.evaluate(() => document.addEventListener('click', event => {
        const choice = event.target.closest('.digits-form_otp_selector');
        if (!choice) { return; }
        document.body.dataset.selectedChannel = choice.dataset.type;
        choice.parentElement.innerHTML = '<input name="sms_otp" class="otp_input" maxlength="6">';
    }));
    await page.locator('input[name="digits_phone"]').fill('09120000000');
    await page.getByRole('button', { name: labels.sendSmsCode, exact: true }).click();
    await page.locator('.dg-otp-slot input').first().waitFor();
    assert.equal(await page.locator('body').getAttribute('data-selected-channel'), 'sms_otp');
    await page.getByRole('checkbox', { name: 'Remember me' }).check();
    await page.locator('.dg-otp-slot input').first().focus();
    await page.keyboard.insertText('123456');
    await page.getByRole('button', { name: labels.verifyCode, exact: true }).click();
    await page.waitForURL('**/complete');
}

async function checkVerificationLabels(page, labels) {
    const phone = page.locator('input[name="digits_phone"]');
    const email = page.locator('input[name="digits_email"]');
    const primary = page.locator('.digits-form_login > .digits-form_submit-btn');
    for (const [field, value, expected, isPhone] of [
        [phone, '', 'Continue'],
        [phone, 'sample-user', 'Continue'],
        [phone, '۰۹۱۲', 'Continue', true],
        [phone, '۰۹۱۲۰۰۰۰۰۰۰', labels.sendSmsCode, true],
        [phone, '۰۲۱۱۲۳۴۵۶۷۸', labels.getCallCode, true],
        [phone, '+442079460018', labels.getCallCode, true],
        [phone, '+12025550123', labels.continueBySms, true],
        [phone, '+448001111', 'Continue', true],
        [phone, '+989120000000', labels.sendSmsCode, true],
        [phone, 'demo@', 'Continue'],
        [email, 'demo@example.com', labels.sendEmailCode],
        [email, 'demo', 'Continue'],
        [phone, '', 'Continue']
    ]) {
        await field.fill(value);
        assert.equal(await primary.innerText(), expected, value || '(empty identity)');
        await checkIdentityPrompt(page, isPhone ? labels.phonePlaceholder : labels.contactPlaceholder);
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

async function checkPasswordField(page, labels, touch) {
    const field = page.locator('.dg-password-field:visible');
    await field.waitFor({ timeout: 4000 });
    const input = field.locator('input');
    const toggle = field.getByRole('button');
    assert.equal(await field.count(), 1);
    assert.equal(await page.locator('.wp-hide-pw:visible, .digits_password_eye:visible').count(), 0);
    await input.fill('layout-test');
    await input.evaluate(element => element.setSelectionRange(2, 6, 'backward'));
    assert.equal(await toggle.getAttribute('aria-label'), labels.showPassword);
    if (touch) {
        await toggle.tap();
    } else {
        await toggle.click();
    }
    assert.equal(await input.getAttribute('type'), 'text');
    assert.equal(await toggle.getAttribute('aria-label'), labels.hidePassword);
    const revealed = await input.evaluate(element => [
        document.activeElement === element, element.selectionStart, element.selectionEnd, element.selectionDirection
    ]);
    assert.deepEqual(revealed, [true, 2, 6, 'backward']);
    await input.press('Tab');
    assert.ok(await toggle.evaluate(element => document.activeElement === element));
    await page.keyboard.press('Space');
    assert.equal(await input.getAttribute('type'), 'password');
    assert.equal(await toggle.getAttribute('aria-label'), labels.showPassword);
    assert.ok(await toggle.evaluate(element => document.activeElement === element));
    assert.equal(await input.inputValue(), 'layout-test');
    assert.equal(await input.evaluate(element => getComputedStyle(element).direction), 'ltr');
    assert.ok(await toggle.evaluate(element => {
        const button = element.getBoundingClientRect();
        const icon = element.querySelector('svg').getBoundingClientRect();
        const row = element.parentElement.getBoundingClientRect();
        const center = rect => (rect.top + rect.bottom) / 2;
        return Math.abs(center(icon) - center(button)) < 1 && Math.abs(center(button) - center(row)) < 1;
    }));
}

async function checkAlternatives(page, visible) {
    for (const selector of ['.digits-use_social-logins', '#digits_google_login', '.dg-passkey-options summary']) {
        assert.equal(await page.locator(selector).isVisible(), visible, selector);
    }
}

async function checkOtpEditor(page, channel) {
    await page.evaluate(name => {
        const wrapper = document.querySelector('.digits-form_tab_wrapper');
        wrapper.closest('form').classList.remove('digits_form_index_section');
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
    const form = page.locator('form.digloginpage');
    const firstSlot = page.locator('.dg-otp-slot').first();
    const cursor = () => firstSlot.evaluate(slot => {
        const style = getComputedStyle(slot, '::after');
        return { animation: style.animationName, height: style.height };
    });
    assert.equal(await form.getAttribute('data-dg-otp-cursor'), 'bar');
    assert.deepEqual(await cursor(), { animation: 'dg-otp-bar-pulse', height: '3px' });
    await form.evaluate(element => { element.dataset.dgOtpCursor = 'circle'; });
    assert.deepEqual(await cursor(), { animation: 'dg-otp-caret', height: '12px' });
    await form.evaluate(element => { element.dataset.dgOtpCursor = 'bar'; });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    assert.deepEqual(await cursor(), { animation: 'none', height: '3px' });
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const entry = await cells.first().evaluate(cell => {
        cell.dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertText', data: '۱', cancelable: true }));
        const roll = cell.nextElementSibling;
        return [cell.value, roll?.textContent, roll?.getAttribute('aria-hidden'), roll?.firstElementChild?.getAnimations().length];
    });
    assert.deepEqual(entry, ['1', '1', 'true', 1]);
    await firstSlot.locator('.dg-otp-roll').waitFor({ state: 'detached' });
    await cells.first().focus();
    const replacement = await cells.first().evaluate(cell => {
        cell.dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertText', data: '۲', cancelable: true }));
        return [cell.value, cell.nextElementSibling?.textContent];
    });
    assert.deepEqual(replacement, ['2', '12']);
    await page.keyboard.press('Backspace');
    assert.equal(await cells.first().inputValue(), '');
    assert.equal(await firstSlot.locator('.dg-otp-roll').count(), 0);
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
                phonePlaceholder: direction === 'rtl' ? 'شماره تلفن' : 'Phone number',
                showPassword: direction === 'rtl' ? 'نمایش رمز عبور' : 'Show password',
                hidePassword: direction === 'rtl' ? 'پنهان کردن رمز عبور' : 'Hide password',
                requiredPassword: direction === 'rtl' ? 'رمز عبور را وارد کنید.' : 'Enter your password.',
                passkeyLogin: direction === 'rtl' ? 'ورود با کلید عبور' : 'Sign in with a passkey',
                sendSmsCode: direction === 'rtl' ? 'ارسال کد پیامکی' : 'Send SMS code',
                sendEmailCode: direction === 'rtl' ? 'ارسال کد به ایمیل' : 'Send email code',
                enterPassword: direction === 'rtl' ? 'وارد کردن رمز عبور' : 'Enter password',
                getCallCode: direction === 'rtl' ? 'دریافت کد تماس' : 'Get call code',
                continueBySms: direction === 'rtl' ? 'ادامه با پیامک' : 'Continue by SMS',
                verifyCode: direction === 'rtl' ? 'تأیید کد' : 'Verify code'
            };
            for (const native of [true, false]) {
                const page = await browser.newPage({ viewport: { width, height: 800 }, hasTouch: direction === 'rtl' });
                await render(page, direction, labels, native);
                await checkAlternatives(page, !native);
                if (!native) {
                    const remember = page.getByRole('checkbox', { name: 'Remember me' });
                    assert.equal(await remember.count(), 0);
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    const identity = page.locator('input[name="digits_phone"]');
                    await identity.fill('۰۹۱۲');
                    await checkIdentityPrompt(page, labels.phonePlaceholder);
                    await identity.fill('');
                    await checkIdentityPrompt(page, labels.identityPlaceholder);
                    await page.locator('.dg-passkey-options summary').click();
                    await checkIconAndLabel(page.getByRole('button', { name: labels.passkeyLogin, exact: true }), direction);
                    await page.locator('input[name="digits_phone"]').fill('fixture-user');
                    await page.getByRole('button', { name: labels.enterPassword, exact: true }).click();
                    await page.locator('.dg-username-password-step').getByRole('checkbox', { name: 'Remember me' }).check();
                    await checkAlternatives(page, false);
                    const profile = page.locator('.dg-username-login-summary');
                    const avatar = profile.locator('.dg-username-login-avatar');
                    assert.equal(await avatar.locator('img').count(), 0);
                    assert.equal(await avatar.locator('svg').isVisible(), true);
                    await page.locator('.dg-username-login-back').click();
                    assert.equal(await remember.count(), 0);
                    await checkAlternatives(page, true);
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: labels.enterPassword, exact: true }).click();
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
                }
                await checkPasswordField(page, labels, direction === 'rtl');
                const password = page.locator('input.dg-password-input:visible');
                await password.fill('   ');
                await password.press('Enter');
                assert.equal(await page.locator('.dg-digits-inline-message').innerText(), labels.requiredPassword);
                assert.ok(await password.evaluate(element => document.activeElement === element));
                await password.fill('layout-test');
                const button = await checkAction(page, labels, direction);
                await button.click();
                await checkAlternatives(page, false);
                if (native) {
                    assert.equal(await page.locator('body').getAttribute('data-sms-tab-selected'), 'true');
                    assert.equal(await page.locator('input[type="password"]').inputValue(), '');
                } else {
                    assert.equal(await page.getByRole('checkbox', { name: 'Remember me' }).count(), 0);
                    assert.equal(await page.locator('form').getAttribute('data-dg-sms-identity'), 'true');
                    assert.equal(await page.locator('input[name="digits_phone"]').evaluate(element => document.activeElement === element), true);
                    await checkIdentityPrompt(page, labels.contactPlaceholder);
                    await checkVerificationLabels(page, labels);
                    await page.locator('.dg-auth-back').click();
                    await checkAlternatives(page, true);
                    await checkIdentityPrompt(page, labels.phonePlaceholder);
                    assert.equal(await page.locator('.dg-auth-back').isVisible(), false);
                    assert.equal(await page.locator('.digits-form_tab_container').count(), 1, 'Digits must have only the identity tab after returning');
                    await page.locator('input[name="digits_phone"]').fill('known-user');
                    await page.getByRole('button', { name: labels.enterPassword, exact: true }).click();
                    await page.locator('.dg-skip-password:visible').click();
                }
                await checkOtpEditor(page, native ? 'sms' : 'email');
                assert.equal(await page.locator('.digits-form_login > .digits-form_submit-btn').innerText(), labels.verifyCode);
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
            const emailGate = await browser.newPage({ viewport: { width, height: 800 } });
            await render(emailGate, direction, labels, false);
            await checkEmailGate(emailGate, labels);
            await emailGate.close();
            const smsAction = await browser.newPage({ viewport: { width, height: 800 } });
            await render(smsAction, direction, labels, false);
            await checkSmsAction(smsAction, labels);
            await smsAction.close();
        }
        console.log('Passwordless actions, OTP, and first-step-only sign-in options passed in RTL and LTR.');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
