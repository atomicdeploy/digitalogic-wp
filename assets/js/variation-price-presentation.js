/* Display WooCommerce's selected variation result in builder price widgets. */
(function ($) {
    'use strict';
    function targets(form) {
        var scope = form.closest('.product-quick-view, .single-product-page, .product');
        return scope ? scope.querySelectorAll('.dgl-product-price, .wd-single-price') : [];
    }
    function render(form, variation) {
        targets(form).forEach(function (widget) {
            var price = widget.querySelector('.price:not(.price-unit)');
            if (!price) { return; }
            // price_html is the server-generated WooCommerce presentation.
            // Never calculate from a parent minimum, attributes or displayed text.
            if (variation && typeof variation.price_html === 'string' && variation.price_html.trim()) {
                price.innerHTML = variation.price_html;
            } else {
                price.textContent = variation ? 'قیمت این مدل هنوز مشخص نیست' : 'برای مشاهده قیمت، مدل را انتخاب کنید';
            }
            widget.setAttribute('data-digitalogic-selected-variation', variation ? String(variation.variation_id) : '');
            price.setAttribute('aria-live', 'polite');
        });
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
}(jQuery));
