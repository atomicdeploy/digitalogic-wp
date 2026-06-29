(function(window, document) {
    'use strict';

    document.documentElement.setAttribute('data-digitalogic-desktop', '1');

    function focusFirstField() {
        var field = document.querySelector('input:not([type="hidden"]):not([disabled]), button, [href], [tabindex]:not([tabindex="-1"])');
        if (field && document.activeElement === document.body) {
            field.focus();
        }
    }

    function announceFiles(files) {
        window.dispatchEvent(new CustomEvent('digitalogic:desktop-files', {detail: files}));
    }

    document.addEventListener('DOMContentLoaded', focusFirstField);

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            var active = document.activeElement;
            if (active && active.blur) {
                active.blur();
            }
        }
    });

    window.addEventListener('digitalogic-desktop-files', function(event) {
        announceFiles(event.detail || []);
    });

    window.addEventListener('digitalogic:panel-preferences', function(event) {
        if (!event.detail) {
            return;
        }
        document.documentElement.setAttribute('data-digitalogic-theme', event.detail.theme || 'system');
    });
})(window, document);
