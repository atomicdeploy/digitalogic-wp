(function ($) {
    'use strict';

    var config = window.digitalogicStorefrontUX || {};
    var busyTimer = null;

    function ensureFeedback() {
        var node = document.querySelector('.digitalogic-storefront-feedback');
        if (node) return node;
        node = document.createElement('div');
        node.className = 'digitalogic-storefront-feedback';
        node.setAttribute('role', 'status');
        node.setAttribute('aria-live', 'polite');
        node.textContent = config.loadingLabel || 'در حال به‌روزرسانی نتایج…';
        document.body.appendChild(node);
        return node;
    }

    function setBusy(active) {
        clearTimeout(busyTimer);
        document.body.classList.toggle('digitalogic-storefront-is-loading', !!active);
        document.querySelectorAll('.products, .shop-loop-head').forEach(function (node) {
            if (active) node.setAttribute('aria-busy', 'true');
            else node.removeAttribute('aria-busy');
        });
        if (active) {
            ensureFeedback();
            busyTimer = setTimeout(function () { setBusy(false); }, 15000);
        }
    }

    function ensureCatalogFilters() {
        var products = document.querySelector('.products');
        if (!products) return;

        var current = new URL(window.location.href);
        var active = current.searchParams.get('dgl_availability') === 'instock';
        current.searchParams.delete('dgl_availability');
        current.searchParams.delete('product-page');
        current.searchParams.delete('paged');
        var allUrl = current.toString();
        current.searchParams.set('dgl_availability', 'instock');
        var stockUrl = current.toString();
        var nav = document.querySelector('.digitalogic-catalog-filters');

        if (!nav) {
            nav = document.createElement('nav');
            nav.className = 'digitalogic-catalog-filters';
            nav.setAttribute('aria-label', 'فیلتر موجودی');

            var label = document.createElement('span');
            label.className = 'digitalogic-catalog-filters__label';
            label.textContent = 'نمایش:';
            nav.appendChild(label);

            ['همه کالاها', 'فقط کالاهای موجود'].forEach(function (text) {
                var link = document.createElement('a');
                link.className = 'digitalogic-catalog-filter';
                link.setAttribute('data-digitalogic-ajax-filter', '');
                link.textContent = text;
                nav.appendChild(link);
            });

            var anchor = document.querySelector('.shop-loop-head') || products;
            anchor.parentNode.insertBefore(nav, anchor === products ? products : anchor.nextSibling);
        }

        var links = nav.querySelectorAll('.digitalogic-catalog-filter');
        if (links[0]) {
            links[0].href = allUrl;
            links[0].classList.toggle('is-active', !active);
        }
        if (links[1]) {
            links[1].href = stockUrl;
            links[1].classList.toggle('is-active', active);
        }
    }

    function replaceExactText(root) {
        (root || document).querySelectorAll('.chaty-channel .on-hover-text').forEach(function (node) {
            if (/^contact us$/i.test(node.textContent.trim())) node.textContent = 'تماس با ما';
        });
        (root || document).querySelectorAll('.tiered-pricing-dynamic-price-wrapper').forEach(function (node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (child) {
                if (child.nodeType === Node.TEXT_NODE && /^\s*from\s*/i.test(child.nodeValue || '')) {
                    child.nodeValue = (child.nodeValue || '').replace(/^\s*from\s*/i, 'از ');
                }
            });
        });
    }

    function bannerKind(text) {
        text = (text || '').replace(/\s+/g, ' ').toLowerCase();
        if (/نیمه.?هادی|semiconductor|آی.?سی|ترانزیستور/.test(text)) return 'semiconductors';
        if (/سنسور|حسگر|sensor/.test(text)) return 'sensors';
        if (/نمایشگر|display|lcd|oled/.test(text)) return 'displays';
        if (/الکترومکانیک|رله|موتور|کلید|electromechanical/.test(text)) return 'electromechanical';
        if (/پسیو|غیرفعال|مقاومت|خازن|passive/.test(text)) return 'passive';
        if (/ماژول|برد توسعه|module|development/.test(text)) return 'modules';
        return '';
    }

    function applyCategoryBanners(root) {
        var banners = config.banners || {};
        (root || document).querySelectorAll('.promo-banner').forEach(function (card) {
            var kind = bannerKind(card.textContent);
            var url = banners[kind];
            var wrapper = card.querySelector('.wrapper-content-banner');
            if (!kind || !url || !wrapper || wrapper.dataset.digitalogicBanner === kind) return;
            wrapper.dataset.digitalogicBanner = kind;
            wrapper.style.backgroundImage = 'url("' + url.replace(/"/g, '') + '")';
            card.querySelectorAll('.banner-image img, .wrapper-content-banner > img').forEach(function (img) {
                img.src = url;
                img.removeAttribute('srcset');
                img.alt = '';
            });
        });
    }

    function replaceResults(doc, url) {
        var selectors = ['.products', '.woocommerce-result-count', '.woocommerce-pagination', '.wd-products-per-page', '.digitalogic-catalog-filters'];
        var replaced = 0;
        selectors.forEach(function (selector) {
            var current = document.querySelector(selector);
            var incoming = doc.querySelector(selector);
            if (current && incoming) {
                current.replaceWith(incoming);
                replaced++;
            }
        });
        if (!replaced) throw new Error('catalog-fragment-missing');
        window.history.pushState({}, '', url);
        replaceExactText(document);
        ensureCatalogFilters();
        applyCategoryBanners(document);
        document.dispatchEvent(new CustomEvent('digitalogic:catalog-updated'));
    }

    function ajaxNavigate(url) {
        if (!url || !window.fetch) {
            window.location.href = url;
            return;
        }
        setBusy(true);
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { if (!response.ok) throw new Error('http-' + response.status); return response.text(); })
            .then(function (html) { replaceResults(new DOMParser().parseFromString(html, 'text/html'), url); setBusy(false); })
            .catch(function () { window.location.href = url; });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-digitalogic-ajax-filter], .wd-products-per-page a, .woocommerce-pagination a');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var url = link.href;
        if (!url || new URL(url, window.location.href).origin !== window.location.origin) return;
        event.preventDefault();
        ajaxNavigate(url);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.widget_price_filter form');
        if (!form) return;
        event.preventDefault();
        ajaxNavigate(form.action + (form.action.indexOf('?') === -1 ? '?' : '&') + new URLSearchParams(new FormData(form)).toString());
    });

    $(document).ajaxSend(function (_event, _xhr, settings) {
        if (/woodmart|woocommerce|products|price_filter/i.test((settings && settings.url) || '')) setBusy(true);
    }).ajaxComplete(function () { setBusy(false); replaceExactText(document); ensureCatalogFilters(); applyCategoryBanners(document); });

    window.addEventListener('pageshow', function () { setBusy(false); });
    window.addEventListener('popstate', function () { window.location.reload(); });

    var observer = new MutationObserver(function (records) {
        records.forEach(function (record) {
            record.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    replaceExactText(node);
                    applyCategoryBanners(node);
                }
            });
        });
    });

    function boot() {
        ensureFeedback();
        replaceExactText(document);
        ensureCatalogFilters();
        applyCategoryBanners(document);
        observer.observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})(jQuery);
