(function(window) {
    'use strict';

    var runtime = window.DigitalogicRuntime;
    if (!runtime) {
        return;
    }

    runtime.registerServiceWorker('/digitalogic-service-worker.js', {scope: '/'}).catch(function(error) {
        if (window.console && window.console.debug) {
            window.console.debug('Digitalogic service worker registration failed', error);
        }
    });
})(window);
