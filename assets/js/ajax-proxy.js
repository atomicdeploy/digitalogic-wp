/**
 * Admin-wide admin-ajax.php to WebSocket proxy.
 */

(function($, window) {
    'use strict';

    if (!$ || !window.digitalogicWs || !window.digitalogicWs.websocket) {
        return;
    }

    var config = window.digitalogicWs.websocket;
    var socket;
    var socketReady = false;
    var socketConnecting = false;
    var requestId = 0;
    var pendingRequests = {};
    var originalAjax = $.ajax;

    function connect() {
        if (!config.enabled || !config.url || socketReady || socketConnecting || typeof window.WebSocket === 'undefined') {
            return;
        }

        socketConnecting = true;
        var separator = config.url.indexOf('?') === -1 ? '?' : '&';
        var authParam = config.token ? 'token=' + encodeURIComponent(config.token) : 'nonce=' + encodeURIComponent(config.nonce);
        var url = config.url + separator + authParam;

        try {
            socket = new window.WebSocket(url);
        } catch (e) {
            socketConnecting = false;
            return;
        }

        socket.onopen = function() {
            socketReady = true;
            socketConnecting = false;
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
            clearTimeout(pending.timeout);

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
            setTimeout(connect, config.reconnect_interval || 3000);
        };

        socket.onerror = function() {
            socketReady = false;
            socketConnecting = false;
        };
    }

    function rejectPending() {
        Object.keys(pendingRequests).forEach(function(id) {
            pendingRequests[id].deferred.reject({message: 'WebSocket disconnected'});
            clearTimeout(pendingRequests[id].timeout);
            delete pendingRequests[id];
        });
    }

    function request(command, data) {
        if (!socketReady || !socket || socket.readyState !== window.WebSocket.OPEN) {
            return null;
        }

        var deferred = $.Deferred();
        var id = 'ajax_' + (++requestId);

        pendingRequests[id] = {
            deferred: deferred,
            timeout: setTimeout(function() {
                if (!pendingRequests[id]) {
                    return;
                }

                pendingRequests[id].deferred.reject({message: 'WebSocket request timed out'});
                delete pendingRequests[id];
            }, config.request_timeout || 15000)
        };

        socket.send(JSON.stringify({
            id: id,
            command: command,
            data: data || {}
        }));

        return deferred.promise();
    }

    function normalizeAjaxArguments(url, options) {
        if (typeof url === 'object') {
            return $.extend(true, {}, url);
        }

        return $.extend(true, {}, options || {}, {url: url});
    }

    function adminAjaxPath(url) {
        var anchor = document.createElement('a');
        anchor.href = url || window.digitalogicWs.ajax_url;

        return anchor.pathname.replace(/\/+$/, '') === parseUrl(window.digitalogicWs.ajax_url).pathname.replace(/\/+$/, '') &&
            anchor.host === parseUrl(window.digitalogicWs.ajax_url).host;
    }

    function parseUrl(url) {
        var anchor = document.createElement('a');
        anchor.href = url;

        return anchor;
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
                if (!part) {
                    return;
                }

                var pieces = part.split('=');
                var key = decodeURIComponent((pieces[0] || '').replace(/\+/g, ' '));
                var value = decodeURIComponent((pieces.slice(1).join('=') || '').replace(/\+/g, ' '));
                if (key) {
                    payload[key] = value;
                }
            });
            return payload;
        }

        if ($.isPlainObject(data)) {
            return $.extend(true, {}, data);
        }

        return null;
    }

    function shouldProxy(settings, payload) {
        var method = (settings.type || settings.method || 'GET').toUpperCase();
        var excluded = config.ajax_proxy_excluded_actions || [];

        if (!config.ajax_proxy_enabled || settings.digitalogicWebSocket === false) {
            return false;
        }

        if (method !== 'POST') {
            return false;
        }

        if (!adminAjaxPath(settings.url || window.digitalogicWs.ajax_url)) {
            return false;
        }

        if (!payload || !payload.action || excluded.indexOf(payload.action) !== -1) {
            return false;
        }

        return true;
    }

    function fallbackAjax(originalArgs, deferred) {
        var jqxhr = originalAjax.apply($, originalArgs);
        jqxhr.done(function(data, textStatus, xhr) {
            deferred.resolve(data, textStatus, xhr);
        });
        jqxhr.fail(function(xhr, textStatus, errorThrown) {
            deferred.reject(xhr, textStatus, errorThrown);
        });

        return jqxhr;
    }

    $.ajax = function(url, options) {
        var originalArgs = arguments;
        var settings = normalizeAjaxArguments(url, options);
        var payload = payloadFromData(settings.data);

        if (!shouldProxy(settings, payload)) {
            return originalAjax.apply($, originalArgs);
        }

        var socketRequest = request(payload.action, payload);
        if (!socketRequest) {
            return originalAjax.apply($, originalArgs);
        }

        var deferred = $.Deferred();
        var jqxhr = deferred.promise({
            abort: function() {
                deferred.reject(null, 'abort', 'abort');
            }
        });

        socketRequest.done(function(data) {
            data = normalizeAjaxResponse(data);

            if (settings.success) {
                settings.success.call(settings.context || settings, data, 'success', jqxhr);
            }
            deferred.resolve(data, 'success', jqxhr);
        });

        socketRequest.fail(function() {
            fallbackAjax(originalArgs, deferred);
        });

        return jqxhr;
    };

    function normalizeAjaxResponse(data) {
        if (
            data &&
            typeof data === 'object' &&
            Object.prototype.hasOwnProperty.call(data, 'success') &&
            (
                Object.prototype.hasOwnProperty.call(data, 'data') ||
                Object.prototype.hasOwnProperty.call(data, 'message')
            )
        ) {
            return data;
        }

        return {
            success: true,
            data: data
        };
    }

    window.digitalogicWebSocketRequest = request;
    connect();
})(jQuery, window);
