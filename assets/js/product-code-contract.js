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
            return result;
        });
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

    global.DigitalogicProductCodeContract = {
        schema: SCHEMA,
        prepare: prepare,
        validateResult: validateResult,
        ambiguous: ambiguous,
        createRequestRegistry: createRequestRegistry
    };
})(window);
