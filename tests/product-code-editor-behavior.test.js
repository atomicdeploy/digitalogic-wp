'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {createHash, webcrypto} = require('node:crypto');

function sha256(value) {
    return 'sha256:' + createHash('sha256').update(value, 'utf8').digest('hex');
}

function productCodeResult(request, overrides = {}) {
    const requestMaterial = JSON.stringify({
        schema: 'digitalogic.product-code-edit',
        product_id: String(request.product_id),
        expected_code: String(request.expected_code),
        product_code: String(request.product_code),
        if_match: String(request.if_match)
    });
    const revisionMaterial = JSON.stringify({
        schema: 'digitalogic.product-code-edit',
        product_id: String(request.product_id),
        product_code: String(request.product_code)
    });
    return Object.assign({
        schema: 'digitalogic.product-code-edit',
        status: 'applied',
        changed: true,
        replayed: false,
        product_id: request.product_id,
        previous_product_code: request.expected_code,
        product_code: request.product_code,
        previous_revision: request.if_match,
        revision: sha256(revisionMaterial),
        request_id: request.request_id,
        request_fingerprint: sha256(requestMaterial),
        backup_reference: 'sha256:' + 'b'.repeat(64),
        governance_evidence_fingerprint: 'sha256:' + 'c'.repeat(64),
        verification: {
            database_readback: true,
            cache_bypassed: true,
            unique: true,
            source_governance: true,
            projection_current: true
        },
        projection: {
            generation_before_hash: 'sha256:' + 'd'.repeat(64),
            generation_after_hash: 'sha256:' + 'e'.repeat(64),
            state_revision_event_durable: true
        }
    }, overrides);
}

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
        crypto: {
			subtle: {digest: webcrypto.subtle.digest.bind(webcrypto.subtle)},
            getRandomValues(values) { randomCounter++; values[0] = randomCounter; values[1] = randomCounter + 1; return values; }
        },
        TextEncoder,
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
		Uint8Array,
		TextEncoder,
        Intl,
        navigator: {},
        encodeURIComponent,
        decodeURIComponent,
        setTimeout: window.setTimeout,
        clearTimeout: window.clearTimeout
    });
	vm.runInContext(
		fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'product-code-contract.js'), 'utf8'),
		context,
		{filename: 'product-code-contract.js'}
	);
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8'),
        context,
        {filename: 'panel-app.js'}
    );

    return {
        appOptions,
		contract: window.DigitalogicProductCodeContract,
		window,
        fireNextTimer() {
            const item = timers.entries().next();
            assert.equal(item.done, false, 'Expected one pending deadline timer.');
            const [id, callback] = item.value;
            timers.delete(id);
            callback();
        },
        wasAborted() {
            return aborted;
        },
        pendingTimerCount() {
            return timers.size;
        }
    };
}

function loadClassicBoundedRequest(prepareImpl) {
	const admin = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'admin.js'), 'utf8');
	const functions = admin.match(/function rejectProductCodeDeferred[\s\S]*?(?=\n\s*function rejectWebSocketRequests)/);
	assert.ok(functions, 'Expected the bounded classic Product Code request functions.');
	const timers = new Map();
	let nextTimer = 0;
	let transportCount = 0;

	function Deferred() {
		let state = 'pending';
		let args = [];
		const callbacks = {done: [], fail: [], always: []};
		const promise = {
			done(callback) { if (state === 'resolved') callback.apply(null, args); else if (state === 'pending') callbacks.done.push(callback); return promise; },
			fail(callback) { if (state === 'rejected') callback.apply(null, args); else if (state === 'pending') callbacks.fail.push(callback); return promise; },
			always(callback) { if (state !== 'pending') callback.apply(null, args); else callbacks.always.push(callback); return promise; }
		};
		return {
			resolve() { if (state !== 'pending') return; state = 'resolved'; args = Array.from(arguments); callbacks.done.concat(callbacks.always).forEach((callback) => callback.apply(null, args)); },
			reject() { if (state !== 'pending') return; state = 'rejected'; args = Array.from(arguments); callbacks.fail.concat(callbacks.always).forEach((callback) => callback.apply(null, args)); },
			promise() { return promise; }
		};
	}

	const context = vm.createContext({
		window: {DigitalogicProductCodeContract: {prepare: prepareImpl, validateResult() { throw new Error('No response validation expected.'); }}},
		digitalogic: {request_timeout: 1000},
		$: {Deferred},
		Promise,
		setTimeout(callback) { const id = ++nextTimer; timers.set(id, callback); return id; },
		clearTimeout(id) { timers.delete(id); },
		digitalogicRequest() { transportCount++; throw new Error('Late Product Code transport started after timeout.'); }
	});
	const request = vm.runInContext('(function(){' + functions[0] + '; return boundedProductCodeRequest;})()', context);

	return {
		request,
		fireNextTimer() {
			const item = timers.entries().next();
			assert.equal(item.done, false, 'Expected one classic deadline timer.');
			const [id, callback] = item.value;
			timers.delete(id);
			callback();
		},
		transportCount() { return transportCount; }
	};
}

test('classic request registry rejects a second intent and ignores reverse stale completion', () => {
    const harness = loadPanel(() => new Promise(() => {}));
    const registry = harness.contract.createRequestRegistry();
    const first = {request_id: 'product-code:741:first'};
    const second = {request_id: 'product-code:741:second'};

    assert.equal(registry.begin(741, first), true);
    assert.equal(registry.begin(741, second), false);
    assert.equal(registry.isCurrent(741, first), true);
    assert.equal(registry.finish(741, second), false);
    assert.equal(registry.isCurrent(741, first), true);
    assert.equal(registry.size(), 1);
    assert.equal(registry.finish(741, first), true);
    assert.equal(registry.begin(741, second), true);
});

test('classic bulk planning never routes a timed-out Product Code intent through generic update', () => {
	const harness = loadPanel(() => new Promise(() => {}));
	const registry = harness.contract.createRequestRegistry();
	const intent = {request_id: 'product-code:741:timed-out'};
	const intents = {741: intent};
	const changes = {
		741: {patris_product_code: '000742'},
		742: {weight: '2.5'}
	};
	registry.begin(741, intent);

	const plan = harness.contract.planBulkUpdates(changes, intents, registry);

	assert.deepEqual(Array.from(plan.pendingProductIds), ['741']);
	assert.deepEqual(Object.keys(plan.updates), ['742']);
	assert.equal(plan.updates[742].weight, '2.5');
	assert.equal(intents[741], intent);
	assert.equal(changes[741].patris_product_code, '000742');
});

test('panel AJAX deadline aborts a hung request with a typed retryable timeout', async () => {
    const harness = loadPanel(() => new Promise(() => {}));
    const state = {transport: ''};
    const pending = harness.appOptions.methods.run.call(
        state,
        'digitalogic_update_product_code',
        {request_id: 'product-code:741:deadline'},
        {ajaxOnly: true, bounded: true, silentError: true}
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

test('one Product Code deadline blocks a late WebCrypto prepare from starting panel transport', async () => {
	const harness = loadPanel(() => {
		throw new Error('Transport must not start before request verification.');
	});
	let resolveDigest;
	harness.window.crypto.subtle.digest = () => new Promise((resolve) => { resolveDigest = resolve; });
	const methods = harness.appOptions.methods;
	let runCount = 0;
	const state = {
		productCodeIntents: {},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery,
		run() { runCount++; return Promise.resolve({}); }
	};
	const product = {
		id: 741,
		patris_product_code: '000741',
		patris_product_code_revision: 'sha256:' + 'a'.repeat(64)
	};
	const pending = methods.saveProductCode.call(state, product, '000742');
	await Promise.resolve();
	harness.fireNextTimer();

	await assert.rejects(pending, (error) => error.code === 'digitalogic_request_timeout' && error.data.retryable === true);
	resolveDigest(new Uint8Array(32).buffer);
	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(runCount, 0);
	assert.equal(harness.wasAborted(), true);
	assert.ok(state.productCodeIntents[741].request_id);
});

test('classic deadline blocks a late prepare from sending the timed-out request', async () => {
	let resolvePrepare;
	const prepare = new Promise((resolve) => { resolvePrepare = resolve; });
	const harness = loadClassicBoundedRequest(() => prepare);
	const snapshot = {
		product_id: 741,
		expected_code: '000741',
		product_code: '000742',
		if_match: 'sha256:' + 'a'.repeat(64),
		request_id: 'product-code:741:late-prepare'
	};
	const intent = {request_id: snapshot.request_id, request_fingerprint: ''};
	let failureStatus = '';
	const pending = harness.request(snapshot, intent);
	pending.fail((xhr, textStatus) => { failureStatus = textStatus; });
	harness.fireNextTimer();
	assert.equal(failureStatus, 'timeout');

	resolvePrepare(Object.assign({}, snapshot, {request_fingerprint: 'sha256:' + 'b'.repeat(64)}));
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(harness.transportCount(), 0);
	assert.equal(intent.request_id, snapshot.request_id);
	assert.equal(intent.request_fingerprint, '');
});

test('the same deadline bounds WebCrypto terminal validation after transport', async () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const originalDigest = webcrypto.subtle.digest.bind(webcrypto.subtle);
	let digestCalls = 0;
	harness.window.crypto.subtle.digest = function() {
		digestCalls++;
		if (digestCalls === 1) return originalDigest.apply(null, arguments);
		return new Promise(() => {});
	};
	const methods = harness.appOptions.methods;
	const state = {
		productCodeIntents: {},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery,
		run(command, data) { return Promise.resolve(productCodeResult(data)); }
	};
	const product = {
		id: 741,
		patris_product_code: '000741',
		patris_product_code_revision: 'sha256:' + 'a'.repeat(64)
	};
	const pending = methods.saveProductCode.call(state, product, '000742');
	for (let index = 0; index < 20 && digestCalls < 2; index++) {
		await new Promise((resolve) => setImmediate(resolve));
	}
	assert.ok(digestCalls >= 2, 'Terminal validation must have started before the deadline fires.');
	harness.fireNextTimer();

	await assert.rejects(pending, (error) => error.code === 'digitalogic_request_timeout' && error.data.retryable === true);
	assert.equal(harness.wasAborted(), true);
	assert.ok(state.productCodeIntents[741].request_id);
});

test('historical replay exposes only its exact current readback to the row', async () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const request = {
		product_id: 741,
		expected_code: '000741',
		product_code: '000742',
		if_match: 'sha256:' + 'a'.repeat(64),
		request_id: 'product-code:741:historical'
	};
	const prepared = await harness.contract.prepare(request);
	const currentCode = '000743';
	const currentRevision = sha256(JSON.stringify({
		schema: 'digitalogic.product-code-edit',
		product_id: '741',
		product_code: currentCode
	}));
	const replay = productCodeResult(prepared, {
		replayed: true,
		current_product_code: currentCode,
		current_revision: currentRevision,
		current_readback: {database_readback: true, cache_bypassed: true}
	});

	const validated = await harness.contract.validateResult(replay, prepared);
	assert.deepEqual(
		JSON.parse(JSON.stringify(harness.contract.currentResult(validated))),
		{product_code: currentCode, revision: currentRevision}
	);
});

test('legacy AJAX commands retain the integration baseline hard deadline', async () => {
    const harness = loadPanel(() => new Promise(() => {}));
    const pending = harness.appOptions.methods.run.call(
        {transport: ''},
        'digitalogic_export_products',
        {},
        {ajaxOnly: true, silentError: true}
    );

    assert.equal(harness.pendingTimerCount(), 1);
    harness.fireNextTimer();
    await assert.rejects(
        pending,
        (error) => error.code === 'digitalogic_transport_outcome_unknown' && error.outcome_unknown === true
    );
    assert.equal(harness.wasAborted(), true);
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
			return Promise.resolve(productCodeResult(data));
        },
        loadProducts() {},
        loadProduct() {},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery
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
    assert.equal(sent[2].options.bounded, true);
});

test('panel preserves one idempotency key after an ambiguous server failure', async () => {
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
			return Promise.resolve(productCodeResult(data));
        },
        loadProducts() {},
        loadProduct() {},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery
    };
    const product = {
        id: 741,
        patris_product_code: '000741',
        patris_product_code_revision: 'sha256:' + 'a'.repeat(64)
    };

    await methods.saveProductCode.call(state, product, '000742');
    const firstRequestId = sent[0].data.request_id;
    methods.handleProductCodeSaveError.call(state, product, {
        code: 'digitalogic_request_failed',
        status: 502,
        data: {}
    });
    await methods.saveProductCode.call(state, product, '000742');

    assert.equal(sent[1].data.request_id, firstRequestId);
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

test('invalid JSON with HTTP 200 is an ambiguous retryable response', async () => {
	const harness = loadPanel(() => Promise.resolve({
		status: 200,
		json: () => Promise.reject(new SyntaxError('invalid json'))
	}));

	await assert.rejects(
		harness.appOptions.methods.run.call(
			{transport: ''},
			'digitalogic_update_product_code',
			{},
			{ajaxOnly: true, bounded: true, silentError: true}
		),
		(error) => {
			assert.equal(error.code, 'digitalogic_response_ambiguous');
			assert.equal(error.status, 0);
			assert.equal(error.data.retryable, true);
			return true;
		}
	);
});

test('empty or mismatched HTTP 200 success keeps the exact idempotency identity', async () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	const sent = [];
	let mode = 'empty';
	const state = {
		productCodeIntents: {},
		edits: {741: {patris_product_code: '000742'}},
		selectedProduct: null,
		t: {error: 'error', productCodeRecoveryRequired: 'recover'},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery,
		run(command, data) {
			sent.push(Object.assign({}, data));
			if (mode === 'empty') return Promise.resolve({});
			if (mode === 'mismatch') return Promise.resolve(productCodeResult(data, {request_id: 'different-request'}));
			return Promise.resolve(productCodeResult(data));
		}
	};
	const product = {
		id: 741,
		patris_product_code: '000741',
		patris_product_code_revision: 'sha256:' + 'a'.repeat(64)
	};

	await assert.rejects(methods.saveProductCode.call(state, product, '000742'), (error) => error.code === 'digitalogic_response_ambiguous');
	const requestId = state.productCodeIntents[741].request_id;
	mode = 'mismatch';
	await assert.rejects(methods.saveProductCode.call(state, product, '000742'), (error) => error.code === 'digitalogic_response_ambiguous');
	assert.equal(state.productCodeIntents[741].request_id, requestId);

	mode = 'valid';
	const result = await methods.saveProductCode.call(state, product, '000742');
	assert.equal(result.request_id, requestId);
	assert.equal(sent[0].request_id, requestId);
	assert.equal(sent[1].request_id, requestId);
	assert.equal(sent[2].request_id, requestId);
});

test('outcome_unknown reload is read-only and never offers same-request retry', () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	const product = {
		id: 741,
		patris_product_code_recovery: {
			status: 'outcome_unknown',
			request_id: 'product-code:741:unknown',
			expected_code: '000741',
			product_code: '000742',
			if_match: 'sha256:' + 'a'.repeat(64),
			request_fingerprint: 'sha256:' + 'b'.repeat(64)
		}
	};
	const state = {productCodeIntents: {741: {request_id: 'old'}}};

	methods.hydrateProductCodeRecovery.call(state, product);

	assert.equal(state.productCodeIntents[741], undefined);
	assert.equal(product.patris_product_code_editable, false);
	assert.equal(product.patris_product_code_edit_reason, 'outcome_unknown');
});

test('reload recovery exposes one manual same-key retry of the exact pending proposal', async () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	let saveCount = 0;
	let savedProposal = '';
	const state = {
		productCodeIntents: {},
		edits: {},
		savePromises: {},
		hydrateProductCodeRecovery: methods.hydrateProductCodeRecovery,
		productCodePendingProposal: methods.productCodePendingProposal,
		saveProduct(product) {
			saveCount++;
			savedProposal = this.edits[product.id].patris_product_code;
			return Promise.resolve();
		}
	};
	const product = {
		id: 741,
		patris_product_code: '000741',
		patris_product_code_recovery: {
			status: 'failed_retryable',
			request_id: 'product-code:741:reload-recovery',
			expected_code: '000741',
			product_code: '000742',
			if_match: 'sha256:' + 'a'.repeat(64),
			request_fingerprint: 'sha256:' + 'b'.repeat(64),
			takeover_required: true
		}
	};

	methods.hydrateProductCodeRecovery.call(state, product);
	const originalRequestId = state.productCodeIntents[741].request_id;
	assert.equal(methods.productCodePendingProposal.call(state, product), '000742');
	assert.equal(saveCount, 0, 'Hydration must never auto-submit a recovery request.');

	await methods.retryPendingProductCode.call(state, product);

	assert.equal(saveCount, 1);
	assert.equal(savedProposal, '000742');
	assert.equal(state.productCodeIntents[741].request_id, originalRequestId);
});

test('a 412 keeps current state separate from the proposal and rotates only on manual retry', () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	const state = {
		productCodeIntents: {
			741: {
				expected_code: '000741',
				if_match: 'sha256:' + 'a'.repeat(64),
				request_id: 'product-code:741:stale',
				request_fingerprint: 'sha256:' + 'b'.repeat(64),
				signature: 'stale'
			}
		},
		edits: {741: {patris_product_code: '000742'}},
		selectedProduct: null,
		loadProducts() {},
		loadProduct() {}
	};
	const product = {id: 741, patris_product_code: '000742', patris_product_code_revision: 'sha256:' + 'a'.repeat(64)};

	methods.handleProductCodeSaveError.call(state, product, {
		code: 'digitalogic_product_code_precondition_failed',
		status: 412,
		data: {current_code: '000740', current_revision: 'sha256:' + 'c'.repeat(64), retryable: false}
	});

	assert.equal(product.patris_product_code, '000740');
	assert.equal(product.patris_product_code_revision, 'sha256:' + 'c'.repeat(64));
	assert.equal(state.productCodeIntents[741].pending_proposal, '000742');
	assert.equal(state.productCodeIntents[741].pending_mode, 'new_request');
	assert.equal(state.productCodeIntents[741].request_id, '');
	assert.equal(state.edits[741].patris_product_code, '000742');
});

test('a terminal Product Code conflict clears the hidden draft and generic bulk cannot resubmit it', () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	const state = {
		productCodeIntents: {741: {expected_code: '000741', if_match: 'sha256:' + 'a'.repeat(64), request_id: 'product-code:741:duplicate'}},
		edits: {741: {patris_product_code: '000742'}},
		selectedProduct: null,
		loadProducts() {},
		loadProduct() {}
	};
	const product = {id: 741, patris_product_code: '000742', patris_product_code_revision: 'sha256:' + 'b'.repeat(64)};

	methods.handleProductCodeSaveError.call(state, product, {
		code: 'digitalogic_product_code_not_unique',
		status: 409,
		data: {retryable: false}
	});

	assert.equal(state.productCodeIntents[741], undefined);
	assert.equal(state.edits[741], undefined);
	assert.equal(product.patris_product_code, '000741');
	assert.equal(product.patris_product_code_revision, 'sha256:' + 'a'.repeat(64));
});

test('missing verifier is a typed read-only state rather than an endless saving state', () => {
	const harness = loadPanel(() => Promise.resolve({status: 200, json: () => Promise.resolve({success: true, data: {}})}));
	const methods = harness.appOptions.methods;
	const contract = harness.window.DigitalogicProductCodeContract;
	delete harness.window.DigitalogicProductCodeContract;
	const state = {t: {productCodeVerifierUnavailable: 'verifier unavailable'}};
	const product = {id: 741, patris_product_code_editable: true};
	const column = {field: 'patris_product_code', editable: true};

	assert.equal(methods.isProductColumnEditable.call(state, product, column), false);
	assert.equal(methods.productColumnEditReason.call(state, product, column), 'verifier unavailable');
	harness.window.DigitalogicProductCodeContract = contract;
});
