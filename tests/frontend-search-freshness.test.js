'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function harness() {
    const calls = [];
    const events = {};
    const requests = [];
    const input = {};
    const instance = {
        suggestions: ['old price'],
        abortAjax() { calls.push('abort'); },
        clearCache() { calls.push('clearCache'); },
        clear() { this.suggestions = []; calls.push('clear'); },
        hide() { calls.push('hide'); },
        onValueChange() { calls.push('refresh'); }
    };
    const collection = {
        length: 1,
        each(fn) { fn.call(input); return this; },
        closest() { return {length: 1}; },
        data() { return instance; },
        on(name, selector, fn) { events[name] = fn; }
    };
    function $(item) { return collection; }
    let options;
    $.fn = {devbridgeAutocomplete: function(value) {
        if (!arguments.length) return instance;
        options = value;
        return this;
    }};
    $.extend = function(...args) {
        if (args[0] === true) args.shift();
        return Object.assign(...args);
    };
    $.isPlainObject = value => !!value && typeof value === 'object';
    $.Deferred = function() {
        let state = 'pending';
        let args;
        const done = [], fail = [];
        const promise = {
            done(fn) { done.push(fn); if (state === 'resolved') fn(...args); return this; },
            fail(fn) { fail.push(fn); if (state === 'rejected') fn(...args); return this; }
        };
        return {
            promise(extra) { return Object.assign(promise, extra); },
            resolveWith(ctx, values) { if (state !== 'pending') return; state = 'resolved'; args = values; done.forEach(fn => fn.apply(ctx, values)); },
            rejectWith(ctx, values) { if (state !== 'pending') return; state = 'rejected'; args = values; fail.forEach(fn => fn.apply(ctx, values)); }
        };
    };
    $.ajax = settings => {
        calls.push(settings);
        const request = $.Deferred();
        const xhr = request.promise({abort() { request.rejectWith(null, [xhr, 'abort']); }});
        requests.push(request);
        return xhr;
    };
    const document = {
        visibilityState: 'visible', activeElement: input,
        addEventListener(name, fn) { events[name] = fn; },
        createElement() {
            return {set href(value) {
                const url = new URL(value, 'https://digitalogic.test/');
                this.host = url.host; this.pathname = url.pathname; this.search = url.search;
            }};
        }
    };
    const window = {
        digitalogicFrontendSearchWs: {
            ajax_url: 'https://digitalogic.test/wp-admin/admin-ajax.php',
            websocket: {enabled: false}
        },
        addEventListener(name, fn) { events[name] = fn; },
        clearTimeout() {}
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/frontend-search-ws.js'), 'utf8'), {
        jQuery: $, window, document, URL
    });
    return {$, calls, requests, events, instance, collection, input, document, options: () => options};
}

test('every repeated price search validates through HTTP rather than an unchecked browser cache', () => {
    const h = harness();
    for (let n = 0; n < 2; n++) {
        h.$.ajax({url: 'https://digitalogic.test/wp-admin/admin-ajax.php?action=woodmart_ajax_search', data: {query: 'TEC1-12704'}});
    }
    assert.equal(h.calls.length, 2);
    assert.equal(h.calls[0].cache, false);
    assert.equal(h.calls[1].cache, false);
    assert.equal(h.calls[1].dataType, 'json');
    h.$.ajax({url: 'https://other.test/wp-admin/admin-ajax.php', data: {action: 'woodmart_ajax_search'}});
    assert.equal(h.calls[2].cache, undefined);
});

test('warm browser cache is reusable only after matching server confirmation; missed event receives new price', () => {
    const h = harness();
    const settings = {url: 'https://digitalogic.test/wp-admin/admin-ajax.php', data: {action: 'woodmart_ajax_search', query: 'TEC1-12704'}};
    const cacheSettings = {browser: true, ttl: 60, entries: 32};
    let rendered;
    h.$.ajax(settings).done(data => { rendered = data.suggestions[0].price; });
    h.requests[0].resolveWith(null, [{suggestions: [{price: '505900'}], dg_cache: {signature: 'a', settings: cacheSettings}}]);
    assert.equal(rendered, '505900');
    rendered = null;
    h.$.ajax(settings).done(data => { rendered = data.suggestions[0].price; });
    assert.equal(rendered, null);
    assert.equal(h.calls[1].data.dg_search_signature, 'a');
    h.requests[1].resolveWith(null, [{unchanged: true, dg_cache: {signature: 'a', settings: cacheSettings}}]);
    assert.equal(rendered, '505900');
    h.$.ajax(settings).done(data => { rendered = data.suggestions[0].price; });
    h.requests[2].resolveWith(null, [{suggestions: [{price: '506000'}], dg_cache: {signature: 'b', settings: cacheSettings}}]);
    assert.equal(rendered, '506000');
});

test('aborted old response cannot overwrite newer results and failed validation never serves cached data', () => {
    const h = harness();
    const settings = {url: 'https://digitalogic.test/wp-admin/admin-ajax.php', data: {action: 'woodmart_ajax_search', query: 'L298'}};
    let rendered = false;
    const old = h.$.ajax(settings).done(() => { rendered = true; });
    old.abort();
    h.requests[0].resolveWith(null, [{suggestions: [{price: 'old'}]}]);
    assert.equal(rendered, false);
    h.$.ajax(settings).done(() => { rendered = true; });
    h.requests[1].rejectWith(null, [{}, 'error']);
    assert.equal(rendered, false);
});

test('autocomplete getters preserve instance and search clears old suggestions before callbacks', () => {
    const h = harness();
    assert.equal(h.$.fn.devbridgeAutocomplete.call(h.collection), h.instance);
    h.$.fn.devbridgeAutocomplete.call(h.collection, {onSearchStart() { h.calls.push('theme'); }});
    assert.equal(h.options().noCache, true);
    h.options().onSearchStart.call(h.input);
    assert.equal(h.instance.suggestions.length, 0);
    assert.deepEqual(h.calls, ['abort', 'clearCache', 'hide', 'theme']);
});

test('pricing event cancels in-flight response and discards old data before requery', () => {
    const h = harness();
    h.events['digitalogic:product-invalidated']();
    assert.deepEqual(h.calls, ['abort', 'clear', 'hide', 'refresh']);
    assert.equal(h.instance.suggestions.length, 0);
});

test('offline and hidden tabs discard stale data without requesting again', () => {
    const h = harness();
    h.events.offline();
    h.document.visibilityState = 'hidden';
    h.events.visibilitychange();
    assert.deepEqual(h.calls, ['abort', 'clear', 'hide', 'abort', 'clear', 'hide']);
});
