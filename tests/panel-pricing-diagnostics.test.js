'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const panelSource = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8');
const panelView = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'views', 'app.php'), 'utf8');

function loadPanel(fetchImpl, websocketEnabled = false) {
    let appOptions;
    let socket;
    const document = {
        body: {setAttribute() {}},
        documentElement: {setAttribute() {}},
        getElementById(id) {
            if (id === 'digitalogic-panel') return {hidden: false};
            if (id === 'digitalogic-panel-template') return {innerHTML: '<main></main>'};
            return null;
        }
    };
    class FakeWebSocket {
        static OPEN = 1;

        constructor() {
            this.readyState = FakeWebSocket.OPEN;
            socket = this;
        }

        send(value) {
            this.lastRequest = JSON.parse(value);
        }
    }
    const window = {
        digitalogicPanel: {
            ajax_url: '/admin-ajax.php',
            nonce: 'test-nonce',
            websocket: {
                enabled: websocketEnabled,
                url: 'wss://example.test/panel',
                request_timeout: 1000,
                reconnect_interval: 1000
            }
        },
        DigitalogicProductQuery: {},
        Vue: {
            createApp(options) {
                appOptions = options;
                return {config: {}, mount() {}};
            }
        },
        WebSocket: FakeWebSocket,
        fetch: fetchImpl,
        location: {href: 'https://example.test/panel/', origin: 'https://example.test', pathname: '/panel/'},
        localStorage: {getItem() { return null; }, setItem() {}, removeItem() {}},
        console: {error() {}, warn() {}, info() {}, log() {}},
        addEventListener() {},
        setInterval() { return 1; },
        clearInterval() {},
        setTimeout,
        clearTimeout
    };
    vm.runInContext(
        panelSource,
        vm.createContext({
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
            setTimeout,
            clearTimeout
        }),
        {filename: 'panel-app.js'}
    );

    return {appOptions, socket};
}

function diagnosticState(methods) {
    return {
        transport: '',
        error: '',
        diagnostic: null,
        loading: true,
        summaryLoading: true,
        saving: true,
        t: {error: 'Request failed'},
        setDiagnostic: methods.setDiagnostic
    };
}

function warningPayload() {
    return {
        success: false,
        data: {
            code: 'optional_digest_mismatch',
            message: 'The optional digest differs, but semantic identity is intact.',
            severity: 'warning',
            blocking: false,
            retryable: true,
            recovery_action: 'Refresh using the canonical state revision.',
            details: {
                state_revision: 'sha256:' + 'a'.repeat(64),
                secret: 'must-not-render'
            }
        }
    };
}

test('AJAX preserves a complete diagnostic and settles all visible loading state', async () => {
    const harness = loadPanel(() => Promise.resolve({
        ok: false,
        status: 409,
        text: () => Promise.resolve(JSON.stringify(warningPayload()))
    }));
    const methods = harness.appOptions.methods;
    const state = diagnosticState(methods);

    await assert.rejects(
        methods.run.call(state, 'digitalogic_pricing_read', {}, {ajaxOnly: true}),
        (error) => {
            assert.equal(error.code, 'optional_digest_mismatch');
            assert.equal(error.severity, 'warning');
            assert.equal(error.blocking, false);
            assert.equal(error.retryable, true);
            assert.equal(error.recoveryAction, 'Refresh using the canonical state revision.');
            assert.equal(error.details.state_revision, 'sha256:' + 'a'.repeat(64));
            assert.equal(Object.hasOwn(error.details, 'secret'), false);
            return true;
        }
    );

    assert.equal(state.diagnostic.code, 'optional_digest_mismatch');
    assert.equal(state.diagnostic.reason, 'The optional digest differs, but semantic identity is intact.');
    assert.equal(state.loading, false);
    assert.equal(state.summaryLoading, false);
    assert.equal(state.saving, false);
});

test('WebSocket application diagnostics survive and are not replayed over AJAX', async () => {
    let ajaxRequests = 0;
    const harness = loadPanel(() => {
        ajaxRequests++;
        return Promise.reject(new Error('AJAX replay must not run for an application diagnostic.'));
    }, true);
    harness.socket.onopen();
    const methods = harness.appOptions.methods;
    const state = diagnosticState(methods);
    const pending = methods.run.call(state, 'digitalogic_pricing_read', {});

    harness.socket.onmessage({
        data: JSON.stringify({
            id: harness.socket.lastRequest.id,
            success: false,
            error: warningPayload().data
        })
    });

    await assert.rejects(pending, (error) => error.diagnostic.code === 'optional_digest_mismatch');
    assert.equal(ajaxRequests, 0);
    assert.equal(state.diagnostic.recovery_action, 'Refresh using the canonical state revision.');
    assert.equal(state.summaryLoading, false);
});

test('panel renders the structured diagnostic without HTML injection and bounds sync loading', () => {
    assert.match(panelView, /v-if="diagnostic"/);
    assert.match(panelView, /diagnostic\.code/);
    assert.match(panelView, /diagnostic\.reason/);
    assert.match(panelView, /diagnostic\.recovery_action/);
    assert.match(panelView, /diagnosticDetailRows/);
    assert.doesNotMatch(panelView, /v-html="diagnostic/);
    assert.match(panelView, /v-if="summaryLoading"[^>]*role="status"/);
    assert.match(panelSource, /loadSummary:[\s\S]*?\.finally\(function\(\) \{[\s\S]*?self\.summaryLoading = false/);
});
