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
        return scope ? scope.querySelectorAll('.dgl-product-price, .wd-single-price') : [];
    }
    function render(form, variation) {
        var priceHtml = validatedPriceHtml(variation);
        targets(form).forEach(function (widget) {
            var price = widget.querySelector('.price:not(.price-unit)');
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
