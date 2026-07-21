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

test('critical Digits rules cannot leak outside the Woodmart sidebar', () => {
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_tab_body\b/m);
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_tab-bar\b/m);
    assert.doesNotMatch(css, /(?:^|\})\s*\.digits-form_submit-btn\b/m);
});
