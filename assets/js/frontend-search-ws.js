/**
 * Route Woodmart front-end search dropdown requests through /wordpress-ws.
 */
(function($, window, document) {
    'use strict';

    if (!$ || !window.digitalogicFrontendSearchWs || !window.digitalogicFrontendSearchWs.websocket) {
        return;
    }

    var rootConfig = window.digitalogicFrontendSearchWs;
    var config = rootConfig.websocket || {};
    var allowedActions = rootConfig.actions || ['woodmart_ajax_search'];
    var fallbackDelay = parseInt(rootConfig.fallback_delay, 10) || 1200;
    var originalAjax = $.ajax;
    var socket = null;
    var socketReady = false;
    var socketConnecting = false;
    var requestId = 0;
    var pendingRequests = {};
    var readyWaiters = [];

    function normalizeWoodmartAutocompleteOptions(collection, options) {
        if (!options || typeof options === 'string' || !collection || !collection.length) {
            return options;
        }

        var isWoodmartSearch = false;
        collection.each(function() {
            if ($(this).closest('form.woodmart-ajax-search').length) {
                isWoodmartSearch = true;
                return false;
            }
        });

        if (!isWoodmartSearch) {
            return options;
        }

        return $.extend({}, options, {
            triggerSelectOnValidInput: false
        });
    }

    function wrapDevbridgeAutocomplete(plugin) {
        if (typeof plugin !== 'function' || plugin.digitalogicSearchWrapped) {
            return plugin;
        }

        var wrapped = function(options, args) {
            return plugin.call(this, normalizeWoodmartAutocompleteOptions(this, options), args);
        };

        $.extend(wrapped, plugin);
        wrapped.digitalogicSearchWrapped = true;

        return wrapped;
    }

    function installAutocompleteGuard() {
        var currentPlugin = $.fn.devbridgeAutocomplete;

        if (currentPlugin) {
            $.fn.devbridgeAutocomplete = wrapDevbridgeAutocomplete(currentPlugin);
            return;
        }

        try {
            Object.defineProperty($.fn, 'devbridgeAutocomplete', {
                configurable: true,
                get: function() {
                    return currentPlugin;
                },
                set: function(plugin) {
                    currentPlugin = wrapDevbridgeAutocomplete(plugin);
                }
            });
        } catch (e) {
            var guardTimer = window.setInterval(function() {
                if ($.fn.devbridgeAutocomplete) {
                    $.fn.devbridgeAutocomplete = wrapDevbridgeAutocomplete($.fn.devbridgeAutocomplete);
                    window.clearInterval(guardTimer);
                }
            }, 50);

            window.setTimeout(function() {
                window.clearInterval(guardTimer);
            }, 5000);
        }
    }

    function connect() {
        if (!config.enabled || !config.url || socketReady || socketConnecting || typeof window.WebSocket === 'undefined') {
            return;
        }

        socketConnecting = true;
        var separator = config.url.indexOf('?') === -1 ? '?' : '&';
        var authParam = config.token ? 'token=' + encodeURIComponent(config.token) : 'nonce=' + encodeURIComponent(config.nonce || '');

        try {
            socket = new window.WebSocket(config.url + separator + authParam);
        } catch (e) {
            socketConnecting = false;
            return;
        }

        socket.onopen = function() {
            socketReady = true;
            socketConnecting = false;
            flushReadyWaiters(true);
        };

        socket.onmessage = function(event) {
            var response;

            try {
                response = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (!response.id || !pendingRequests[response.id]) {
                return;
            }

            var pending = pendingRequests[response.id];
            delete pendingRequests[response.id];
            window.clearTimeout(pending.timeout);

            if (response.success) {
                pending.deferred.resolve(response.data);
            } else {
                pending.deferred.reject(response.error || {message: 'WebSocket request failed'});
            }
        };

        socket.onclose = function() {
            socketReady = false;
            socketConnecting = false;
            rejectPending();
            flushReadyWaiters(false);
            window.setTimeout(connect, config.reconnect_interval || 3000);
        };

        socket.onerror = function() {
            socketReady = false;
            socketConnecting = false;
            flushReadyWaiters(false);
        };
    }

    function flushReadyWaiters(success) {
        var waiters = readyWaiters.slice();
        readyWaiters = [];

        waiters.forEach(function(waiter) {
            window.clearTimeout(waiter.timeout);
            success ? waiter.deferred.resolve() : waiter.deferred.reject();
        });
    }

    function rejectPending() {
        Object.keys(pendingRequests).forEach(function(id) {
            pendingRequests[id].deferred.reject({message: 'WebSocket disconnected'});
            window.clearTimeout(pendingRequests[id].timeout);
            delete pendingRequests[id];
        });
    }

    function waitForReady() {
        var deferred = $.Deferred();

        if (socketReady && socket && socket.readyState === window.WebSocket.OPEN) {
            deferred.resolve();
            return deferred.promise();
        }

        connect();
        readyWaiters.push({
            deferred: deferred,
            timeout: window.setTimeout(function() {
                deferred.reject();
            }, fallbackDelay)
        });

        return deferred.promise();
    }

    function request(command, data) {
        var deferred = $.Deferred();

        waitForReady().done(function() {
            if (!socketReady || !socket || socket.readyState !== window.WebSocket.OPEN) {
                deferred.reject({message: 'WebSocket unavailable'});
                return;
            }

            var id = 'front_search_' + (++requestId);
            pendingRequests[id] = {
                deferred: deferred,
                timeout: window.setTimeout(function() {
                    if (!pendingRequests[id]) {
                        return;
                    }

                    pendingRequests[id].deferred.reject({message: 'WebSocket request timed out'});
                    delete pendingRequests[id];
                }, config.request_timeout || 8000)
            };

            socket.send(JSON.stringify({
                id: id,
                command: command,
                data: data || {}
            }));
        }).fail(function() {
            deferred.reject({message: 'WebSocket unavailable'});
        });

        return deferred.promise();
    }

    function normalizeAjaxArguments(url, options) {
        if (typeof url === 'object') {
            return $.extend(true, {}, url);
        }

        return $.extend(true, {}, options || {}, {url: url});
    }

    function parseUrl(url) {
        var anchor = document.createElement('a');
        anchor.href = url || rootConfig.ajax_url;

        return anchor;
    }

    function adminAjaxPath(url) {
        var target = parseUrl(url || rootConfig.ajax_url);
        var ajax = parseUrl(rootConfig.ajax_url);

        return target.pathname.replace(/\/+$/, '') === ajax.pathname.replace(/\/+$/, '') &&
            target.host === ajax.host;
    }

    function paramsFromUrl(url) {
        var params = {};
        var anchor = parseUrl(url);
        var query = anchor.search ? anchor.search.replace(/^\?/, '') : '';

        if (!query) {
            return params;
        }

        query.split('&').forEach(function(part) {
            appendParam(params, part);
        });

        return params;
    }

    function payloadFromData(data) {
        var payload = {};

        if (!data) {
            return payload;
        }

        if (window.FormData && data instanceof window.FormData) {
            return null;
        }

        if (Array.isArray(data)) {
            data.forEach(function(item) {
                if (item && item.name) {
                    payload[item.name] = item.value;
                }
            });
            return payload;
        }

        if (typeof data === 'string') {
            data.split('&').forEach(function(part) {
                appendParam(payload, part);
            });
            return payload;
        }

        if ($.isPlainObject(data)) {
            return $.extend(true, {}, data);
        }

        return null;
    }

    function appendParam(payload, part) {
        if (!part) {
            return;
        }

        var pieces = part.split('=');
        var key = decodeURIComponent((pieces[0] || '').replace(/\+/g, ' '));
        var value = decodeURIComponent((pieces.slice(1).join('=') || '').replace(/\+/g, ' '));

        if (key) {
            payload[key] = value;
        }
    }

    function payloadFromSettings(settings) {
        var urlPayload = paramsFromUrl(settings.url || rootConfig.ajax_url);
        var dataPayload = payloadFromData(settings.data);

        if (dataPayload === null) {
            return null;
        }

        return $.extend({}, urlPayload, dataPayload || {});
    }

    function shouldProxy(settings, payload) {
        var method = (settings.type || settings.method || 'GET').toUpperCase();

        if (settings.digitalogicWebSocket === false) {
            return false;
        }

        if (method !== 'GET' && method !== 'POST') {
            return false;
        }

        if (!adminAjaxPath(settings.url || rootConfig.ajax_url)) {
            return false;
        }

        return !!(payload && payload.action && allowedActions.indexOf(payload.action) !== -1);
    }

    function callSuccess(settings, jqxhr, deferred, data) {
        if (settings.dataFilter) {
            data = settings.dataFilter.call(settings.context || settings, data, settings.dataType || 'json');
        }

        if (settings.success) {
            settings.success.call(settings.context || settings, data, 'success', jqxhr);
        }

        deferred.resolveWith(settings.context || settings, [data, 'success', jqxhr]);

        if (settings.complete) {
            settings.complete.call(settings.context || settings, jqxhr, 'success');
        }
    }

    function fallbackAjax(originalArgs, deferred) {
        var jqxhr = originalAjax.apply($, originalArgs);
        jqxhr.done(function() {
            deferred.resolveWith(this, arguments);
        });
        jqxhr.fail(function() {
            deferred.rejectWith(this, arguments);
        });

        return jqxhr;
    }

    $.ajax = function(url, options) {
        var originalArgs = arguments;
        var settings = normalizeAjaxArguments(url, options);
        var payload = payloadFromSettings(settings);

        if (!shouldProxy(settings, payload)) {
            return originalAjax.apply($, originalArgs);
        }

        var deferred = $.Deferred();
        var fallbackXhr = null;
        var aborted = false;
        var jqxhr = deferred.promise({
            abort: function() {
                aborted = true;
                if (fallbackXhr && fallbackXhr.abort) {
                    fallbackXhr.abort();
                }
                deferred.rejectWith(settings.context || settings, [jqxhr, 'abort', 'abort']);
            }
        });

        request(payload.action, payload).done(function(data) {
            if (aborted) {
                return;
            }

            callSuccess(settings, jqxhr, deferred, data);
        }).fail(function() {
            if (aborted) {
                return;
            }

            fallbackXhr = fallbackAjax(originalArgs, deferred);
        });

        return jqxhr;
    };

    window.digitalogicFrontendSearchWsRequest = request;
    installAutocompleteGuard();
    connect();
})(jQuery, window, document);
