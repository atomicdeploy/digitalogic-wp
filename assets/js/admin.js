/**
 * Digitalogic Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Log that admin.js has loaded
    console.info('Digitalogic admin.js loaded successfully');
    
    var productsTable;
    var logsTable;
    var changedProducts = {};
    var productCodeIntents = {};
	var productCodeRequests = window.DigitalogicProductCodeContract &&
		typeof window.DigitalogicProductCodeContract.createRequestRegistry === 'function'
		? window.DigitalogicProductCodeContract.createRequestRegistry()
		: null;
	var productCodeNotices = {};
    var websocket;
    var websocketReady = false;
    var websocketConnecting = false;
    var websocketRequests = {};
    var websocketRequestId = 0;

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' || value === '' ? '-' : value).html();
    }

	function hydrateProductCodeRecovery(row) {
		var recovery = row && row.patris_product_code_recovery;
		if (!recovery || typeof recovery !== 'object' || typeof recovery.request_id !== 'string') return;
		if (recovery.status === 'outcome_unknown') {
			delete productCodeIntents[row.id];
			productCodeNotices[row.id] = {
				code: 'digitalogic_product_code_outcome_unknown',
				message: (digitalogic.i18n && digitalogic.i18n.product_code_outcome_unknown) || '',
				action: (digitalogic.i18n && digitalogic.i18n.product_code_manual_reconcile) || ''
			};
			return;
		}
		if (
			typeof recovery.expected_code !== 'string' ||
			typeof recovery.product_code !== 'string' ||
			typeof recovery.if_match !== 'string'
		) return;
		var signature = [row.id, recovery.expected_code, recovery.product_code, recovery.if_match].join('\u0000');
		var current = productCodeIntents[row.id];
		if (!current || current.request_id !== recovery.request_id) {
			productCodeIntents[row.id] = {
				expected_code: recovery.expected_code,
				if_match: recovery.if_match,
				request_id: recovery.request_id,
				request_fingerprint: String(recovery.request_fingerprint || ''),
				signature: signature,
				recovery_required: true,
				recovery_product_code: recovery.product_code
			};
		}
		if (!productCodeNotices[row.id]) {
			productCodeNotices[row.id] = {
				code: 'digitalogic_product_code_recovery_required',
				message: (digitalogic.i18n && digitalogic.i18n.product_code_recovery_required) || '',
				action: (digitalogic.i18n && digitalogic.i18n.product_code_retry_same_request) || ''
			};
		}
	}

	function productCodeNoticeHtml(productId) {
		var notice = productCodeNotices[productId];
		if (!notice) return '';
		return '<div class="digitalogic-product-code-notice" role="alert" aria-live="assertive">' +
			'<span>' + escapeHtml(notice.message) + '</span>' +
			'<code>' + escapeHtml(String(notice.code || '').replace(/[^a-z0-9_.:-]/gi, '').slice(0, 96)) + '</code>' +
			'<small>' + escapeHtml(notice.action) + '</small>' +
			'</div>';
	}

	function setProductCodeNotice(productId, code, message, actionKey) {
		productCodeNotices[productId] = {
			code: code,
			message: message || ((digitalogic.i18n && digitalogic.i18n.product_code_request_failed) || ''),
			action: (digitalogic.i18n && digitalogic.i18n[actionKey]) || ''
		};
	}

	function planBulkProductUpdates(updates) {
		var contract = window.DigitalogicProductCodeContract;
		if (contract && typeof contract.planBulkUpdates === 'function') {
			return contract.planBulkUpdates(updates, productCodeIntents, productCodeRequests);
		}
		var pending = [];
		Object.keys(updates || {}).forEach(function(productId) {
			var fields = updates[productId] || {};
			if (Object.prototype.hasOwnProperty.call(fields, 'patris_product_code') || productCodeIntents[productId]) {
				pending.push(String(productId));
			}
		});
		return {updates: {}, pendingProductIds: pending};
	}

    function normalizeDigits(value) {
        return String(value || '').replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function(digit) {
            var code = digit.charCodeAt(0);
            return String(code >= 0x06F0 ? code - 0x06F0 : code - 0x0660);
        });
    }

    function normalizeNumber(value) {
        var cleaned = normalizeDigits(value)
            .replace(/[\u066C\u060C,\s]/g, '')
            .replace(/[^0-9.]/g, '');
        var parts = cleaned.split('.');
        return parts.length > 2 ? parts.shift() + '.' + parts.join('') : cleaned;
    }

    function formatInputNumber(value) {
        var raw = normalizeNumber(value);
        if (raw === '') {
            return '';
        }
        var parts = raw.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }

    function editableCell(row, field, value, inputType, step) {
        return '<button type="button" class="digitalogic-editable-cell" data-id="' + row.id + '" data-field="' + field + '" data-type="' + (inputType || 'text') + '" data-step="' + (step || '') + '">' + escapeHtml(value) + '</button>';
    }

    function productCodeCell(row, value) {
		hydrateProductCodeRecovery(row);
		var notice = productCodeNoticeHtml(row.id);
		if (!productCodeRequests || productCodeRequests.has(row.id)) {
			return '<span class="digitalogic-editable-cell is-saving" aria-disabled="true" aria-busy="true">' + escapeHtml(value) + '</span>' + notice;
		}
        if (row.patris_product_code_editable === false) {
			var reason = row.patris_product_code_edit_reason || 'state_unavailable';
			var reasonKeys = {
				source_managed: 'product_code_source_managed',
				metadata_conflict: 'product_code_metadata_conflict',
				state_changed: 'product_code_state_changed',
				state_unavailable: 'product_code_state_unavailable',
				source_state_unavailable: 'product_code_state_unavailable',
				permission_denied: 'product_code_permission_denied',
				recovery_unavailable: 'product_code_recovery_unavailable',
				outcome_unknown: 'product_code_outcome_unknown'
			};
			var reasonKey = reasonKeys[reason] || 'product_code_state_unavailable';
			return '<span class="digitalogic-editable-cell is-readonly" aria-disabled="true" title="' + escapeHtml((digitalogic.i18n && digitalogic.i18n[reasonKey]) || '') + '">' + escapeHtml(value) + '</span>' + notice;
        }

		return editableCell(row, 'patris_product_code', value, 'text') + notice;
    }

    function productTableRow(productId, $field) {
		if (!productsTable) return null;
		if ($field && $field.closest('tr').length) {
			var direct = productsTable.row($field.closest('tr'));
			var directData = direct.data();
			if (directData && String(directData.id) === String(productId)) return direct;
		}

		return productsTable.row(function(index, data) {
			return data && String(data.id) === String(productId);
		});
	}
    
    $(document).ready(function() {
        connectWebSocket();
        initProductsTable();
        initLogsTable();
        initEventHandlers();
    });

    /**
     * Connect to the Digitalogic WebSocket server when configured.
     */
    function connectWebSocket() {
        if (
            typeof digitalogic === 'undefined' ||
            !digitalogic.websocket ||
            !digitalogic.websocket.enabled ||
            !digitalogic.websocket.url ||
            websocketConnecting ||
            websocketReady
        ) {
            return;
        }

        if (typeof window.WebSocket === 'undefined') {
            return;
        }

        websocketConnecting = true;
        var separator = digitalogic.websocket.url.indexOf('?') === -1 ? '?' : '&';
        var authParam = digitalogic.websocket.token ? 'token=' + encodeURIComponent(digitalogic.websocket.token) : 'nonce=' + encodeURIComponent(digitalogic.websocket.nonce);
        var url = digitalogic.websocket.url + separator + authParam;

        try {
            websocket = new WebSocket(url);
        } catch (e) {
            websocketConnecting = false;
            return;
        }

        websocket.onopen = function() {
            websocketReady = true;
            websocketConnecting = false;
        };

        websocket.onmessage = function(event) {
            var response;
            try {
                response = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (!response.id || !websocketRequests[response.id]) {
                if (response.event && response.event.indexOf('product') !== -1 && productsTable) {
                    productsTable.ajax.reload(null, false);
                }
                return;
            }

            var pending = websocketRequests[response.id];
            delete websocketRequests[response.id];
            clearTimeout(pending.timeout);

            if (response.success) {
                pending.deferred.resolve({
                    success: true,
                    data: response.data
                });
            } else {
                pending.deferred.reject(response.error || {message: digitalogic.i18n.error});
            }
        };

        websocket.onclose = function() {
            websocketReady = false;
            websocketConnecting = false;
            rejectWebSocketRequests();
            setTimeout(connectWebSocket, digitalogic.websocket.reconnect_interval || 3000);
        };

        websocket.onerror = function() {
            websocketReady = false;
            websocketConnecting = false;
        };
    }

	function rejectProductCodeDeferred(deferred, error, textStatus) {
		error = error && typeof error === 'object' ? error : {};
		var status = Number(error.status || 0);
		deferred.reject({
			status: status,
			responseJSON: {
				success: false,
				data: {
					code: String(error.code || 'digitalogic_response_ambiguous'),
					message: String(error.message || ''),
					status: status,
					data: error.data && typeof error.data === 'object' ? error.data : {retryable: true}
				}
			}
		}, textStatus || 'parsererror', String(error.message || ''));
	}

	function boundedProductCodeRequest(snapshot, intent) {
		var deferred = $.Deferred();
		var transport = null;
		var settled = false;
		var timeoutMs = Math.max(1000, Math.min(30000, Number(digitalogic.request_timeout) || 12000));
		var timeoutHandle = setTimeout(function() {
			if (settled) return;
			settled = true;
			if (transport && typeof transport.abort === 'function') transport.abort('timeout');
			rejectProductCodeDeferred(deferred, {
				code: 'digitalogic_request_timeout',
				message: 'The Product Code request timed out. Retry the unchanged request.',
				status: 0,
				data: {retryable: true}
			}, 'timeout');
		}, timeoutMs);
		function resolveOnce(response) {
			if (settled) return;
			settled = true;
			clearTimeout(timeoutHandle);
			deferred.resolve(response);
		}
		function rejectOnce(error, textStatus, fallback) {
			if (settled) return;
			settled = true;
			clearTimeout(timeoutHandle);
			rejectProductCodeDeferred(deferred, error, textStatus, fallback);
		}
		var contract = window.DigitalogicProductCodeContract;
		if (!contract || typeof contract.prepare !== 'function' || typeof contract.validateResult !== 'function') {
			rejectOnce({
				code: 'digitalogic_response_ambiguous',
				message: 'The Product Code response verifier is unavailable.',
				data: {retryable: true}
			});
			return deferred.promise();
		}

		contract.prepare(snapshot).then(function(prepared) {
			if (settled) return;
			intent.request_fingerprint = prepared.request_fingerprint;
			transport = digitalogicRequest('digitalogic_update_product_code', {
				product_id: prepared.product_id,
				expected_code: prepared.expected_code,
				product_code: prepared.product_code,
				if_match: prepared.if_match,
				request_id: prepared.request_id
			}, {ajaxOnly: true, bounded: true});
			transport.done(function(response) {
				if (settled) return;
				if (response && response.success === false) {
					var payload = response.data && typeof response.data === 'object' ? response.data : {};
					rejectOnce({
						code: payload.code,
						message: payload.message,
						status: payload.status,
						data: payload.data
					}, 'error');
					return;
				}
				var result = response && response.data !== undefined ? response.data : response;
				contract.validateResult(result, prepared).then(
					function() { resolveOnce(response); },
					function(error) { rejectOnce(error); }
				);
			}).fail(function() {
				if (settled) return;
				settled = true;
				clearTimeout(timeoutHandle);
				deferred.reject.apply(deferred, arguments);
			});
		}, function(error) {
			rejectOnce(error);
		});

		return deferred.promise();
	}

    function rejectWebSocketRequests() {
        Object.keys(websocketRequests).forEach(function(id) {
            websocketRequests[id].deferred.reject({message: 'WebSocket disconnected'});
            clearTimeout(websocketRequests[id].timeout);
            delete websocketRequests[id];
        });
    }

    /**
     * Run a Digitalogic command over WebSocket, falling back to admin-ajax.
     */
    function digitalogicRequest(action, data, options) {
        data = data || {};
		options = options || {};

		if (!options.ajaxOnly && websocketReady && websocket && websocket.readyState === WebSocket.OPEN) {
            var deferred = $.Deferred();
            var id = 'req_' + (++websocketRequestId);
            websocketRequests[id] = {
                deferred: deferred,
                timeout: setTimeout(function() {
                    if (websocketRequests[id]) {
                        websocketRequests[id].deferred.reject({message: 'WebSocket request timed out'});
                        delete websocketRequests[id];
                    }
                }, (digitalogic.websocket && digitalogic.websocket.request_timeout) || 15000)
            };

            websocket.send(JSON.stringify({
                id: id,
                command: action,
                data: data
            }));

            return deferred.promise();
        }

		var requestOptions = {
			url: digitalogic.ajax_url,
			type: 'POST',
			data: $.extend({
				action: action,
				nonce: digitalogic.nonce
			}, data)
		};
		if (options.bounded === true) {
			requestOptions.timeout = Math.max(1000, Math.min(30000, Number(digitalogic.request_timeout) || 12000));
		}

		return $.ajax(requestOptions);
    }
    
    /**
     * Initialize products DataTable
     */
    function initProductsTable() {
        if ($('#products-table').length === 0) {
            return;
        }
        
        // Check if DataTables library is loaded
        if (typeof $.fn.DataTable === 'undefined') {
            console.error('DataTables library not loaded');
            alert('Error: DataTables library failed to load. Please refresh the page.');
            return;
        }
        
        // Check if digitalogic object is available
        if (typeof digitalogic === 'undefined') {
            console.error('Digitalogic configuration not loaded');
            alert('Error: Configuration not loaded. Please refresh the page.');
            return;
        }
        
        productsTable = $('#products-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: function(d, callback) {
                var searchValue = (typeof d.search === 'object' && d.search !== null) ? d.search.value : (d.search || '');
                var start = (typeof d.start === 'number' && !isNaN(d.start)) ? d.start : 0;
                var length = (typeof d.length === 'number' && !isNaN(d.length) && d.length > 0) ? d.length : 50;

                digitalogicRequest('digitalogic_get_products', {
                    page: Math.floor(start / length) + 1,
                    limit: length,
                    search: searchValue
                }).done(function(json) {
                    var payload = json && json.success ? json.data : json;
                    var total = payload && (payload.recordsTotal || payload.total || 0);
                    var filtered = payload && (payload.recordsFiltered || total);

                    if (payload && Array.isArray(payload.products)) {
                        callback({
                            draw: d.draw,
                            data: payload.products,
                            recordsTotal: total,
                            recordsFiltered: filtered
                        });
                        return;
                    }

                    console.error('Invalid response format:', json);
                    callback({draw: d.draw, data: [], recordsTotal: 0, recordsFiltered: 0});
                }).fail(function(error) {
                    console.error('Digitalogic request error:', error);
                    callback({draw: d.draw, data: [], recordsTotal: 0, recordsFiltered: 0});
                });
            },
            columns: [
                {
                    data: null,
                    orderable: false,
                    render: function(data, type, row) {
                        return '<input type="checkbox" class="product-checkbox" data-id="' + row.id + '">';
                    }
                },
                { data: 'id' },
                {
                    data: 'image',
                    orderable: false,
                    render: function(data, type, row) {
                        return data ? '<img src="' + data + '" alt="">' : '';
                    }
                },
                { data: 'name' },
                {
                    data: 'patris_product_code',
                    render: function(data, type, row) {
                        return type === 'display'
							? productCodeCell(row, data)
                            : data;
                    }
                },
                { data: 'sku' },
                {
                    data: 'regular_price',
                    render: function(data, type, row) {
                        return editableCell(row, 'regular_price', formatInputNumber(data), 'number', '0.01');
                    }
                },
                {
                    data: 'sale_price',
                    render: function(data, type, row) {
                        return editableCell(row, 'sale_price', formatInputNumber(data), 'number', '0.01');
                    }
                },
                {
                    data: 'stock_quantity',
                    render: function(data, type, row) {
                        return editableCell(row, 'stock_quantity', formatInputNumber(data), 'number', '1');
                    }
                },
                {
                    data: 'weight',
                    render: function(data, type, row) {
                        return editableCell(row, 'weight', formatInputNumber(data), 'number', '0.01');
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data, type, row) {
                        return '<div class="digitalogic-actions">' +
                            '<a class="button button-small view-product" href="' + escapeHtml(row.permalink || ('/?p=' + row.id)) + '" target="_blank" rel="noopener" data-id="' + row.id + '">' + digitalogic.i18n.view_product + '</a>' +
                            '<a class="button button-small edit-product" href="' + escapeHtml(row.edit_url || ('/wp-admin/post.php?post=' + row.id + '&action=edit')) + '" target="_blank" rel="noopener" data-id="' + row.id + '">' + (digitalogic.i18n.edit_product || 'Edit') + '</a>' +
                            '</div>';
                    }
                }
            ],
            pageLength: 50,
            language: {
                processing: digitalogic.i18n.loading,
                search: '',
                searchPlaceholder: digitalogic.i18n.search_products || 'Search products...',
                lengthMenu: digitalogic.i18n.show + ' _MENU_ ' + digitalogic.i18n.entries,
                info: digitalogic.i18n.showing + ' _START_ ' + digitalogic.i18n.to + ' _END_ ' + digitalogic.i18n.of + ' _TOTAL_ ' + digitalogic.i18n.entries_text,
                infoEmpty: digitalogic.i18n.showing + ' 0 ' + digitalogic.i18n.to + ' 0 ' + digitalogic.i18n.of + ' 0 ' + digitalogic.i18n.entries_text,
                infoFiltered: digitalogic.i18n.filtered,
                emptyTable: digitalogic.i18n.no_data,
                zeroRecords: digitalogic.i18n.no_records
            }
        });
        
        // Track changes
        $('#products-table').on('change', '.product-field', function() {
            var $field = $(this);
            var productId = $field.data('id');
            var fieldName = $field.data('field');
            var value = $field.val();
            
            if (!changedProducts[productId]) {
                changedProducts[productId] = {};
            }
            
            changedProducts[productId][fieldName] = value;
            $field.addClass('changed');
            saveProductField(productId, fieldName, value, $field);
        });

        $('#products-table').on('click keydown', '.digitalogic-editable-cell', function(event) {
			var $cell = $(this);
			var cellProductId = $cell.data('id');
			var cellField = $cell.data('field');
			if (
				$cell.hasClass('is-readonly') ||
				$cell.hasClass('is-saving') ||
				(cellField === 'patris_product_code' && (!productCodeRequests || productCodeRequests.has(cellProductId)))
			) {
				return;
			}
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== 'F2') {
                return;
            }

            event.preventDefault();
            var value = $cell.text() === '-' ? '' : $cell.text();
            var type = $cell.data('type') || 'text';
            var step = $cell.data('step') || '';
            var $input = $('<input>')
                .attr('type', type === 'number' ? 'text' : type)
                .attr('inputmode', type === 'number' ? 'decimal' : '')
                .attr('step', step)
                .attr('data-id', $cell.data('id'))
                .attr('data-field', $cell.data('field'))
                .attr('data-type', type)
                .addClass('product-field digitalogic-cell-input')
                .val(value);

            $cell.replaceWith($input);
            $input.trigger('focus').trigger('select');
        });

        $('#products-table').on('blur keydown', '.digitalogic-cell-input', function(event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== 'Escape') {
                return;
            }

            var $input = $(this);
            if (event.key === 'Escape') {
                productsTable.ajax.reload(null, false);
                return;
            }

            var value = $input.val();
            var row = {id: $input.data('id')};
            var productId = $input.data('id');
            var fieldName = $input.data('field');
            var fieldType = $input.data('type') || $input.attr('type');
            var $display = $(editableCell(row, fieldName, value, fieldType, $input.attr('step'))).addClass('changed');

            if (!changedProducts[productId]) {
                changedProducts[productId] = {};
            }
            changedProducts[productId][fieldName] = value;

            $input.replaceWith($display);
            saveProductField(productId, fieldName, value, $display);
        });

        $('#products-table').on('input', '.digitalogic-cell-input[data-type="number"]', function() {
            var raw = normalizeNumber(this.value);
            this.value = formatInputNumber(raw);
        });
    }

    function saveProductField(productId, fieldName, value, $field) {
		if (fieldName === 'patris_product_code' && (!productCodeRequests || productCodeRequests.has(productId))) {
			return;
		}
        var data = {};
        data[fieldName] = $field.data('type') === 'number' ? normalizeNumber(value) : value;
        $field.addClass('is-saving').prop('disabled', true).attr('aria-busy', 'true');

		var requestSnapshot = null;
		var request;
        if (fieldName === 'patris_product_code') {
			var startingRow = productTableRow(productId, $field);
			var row = startingRow && startingRow.data() ? startingRow.data() : {};
            var intent = productCodeIntents[productId] || {
                expected_code: String(row.patris_product_code || ''),
                if_match: String(row.patris_product_code_revision || ''),
                request_id: '',
                signature: ''
            };
            var desiredCode = String(value);
			if (intent.recovery_required && desiredCode !== intent.recovery_product_code) {
				setProductCodeNotice(
					productId,
					'digitalogic_product_code_recovery_required',
					(digitalogic.i18n && digitalogic.i18n.product_code_recovery_required) || '',
					'product_code_retry_same_request'
				);
				$field.addClass('is-error');
				$field.removeClass('is-saving').prop('disabled', false).removeAttr('aria-busy');
				if (productsTable) {
					productsTable.rows().invalidate();
					productsTable.draw(false);
				}
				return;
			}
            var signature = [productId, intent.expected_code, desiredCode, intent.if_match].join('\u0000');
            if (!intent.request_id || intent.signature !== signature) {
                var random = '';
                if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                    var bytes = new Uint32Array(2);
                    window.crypto.getRandomValues(bytes);
                    random = Array.prototype.map.call(bytes, function(item) {
                        return item.toString(16);
                    }).join('');
                } else {
                    random = Math.random().toString(16).slice(2);
                }
                intent.request_id = 'product-code:' + productId + ':' + Date.now() + ':' + random;
				intent.request_fingerprint = '';
                intent.signature = signature;
            }
            productCodeIntents[productId] = intent;
			requestSnapshot = {
				product_id: productId,
				expected_code: intent.expected_code,
				product_code: desiredCode,
				if_match: intent.if_match,
				request_id: intent.request_id,
				request_fingerprint: String(intent.request_fingerprint || ''),
				signature: intent.signature,
				desired_code: desiredCode
			};
			if (!productCodeRequests.begin(productId, requestSnapshot)) {
				$field.removeClass('is-saving').prop('disabled', false).removeAttr('aria-busy');
				return;
			}
			request = boundedProductCodeRequest(requestSnapshot, intent);
        } else {
            request = digitalogicRequest('digitalogic_update_product', {
                product_id: productId,
                data: data
            });
        }

        request.done(function(response) {
			if (fieldName === 'patris_product_code' && !productCodeRequests.isCurrent(productId, requestSnapshot)) {
				return;
			}
            if (!response || response.success === false) {
                $field.addClass('is-error');
                return;
            }

            if (fieldName === 'patris_product_code') {
                var result = response.data || response;
                if (!result || typeof result.product_code !== 'string' || typeof result.revision !== 'string') {
                    $field.addClass('is-error');
                    return;
                }
				var currentResult = window.DigitalogicProductCodeContract.currentResult(result);
				var productRowApi = productTableRow(productId, $field);
				var productRow = productRowApi && productRowApi.data ? productRowApi.data() : null;
                if (productRow) {
					productRow.patris_product_code = currentResult.product_code;
					productRow.patris_product_code_revision = currentResult.revision;
					productRow.patris_product_code_recovery = {};
                }
				delete productCodeNotices[productId];
				if (
					productCodeIntents[productId] &&
					productCodeIntents[productId].request_id === requestSnapshot.request_id
				) {
					delete productCodeIntents[productId];
				}
            }

            if (changedProducts[productId]) {
				if (
					fieldName !== 'patris_product_code' ||
					String(changedProducts[productId][fieldName]) === requestSnapshot.desired_code
				) {
					delete changedProducts[productId][fieldName];
				}
                if (Object.keys(changedProducts[productId]).length === 0) {
                    delete changedProducts[productId];
                }
            }

            $field.removeClass('changed is-error').addClass('is-saved');
            setTimeout(function() {
                $field.removeClass('is-saved');
            }, 1200);
		}).fail(function(xhr, textStatus, errorThrown) {
			if (fieldName === 'patris_product_code') {
				if (!productCodeRequests.isCurrent(productId, requestSnapshot)) {
					return;
				}
				var response = xhr && xhr.responseJSON;
				var payload = response && response.data && typeof response.data === 'object' ? response.data : {};
				var details = payload.data && typeof payload.data === 'object' ? payload.data : {};
				var status = Number(payload.status || details.status || (xhr && xhr.status) || 0);
				var timedOut = textStatus === 'timeout';
				var errorCode = timedOut ? 'digitalogic_request_timeout' : String(payload.code || 'digitalogic_product_code_request_failed');
				var intent = productCodeIntents[productId];
				if (
					errorCode === 'digitalogic_product_code_recovery_required' &&
					details.recovery &&
					typeof details.recovery.request_id === 'string'
				) {
					var recovery = details.recovery;
					productCodeIntents[productId] = {
						expected_code: String(recovery.expected_code || ''),
						if_match: String(recovery.if_match || ''),
					request_id: recovery.request_id,
					request_fingerprint: String(recovery.request_fingerprint || ''),
						signature: [productId, String(recovery.expected_code || ''), String(recovery.product_code || ''), String(recovery.if_match || '')].join('\u0000'),
						recovery_required: true,
						recovery_product_code: String(recovery.product_code || '')
					};
					intent = productCodeIntents[productId];
				}
				var actionKey = 'product_code_reload';
				if (timedOut || status === 0 || status === 408 || status === 429 || status >= 500 || details.retryable === true) {
					actionKey = 'product_code_retry_same_request';
				} else if (errorCode === 'digitalogic_product_code_source_managed') {
					actionKey = 'product_code_correct_source';
				} else if (errorCode === 'digitalogic_product_code_outcome_unknown') {
					actionKey = 'product_code_manual_reconcile';
				} else if (errorCode === 'digitalogic_product_code_not_unique' || errorCode === 'digitalogic_product_code_meta_conflict') {
					actionKey = 'product_code_resolve_conflict';
				}
				var errorMessage = errorCode === 'digitalogic_response_ambiguous'
					? ((digitalogic.i18n && digitalogic.i18n.product_code_response_ambiguous) || '')
					: String(payload.message || errorThrown || '');
				setProductCodeNotice(productId, errorCode, errorMessage, actionKey);
				if (
					intent &&
					errorCode === 'digitalogic_product_code_precondition_failed' &&
					typeof details.current_code === 'string' &&
					typeof details.current_revision === 'string'
				) {
					intent.expected_code = details.current_code;
					intent.if_match = details.current_revision;
					intent.request_id = '';
					intent.request_fingerprint = '';
					intent.signature = '';
					var staleRowApi = productTableRow(productId, $field);
					var staleRow = staleRowApi && staleRowApi.data ? staleRowApi.data() : null;
					if (staleRow) {
						staleRow.patris_product_code = details.current_code;
						staleRow.patris_product_code_revision = details.current_revision;
					}
				} else if (errorCode === 'digitalogic_product_code_outcome_unknown') {
					delete productCodeIntents[productId];
					if (changedProducts[productId]) {
						delete changedProducts[productId][fieldName];
					}
					productsTable.ajax.reload(null, false);
				} else if (
					errorCode === 'digitalogic_product_code_source_managed' ||
					errorCode === 'digitalogic_product_code_meta_conflict'
				) {
					delete productCodeIntents[productId];
					if (changedProducts[productId]) {
						delete changedProducts[productId][fieldName];
					}
					var guardedRowApi = productTableRow(productId, $field);
					var guardedRow = guardedRowApi && guardedRowApi.data ? guardedRowApi.data() : null;
					if (guardedRow) {
						guardedRow.patris_product_code_editable = false;
						guardedRow.patris_product_code_edit_reason = errorCode === 'digitalogic_product_code_source_managed'
							? 'source_managed'
							: 'metadata_conflict';
						guardedRowApi.data(guardedRow).invalidate();
						productsTable.draw(false);
					}
					productsTable.ajax.reload(null, false);
				} else if (
					!timedOut &&
					status > 0 &&
					status !== 408 &&
					status !== 429 &&
					status < 500 &&
					details.retryable !== true
				) {
					delete productCodeIntents[productId];
				}
			}
            $field.addClass('is-error');
        }).always(function() {
			if (fieldName === 'patris_product_code') {
				if (!productCodeRequests.isCurrent(productId, requestSnapshot)) {
					return;
				}
				productCodeRequests.finish(productId, requestSnapshot);
			}
            $field.removeClass('is-saving').prop('disabled', false).removeAttr('aria-busy');
			if (fieldName === 'patris_product_code' && productsTable) {
				productsTable.rows().invalidate();
				productsTable.draw(false);
			}
        });
    }
    
    /**
     * Initialize logs DataTable
     */
    function initLogsTable() {
        if ($('#logs-table').length === 0) {
            return;
        }
        
        logsTable = $('#logs-table').DataTable({
            processing: true,
            serverSide: false,
            ajax: function(d, callback) {
                digitalogicRequest('digitalogic_get_logs', {
                    page: Math.floor(d.start / d.length) + 1,
                    limit: d.length
                }).done(function(json) {
                    if (json.success && json.data && json.data.logs) {
                        callback({data: json.data.logs});
                        return;
                    }

                    console.error('Invalid response format:', json);
                    callback({data: []});
                }).fail(function(error) {
                    console.error('Digitalogic request error:', error);
                    callback({data: []});
                });
            },
            columns: [
                { data: 'id' },
                {
                    data: 'user_id',
                    render: function(data) {
                        return data > 0 ? 'User #' + data : 'System';
                    }
                },
                { data: 'action' },
                { data: 'object_type' },
                { data: 'object_id' },
                { data: 'created_at' },
                { data: 'ip_address' }
            ],
            order: [[0, 'desc']],
            pageLength: 50
        });
    }
    
    /**
     * Initialize event handlers
     */
    function initEventHandlers() {
        // Select all checkbox
        $('#select-all').on('change', function() {
            $('.product-checkbox').prop('checked', $(this).prop('checked'));
        });
        
        // Refresh products
        $('#refresh-products').on('click', function() {
            if (productsTable) {
                productsTable.ajax.reload();
				var refreshPlan = planBulkProductUpdates(changedProducts);
				Object.keys(refreshPlan.updates).forEach(function(productId) {
					delete changedProducts[productId];
				});
            }
        });
        
        // Product search
        $('#product-search').on('keyup', function() {
            var searchTerm = $(this).val();
            if (productsTable) {
                productsTable.search(searchTerm).draw();
            }
        });
        
        // Bulk update
        $('#bulk-update-btn').on('click', function() {
            if (Object.keys(changedProducts).length === 0) {
                alert('No changes to save');
                return;
            }
			var bulkPlan = planBulkProductUpdates(changedProducts);
			bulkPlan.pendingProductIds.forEach(function(productId) {
				setProductCodeNotice(
					productId,
					'digitalogic_product_code_dedicated_save_required',
					(digitalogic.i18n && digitalogic.i18n.product_code_bulk_pending) || '',
					'product_code_retry_same_request'
				);
			});
			if (bulkPlan.pendingProductIds.length && productsTable) {
				productsTable.rows().invalidate();
				productsTable.draw(false);
			}
			if (Object.keys(bulkPlan.updates).length === 0) {
				alert((digitalogic.i18n && digitalogic.i18n.product_code_bulk_pending) || 'Product Code changes must finish through their dedicated save operation.');
				return;
			}
            
            if (!confirm(digitalogic.i18n.confirm_bulk_update)) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');
            
            digitalogicRequest('digitalogic_bulk_update', {
				updates: bulkPlan.updates
            }).done(function(response) {
                if (response.success) {
					var pendingMessage = bulkPlan.pendingProductIds.length
						? '\n' + ((digitalogic.i18n && digitalogic.i18n.product_code_bulk_pending) || '')
						: '';
                    alert(digitalogic.i18n.success + ': ' + response.data.success + ' products updated' + pendingMessage);
					Object.keys(bulkPlan.updates).forEach(function(productId) {
						delete changedProducts[productId];
					});
                    $('.product-field').removeClass('changed');
					if (productsTable && bulkPlan.pendingProductIds.length === 0 && (!productCodeRequests || productCodeRequests.size() === 0)) {
                        productsTable.ajax.reload();
					} else if (productsTable) {
						productsTable.rows().invalidate();
						productsTable.draw(false);
                    }
                } else {
                    alert(digitalogic.i18n.error + ': ' + response.data);
                }
            }).fail(function() {
                alert(digitalogic.i18n.error);
            }).always(function() {
                $btn.prop('disabled', false).text('Save Changes');
            });
        });
        
        function runExport($btn, format, template) {
            var $result = $('#export-result');
            
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Exporting...');
            $result.removeClass('success error').text('');
            
            digitalogicRequest('digitalogic_export', {
                format: format,
                product_ids: [],
                locale: $('#export_locale').val(),
                template: template ? 1 : 0
            }).done(function(response) {
                if (response.success) {
                    var $link = $('<a>').attr('href', response.data.url).attr('download', '').text('Download file');
                    $result.addClass('success').text('Export completed! ').append($link);
                } else {
                    $result.addClass('error').text('Export failed: ' + response.data);
                }
            }).fail(function() {
                $result.addClass('error').text('Export failed');
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        }

        // Export
        $('#export-btn').on('click', function() {
            runExport($(this), $('#export_format').val(), false);
        });

        $('#excel-template-btn').on('click', function() {
            runExport($(this), 'excel', true);
        });
        
        // Import
        $('#import-btn').on('click', function() {
            var fileInput = $('#import_file')[0];
            
            if (!fileInput.files.length) {
                alert('Please select a file');
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'digitalogic_import');
            formData.append('nonce', digitalogic.nonce);
            formData.append('file', fileInput.files[0]);
            
            var $btn = $(this);
            var $result = $('#import-result');
            
            $btn.prop('disabled', true).text('Importing...');
            $result.removeClass('success error').text('');
            
            $.ajax({
                url: digitalogic.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $result.addClass('success').html(
                            'Import completed! Success: ' + response.data.success + 
                            ', Failed: ' + response.data.failed
                        );
                        if (productsTable) {
                            productsTable.ajax.reload();
                        }
                    } else {
                        $result.addClass('error').text('Import failed: ' + response.data);
                    }
                },
                error: function() {
                    $result.addClass('error').text('Import failed');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Import Products');
                    fileInput.value = '';
                }
            });
        });
        
        // View product
        $('#products-table').on('click', '.view-product', function() {
            if (this.tagName && this.tagName.toLowerCase() === 'a') {
                return;
            }

            var productId = $(this).data('id');
            var baseUrl = digitalogic.panel_url || '/panel/';
            window.open(baseUrl.replace(/\/+$/, '') + '/products/' + encodeURIComponent(productId), '_blank', 'noopener');
        });
    }
    
    // Auto-refresh (polling) every 60 seconds to reduce server load
    // For more real-time updates, consider implementing WebSockets or Server-Sent Events
    setInterval(function() {
		if (
			productsTable &&
			typeof productsTable.ajax !== 'undefined' &&
			$('#products-table').is(':visible') &&
			productCodeRequests && productCodeRequests.size() === 0 &&
			$('#products-table .digitalogic-cell-input').length === 0
		) {
            try {
                productsTable.ajax.reload(null, false); // false = don't reset paging
            } catch (e) {
                console.error('Error during auto-refresh:', e);
            }
        }
    }, 60000); // 60 seconds
    
})(jQuery);
