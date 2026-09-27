(function () {
    'use strict';

    var config = window.DigitalogicAdminTheme || {};
    var root = document.documentElement;
    var key = config.storageKey || 'digitalogic-admin-theme-v1';
    var theme = '';

    try {
        theme = window.localStorage.getItem(key) || '';
    } catch (error) {
        theme = '';
    }

    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    root.setAttribute('data-digitalogic-admin-theme', theme);

    function syncControls() {
        var labels = config.labels || {};
        var next = theme === 'dark' ? (labels.light || 'حالت روشن') : (labels.dark || 'حالت تیره');

        document.querySelectorAll('[data-digitalogic-admin-theme-label]').forEach(function (label) {
            label.textContent = next;
        });

        document.querySelectorAll(
            '[data-digitalogic-admin-theme-toggle], #wp-admin-bar-digitalogic-admin-theme-toggle > a'
        ).forEach(function (control) {
            control.setAttribute('aria-label', labels.toggle || 'تغییر حالت روشن و تیره');
            control.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    function toggle(event) {
        if (event) {
            event.preventDefault();
        }

        theme = theme === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-digitalogic-admin-theme', theme);

        try {
            window.localStorage.setItem(key, theme);
        } catch (error) {
            // Storage can be unavailable in hardened/private browser contexts.
        }

        syncControls();
    }

    function bind() {
        document.querySelectorAll(
            '[data-digitalogic-admin-theme-toggle], #wp-admin-bar-digitalogic-admin-theme-toggle > a'
        ).forEach(function (control) {
            control.addEventListener('click', toggle);
        });
        syncControls();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind, { once: true });
    } else {
        bind();
    }
}());


