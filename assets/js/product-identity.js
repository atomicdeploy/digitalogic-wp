(function ($) {
    'use strict';

    function renderSingleProductIdentity() {
        var config = window.digitalogicProductIdentity || {};
        var name = config.singleProductPatrisName ? String(config.singleProductPatrisName).trim() : '';
        var titles;
        var title;
        var identity;

        if (name === '') {
            return;
        }

        titles = document.querySelectorAll('h1.product_title.entry-title.wd-entities-title');
        if (titles.length !== 1) {
            return;
        }

        title = titles[0];
        if (title.parentElement && title.parentElement.querySelector('.digitalogic-patris-name')) {
            return;
        }

        identity = document.createElement('div');
        identity.className = 'digitalogic-patris-name';
        identity.dir = 'ltr';
        identity.lang = 'en';
        identity.textContent = name;
        title.insertAdjacentElement('afterend', identity);
    }

    function renderIdentity($form, variation) {
        var $slot = $form.siblings('.digitalogic-variation-identity').first();
        var name = variation && variation.digitalogic_patris_name ? String(variation.digitalogic_patris_name) : '';

        if (!$slot.length) {
            $slot = $form.parent().find('.digitalogic-variation-identity').first();
        }
        if (!$slot.length) {
            return;
        }
        $slot.text(name).prop('hidden', name === '');
    }

    $(renderSingleProductIdentity);

    $(document)
        .on('found_variation', '.variations_form', function (event, variation) {
            renderIdentity($(this), variation);
        })
        .on('reset_data hide_variation', '.variations_form', function () {
            renderIdentity($(this), null);
        });
}(jQuery));
