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

    window.addEventListener('message', function(event) {
        var data = event.data || {};
        if (!data || data.type !== 'digitalogic-desktop-product-action') {
            return;
        }

        runProductAction(data.action, data.id);
    });
})(window, document);
