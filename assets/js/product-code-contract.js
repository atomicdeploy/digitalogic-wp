/**
 * Browser-side validation for the Living Product Code operation contract.
 */
(function(global) {
    'use strict';

    var SCHEMA = 'digitalogic.product-code-edit';
    var HASH_PATTERN = /^sha256:[a-f0-9]{64}$/;

    function ambiguous(message) {
        var error = new Error(message || 'The Product Code response could not be verified.');
        error.code = 'digitalogic_response_ambiguous';
        error.status = 0;
        error.data = {retryable: true};
        return error;
    }

	function timeoutError() {
		var error = new Error('The Product Code request timed out. Retry the unchanged request.');
		error.code = 'digitalogic_request_timeout';
		error.status = 0;
		error.data = {retryable: true};
		return error;
	}

	/** Bound prepare, transport, and terminal verification with one deadline. */
	function withDeadline(work, timeoutMs, onTimeout) {
		var timeout = Math.max(1000, Math.min(30000, Number(timeoutMs) || 12000));
		var timeoutHandle;
		var deadline = new Promise(function(resolve, reject) {
			timeoutHandle = global.setTimeout(function() {
				if (typeof onTimeout === 'function') onTimeout();
				reject(timeoutError());
			}, timeout);
		});
		var operation = Promise.resolve().then(work);
		return Promise.race([operation, deadline]).finally(function() {
			global.clearTimeout(timeoutHandle);
		});
	}

    function digest(material) {
        if (
            !global.crypto ||
            !global.crypto.subtle ||
            typeof global.crypto.subtle.digest !== 'function' ||
            typeof global.TextEncoder !== 'function'
        ) {
            return Promise.reject(ambiguous('This browser cannot verify the Product Code response fingerprint.'));
        }

        return global.crypto.subtle.digest('SHA-256', new global.TextEncoder().encode(material)).then(function(buffer) {
            return 'sha256:' + Array.prototype.map.call(new Uint8Array(buffer), function(value) {
                return value.toString(16).padStart(2, '0');
            }).join('');
        });
    }

    function requestMaterial(request) {
        return canonicalJson({
            schema: SCHEMA,
            product_id: String(request.product_id),
            expected_code: String(request.expected_code),
            product_code: String(request.product_code),
            if_match: String(request.if_match)
        });
    }

    function revisionMaterial(productId, productCode) {
        return canonicalJson({
            schema: SCHEMA,
            product_id: String(productId),
            product_code: String(productCode)
        });
    }

	function canonicalJson(value) {
		return JSON.stringify(value).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
	}

    function prepare(request) {
        return digest(requestMaterial(request)).then(function(fingerprint) {
            if (
                request.request_fingerprint &&
                !constantTimeEqual(String(request.request_fingerprint), fingerprint)
            ) {
                throw ambiguous('The saved Product Code recovery fingerprint does not match this request.');
            }
            request.request_fingerprint = fingerprint;
            return request;
        });
    }

    function constantTimeEqual(left, right) {
        left = String(left);
        right = String(right);
        if (left.length !== right.length) return false;
        var mismatch = 0;
        for (var index = 0; index < left.length; index++) {
            mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
        }
        return mismatch === 0;
    }

    function validateResult(result, request) {
        if (!result || typeof result !== 'object') {
            return Promise.reject(ambiguous());
        }

        return Promise.all([
            digest(requestMaterial(request)),
            digest(revisionMaterial(request.product_id, request.product_code))
        ]).then(function(hashes) {
            var fingerprint = hashes[0];
            var revision = hashes[1];
            var verification = result.verification;
            var statusMatches = (result.status === 'applied' && result.changed === true) ||
                (result.status === 'unchanged' && result.changed === false);
            var projectionValid = result.changed === false || (
                result.projection &&
                HASH_PATTERN.test(String(result.projection.generation_before_hash || '')) &&
                HASH_PATTERN.test(String(result.projection.generation_after_hash || '')) &&
                result.projection.state_revision_event_durable === true
            );
            var valid = result.schema === SCHEMA &&
                statusMatches &&
                typeof result.replayed === 'boolean' &&
                Number(result.product_id) === Number(request.product_id) &&
                constantTimeEqual(result.previous_product_code, request.expected_code) &&
                constantTimeEqual(result.product_code, request.product_code) &&
                constantTimeEqual(result.previous_revision, request.if_match) &&
                constantTimeEqual(result.revision, revision) &&
                constantTimeEqual(result.request_id, request.request_id) &&
                constantTimeEqual(result.request_fingerprint, fingerprint) &&
                HASH_PATTERN.test(String(result.governance_evidence_fingerprint || '')) &&
				(result.changed === false || HASH_PATTERN.test(String(result.backup_reference || ''))) &&
				(result.changed !== false || result.backup_reference === '');

            valid = valid && projectionValid && verification &&
                verification.database_readback === true &&
                verification.cache_bypassed === true &&
                verification.unique === true &&
                verification.source_governance === true &&
                verification.projection_current === true;

            if (!valid) {
                throw ambiguous();
            }
			if (result.replayed !== true) return result;
			if (
				typeof result.current_product_code !== 'string' ||
				!HASH_PATTERN.test(String(result.current_revision || '')) ||
				!result.current_readback ||
				result.current_readback.database_readback !== true ||
				result.current_readback.cache_bypassed !== true
			) {
				throw ambiguous();
			}
			return digest(revisionMaterial(request.product_id, result.current_product_code)).then(function(currentRevision) {
				if (!constantTimeEqual(result.current_revision, currentRevision)) throw ambiguous();
				return result;
			});
        });
    }

	/** Return only the verified DB-fresh row state after a historical replay. */
	function currentResult(result) {
		return result && result.replayed === true
			? {product_code: result.current_product_code, revision: result.current_revision}
			: {product_code: result.product_code, revision: result.revision};
	}

    /** Keep one exact classic-admin request active per product. */
    function createRequestRegistry() {
        var active = Object.create(null);

        return {
            begin: function(productId, request) {
                var key = String(productId);
                if (active[key]) return false;
                active[key] = request;
                return true;
            },
            has: function(productId) {
                return Boolean(active[String(productId)]);
            },
            isCurrent: function(productId, request) {
                return active[String(productId)] === request;
            },
            finish: function(productId, request) {
                var key = String(productId);
                if (active[key] !== request) return false;
                delete active[key];
                return true;
            },
            size: function() {
                return Object.keys(active).length;
            }
        };
    }

	/** Keep dedicated Product Code intents out of the generic bulk writer. */
	function planBulkUpdates(updates, intents, registry) {
		var safe = {};
		var pending = [];
		Object.keys(updates || {}).forEach(function(productId) {
			var fields = updates[productId];
			if (!fields || typeof fields !== 'object') return;
			var hasProductCode = Object.prototype.hasOwnProperty.call(fields, 'patris_product_code');
			var hasIntent = Boolean(intents && intents[productId]);
			var inFlight = Boolean(registry && typeof registry.has === 'function' && registry.has(productId));
			if (hasProductCode || hasIntent || inFlight) {
				pending.push(String(productId));
				return;
			}
			safe[productId] = Object.assign({}, fields);
		});
		pending.sort();

		return {updates: safe, pendingProductIds: pending};
	}

    global.DigitalogicProductCodeContract = {
        schema: SCHEMA,
        prepare: prepare,
        validateResult: validateResult,
		currentResult: currentResult,
		withDeadline: withDeadline,
		planBulkUpdates: planBulkUpdates,
        ambiguous: ambiguous,
        createRequestRegistry: createRequestRegistry
    };
})(window);
