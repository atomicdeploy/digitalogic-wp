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
    var websocket;
    var websocketReady = false;
    var websocketConnecting = false;
    var websocketRequests = {};
    var websocketRequestId = 0;

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' || value === '' ? '-' : value).html();
    }

    function editableCell(row, field, value, inputType, step) {
        return '<button type="button" class="digitalogic-editable-cell" data-id="' + row.id + '" data-field="' + field + '" data-type="' + (inputType || 'text') + '" data-step="' + (step || '') + '">' + escapeHtml(value) + '</button>';
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
    function digitalogicRequest(action, data) {
        data = data || {};

        if (websocketReady && websocket && websocket.readyState === WebSocket.OPEN) {
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

        return $.ajax({
            url: digitalogic.ajax_url,
            type: 'POST',
            data: $.extend({
                action: action,
                nonce: digitalogic.nonce
            }, data)
        });
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
            ajax: {
                url: digitalogic.ajax_url,
                type: 'POST',
                data: function(d) {
                    // Handle both object and string formats for search
                    var searchValue = (typeof d.search === 'object' && d.search !== null) ? d.search.value : (d.search || '');
                    
                    // Ensure d.start and d.length are valid numbers to prevent NaN
                    var start = (typeof d.start === 'number' && !isNaN(d.start)) ? d.start : 0;
                    var length = (typeof d.length === 'number' && !isNaN(d.length) && d.length > 0) ? d.length : 50;
                    
                    return {
                        action: 'digitalogic_get_products',
                        nonce: digitalogic.nonce,
                        page: Math.floor(start / length) + 1,
                        limit: length,
                        search: searchValue
                    };
                },
                dataSrc: function(json) {
                    console.log('Products AJAX response:', json);
                    
                    // Handle WordPress AJAX response format for server-side DataTables
                    if (json.success && json.data) {
                        var total = json.data.recordsTotal || json.data.total || 0;
                        var filtered = json.data.recordsFiltered || total;

                        // Update DataTables pagination info
                        json.recordsTotal = total;
                        json.recordsFiltered = filtered;
                        
                        if (json.data.products) {
                            return json.data.products;
                        }
                    }

                    console.error('Invalid response format:', json);
                    if (json.data && typeof json.data === 'string') {
                        alert('Error loading products: ' + json.data);
                    } else {
                        alert('Error loading products. Please check console for details.');
                    }
                    return [];
                },
                error: function(xhr, error, thrown) {
                    console.error('Digitalogic request error:', error, thrown);
                    alert(digitalogic.i18n.error + ': ' + thrown);
                }
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
                { data: 'sku' },
                {
                    data: 'regular_price',
                    render: function(data, type, row) {
                        return editableCell(row, 'regular_price', data, 'number', '0.01');
                    }
                },
                {
                    data: 'sale_price',
                    render: function(data, type, row) {
                        return editableCell(row, 'sale_price', data, 'number', '0.01');
                    }
                },
                {
                    data: 'stock_quantity',
                    render: function(data, type, row) {
                        return editableCell(row, 'stock_quantity', data, 'number', '1');
                    }
                },
                {
                    data: 'weight',
                    render: function(data, type, row) {
                        return editableCell(row, 'weight', data, 'number', '0.01');
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data, type, row) {
                        var panelUrl = (digitalogic.panel_url || '/panel/').replace(/\/+$/, '') + '/products/' + encodeURIComponent(row.id);
                        return '<div class="digitalogic-actions">' +
                            '<a class="button button-small view-product" href="' + panelUrl + '" target="_blank" rel="noopener" data-id="' + row.id + '">' + digitalogic.i18n.view_product + '</a>' +
                            '</div>';
                    }
                }
            ],
            pageLength: 50,
            language: {
                processing: digitalogic.i18n.loading,
                search: '',
                searchPlaceholder: 'Search products...',
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
        });

        $('#products-table').on('click keydown', '.digitalogic-editable-cell', function(event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== 'F2') {
                return;
            }

            event.preventDefault();
            var $cell = $(this);
            var value = $cell.text() === '-' ? '' : $cell.text();
            var type = $cell.data('type') || 'text';
            var step = $cell.data('step') || '';
            var $input = $('<input>')
                .attr('type', type)
                .attr('step', step)
                .attr('data-id', $cell.data('id'))
                .attr('data-field', $cell.data('field'))
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

            $input.trigger('change');
            var value = $input.val();
            var row = {id: $input.data('id')};
            var $display = $(editableCell(row, $input.data('field'), value, $input.attr('type'), $input.attr('step'))).addClass('changed');
            $input.replaceWith($display);
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
                changedProducts = {};
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
            
            if (!confirm(digitalogic.i18n.confirm_bulk_update)) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');
            
            digitalogicRequest('digitalogic_bulk_update', {
                updates: changedProducts
            }).done(function(response) {
                if (response.success) {
                    alert(digitalogic.i18n.success + ': ' + response.data.success + ' products updated');
                    changedProducts = {};
                    $('.product-field').removeClass('changed');
                    if (productsTable) {
                        productsTable.ajax.reload();
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
        
        // Export
        $('#export-btn').on('click', function() {
            var format = $('#export_format').val();
            var $btn = $(this);
            var $result = $('#export-result');
            
            $btn.prop('disabled', true).text('Exporting...');
            $result.removeClass('success error').text('');
            
            digitalogicRequest('digitalogic_export', {
                format: format,
                product_ids: []
            }).done(function(response) {
                if (response.success) {
                    $result.addClass('success').html(
                        'Export completed! <a href="' + response.data.url + '" download>Download file</a>'
                    );
                } else {
                    $result.addClass('error').text('Export failed: ' + response.data);
                }
            }).fail(function() {
                $result.addClass('error').text('Export failed');
            }).always(function() {
                $btn.prop('disabled', false).text('Export All Products');
            });
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
        if (productsTable && typeof productsTable.ajax !== 'undefined' && $('#products-table').is(':visible')) {
            try {
                productsTable.ajax.reload(null, false); // false = don't reset paging
            } catch (e) {
                console.error('Error during auto-refresh:', e);
            }
        }
    }, 60000); // 60 seconds
    
})(jQuery);
