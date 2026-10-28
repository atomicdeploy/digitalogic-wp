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
        remove(value) {
            classes.delete(value);
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
        window,
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

test('native serialized Digits form requests are recognized without matching unrelated AJAX', () => {
    const {hooks} = createHarness({});
    assert.equal(hooks.isDigitsAjaxRequest({data:[{name:'action',value:'digits_forms_ajax'}]}),true);
    assert.equal(hooks.isDigitsAjaxRequest({data:[{name:'digits',value:'1'}]}),true);
    assert.equal(hooks.isDigitsAjaxRequest({data:[{name:'action',value:'unrelated'}]}),false);
    assert.equal(hooks.isDigitsAjaxRequest({data:{action:'digits_forms_ajax'}}),true);
    assert.equal(hooks.isDigitsAjaxRequest({data:'action=digits_forms_ajax'}),true);
});

test('empty or non-JSON AJAX results cannot enter the native Digits callback', () => {
    const {hooks} = createHarness({});
    for(const payload of ['', '0', '<html>Unavailable</html>', null, {}, {success:true,data:{}}]) {
        assert.equal(hooks.hasUsableDigitsPayload(payload),false);
    }
    assert.equal(hooks.hasUsableDigitsPayload({success:false,data:{message:'Invalid mobile'}}),true);
    assert.equal(hooks.hasUsableDigitsPayload({success:true,data:{html:'<div>Next step</div>'}}),true);
});

test('client validation rejection does not schedule a misleading registration timeout', () => {
    const harness=createHarness({});
    const listeners={};
    const timers=[];
    const form={matches:()=>true,checkValidity:()=>true};
    const button={closest:()=>form};
    harness.document.addEventListener=(name,callback)=>{listeners[name]=callback;};
    harness.window.addEventListener=()=>{};
    harness.window.setTimeout=(callback)=>{timers.push(callback);};
    harness.hooks.bindLoadingStates();
    // HTML required fields are populated, but native strength/captcha validation
    // may reject before AJAX. A click alone is not an in-flight request.
    listeners.click({target:{closest:()=>button}});
    assert.equal(timers.length,0);
});

test('Digits passkey clicks reach the native handler without ordinary identity validation', () => {
    const harness=createHarness({});
    const windowListeners={};
    harness.window.addEventListener=(name,callback)=>{windowListeners[name]=callback;};
    const button={matches:(s)=>s==='.digits-form_passwordless_login',closest(){throw new Error('Ordinary form path must not run');}};
    let prevented=0;
    harness.hooks.bindLoadingStates();
    windowListeners.click({target:{closest:()=>button},preventDefault(){prevented++;},stopPropagation(){prevented++;},stopImmediatePropagation(){prevented++;}});
    assert.equal(prevented,0);
});

test('explicit passkey submitter delegates once to its native click instead of an HTTP form POST', () => {
    const harness=createHarness({});
    const listeners={};
    let clicks=0;
    let prevented=0;
    harness.window.addEventListener=()=>{};
    harness.document.addEventListener=(name,callback)=>{listeners[name]=callback;};
    harness.hooks.bindLoadingStates();
    listeners.submit({target:{matches:()=>true},submitter:{matches:()=>true,click(){clicks++;}},preventDefault(){prevented++;}});
    assert.equal(clicks,1);
    assert.equal(prevented,1);
});

test('ordinary Enter remains ordinary when a secondary passkey button exists', () => {
    const harness=createHarness({});
    const listeners={};
    let passkeyClicks=0;
    let validityChecks=0;
    harness.window.addEventListener=()=>{};
    harness.window.clearTimeout=()=>{};
    harness.document.addEventListener=(name,callback)=>{listeners[name]=callback;};
    const hiddenPasskey={click(){passkeyClicks++;}};
    const form={matches:()=>true,querySelector:()=>hiddenPasskey,querySelectorAll:()=>[],checkValidity(){validityChecks++;return false;}};
    harness.hooks.bindLoadingStates();
    listeners.submit({target:form,submitter:{matches:()=>false},preventDefault(){}});
    assert.equal(passkeyClicks,0);
    assert.equal(validityChecks,1);
});

test('both native passkey request stages remain compatible with the AJAX guard', () => {
    const {hooks}=createHarness({});
    for(const stage of ['start_passwordless_login','complete_passwordless_login']){
        assert.equal(hooks.isDigitsAjaxRequest({data:[{name:'action',value:'digits_forms_ajax'},{name:'sub_action',value:stage}]}),true);
    }
    assert.equal(hooks.hasUsableDigitsPayload({success:true,data:{token:'synthetic-challenge'}}),true);
});

test('real requests start loading only in beforeSend and cancellation preserves native behavior', () => {
    const harness=createHarness({});
    let guarded;
    let before=0;
    let completed=0;
    const timers=[];
    harness.window.jQuery={ajax(settings){guarded=settings;return 'xhr';}};
    harness.window.clearTimeout=()=>{};
    harness.window.setTimeout=(callback,delay)=>{timers.push(delay);return 1;};
    const attributes=new Map();
    const form={
        dataset:{},classList:fakeClassList(),offsetWidth:300,
        matches:()=>true,
        setAttribute:(k,v)=>attributes.set(k,v),
        removeAttribute:(k)=>attributes.delete(k),
        querySelectorAll:(s)=>s.includes('.digits-form_submit-btn')?[button]:[]
    };
    const button={
        dataset:{},classList:fakeClassList(),offsetWidth:200,disabled:false,
        closest:()=>form,setAttribute(){},removeAttribute(){},matches:()=>false
    };
    harness.document.activeElement={closest:()=>form};
    harness.hooks.patchDigitsAjaxFailureGuard();
    harness.window.jQuery.ajax({
        data:[{name:'action',value:'digits_forms_ajax'}],
        beforeSend(){before++;return false;},complete(){completed++;}
    });
    assert.equal(guarded.timeout,30000);
    assert.equal(timers.length,0);
    assert.equal(guarded.beforeSend(),false);
    assert.equal(before,1);
    assert.equal(timers.length,0);
    guarded.complete();
    assert.equal(completed,1);
    harness.window.jQuery.ajax({data:[{name:'digits',value:'1'}]});
    guarded.beforeSend();
    assert.equal(button.dataset.dgLoading,'true');
    assert.equal(form.dataset.dgPending,'true');
    assert.deepEqual(timers,[35000]);
});

test('registration autofill identifies separate names and keeps already filled values intact', () => {
    const harness=createHarness({});
    const fields={};
    for(const name of ['digits_reg_name','digits_reg_lastname','digits_reg_username','phone','digits_reg_password']){
        const attrs=new Map();
        fields[name]={id:name==='digits_reg_lastname'?'native-family-id':'',value:'already-filled',attrs,setAttribute:(k,v)=>attrs.set(k,v)};
    }
    const formAttrs=new Map();
    const form={setAttribute:(k,v)=>formAttrs.set(k,v),querySelector:(s)=>fields[s.match(/name="([^"]+)"/)[1]]};
    harness.document.querySelectorAll=()=>[form];
    harness.document.getElementById=()=>null;
    harness.hooks.prepareDigitsRegistrationAutofill();
    harness.hooks.prepareDigitsRegistrationAutofill();
    assert.equal(formAttrs.get('autocomplete'),'on');
    assert.equal(fields.digits_reg_name.attrs.get('autocomplete'),'section-digitalogic-register given-name');
    assert.equal(fields.digits_reg_lastname.attrs.get('autocomplete'),'section-digitalogic-register family-name');
    assert.equal(fields.digits_reg_username.attrs.get('autocomplete'),'section-digitalogic-register username');
    assert.equal(fields.phone.attrs.get('autocomplete'),'section-digitalogic-register tel-national');
    assert.equal(fields.digits_reg_password.attrs.get('autocomplete'),'section-digitalogic-register new-password');
    assert.equal(fields.digits_reg_lastname.id,'native-family-id');
    for(const field of Object.values(fields)){assert.equal(field.value,'already-filled');assert.ok(field.id);}
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
