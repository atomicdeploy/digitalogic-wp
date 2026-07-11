importScripts('/wp-content/plugins/digitalogic-wp/assets/js/digitalogic-runtime.js');

self.DigitalogicRuntime.installServiceWorkerHandlers({
  appName: 'Digitalogic',
  cacheName: 'digitalogic-runtime-v1',
  cacheAssets: [
    '/wp-content/plugins/digitalogic-wp/assets/js/digitalogic-runtime.js',
    '/wp-content/plugins/digitalogic-wp/assets/css/desktop-app.css'
  ]
});
