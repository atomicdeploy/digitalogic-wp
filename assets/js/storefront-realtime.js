(function () {
    'use strict';

    var config = window.DigitalogicRealtime || {};
    if (!config.streamUrl) {
        return;
    }

    var audienceKey = String(config.audienceKey || 'guest').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32) || 'guest';
    var prefix = 'digitalogic.realtime.v1.' + audienceKey + '.';
    var channelName = prefix + 'channel';
    var leaderKey = prefix + 'leader';
    var eventKey = prefix + 'event';
    var cursorKey = prefix + 'cursor';
    var currencyKey = prefix + 'currency';
    var tabKey = prefix + 'tab';
    var productEventKey = prefix + 'product-event';
    var notificationEventKey = prefix + 'notification-event';
    var dismissedKey = prefix + 'dismissed-notifications';
    var tabId = readSession(tabKey) || randomId();
    var channel = null;
    var source = null;
    var leaderTimer = null;
    var refreshController = null;
    var lastProductEventId = Number(readSession(productEventKey) || 0);
    var lastNotificationEventId = Number(readSession(notificationEventKey) || 0);
    var currentProductId = Number(config.currentProductId || 0);
    var leaderTtl = Math.max(6000, Number(config.leaderTtlMs || 12000));

    writeSession(tabKey, tabId);

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function readLocal(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeLocal(key, value) {
        try {
            window.localStorage.setItem(key, value);
            return true;
        } catch (error) {
            return false;
        }
    }

    function readSession(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeSession(key, value) {
        try {
            window.sessionStorage.setItem(key, String(value));
        } catch (error) {
            // Storage is an optimization; the live connection still works without it.
        }
    }

    function parseJson(value) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function formatNumber(value) {
        var number = Number(value || 0);
        try {
            return new Intl.NumberFormat('en-US', {
                maximumFractionDigits: 0
            }).format(number);
        } catch (error) {
            return String(Math.round(number)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
    }

    function currencyDate(currency, snapshot) {
        var specific = currency === 'USD' ? snapshot.usd_display_date : snapshot.cny_display_date;
        return String(specific || snapshot.updated_at || '');
    }

    function applyCurrency(snapshot, eventId) {
        if (!snapshot || typeof snapshot !== 'object') {
            return;
        }

        document.querySelectorAll('[data-digitalogic-currency]').forEach(function (card) {
            var currency = String(card.getAttribute('data-digitalogic-currency') || '').toUpperCase();
            var rate = currency === 'USD' ? snapshot.dollar_price : snapshot.yuan_price;
            var symbol = currency === 'USD' ? '$' : '¥';
            var rateNode = card.querySelector('[data-digitalogic-currency-rate]');
            var dateNode = card.querySelector('[data-digitalogic-currency-date]');
            if (rateNode && Number(rate) > 0) {
                rateNode.textContent = formatNumber(rate) + ' ' + symbol;
            }
            if (dateNode) {
                dateNode.textContent = currencyDate(currency, snapshot);
            }
        });

        var cached = {
            eventId: Number(eventId || 0),
            storedAt: Date.now(),
            snapshot: snapshot
        };
        writeLocal(currencyKey, JSON.stringify(cached));
        window.dispatchEvent(new CustomEvent('digitalogic:currency-updated', {detail: cached}));
    }

    function hydrateCurrency() {
        var cached = parseJson(readLocal(currencyKey));
        var ttl = Math.max(60000, Number(config.currencyTtlMs || 21600000));
        if (
            cached
            && cached.snapshot
            && Date.now() - Number(cached.storedAt || 0) <= ttl
            && Number(cached.eventId || 0) >= Number(config.initialEventId || 0)
        ) {
            applyCurrency(cached.snapshot, cached.eventId);
            return;
        }
        applyCurrency(config.currency || {}, Number(config.initialEventId || 0));
    }

    function notificationExpired(notification) {
        var expiresAt = Date.parse(String(notification.expires_at || ''));
        return Number.isFinite(expiresAt) && expiresAt < Date.now();
    }

    function dismissedNotifications() {
        var stored = parseJson(readLocal(dismissedKey)) || {};
        var current = {};
        Object.keys(stored).slice(-50).forEach(function (id) {
            if (Number(stored[id] || 0) > Date.now()) {
                current[id] = Number(stored[id]);
            }
        });
        return current;
    }

    function notificationDismissed(notification) {
        return Boolean(dismissedNotifications()[String(notification.id || '')]);
    }

    function rememberDismissed(notification) {
        var id = String(notification.id || '');
        if (!id) {
            return;
        }
        var stored = dismissedNotifications();
        var expiresAt = Date.parse(String(notification.expires_at || ''));
        stored[id] = Number.isFinite(expiresAt) ? expiresAt : Date.now() + 86400000;
        var compact = {};
        Object.keys(stored).slice(-50).forEach(function (key) {
            compact[key] = stored[key];
        });
        writeLocal(dismissedKey, JSON.stringify(compact));
    }

    function notificationContainer(kind) {
        var id = kind === 'banner' ? 'digitalogic-realtime-banners' : 'digitalogic-realtime-toasts';
        var container = document.getElementById(id);
        if (container) {
            return container;
        }
        container = document.createElement('div');
        container.id = id;
        container.className = 'digitalogic-realtime-' + kind + 's';
        container.setAttribute('aria-live', kind === 'banner' ? 'polite' : 'assertive');
        container.setAttribute('aria-atomic', 'false');
        if (kind === 'banner') {
            document.body.insertBefore(container, document.body.firstChild);
        } else {
            document.body.appendChild(container);
        }
        return container;
    }

    function removeNotificationNodes(notificationId) {
        document.querySelectorAll('[data-digitalogic-notification-id]').forEach(function (node) {
            if (node.getAttribute('data-digitalogic-notification-id') === notificationId) {
                node.remove();
            }
        });
    }

    function renderNotification(notification, kind) {
        var id = String(notification.id || '');
        if (!id || notificationExpired(notification) || notificationDismissed(notification)) {
            return;
        }
        var container = notificationContainer(kind);
        var duplicate = Array.from(container.querySelectorAll('[data-digitalogic-notification-id]')).some(function (node) {
            return node.getAttribute('data-digitalogic-notification-id') === id;
        });
        if (duplicate) {
            return;
        }

        var notice = document.createElement('section');
        notice.className = 'digitalogic-realtime-notice digitalogic-realtime-notice--' + String(notification.level || 'info');
        notice.setAttribute('data-digitalogic-notification-id', id);
        notice.setAttribute('role', String(notification.level) === 'error' ? 'alert' : 'status');
        notice.setAttribute('dir', 'rtl');

        var content = document.createElement('div');
        content.className = 'digitalogic-realtime-notice__content';
        if (notification.title) {
            var title = document.createElement('strong');
            title.className = 'digitalogic-realtime-notice__title';
            title.textContent = String(notification.title);
            content.appendChild(title);
        }
        if (notification.message) {
            var message = document.createElement('span');
            message.className = 'digitalogic-realtime-notice__message';
            message.textContent = String(notification.message);
            content.appendChild(message);
        }
        if (notification.link && notification.link.href && notification.link.label) {
            var link = document.createElement('a');
            link.className = 'digitalogic-realtime-notice__link';
            link.href = String(notification.link.href);
            link.textContent = String(notification.link.label);
            content.appendChild(link);
        }
        notice.appendChild(content);

        if (notification.dismissible) {
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'digitalogic-realtime-notice__close';
            close.setAttribute('aria-label', 'بستن اعلان');
            close.textContent = '×';
            close.addEventListener('click', function () {
                rememberDismissed(notification);
                removeNotificationNodes(id);
            });
            notice.appendChild(close);
        }
        container.appendChild(notice);

        if (kind === 'toast') {
            window.setTimeout(function () {
                if (notice.isConnected) {
                    notice.remove();
                }
            }, Math.max(1000, Math.min(60000, Number(notification.duration_ms || 7000))));
        }
    }

    function showNotification(event) {
        var eventId = Number(event.id || 0);
        var notification = event.data && event.data.notification;
        if (!notification || eventId <= lastNotificationEventId) {
            return;
        }
        lastNotificationEventId = eventId;
        writeSession(notificationEventKey, eventId);
        var display = String(notification.display || 'toast');
        if (display === 'toast' || display === 'both') {
            renderNotification(notification, 'toast');
        }
        if (display === 'banner' || display === 'both') {
            renderNotification(notification, 'banner');
        }
        window.dispatchEvent(new CustomEvent('digitalogic:notification', {
            detail: {event: event, notification: notification}
        }));
    }

    function hydrateNotifications() {
        (Array.isArray(config.notifications) ? config.notifications : []).forEach(showNotification);
    }

    function productMatches(data) {
        if (!currentProductId || !data) {
            return false;
        }
        return [data.product_id, data.object_id, data.parent_id].some(function (id) {
            return Number(id || 0) === currentProductId;
        });
    }

    function productRoot(root) {
        if (!root) {
            return null;
        }
        return root.querySelector('#product-' + currentProductId)
            || root.querySelector('.post-' + currentProductId + '.product')
            || root.querySelector('main .single-product .product')
            || root.querySelector('main .product.type-product')
            || root.querySelector('.single-product .product');
    }

    function copyHead(documentNode) {
        var incomingTitle = documentNode.querySelector('title');
        if (incomingTitle) {
            document.title = incomingTitle.textContent;
        }
        ['meta[name="description"]', 'link[rel="canonical"]'].forEach(function (selector) {
            var current = document.querySelector(selector);
            var incoming = documentNode.querySelector(selector);
            if (current && incoming) {
                Array.from(current.attributes).forEach(function (attribute) {
                    current.removeAttribute(attribute.name);
                });
                Array.from(incoming.attributes).forEach(function (attribute) {
                    current.setAttribute(attribute.name, attribute.value);
                });
            }
        });
    }

    function initializeWooCommerce(root) {
        var $ = window.jQuery;
        if (!$ || !root) {
            return;
        }
        $(root).find('.woocommerce-product-gallery').each(function () {
            if (typeof $.fn.wc_product_gallery === 'function') {
                $(this).wc_product_gallery();
            }
        });
        $(root).find('.variations_form').each(function () {
            if (typeof $.fn.wc_variation_form === 'function') {
                $(this).wc_variation_form();
            }
        });
        $(document.body).trigger('digitalogic_product_refreshed', [currentProductId]);
    }

    function fallbackReload(eventId) {
        writeSession(productEventKey, eventId);
        window.location.reload();
    }

    function refreshProduct(event) {
        var eventId = Number(event.id || 0);
        if (!productMatches(event.data) || eventId <= lastProductEventId) {
            return;
        }
        lastProductEventId = eventId;
        writeSession(productEventKey, eventId);

        if (document.visibilityState === 'hidden') {
            writeSession(prefix + 'pending-product-event', eventId);
            return;
        }
        if (String(event.name) === 'product.deleted') {
            fallbackReload(eventId);
            return;
        }

        if (refreshController && typeof refreshController.abort === 'function') {
            refreshController.abort();
        }
        refreshController = typeof AbortController === 'function' ? new AbortController() : null;
        var requestUrl = new URL(window.location.href);
        requestUrl.searchParams.set('digitalogic_realtime', eventId);

        fetch(requestUrl.toString(), {
            credentials: 'same-origin',
            headers: {'Accept': 'text/html'},
            cache: 'no-store',
            signal: refreshController ? refreshController.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('product_refresh_http_' + response.status);
            }
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var current = productRoot(document);
            var incoming = productRoot(parsed);
            if (!current || !incoming) {
                throw new Error('product_refresh_fragment_missing');
            }
            var replacement = document.importNode(incoming, true);
            current.replaceWith(replacement);
            copyHead(parsed);
            initializeWooCommerce(replacement);
            window.dispatchEvent(new CustomEvent('digitalogic:product-updated', {
                detail: {event: event, productId: currentProductId, root: replacement}
            }));
        }).catch(function (error) {
            if (!error || error.name !== 'AbortError') {
                fallbackReload(eventId);
            }
        });
    }

    function handleEvent(event) {
        if (!event || typeof event !== 'object') {
            return;
        }
        var eventId = Number(event.id || 0);
        if (eventId > 0) {
            writeLocal(cursorKey, String(eventId));
        }
        if (event.data && event.data.currency) {
            applyCurrency(event.data.currency, eventId);
        }
        if (event.data && Array.isArray(event.data.notifications)) {
            event.data.notifications.forEach(showNotification);
        }
        if (String(event.name || '') === 'workstation.notification') {
            showNotification(event);
        }
        if (String(event.name || '').indexOf('product.') === 0) {
            refreshProduct(event);
        }
    }

    function relay(event) {
        var envelope = {sender: tabId, sentAt: Date.now(), event: event};
        if (channel) {
            channel.postMessage(envelope);
        }
        writeLocal(eventKey, JSON.stringify(envelope));
    }

    function openStream() {
        if (source || typeof window.EventSource !== 'function') {
            return;
        }
        var cursor = Number(readLocal(cursorKey) || config.initialEventId || 0);
        var url = new URL(config.streamUrl, window.location.href);
        if (cursor > 0) {
            url.searchParams.set('last_event_id', String(cursor));
        }
        source = new window.EventSource(url.toString(), {withCredentials: true});
        source.onmessage = function (message) {
            var event = parseJson(message.data);
            if (!event) {
                return;
            }
            handleEvent(event);
            relay(event);
        };
        source.onerror = function () {
            if (!ownsLease()) {
                closeStream();
            }
        };
    }

    function closeStream() {
        if (source) {
            source.close();
            source = null;
        }
    }

    function lease() {
        return parseJson(readLocal(leaderKey));
    }

    function ownsLease() {
        var current = lease();
        return current && current.tabId === tabId && Number(current.expiresAt || 0) > Date.now();
    }

    function maintainLeadership() {
        var now = Date.now();
        var current = lease();
        if (!current || Number(current.expiresAt || 0) <= now || current.tabId === tabId) {
            writeLocal(leaderKey, JSON.stringify({tabId: tabId, expiresAt: now + leaderTtl}));
            current = lease();
        }
        if (current && current.tabId === tabId) {
            openStream();
        } else {
            closeStream();
        }
    }

    function startCoordination() {
        if (typeof window.BroadcastChannel === 'function') {
            channel = new window.BroadcastChannel(channelName);
            channel.onmessage = function (message) {
                var envelope = message.data || {};
                if (envelope.sender !== tabId) {
                    handleEvent(envelope.event);
                }
            };
        }
        window.addEventListener('storage', function (storageEvent) {
            if (storageEvent.key === eventKey && storageEvent.newValue) {
                var envelope = parseJson(storageEvent.newValue);
                if (envelope && envelope.sender !== tabId) {
                    handleEvent(envelope.event);
                }
            }
            if (storageEvent.key === leaderKey) {
                maintainLeadership();
            }
        });
        maintainLeadership();
        leaderTimer = window.setInterval(maintainLeadership, Math.floor(leaderTtl / 3));
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            var pending = Number(readSession(prefix + 'pending-product-event') || 0);
            if (pending > 0) {
                writeSession(prefix + 'pending-product-event', 0);
                fallbackReload(pending);
            }
        }
    });

    window.addEventListener('pagehide', function () {
        if (leaderTimer) {
            window.clearInterval(leaderTimer);
        }
        closeStream();
        if (channel) {
            channel.close();
        }
        if (ownsLease()) {
            writeLocal(leaderKey, JSON.stringify({tabId: tabId, expiresAt: 0}));
        }
    });

    hydrateCurrency();
    hydrateNotifications();
    startCoordination();
}());
