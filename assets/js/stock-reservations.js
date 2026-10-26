(function () {
    'use strict';
    var config = window.DigitalogicCartStock;
    if (!config || !config.url) return;
    var blocked = false;
    var running = false;
    var again = false;
    var timer;
    var fingerprint = '';

    function actions() {
        return Array.from(document.querySelectorAll('#place_order, .checkout-button, .wc-block-cart__submit-button'));
    }

    function render(data) {
        blocked = !!data.blocked;
        document.querySelectorAll('[data-dg-cart-stock]').forEach(function (node) { node.remove(); });
        Object.keys(data.items || {}).forEach(function (key) {
            var item = data.items[key];
            var input = document.getElementsByName('cart[' + key + '][qty]')[0];
            var row = input && input.closest('.cart_item');
            if (!row) {
                var marker = document.querySelector('[data-dg-stock-key="' + key + '"]');
                row = marker && marker.closest('.cart_item');
            }
            if (!row && item.product_id) {
                var remove = document.querySelector('[data-cart_item_key="' + key + '"]');
                row = remove && remove.closest('.cart_item');
            }
            if (input && item.available !== null && item.available !== undefined && !item.backorders) input.max = String(item.available);
            if (row && item.message) {
                var notice = document.createElement('p');
                notice.dataset.dgCartStock = 'item';
                notice.setAttribute('role', 'status');
                notice.style.cssText = 'font-weight:600;margin:8px 0;color:' + (item.blocked ? '#a12622' : '#21653c');
                notice.textContent = item.message;
                (row.querySelector('.product-name') || row).appendChild(notice);
            }
        });
        if (blocked) {
            var form = document.querySelector('form.checkout, form.woocommerce-cart-form');
            if (form) {
                var notice = document.createElement('div');
                notice.dataset.dgCartStock = 'summary';
                notice.className = 'woocommerce-error';
                notice.setAttribute('role', 'alert');
                notice.textContent = 'موجودی بعضی کالاها تغییر کرده است. پیش از ادامه، تعداد و کالاهای سبد را بررسی کنید.';
                form.prepend(notice);
            }
        }
        actions().forEach(function (action) {
            if (blocked) {
                if (!action.hasAttribute('data-dg-stock-disabled')) {
                    action.dataset.dgStockDisabled = action.disabled ? 'already' : 'ours';
                    action.dataset.dgStockOpacity = action.style.opacity;
                    action.dataset.dgStockCursor = action.style.cursor;
                }
                if ('disabled' in action) action.disabled = true;
                action.setAttribute('aria-disabled', 'true');
                action.style.opacity = '0.5';
                action.style.cursor = 'not-allowed';
            } else if (action.hasAttribute('data-dg-stock-disabled')) {
                if ('disabled' in action && action.dataset.dgStockDisabled === 'ours') action.disabled = false;
                action.removeAttribute('aria-disabled');
                action.style.opacity = action.dataset.dgStockOpacity || '';
                action.style.cursor = action.dataset.dgStockCursor || '';
                delete action.dataset.dgStockDisabled;
                delete action.dataset.dgStockOpacity;
                delete action.dataset.dgStockCursor;
            }
        });
        document.documentElement.dataset.dgCartStockChecked = String(data.checked_at || '');
        document.documentElement.dataset.dgCartStockState = blocked ? 'blocked' : 'current';
        var next = JSON.stringify(data.items || {});
        if (fingerprint && fingerprint !== next && window.jQuery && document.querySelector('form.checkout')) {
            window.jQuery(document.body).trigger('update_checkout');
        }
        fingerprint = next;
    }

    function refresh() {
        if (document.visibilityState === 'hidden') return;
        if (running) { again = true; return; }
        running = true;
        fetch(config.url, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}})
            .then(function (response) { if (!response.ok) throw new Error('stock_http'); return response.json(); })
            .then(render)
            .catch(function () { document.documentElement.dataset.dgCartStockState = 'stale'; })
            .finally(function () { running = false; if (again) { again = false; schedule(); } });
    }

    function schedule() { window.clearTimeout(timer); timer = window.setTimeout(refresh, 200); }
    window.addEventListener('digitalogic:product-invalidated', schedule);
    window.addEventListener('focus', schedule);
    window.addEventListener('pageshow', schedule);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') schedule(); });
    document.addEventListener('click', function (event) {
        if (blocked && event.target.closest('#place_order, .checkout-button, .wc-block-cart__submit-button')) {
            event.preventDefault(); event.stopImmediatePropagation(); schedule();
        }
    }, true);
    if (window.jQuery) window.jQuery(document.body).on('updated_wc_div updated_cart_totals updated_checkout added_to_cart removed_from_cart', schedule);
    window.setInterval(refresh, Number(config.interval) || 15000);
    schedule();
}());
