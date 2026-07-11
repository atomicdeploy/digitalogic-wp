(function(root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.DigitalogicRuntime = factory();
  }
})(typeof self !== 'undefined' ? self : this, function() {
  'use strict';

  var DEFAULTS = {
    appName: 'Digitalogic',
    apiBase: 'https://digitalogic.ir/wp-json/digitalogic/v1',
    automationBase: 'https://automation.digitalogic.ir',
    panelBase: 'https://digitalogic.ir/panel',
    desktopManifest: 'https://digitalogic.ir/wp-json/digitalogic/v1/desktop/manifest',
    extensionManifest: 'https://digitalogic.ir/wp-json/digitalogic/v1/extension/manifest',
    cacheName: 'digitalogic-runtime-v1',
    cacheAssets: [],
    usefulLinkRules: [
      /digitalogic\.ir/i,
      /automation\.digitalogic\.ir/i,
      /meet\.digitalogic\.ir/i,
      /wp-admin/i,
      /\/panel\//i,
      /\.(pdf|docx?|xlsx?|csv|zip)(\?|#|$)/i
    ]
  };

  function mergeConfig(config) {
    return Object.assign({}, DEFAULTS, config || {});
  }

  function normalizeUrl(url, base) {
    try {
      return new URL(url, base || (typeof location !== 'undefined' ? location.href : DEFAULTS.panelBase)).toString();
    } catch (_) {
      return '';
    }
  }

  function createApiClient(config, fetchImpl) {
    var options = mergeConfig(config);
    var transport = fetchImpl || (typeof fetch !== 'undefined' ? fetch.bind(typeof self !== 'undefined' ? self : window) : null);

    function request(path, requestOptions) {
      if (!transport) return Promise.reject(new Error('Fetch is not available in this runtime'));
      var url = /^https?:\/\//i.test(path) ? path : options.apiBase.replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
      var headers = Object.assign({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Digitalogic-Client': options.clientName || options.appName
      }, requestOptions && requestOptions.headers ? requestOptions.headers : {});
      if (options.token) headers.Authorization = 'Bearer ' + options.token;

      return transport(url, Object.assign({}, requestOptions || {}, { headers: headers })).then(function(response) {
        if (!response.ok) {
          var error = new Error('Digitalogic API request failed with ' + response.status);
          error.status = response.status;
          throw error;
        }
        var contentType = response.headers && response.headers.get ? response.headers.get('content-type') : '';
        return contentType && contentType.indexOf('application/json') !== -1 ? response.json() : response.text();
      });
    }

    return {
      request: request,
      manifest: function(kind) {
        return request(kind === 'extension' ? options.extensionManifest : options.desktopManifest, { method: 'GET' });
      },
      notify: function(payload) {
        return request('/notifications', { method: 'POST', body: JSON.stringify(payload || {}) });
      },
      enqueueAutomation: function(payload) {
        return request('/automation/jobs', { method: 'POST', body: JSON.stringify(payload || {}) });
      },
      captureLinks: function(payload) {
        return request('/links/capture', { method: 'POST', body: JSON.stringify(payload || {}) });
      }
    };
  }

  function createStorage(adapter) {
    adapter = adapter || {};
    return {
      get: function(keys) {
        if (adapter.get) return adapter.get(keys);
        var result = {};
        [].concat(keys || []).forEach(function(key) {
          try {
            result[key] = JSON.parse(localStorage.getItem(key));
          } catch (_) {
            result[key] = localStorage.getItem(key);
          }
        });
        return Promise.resolve(result);
      },
      set: function(values) {
        if (adapter.set) return adapter.set(values);
        Object.keys(values || {}).forEach(function(key) {
          localStorage.setItem(key, JSON.stringify(values[key]));
        });
        return Promise.resolve();
      }
    };
  }

  function isUsefulLink(href, config) {
    var options = mergeConfig(config);
    return options.usefulLinkRules.some(function(rule) {
      return rule.test(href);
    });
  }

  function extractUsefulLinks(input, config) {
    var pageUrl = input && input.url ? input.url : (typeof location !== 'undefined' ? location.href : '');
    var title = input && input.title ? input.title : (typeof document !== 'undefined' ? document.title : '');
    var anchors = input && input.anchors ? input.anchors : [];
    if (!anchors.length && typeof document !== 'undefined') {
      anchors = Array.prototype.slice.call(document.querySelectorAll('a[href]')).map(function(anchor) {
        return {
          href: anchor.getAttribute('href'),
          text: anchor.textContent,
          rel: anchor.getAttribute('rel') || '',
          type: anchor.getAttribute('type') || ''
        };
      });
    }

    var seen = {};
    return anchors.map(function(anchor) {
      var href = normalizeUrl(anchor.href, pageUrl);
      if (!href || seen[href] || !isUsefulLink(href, config)) return null;
      seen[href] = true;
      return {
        url: href,
        text: String(anchor.text || '').replace(/\s+/g, ' ').trim().slice(0, 160),
        sourceUrl: pageUrl,
        sourceTitle: title,
        rel: anchor.rel || '',
        type: anchor.type || ''
      };
    }).filter(Boolean).slice(0, 80);
  }

  function registerServiceWorker(scriptUrl, options) {
    if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
      return Promise.resolve(null);
    }
    return navigator.serviceWorker.register(scriptUrl || '/digitalogic-service-worker.js', options || { scope: '/' });
  }

  function installServiceWorkerHandlers(config) {
    var options = mergeConfig(config);
    if (typeof self === 'undefined' || !self.addEventListener) return;

    self.addEventListener('install', function(event) {
      event.waitUntil(caches.open(options.cacheName).then(function(cache) {
        return options.cacheAssets && options.cacheAssets.length ? cache.addAll(options.cacheAssets) : Promise.resolve();
      }).then(function() {
        return self.skipWaiting && self.skipWaiting();
      }));
    });

    self.addEventListener('activate', function(event) {
      event.waitUntil(caches.keys().then(function(keys) {
        return Promise.all(keys.filter(function(key) {
          return key.indexOf('digitalogic-runtime-') === 0 && key !== options.cacheName;
        }).map(function(key) {
          return caches.delete(key);
        }));
      }).then(function() {
        return self.clients && self.clients.claim ? self.clients.claim() : null;
      }));
    });

    self.addEventListener('fetch', function(event) {
      var request = event.request;
      if (!request || request.method !== 'GET') return;
      var url = normalizeUrl(request.url);
      if (!/digitalogic\.ir|automation\.digitalogic\.ir|meet\.digitalogic\.ir/i.test(url)) return;

      event.respondWith(fetch(request).then(function(response) {
        var copy = response.clone();
        caches.open(options.cacheName).then(function(cache) {
          cache.put(request, copy);
        });
        return response;
      }).catch(function() {
        return caches.match(request);
      }));
    });

    self.addEventListener('message', function(event) {
      var data = event.data || {};
      if (data.type === 'DIGITALOGIC_PING') {
        event.source && event.source.postMessage({ type: 'DIGITALOGIC_PONG', appName: options.appName });
      }
      if (data.type === 'DIGITALOGIC_EXTRACT_LINKS') {
        var links = extractUsefulLinks(data.payload || {}, options);
        event.source && event.source.postMessage({ type: 'DIGITALOGIC_LINKS', links: links });
      }
      if (data.type === 'DIGITALOGIC_SHOW_NOTIFICATION' && self.registration && self.registration.showNotification) {
        self.registration.showNotification(data.title || options.appName, data.options || {});
      }
    });

    self.addEventListener('push', function(event) {
      var payload = {};
      try {
        payload = event.data ? event.data.json() : {};
      } catch (_) {
        payload = { title: options.appName, body: event.data ? event.data.text() : '' };
      }
      event.waitUntil(self.registration.showNotification(payload.title || options.appName, {
        body: payload.body || '',
        icon: payload.icon || '/digitalogic-icon-192.png',
        badge: payload.badge || '/digitalogic-icon-96.png',
        data: payload.data || {}
      }));
    });
  }

  return {
    defaults: DEFAULTS,
    mergeConfig: mergeConfig,
    normalizeUrl: normalizeUrl,
    createApiClient: createApiClient,
    createStorage: createStorage,
    extractUsefulLinks: extractUsefulLinks,
    registerServiceWorker: registerServiceWorker,
    installServiceWorkerHandlers: installServiceWorkerHandlers
  };
});
