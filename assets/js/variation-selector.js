(function ($) {
    'use strict';

    var sequence = 0;
    var instances = new WeakMap();

    function element(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    // Persian/Arabic keyboard variants and digits should match the same model.
    function normalize(value) {
        return String(value || '').normalize('NFKC').toLocaleLowerCase()
            .replace(/ي|ى/g, 'ی').replace(/ك/g, 'ک')
            .replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
            .replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); })
            .replace(/[\u064b-\u065f\u200c\u200d]/g, '').trim();
    }

    function svgIcon(pathData, className) {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        var path = document.createElementNS(namespace, 'path');
        svg.setAttribute('class', className || '');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        path.setAttribute('d', pathData);
        svg.append(path);
        return svg;
    }

    function media(item, label) {
        if (item.image) {
            try {
                var imageURL = new URL(item.image, document.baseURI);
                if (imageURL.protocol === 'https:' || imageURL.protocol === 'http:') {
                    var image = element('img', 'digitalogic-model-image');
                    image.src = imageURL.href;
                    image.alt = item.title ? label + ' ' + item.title : label;
                    image.width = 52;
                    image.height = 52;
                    image.loading = 'lazy';
                    return image;
                }
            } catch (_) { /* Invalid media falls through to the branded placeholder. */ }
        }
        var placeholder = element('span', 'digitalogic-model-image digitalogic-model-image--placeholder');
        placeholder.setAttribute('role', 'img');
        placeholder.setAttribute('aria-label', label);
        placeholder.append(svgIcon('M5 5h14v14H5zM8 15l3-3 2 2 2-2 2 3M9 9h.01', 'digitalogic-model-placeholder-icon'));
        return placeholder;
    }

    function initialize(root) {
        if (instances.has(root)) return;
        var select = root.querySelector('select');
        var form = root.closest('form.variations_form');
        if (!select || !form) return;
        var data;
        try { data = JSON.parse(root.getAttribute('data-digitalogic-model-selector')); }
        catch (_) { return; }
        if (!data || !Array.isArray(data.items)) return;

        var id = 'digitalogic-model-' + (++sequence);
        var button = element('button', 'digitalogic-model-trigger');
        button.id = id + '-trigger';
        button.type = 'button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', id + '-list');
        var popup = element('div', 'digitalogic-model-popup');
        popup.hidden = true;
        if (typeof popup.showPopover === 'function') popup.setAttribute('popover', 'manual');
        var search = element('input', 'digitalogic-model-search');
        search.type = 'search';
        search.autocomplete = 'off';
        search.placeholder = data.search;
        search.setAttribute('aria-label', data.search);
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-expanded', 'false');
        search.setAttribute('aria-controls', id + '-list');
        var list = element('div', 'digitalogic-model-list');
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', data.label);
        var empty = element('div', 'digitalogic-model-empty', data.empty);
        empty.setAttribute('role', 'status');
        empty.hidden = true;
        popup.append(search, list, empty);
        root.append(button, popup);
        var active = -1;
        var visible = [];
        var options = [];

        function metadata(option) {
            var matches = data.items.filter(function (item) {
                if (item.value !== option.value) return false;
                return Array.from(form.querySelectorAll('.variations select')).every(function (other) {
                    var expected = item.attributes && item.attributes[other.name];
                    return other === select || !other.value || !expected || expected === other.value;
                });
            });
            // An option may map to several children until another attribute is
            // selected. Do not attribute one child's SKU/image to all of them.
            return matches.length === 1 ? matches[0] : {};
        }

        function content(container, option, item) {
            container.replaceChildren();
            container.append(media(item, data.imageLabel || 'تصویر مدل'));
            var copy = element('span', 'digitalogic-model-copy');
            copy.append(element('span', 'digitalogic-model-title', item.title || option.textContent || data.placeholder));
            if (item.description) copy.append(element('span', 'digitalogic-model-description', item.description));
			var productCode = item.productCode || item.sku;
            if (productCode) {
				var sku = element('span', 'digitalogic-model-sku');
				var skuIcon = element('span', 'digitalogic-model-sku__icon');
				skuIcon.setAttribute('aria-hidden', 'true');
				skuIcon.append(svgIcon('M5 4h11l3 3v13H5zM15 4v4h4M8 12h8M8 16h5', ''));
				var skuLabel = element('span', 'digitalogic-model-sku__label', data.skuLabel + ':');
				var code = element('bdi', '', productCode);
				code.dir = 'ltr';
				sku.append(skuIcon, skuLabel, code);
                copy.append(sku);
            }
            container.append(copy);
			if (item.available === false) {
				var unavailable = element('span', 'digitalogic-model-availability', data.unavailable || 'ناموجود');
				unavailable.setAttribute('role', 'status');
				container.append(unavailable);
			} else if (item.priceRaw && /^\d+(?:\.\d+)?$/.test(String(item.priceRaw)) && item.priceText) {
				var price = element('span', 'digitalogic-model-price', item.priceText);
				price.setAttribute('data-price-raw', String(item.priceRaw));
				container.append(price);
			}
        }

        function setActive(index, scroll) {
            active = index;
            visible.forEach(function (entry, i) { entry.node.classList.toggle('is-active', i === active); });
            if (visible[active]) {
                search.setAttribute('aria-activedescendant', visible[active].node.id);
                if (scroll) visible[active].node.scrollIntoView({ block: 'nearest' });
            } else search.removeAttribute('aria-activedescendant');
        }

        function filter() {
            var query = normalize(search.value);
            visible = [];
            options.forEach(function (entry) {
                entry.node.hidden = !!query && entry.search.indexOf(query) === -1;
                if (!entry.node.hidden && !entry.option.disabled && entry.item.available !== false) visible.push(entry);
            });
            empty.hidden = options.some(function (entry) { return !entry.node.hidden; });
            var selectedIndex = visible.findIndex(function (entry) { return entry.option.value === select.value; });
            setActive(selectedIndex >= 0 ? selectedIndex : (visible.length ? 0 : -1), false);
        }

        function positionPopup() {
            if (popup.hidden) return;
            var rect = button.getBoundingClientRect();
            var viewport = window.visualViewport;
            var top = viewport ? viewport.offsetTop : 0;
            var bottom = top + (viewport ? viewport.height : window.innerHeight);
            var below = bottom - rect.bottom - 14;
            var above = rect.top - top - 14;
            var upward = below < 260 && above > below;
            var available = Math.max(110, upward ? above : below);
            popup.style.width = rect.width + 'px';
            popup.style.left = rect.left + 'px';
            list.style.maxHeight = Math.min(340, Math.max(60, available - 70)) + 'px';
            popup.style.top = (upward ? Math.max(top + 8, rect.top - popup.offsetHeight - 6) : rect.bottom + 6) + 'px';
        }

        function close(restoreFocus) {
            if (popup.hasAttribute('popover') && popup.matches(':popover-open')) popup.hidePopover();
            popup.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            root.classList.remove('is-open');
            if (restoreFocus) button.focus();
        }

        function sync() {
            var selected = select.options[select.selectedIndex];
            content(button, selected || { textContent: data.placeholder }, selected && selected.value ? metadata(selected) : {});
            button.setAttribute('aria-label', data.label + ': ' + button.textContent);
            button.disabled = select.disabled;
            list.replaceChildren();
            options = [];
            Array.from(select.options).forEach(function (option) {
                if (!option.value) return;
                var item = metadata(option);
                var node = element('div', 'digitalogic-model-option');
				var unavailable = item.available === false;
                node.id = id + '-option-' + options.length;
                node.setAttribute('role', 'option');
                node.setAttribute('aria-selected', String(option.selected));
				node.setAttribute('aria-disabled', String(option.disabled || unavailable));
				node.classList.toggle('is-unavailable', unavailable);
                content(node, option, item);
				var entry = { node: node, option: option, item: item, search: normalize([option.textContent, item.title, item.description, item.productCode, item.sku, item.priceText, unavailable ? data.unavailable : ''].join(' ')) };
                node.addEventListener('mousedown', function (event) { event.preventDefault(); });
                node.addEventListener('click', function () { choose(entry); });
                options.push(entry);
                list.append(node);
            });
            filter();
            if (select.disabled) close(false);
        }

        function choose(entry) {
            if (!entry || select.disabled || entry.option.disabled || entry.item.available === false || !select.contains(entry.option)) return;
            select.value = entry.option.value;
            // WooCommerce, Woodmart, Elementor and existing identity handlers
            // receive the same native select/change contract as before.
            $(select).trigger('change');
            sync();
            close(true);
        }

		function selectProductCode(event) {
			var detail = event && event.detail ? event.detail : {};
			var code = String(detail.code || '').trim();
			var scope = form.closest('.product-quick-view, .single-product-page, .product');
			if (!code || (detail.identity && scope && !scope.contains(detail.identity))) return;
			sync();
			var matching = options.filter(function (entry) {
				var candidate = entry.item && entry.item.productCode;
				return String(candidate || '').trim() === code;
			});
			if (matching.length === 1) choose(matching[0]);
		}

        function open() {
            if (button.disabled) return;
            search.value = '';
            sync();
            popup.hidden = false;
            root.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            search.setAttribute('aria-expanded', 'true');
            if (popup.hasAttribute('popover')) popup.showPopover();
            positionPopup();
            search.focus({ preventScroll: true });
        }


        button.addEventListener('click', function () { if (popup.hidden) open(); else close(false); });
        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                open();
                setActive(event.key === 'ArrowDown' ? 0 : visible.length - 1, true);
            }
        });
        search.addEventListener('input', filter);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); close(true); }
            else if (event.key === 'Tab') close(false);
            else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var next = active + (event.key === 'ArrowDown' ? 1 : -1);
                setActive(Math.max(0, Math.min(visible.length - 1, next)), true);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                choose(visible[active]);
            }
        });
        root.addEventListener('focusout', function () {
            window.setTimeout(function () { if (!root.contains(document.activeElement)) close(false); }, 0);
        });
        // One native select remains in the form for resets, submission, links,
        // and Woo's availability updates. Hide it only after initialization.
        if ($(select).data('select2') && $.fn.select2) $(select).select2('destroy');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.classList.add('digitalogic-model-native');
        root.classList.add('is-enhanced');
        Array.from(select.labels || []).forEach(function (label) { label.htmlFor = button.id; });
        $(form).on('change.digitalogicModel woocommerce_update_variation_values.digitalogicModel reset_data.digitalogicModel found_variation.digitalogicModel', sync);
        form.addEventListener('reset', function () { window.setTimeout(sync, 0); });
		document.addEventListener('digitalogic:select-product-code', selectProductCode);
        new MutationObserver(sync).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected'] });
        instances.set(root, { close: close, sync: sync, position: positionPopup });
        sync();
    }

    function scan(scope) {
        if (scope.matches && scope.matches('[data-digitalogic-model-selector]')) initialize(scope);
        scope.querySelectorAll('[data-digitalogic-model-selector]').forEach(initialize);
    }

    $(function () {
        scan(document);
        // Woo quick-view forms and Elementor render fragments after DOM ready.
        $(document).on('wc_variation_form', '.variations_form', function () { scan(this); });
        new MutationObserver(function (changes) {
            changes.forEach(function (change) {
                change.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1 && !node.closest('.digitalogic-model-popup, .digitalogic-model-trigger')) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
        function repositionOpen() {
            document.querySelectorAll('.digitalogic-model-selector.is-open').forEach(function (root) {
                instances.get(root).position();
            });
        }
        window.addEventListener('resize', repositionOpen);
        document.addEventListener('scroll', repositionOpen, true);
        if (window.visualViewport) window.visualViewport.addEventListener('resize', repositionOpen);
        document.addEventListener('pointerdown', function (event) {
            document.querySelectorAll('.digitalogic-model-selector.is-open').forEach(function (root) {
                if (!root.contains(event.target)) instances.get(root).close(false);
            });
        });
    });
})(jQuery);
