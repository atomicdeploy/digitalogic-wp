/* Display WooCommerce's selected variation result in builder price widgets. */
(function (factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
        return;
    }
    factory(jQuery, document);
}(function ($, document) {
    'use strict';

    function legacyPriceHtml(variation) {
        if (!variation || typeof variation.price_html !== 'string') { return null; }
        var html = variation.price_html.trim();
        return html ? html : null;
    }

    function validatedPriceHtml(variation) {
        if (!variation) { return null; }
        var ownsContract = Object.prototype.hasOwnProperty.call(variation, 'digitalogic_price_contract')
            || Object.prototype.hasOwnProperty.call(variation, 'digitalogic_price_raw')
            || Object.prototype.hasOwnProperty.call(variation, 'digitalogic_price_html');
        if (!ownsContract) { return legacyPriceHtml(variation); }
        if (String(variation.digitalogic_price_contract) !== '1') { return null; }
        if (typeof variation.digitalogic_price_raw !== 'string'
            || !/^\d+(?:\.\d+)?$/.test(variation.digitalogic_price_raw.trim())) {
            return null;
        }
        if (typeof variation.digitalogic_price_html !== 'string'
            || !variation.digitalogic_price_html.trim()) {
            return null;
        }

        var raw = variation.digitalogic_price_raw.trim();
        var template = document.createElement('template');
        template.innerHTML = variation.digitalogic_price_html.trim();
        var contract = template.content.querySelector('.digitalogic-variation-price-contract[data-digitalogic-price-raw]');
        if (!contract || contract.getAttribute('data-digitalogic-price-raw') !== raw) {
            return null;
        }
        return variation.digitalogic_price_html;
    }

    function targets(form) {
        var scope = form.closest('.product-quick-view, .single-product-page, .product');
		return scope ? scope.querySelectorAll('.dgl-product-price, .wd-single-price, .dgl-mobile-purchase-bar__price') : [];
    }
	function renderTimestamp(form, variation) {
		var scope = form.closest('.product-quick-view, .single-product-page, .product');
		var target = scope ? scope.querySelector('.digitalogic-price-updated[data-digitalogic-contextual-price-update]') : null;
		var payload = variation && variation.digitalogic_price_updated;
		if (!target) return;
		if (!payload || !payload.datetime || !payload.absolute || !payload.relative) {
			target.hidden = true;
			target.removeAttribute('data-price-updated-source');
			return;
		}
		var time = target.querySelector('time');
		var absolute = target.querySelector('.digitalogic-price-updated__absolute');
		var relative = target.querySelector('.digitalogic-price-updated__relative');
		if (!time || !absolute || !relative) return;
		time.dateTime = String(payload.datetime);
		time.title = String(payload.absolute) + ' به وقت تهران';
		absolute.textContent = String(payload.absolute);
		relative.textContent = String(payload.relative);
		target.setAttribute('data-price-updated-source', String(payload.source || ''));
		target.hidden = false;
	}
    function render(form, variation) {
        var priceHtml = validatedPriceHtml(variation);
        targets(form).forEach(function (widget) {
			var price = widget.matches('.dgl-mobile-purchase-bar__price') ? widget : widget.querySelector('.price:not(.price-unit)');
            if (!price) { return; }
            // Render only server-generated HTML paired with the exact raw-price
            // contract. Never calculate from a parent minimum or displayed text.
            if (priceHtml) {
                price.innerHTML = priceHtml;
            } else {
                price.textContent = variation ? 'قیمت این مدل هنوز مشخص نیست' : 'برای مشاهده قیمت، مدل را انتخاب کنید';
            }
            widget.setAttribute('data-digitalogic-selected-variation', variation ? String(variation.variation_id) : '');
            price.setAttribute('aria-live', 'polite');
        });
		renderTimestamp(form, variation);
    }

    if (typeof $ !== 'function') {
        return {
            validatedPriceHtml: validatedPriceHtml
        };
    }

    $(document)
        .on('found_variation.digitalogicPrice', 'form.variations_form', function (event, variation) {
            render(this, variation);
        })
        .on('reset_data.digitalogicPrice hide_variation.digitalogicPrice', 'form.variations_form', function () {
            render(this, null);
        })
        .on('wc_variation_form.digitalogicPrice', 'form.variations_form', function () {
            if (!$(this).find('input.variation_id').val()) { render(this, null); }
        });
    $(function () {
        $('form.variations_form').each(function () {
            if (!$(this).find('input.variation_id').val()) { render(this, null); }
        });
    });
    return {
        validatedPriceHtml: validatedPriceHtml
    };
}));
