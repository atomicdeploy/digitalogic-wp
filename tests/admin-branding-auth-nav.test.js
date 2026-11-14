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
        MutationObserver: options.MutationObserver,
        navigator: { language: options.lang || 'en-US', credentials: options.credentials },
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

test('a successful Digits login resumes its same-site OpenID request with the original parameters', () => {
    const {document, window, hooks} = createHarness({});
    const target = new URL('/wp-login.php', window.location.href);
    target.searchParams.set('action', 'openid-authenticate');
    target.searchParams.set('client_id', 'git-probe');
    target.searchParams.set('response_type', 'code');
    target.searchParams.set('state', 'probe+state/&=%');
    const hidden = {value: target.href};
    const form = {offsetWidth: 300, matches: () => true, closest: () => ({}), querySelector: () => hidden};
    document.activeElement = {closest: () => form};
    let guarded;
    window.jQuery = {ajax(settings) { guarded = settings; }};
    hooks.patchDigitsAjaxFailureGuard();
    window.jQuery.ajax({data: [{name: 'action', value: 'digits_forms_ajax'}]});

    const payload = {success: true, data: {process: true, process_type: 'login', redirect: '/', setup_biometric: true, force_biometric: true, delay: 1000}};
    guarded.success(payload);
    assert.equal(payload.data.redirect, target.href);
    assert.equal(payload.data.setup_biometric, false);
    assert.equal(payload.data.force_biometric, false);
    assert.equal(payload.data.delay, 0);

    payload.data.redirect = '/';
    payload.success = false;
    guarded.success(payload);
    assert.equal(payload.data.redirect, '/');

    payload.success = true;
    hidden.value = target.href.replace('digitalogic.test', 'other.test');
    guarded.success(payload);
    assert.equal(payload.data.redirect, '/');
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

test('skip password selects the native SMS tab without sending the typed password', () => {
    let update;
    const harness = createHarness({
        labels: {skipPassword: 'ورود بدون رمز عبور', skipPasswordCaption: 'فراموشی رمز عبور'},
        MutationObserver: class {
            constructor(callback) { update = callback; }
            observe() {}
        }
    });
    const listeners = {};
    const createElement = (tagName) => ({
        tagName,
        children: [],
        attributes: new Map(),
        classList: fakeClassList(),
        addEventListener(name, callback) { listeners[name] = callback; },
        setAttribute(name, value) { this.attributes.set(name, value); },
        querySelector() { return null; },
        append(...children) { this.children.push(...children); },
        appendChild(child) { this.append(child); },
        prepend(child) { this.children.unshift(child); }
    });
    const wrapper = {};
    let smsClicks = 0;
    const otpTab = {click: () => { smsClicks++; }};
    const step = {querySelector: (selector) => selector.includes('data-value') ? otpTab : {}};
    const password = {value: 'test-input', offsetWidth: 0, getClientRects: () => [], closest: () => step};
    let action;
    const primary = {after: (node) => { action = node; }};
    const form = {
        dataset: {},
        querySelector: (selector) => selector === '.digits-form_tab_wrapper' ? wrapper : primary,
        querySelectorAll: (selector) => selector.includes('sms_otp') ? [] : [password],
        addEventListener() {}
    };
    harness.document.createElement = createElement;
    harness.document.createElementNS = (namespaceURI, tag) => ({...createElement(tag), namespaceURI});
    harness.window.addEventListener = () => {};
    harness.document.querySelectorAll = (selector) => selector === '.dg-digits-login-shell form' ? [form] : [];
    harness.hooks.normalizeAuthChrome();
    const [skip, caption] = action.children;
    const [icon, label] = skip.children;
    assert.equal(skip.type, 'button');
    assert.equal(icon.tagName, 'svg');
    assert.equal(label.textContent, 'ورود بدون رمز عبور');
    assert.equal(caption.tagName, 'p');
    assert.equal(caption.textContent, 'فراموشی رمز عبور');
    assert.equal(skip.attributes.get('aria-describedby'), caption.id);
    assert.equal(action.hidden, true);
    password.offsetWidth = 200;
    update();
    assert.equal(action.hidden, false);
    listeners.click();
    assert.equal(password.value, '');
    assert.equal(smsClicks, 1);
    password.disabled = true;
    update();
    assert.equal(action.hidden, true);
});

test('WebOTP can receive the code before the SMS response and submits once the Digits field is ready', async () => {
    let receive;
    let submitted = 0;
    let request;
    let signal;
    const harness = createHarness({credentials: {get(options) {
        signal = options.signal;
        return new Promise((resolve) => { receive = resolve; });
    }}});
    harness.window.OTPCredential = function () {};
    harness.window.AbortController = AbortController;
    harness.window.clearTimeout = () => {};
    harness.document.body.classList.remove = () => {};
    harness.window.jQuery = {ajax(settings) { request = settings; }};
    const otp = {value: '', offsetWidth: 200};
    const primary = {offsetWidth: 200, click: () => { submitted++; }};
    const form = {
        matches: () => true,
        closest: () => ({}),
        offsetWidth: 200,
        querySelectorAll: (selector) => selector.includes('sms_otp') ? [otp] : [primary]
    };
    harness.document.activeElement = {closest: () => form};
    harness.hooks.patchDigitsAjaxFailureGuard();
    harness.window.jQuery.ajax({data: [{name: 'action', value: 'digits_forms_ajax'}, {name: 'sub_action', value: 'sms_otp'}]});
    request.beforeSend();
    const sendRequest = request;
    harness.window.jQuery.ajax({data: [{name: 'action', value: 'digits_forms_ajax'}, {name: 'check_status', value: '1'}, {name: 'sms_otp', value: '123456'}]});
    assert.equal(request.beforeSend, undefined);
    assert.equal(request.data.find(field => field.name === 'sms_otp').value, '');
    assert.equal(signal.aborted, false);
    receive({code: '123456'});
    await Promise.resolve();
    assert.equal(submitted, 0);
    const payload = {success: true, data: {auto_fill: true, check_remote_status: true}};
    sendRequest.success(payload);
    sendRequest.complete();
    assert.equal(payload.data.auto_fill, false);
    assert.equal(payload.data.check_remote_status, false);
    assert.equal(otp.value, '123456');
    assert.equal(submitted, 1);
    assert.equal(signal.aborted, true);
});

test('carousel slide track uses the same left-to-right geometry in RTL', () => {
    assert.match(brandingCss, /body\.login \.dg-hero-track\s*\{[^}]*\bdirection:\s*ltr;/);
});

test('ordinary Enter remains ordinary when a secondary SMS button exists', () => {
    const harness=createHarness({});
    const listeners={};
    let skipClicks=0;
    let validityChecks=0;
    harness.window.addEventListener=()=>{};
    harness.window.clearTimeout=()=>{};
    harness.document.addEventListener=(name,callback)=>{listeners[name]=callback;};
    const skip={click(){skipClicks++;}};
    const form={dataset:{},matches:()=>true,querySelector:()=>skip,querySelectorAll:()=>[],checkValidity(){validityChecks++;return false;}};
    harness.hooks.bindLoadingStates();
    listeners.submit({target:form,submitter:{matches:()=>false},preventDefault(){}});
    assert.equal(skipClicks,0);
    assert.equal(validityChecks,1);
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
    assert.equal(harness.recoveryField.getAttribute('dir'), 'auto');
    assert.equal(harness.recoveryField.getAttribute('placeholder'), undefined);
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

    harness.hooks.enhanceLoginLabels();
    harness.hooks.normalizeAuthChrome();

    assert.equal(harness.recoveryLabel.textContent, 'نام کاربری یا نشانی ایمیل');
    assert.equal(harness.recoveryLabel.dataset.dgLabelReady, undefined);
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
