'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function loadPanel(fetchImpl) {
    const timers = new Map();
    let nextTimer = 0;
    let randomCounter = 0;
    let appOptions;
    let aborted = false;
    const root = {hidden: false};
    const template = {innerHTML: '<main></main>'};
    const document = {
        body: {setAttribute() {}},
        documentElement: {setAttribute() {}},
        getElementById(id) {
            if (id === 'digitalogic-panel') return root;
            if (id === 'digitalogic-panel-template') return template;
            return null;
        }
    };
    const window = {
        digitalogicPanel: {
            ajax_url: '/admin-ajax.php',
            nonce: 'test-nonce',
            request_timeout: 1000,
            websocket: {enabled: false}
        },
        DigitalogicProductQuery: {},
        Vue: {
            createApp(options) {
                appOptions = options;
                return {
                    config: {},
                    mount() {}
                };
            }
        },
        AbortController: class {
            constructor() {
                this.signal = {};
            }
            abort() {
                aborted = true;
            }
        },
        WebSocket: {OPEN: 1},
        fetch: fetchImpl,
        location: {href: 'https://example.test/panel/', origin: 'https://example.test', pathname: '/panel/'},
        localStorage: {getItem() { return null; }, setItem() {}},
        console: {error() {}, warn() {}, info() {}, log() {}},
        crypto: {getRandomValues(values) { randomCounter++; values[0] = randomCounter; values[1] = randomCounter + 1; return values; }},
        addEventListener() {},
        setInterval() { return 1; },
        clearInterval() {},
        setTimeout(callback) {
            const id = ++nextTimer;
            timers.set(id, callback);
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        }
    };
    const context = vm.createContext({
        window,
        document,
        URL,
        URLSearchParams,
        Promise,
        Error,
        Date,
        Math,
        Number,
        String,
        Object,
        Array,
        JSON,
        Intl,
        navigator: {},
        encodeURIComponent,
        decodeURIComponent,
        setTimeout: window.setTimeout,
        clearTimeout: window.clearTimeout
    });
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8'),
        context,
        {filename: 'panel-app.js'}
    );

    return {
        appOptions,
        fireNextTimer() {
            const item = timers.entries().next();
            assert.equal(item.done, false, 'Expected one pending deadline timer.');
            const [id, callback] = item.value;
            timers.delete(id);
            callback();
        },
        wasAborted() {
            return aborted;
        }
    };
}

test('panel AJAX deadline aborts a hung request with a typed retryable timeout', async () => {
    const harness = loadPanel(() => new Promise(() => {}));
    const state = {transport: ''};
    const pending = harness.appOptions.methods.run.call(
        state,
        'digitalogic_update_product_code',
        {request_id: 'product-code:741:deadline'},
        {ajaxOnly: true, silentError: true}
    );

    harness.fireNextTimer();

    await assert.rejects(pending, (error) => {
        assert.equal(error.code, 'digitalogic_request_timeout');
        assert.equal(error.data.retryable, true);
        assert.equal(error.status, 0);
        return true;
    });
    assert.equal(harness.wasAborted(), true);
    assert.equal(state.transport, 'ajax');
});

test('panel preserves one idempotency key after timeout and rotates it after 412 state refresh', async () => {
    const harness = loadPanel(() => Promise.resolve({
        status: 200,
        json: () => Promise.resolve({success: true, data: {}})
    }));
    const methods = harness.appOptions.methods;
    const sent = [];
    const state = {
        productCodeIntents: {},
        edits: {741: {patris_product_code: '000742'}},
        selectedProduct: null,
        run(command, data, options) {
            sent.push({command, data: Object.assign({}, data), options});
            return Promise.resolve({});
        },
        loadProducts() {},
        loadProduct() {}
    };
    const product = {
        id: 741,
        patris_product_code: '000741',
        patris_product_code_revision: 'sha256:' + 'a'.repeat(64)
    };

    await methods.saveProductCode.call(state, product, '000742');
    const firstRequestId = sent[0].data.request_id;
    methods.handleProductCodeSaveError.call(state, product, {
        code: 'digitalogic_request_timeout',
        status: 0,
        data: {retryable: true}
    });
    await methods.saveProductCode.call(state, product, '000742');
    assert.equal(sent[1].data.request_id, firstRequestId);

    methods.handleProductCodeSaveError.call(state, product, {
        code: 'digitalogic_product_code_precondition_failed',
        status: 412,
        data: {
            current_code: '000740',
            current_revision: 'sha256:' + 'b'.repeat(64),
            retryable: false
        }
    });
    await methods.saveProductCode.call(state, product, '000742');

    assert.equal(sent[2].data.expected_code, '000740');
    assert.equal(sent[2].data.if_match, 'sha256:' + 'b'.repeat(64));
    assert.notEqual(sent[2].data.request_id, firstRequestId);
    assert.equal(sent[2].options.ajaxOnly, true);
});

test('structured 409 response reaches the panel without losing machine fields', async () => {
    const harness = loadPanel(() => Promise.resolve({
        status: 409,
        json: () => Promise.resolve({
            success: false,
            data: {
                code: 'digitalogic_product_code_outcome_unknown',
                message: 'Exact readback required.',
                status: 409,
                data: {retryable: false, backup_reference: 'sha256:' + 'c'.repeat(64)}
            }
        })
    }));

    await assert.rejects(
        harness.appOptions.methods.run.call(
            {transport: ''},
            'digitalogic_update_product_code',
            {},
            {ajaxOnly: true, silentError: true}
        ),
        (error) => {
            assert.equal(error.code, 'digitalogic_product_code_outcome_unknown');
            assert.equal(error.status, 409);
            assert.equal(error.data.retryable, false);
            assert.match(error.data.backup_reference, /^sha256:/);
            return true;
        }
    );
});
