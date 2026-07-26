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

    function announceSession() {
        var panel = window.digitalogicPanel || {};
        var websocket = panel.websocket || {};
        if (!websocket.enabled || !websocket.url || !websocket.nonce || !panel.user || !panel.user.id) {
            return;
        }

        window.dispatchEvent(new CustomEvent('digitalogic:desktop-session', {
            detail: {
                websocket: {
                    url: String(websocket.url),
                    nonce: String(websocket.nonce),
                    reconnect_interval: Number(websocket.reconnect_interval || 3000),
                    request_timeout: Number(websocket.request_timeout || 15000)
                },
                user: {
                    id: Number(panel.user.id),
                    display_name: String(panel.user.display_name || '')
                },
                event_cursor: Number(panel.event_cursor || 0),
                capabilities: ['panel', 'event_mesh']
            }
        }));
    }

    function panelVm() {
        var root = document.getElementById('digitalogic-panel');
        var app = root && root.__vue_app__;
        return app && app._instance && app._instance.proxy ? app._instance.proxy : null;
    }

    function runProductAction(action, id) {
        var vm = panelVm();
        var product = vm && typeof vm.productById === 'function' ? vm.productById(id) : null;
        if (!vm || !product) {
            return false;
        }

        if (action === 'view' && typeof vm.viewProduct === 'function') {
            vm.viewProduct(product);
            return true;
        }

        if (action === 'edit' && typeof vm.openProductPanel === 'function') {
            vm.openProductPanel(product, {reveal: true});
            return true;
        }

        if (action === 'modal' && typeof vm.openProductDialog === 'function') {
            vm.openProductDialog(product);
            return true;
        }

        if (action === 'woocommerce' && typeof vm.editProductPage === 'function') {
            vm.editProductPage(product);
            return true;
        }

        if (action === 'copy' && typeof vm.copy === 'function') {
            vm.copy(product.sku || product.id);
            return true;
        }

        return false;
    }

    document.addEventListener('DOMContentLoaded', function() {
        focusFirstField();
        announceSession();
    });
    if (document.readyState !== 'loading') {
        announceSession();
    }

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

    window.addEventListener('message', function(event) {
        var data = event.data || {};
        if (!data || data.type !== 'digitalogic-desktop-product-action') {
            return;
        }

        runProductAction(data.action, data.id);
    });
})(window, document);
