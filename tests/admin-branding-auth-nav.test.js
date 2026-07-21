'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const brandingSource = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'branding', 'admin-branding.js'),
    'utf8'
);
const brandingCss = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'css', 'branding', 'admin-branding.css'),
    'utf8'
);

function fakeClassList(values) {
    const classes = new Set(values || []);

    return {
        add(value) {
            classes.add(value);
        },
        contains(value) {
            return classes.has(value);
        }
    };
}

function fakeLink(href, classes, textContent) {
    const attributes = new Map([['href', href]]);

    return {
        classList: fakeClassList(classes),
        textContent: textContent || '',
        getAttribute(name) {
            return attributes.has(name) ? attributes.get(name) : null;
        },
        setAttribute(name, value) {
            attributes.set(name, String(value));
        }
    };
}

function createHarness(options) {
    const navLinks = options.navLinks || [];
    const digitsLinks = options.digitsLinks || [];
    const recoveryLabel = {
        classList: fakeClassList(),
        dataset: {},
        innerHTML: '',
        textContent: options.recoveryText || ''
    };
    const recoveryAttributes = new Map();
    const recoveryField = {
        setAttribute(name, value) {
            recoveryAttributes.set(name, String(value));
        },
        getAttribute(name) {
            return recoveryAttributes.get(name);
        }
    };
    const document = {
        activeElement: null,
        body: {
            classList: fakeClassList(['login'].concat(options.bodyClasses || [])),
            dataset: {}
        },
        documentElement: {
            lang: options.lang || 'en-US',
            getAttribute() {
                return null;
            }
        },
        addEventListener() {},
        querySelector(selector) {
            if (selector === 'label[for="user_login"]') {
                return options.hasRecoveryForm ? recoveryLabel : null;
            }

            if (selector === 'body.login #lostpasswordform label[for="user_login"]') {
                return options.hasRecoveryForm ? recoveryLabel : null;
            }

            if (selector === 'body.login #lostpasswordform #user_login') {
                return options.hasRecoveryForm ? recoveryField : null;
            }

            return null;
        },
        querySelectorAll(selector) {
            if (selector === 'body.login #nav a') {
                return navLinks;
            }

            if (selector === '.digits-form_toggle_login_register.show_login') {
                return digitsLinks;
            }

            return [];
        }
    };
    const config = {
        labels: options.labels || {},
        loginUrl: 'https://digitalogic.test/login/',
        testHooks: {}
    };
    const window = {
        DigitalogicBranding: config,
        URL,
        location: { href: options.locationHref || 'https://digitalogic.test/login/' }
    };
    const context = {
        console,
        document,
        navigator: { language: options.lang || 'en-US' },
        Set,
        URL,
        URLSearchParams,
        window
    };

    window.window = window;
    vm.runInNewContext(brandingSource, context, { filename: 'admin-branding.js' });

    return {
        document,
        hooks: config.testHooks,
        recoveryField,
        recoveryLabel
    };
}

test('canonical login navigation labels follow href actions, not DOM position', () => {
    const lostPassword = fakeLink('/login/?action=lostpassword', [], 'Wrong one');
    const register = fakeLink('/login/?action=register', [], 'Wrong two');
    const harness = createHarness({
        navLinks: [lostPassword, register],
        labels: {
            lostPassword: 'Reset password',
            register: 'Register',
            login: 'Log in'
        }
    });

    harness.hooks.normalizeAuthChrome();

    assert.equal(lostPassword.textContent, 'Reset password');
    assert.equal(register.textContent, 'Register');
});

test('lost-password navigation keeps login and registration meanings in either order', () => {
    const register = fakeLink('?action=register', ['wp-login-register'], 'Reset password');
    const login = fakeLink('/login/', ['wp-login-log-in'], 'Register');
    const unknown = fakeLink('https://example.test/help', [], 'Help');
    const harness = createHarness({
        bodyClasses: ['login-action-lostpassword'],
        locationHref: 'https://digitalogic.test/wp-login.php?action=lostpassword',
        navLinks: [register, login, unknown],
        labels: {
            lostPassword: 'Reset password',
            register: 'Register',
            login: 'Log in'
        }
    });

    harness.hooks.normalizeAuthChrome();

    assert.equal(register.textContent, 'Register');
    assert.equal(login.textContent, 'Log in');
    assert.equal(unknown.textContent, 'Help');
});

test('lost-password navigation applies the Persian login and registration labels', () => {
    const login = fakeLink('/login/', ['wp-login-log-in'], 'ثبت نام');
    const register = fakeLink('?action=register', ['wp-login-register'], 'بازیابی رمز عبور');
    const harness = createHarness({
        bodyClasses: ['login-action-lostpassword'],
        lang: 'fa-IR',
        locationHref: 'https://digitalogic.test/wp-login.php?action=lostpassword',
        navLinks: [login, register],
        labels: {
            lostPassword: 'بازیابی رمز عبور',
            register: 'ثبت نام',
            login: 'ورود'
        }
    });

    harness.hooks.normalizeAuthChrome();

    assert.equal(login.textContent, 'ورود');
    assert.equal(register.textContent, 'ثبت نام');
});

test('Digits login transition retains its class behavior and gains an HTTP fallback', () => {
    const loginTransition = fakeLink('#', ['digits-form_toggle_login_register', 'show_login'], 'اکنون وارد شوید');
    const harness = createHarness({ digitsLinks: [loginTransition] });

    harness.hooks.normalizeAuthChrome();

    assert.equal(loginTransition.getAttribute('href'), 'https://digitalogic.test/login/');
    assert.equal(loginTransition.getAttribute('data-dg-http-fallback'), 'login');
    assert.equal(loginTransition.classList.contains('show_login'), true);
});

test('WordPress recovery describes only its supported username or email identity', () => {
    const harness = createHarness({
        bodyClasses: ['login-action-lostpassword'],
        locationHref: 'https://digitalogic.test/wp-login.php?action=lostpassword',
        hasRecoveryForm: true,
        recoveryText: 'Username, email, or mobile number',
        labels: { recoveryIdentity: 'Username or email address' }
    });

    harness.hooks.normalizeAuthChrome();

    assert.equal(harness.recoveryLabel.textContent, 'Username or email address');
    assert.equal(harness.recoveryField.getAttribute('placeholder'), 'Username or email address');
    assert.equal(harness.recoveryField.getAttribute('aria-label'), 'Username or email address');
});

test('real startup order cannot restore mobile wording on WordPress recovery', () => {
    const harness = createHarness({
        bodyClasses: ['login-action-lostpassword'],
        lang: 'fa-IR',
        locationHref: 'https://digitalogic.test/wp-login.php?action=lostpassword',
        hasRecoveryForm: true,
        recoveryText: 'نام کاربری، ایمیل یا شماره موبایل',
        labels: {
            recoveryIdentity: 'نام کاربری یا نشانی ایمیل',
            usernameEmailPhone: 'نام کاربری، ایمیل یا شماره موبایل'
        }
    });

    // DOMContentLoaded currently normalizes auth chrome before login labels.
    harness.hooks.normalizeAuthChrome();
    harness.hooks.enhanceLoginLabels();

    assert.match(harness.recoveryLabel.innerHTML, /نام کاربری یا نشانی ایمیل/);
    assert.doesNotMatch(harness.recoveryLabel.innerHTML, /موبایل/);
});

test('one canonical identity normalizer handles Persian and Arabic digits without spaces', () => {
    const harness = createHarness({});

    assert.equal((brandingSource.match(/function normalizeDigits\(/g) || []).length, 1);
    assert.equal(harness.hooks.normalizeDigits('۰۹۱۲٣٤٥٦٧٨٩'), '09123456789');
    assert.equal(harness.hooks.sanitizeIdentityValue('۰۹۱۲ ۳۴۵ ۶۷۸۹'), '09123456789');
    assert.equal(harness.hooks.sanitizeIdentityValue(' mahdi+tag @ example.com '), 'mahdi+tag@example.com');
    assert.equal(harness.hooks.classifyIdentity('mahdi@example.com'), 'email');
    assert.equal(harness.hooks.classifyIdentity('09123456789'), 'phone');
    assert.equal(harness.hooks.classifyIdentity('mahdi_user'), 'username');

    const phone = harness.hooks.phonePartsFromValue('+989123456789');
    assert.equal(phone.type, 'phone');
    assert.equal(phone.national, '9123456789');
    assert.equal(phone.countryCode, '+98');
    assert.equal(phone.valid, true);
});

test('password visibility restoration preserves the user-selected range and direction', () => {
    const harness = createHarness({});
    const restored = [];
    const input = {
        selectionStart: 2,
        selectionEnd: 7,
        selectionDirection: 'backward',
        focus() {},
        setSelectionRange(start, end, direction) {
            restored.push({ start, end, direction });
        }
    };
    harness.document.activeElement = input;

    const restore = harness.hooks.capturePasswordSelection(input);
    input.selectionStart = 0;
    input.selectionEnd = 0;
    restore();

    assert.deepEqual(restored, [{ start: 2, end: 7, direction: 'backward' }]);
});

test('native Digits captcha remains active unless a configured replacement is prepared', () => {
    const start = brandingSource.indexOf('function prepareDigitsCaptchaProtection');
    const end = brandingSource.indexOf('function bindDigitsAutoIdentity', start);
    const protection = brandingSource.slice(start, end);

    assert.ok(start > 0 && end > start);
    assert.match(protection, /prepareDigitsRecaptcha\(row\)/);
    assert.doesNotMatch(protection, /input\.disabled\s*=\s*true/);
    assert.doesNotMatch(protection, /removeAttribute\('required'\)/);
    assert.match(brandingCss, /\.digits_captcha_row\[hidden\]\s*\{\s*display:\s*none\s*!important;/s);
    assert.doesNotMatch(brandingCss, /:is\(\.digits_captcha_row,\s*\.dg_min_capt,[^}]+display:\s*none/s);
});

test('checkbox, localized step controls, and language picker use one accessible implementation', () => {
    assert.doesNotMatch(brandingSource + brandingCss, /M5 10\.4l3\.2 3\.2L15\.4 6\.5/);
    assert.match(brandingCss, /digits_login_remember_me:checked::before/);
    assert.match(brandingSource, /getLabel\('back', 'Back'\)/);
    assert.match(brandingSource, /getLabel\('login', 'Log in'\)/);
    assert.match(brandingSource, /trigger\.addEventListener\('keydown'/);
    assert.match(brandingSource, /menu\.addEventListener\('keydown'/);
    assert.match(brandingSource, /event\.key === 'ArrowDown'/);
    assert.match(brandingSource, /event\.key === 'Escape'/);
    assert.match(brandingSource, /dashicons-arrow-down-alt2/);
    assert.match(brandingSource, /dashicons-arrow-up-alt2/);
});
