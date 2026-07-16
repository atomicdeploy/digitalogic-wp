/**
 * Currency-page WordPress postbox initialization.
 */
(function($, window) {
    'use strict';

    $(function() {
        var config = window.digitalogicCurrencyPostboxes || {};

        if (!config.screenId || !window.postboxes || typeof window.postboxes.add_postbox_toggles !== 'function') {
            return;
        }

        window.postboxes.add_postbox_toggles(config.screenId);
    });
})(jQuery, window);
