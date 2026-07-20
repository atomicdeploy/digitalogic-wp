(function ($) {
    'use strict';

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

    $(document)
        .on('found_variation', '.variations_form', function (event, variation) {
            renderIdentity($(this), variation);
        })
        .on('reset_data hide_variation', '.variations_form', function () {
            renderIdentity($(this), null);
        });
}(jQuery));
