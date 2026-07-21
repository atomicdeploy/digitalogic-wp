'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const css = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'css', 'sidebar-login.css'),
    'utf8'
);
const pluginSource = fs.readFileSync(
    path.join(__dirname, '..', 'digitalogic.php'),
    'utf8'
);
const accessibilityScript = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'sidebar-login.js'),
    'utf8'
);

test('the plugin boots the storefront sidebar integration', () => {
    assert.match(
        pluginSource,
        /require_once DIGITALOGIC_PLUGIN_DIR \. 'includes\/integrations\/class-digitalogic-sidebar-login\.php';/
    );
    assert.match(pluginSource, /Digitalogic_Sidebar_Login::init\(\);/);
});

test('sidebar styles hide every inactive Digits step and show only the active step', () => {
    assert.match(
        css,
        /\.login-form-side \.digits-form_tab_body:not\(\.digits-tab_active\)\s*\{[^}]*display:\s*none\s*!important/s
    );
    assert.match(
        css,
        /\.login-form-side \.digits-form_tab_body\.digits-tab_active\s*\{[^}]*display:\s*block\s*!important/s
    );
});

test('sidebar tab and control layout remains bounded and touch friendly', () => {
    assert.match(css, /\.login-form-side \.digits-form_tab-bar\s*\{[^}]*display:\s*flex/s);
    assert.match(css, /\.login-form-side \.digits-form_tab-bar \.digits-form_tab-item\s*\{[^}]*min-height:\s*44px/s);
    assert.match(css, /\.login-form-side \.digits-form_submit-btn\s*\{[^}]*width:\s*100%/s);
    assert.match(css, /\.login-form-side \.digits_otp_dest\s*\{[^}]*direction:\s*ltr/s);
    assert.match(css, /@media \(max-width:\s*380px\)/);
});

test('the RTL password-eye keeps its logical horizontal anchor', () => {
    assert.match(
        css,
        /\.login-form-side \.digits_password_eye\s*\{[^}]*right:\s*auto;[^}]*left:\s*auto;[^}]*inset-inline-end:\s*\.75rem;/s
    );
    assert.match(css, /\.login-form-side \.digits_password_eye\s*\{[^}]*position:\s*absolute/s);
});

test('submissions retain a visible scoped loading indicator', () => {
    assert.match(
        css,
        /\.login-form-side \.digits_loading_spinner\s*\{[^}]*color:\s*#fff\s*!important;[^}]*border:\s*\.15rem solid #fff;[^}]*border-top-color:\s*transparent;[^}]*animation:\s*digitalogic-sidebar-login-spin/s
    );
    assert.match(css, /@keyframes digitalogic-sidebar-login-spin/);
    assert.match(
        css,
        /\.login-form-side \.digits_form_processing\s*\{[^}]*pointer-events:\s*none/s
    );
});

test('dynamic Digits controls receive scoped keyboard semantics', () => {
    assert.match(accessibilityScript, /\.digits-form_tab-item/);
    assert.match(accessibilityScript, /\.digits-form_link/);
    assert.match(accessibilityScript, /\.digits_password_eye/);
    assert.match(
        accessibilityScript,
        /event\.key !== 'Enter' && event\.key !== ' '/
    );
    assert.match(
        accessibilityScript,
        /if \(!event\.repeat && \(!inSidebar \|\| !control\.closest\('\.digits_form_processing'\)\)\)\s*\{\s*control\.click\(\);/s
    );
    assert.match(accessibilityScript, /new MutationObserver\(\(records\) =>/);
    assert.match(accessibilityScript, /control\.closest\(sidebarSelector\)/);
});

test('body-appended Digits notices are marked and styled only for sidebar requests', () => {
    assert.match(accessibilityScript, /const noticeClass = 'digitalogic-sidebar-login-notice'/);
    assert.match(accessibilityScript, /sidebarNoticePending/);
    assert.match(accessibilityScript, /notice\.classList\.add\(noticeClass\)/);
    assert.match(accessibilityScript, /notice\.setAttribute\('role', 'alert'\)/);
    assert.match(
        css,
        /body > \.dig_popmessage\.digitalogic-sidebar-login-notice\s*\{[^}]*position:\s*fixed/s
    );
    assert.doesNotMatch(css, /\.login-form-side \.dig_popmessage/);
});

test('the PBX singleton mounts only after the active OTP resend control', () => {
    assert.match(accessibilityScript, /const callWidgetSelector = '\[data-digitalogic-sidebar-call-widget\]'/);
    assert.match(accessibilityScript, /\.digits-form_tab_body\.digits-tab_active/);
    assert.match(accessibilityScript, /body\.querySelector\('\.digits-form_resend_otp'\)/);
    assert.match(accessibilityScript, /const mountAnchor = resend\.closest\('\.digits-form_footer_content'\) \|\| resend/);
    assert.match(accessibilityScript, /mountAnchor\.nextElementSibling !== sidebarCallWidget/);
    assert.match(accessibilityScript, /mountAnchor\.insertAdjacentElement\('afterend', sidebarCallWidget\)/);
    assert.doesNotMatch(accessibilityScript, /resend\.insertAdjacentElement\('afterend', sidebarCallWidget\)/);
    assert.doesNotMatch(accessibilityScript, /cloneNode\(/);
});

test('the PBX singleton is parked through Digits rerenders and only prefills phone-like input', () => {
    assert.match(accessibilityScript, /const callParkingSelector = '\[data-digitalogic-sidebar-call-parking\]'/);
    assert.match(accessibilityScript, /sidebarCallParking\.append\(sidebarCallWidget\)/);
    assert.match(accessibilityScript, /sidebarCallWidget\.hidden = true/);
    assert.match(accessibilityScript, /\^\(\?:\\\+98\|0098\|98\|0\)\[0-9\]\{10\}\$/);
    assert.match(accessibilityScript, /const candidate = sidebar\.querySelector\('#username'\)\?\.value/);
    assert.doesNotMatch(accessibilityScript, /querySelector\('input\[name="digits_phone"\]'\)/);
    assert.match(accessibilityScript, /input\.value = candidate\.trim\(\)/);
});

test('the sidebar PBX panel remains bounded, RTL-safe, and touch friendly', () => {
    assert.match(css, /\.login-form-side \.digitalogic-call-verification--sidebar\s*\{[^}]*width:\s*100%/s);
    assert.match(css, /\.login-form-side \.digitalogic-call-verification--sidebar \*\s*\{[^}]*max-width:\s*100%;[^}]*min-width:\s*0;/s);
    assert.match(css, /\.digitalogic-call-toggle\s*\{[^}]*min-height:\s*44px/s);
    assert.match(css, /\[data-call-phone\]\s*\{[^}]*width:\s*100%;[^}]*min-height:\s*44px;[^}]*direction:\s*ltr;/s);
    assert.match(css, /\.digitalogic-call-code\s*\{[^}]*unicode-bidi:\s*isolate;/s);
    assert.match(css, /@media \(max-width:\s*380px\)/);
});

test('critical Digits rules cannot leak outside the Woodmart sidebar', () => {
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_tab_body\b/m);
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_tab-bar\b/m);
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_submit-btn\b/m);
});
