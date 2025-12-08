/**
 * Digitalogic Admin JavaScript
 */

(function($) {
    'use strict';
    
    var productsTable;
    var logsTable;
    var changedProducts = {};
    
    $(document).ready(function() {
        initProductsTable();
        initLogsTable();
        initEventHandlers();
    });
    
    /**
     * Initialize products DataTable
     */
    function initProductsTable() {
        if ($('#products-table').length === 0) {
            return;
        }
        
        productsTable = $('#products-table').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: digitalogic.ajax_url,
                type: 'POST',
                data: function(d) {
                    return {
                        action: 'digitalogic_get_products',
                        nonce: digitalogic.nonce,
                        page: Math.floor(d.start / d.length) + 1,
                        limit: d.length,
                        search: d.search.value
                    };
                },
                dataSrc: function(json) {
                    // Handle WordPress AJAX response format
                    if (json.success && json.data && json.data.products) {
                        return json.data.products;
                    }
                    console.error('Invalid response format:', json);
                    return [];
                },
                error: function(xhr, error, thrown) {
                    console.error('AJAX error:', error, thrown);
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
                        return '<input type="number" class="product-field" data-id="' + row.id + '" data-field="regular_price" value="' + (data || '') + '" step="0.01">';
                    }
                },
                {
                    data: 'sale_price',
                    render: function(data, type, row) {
                        return '<input type="number" class="product-field" data-id="' + row.id + '" data-field="sale_price" value="' + (data || '') + '" step="0.01">';
                    }
                },
                {
                    data: 'stock_quantity',
                    render: function(data, type, row) {
                        return '<input type="number" class="product-field" data-id="' + row.id + '" data-field="stock_quantity" value="' + (data || '') + '" step="1">';
                    }
                },
                {
                    data: 'weight',
                    render: function(data, type, row) {
                        return '<input type="number" class="product-field" data-id="' + row.id + '" data-field="weight" value="' + (data || '') + '" step="0.01">';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data, type, row) {
                        return '<div class="digitalogic-actions">' +
                            '<button type="button" class="button button-small view-product" data-id="' + row.id + '">View</button>' +
                            '</div>';
                    }
                }
            ],
            pageLength: 50,
            language: {
                processing: digitalogic.i18n.loading,
                search: '',
                searchPlaceholder: 'Search products...'
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
            ajax: {
                url: digitalogic.ajax_url,
                type: 'POST',
                data: function(d) {
                    return {
                        action: 'digitalogic_get_logs',
                        nonce: digitalogic.nonce,
                        page: Math.floor(d.start / d.length) + 1,
                        limit: d.length
                    };
                },
                dataSrc: function(json) {
                    // Handle WordPress AJAX response format
                    if (json.success && json.data && json.data.logs) {
                        return json.data.logs;
                    }
                    console.error('Invalid response format:', json);
                    return [];
                },
                error: function(xhr, error, thrown) {
                    console.error('AJAX error:', error, thrown);
                    alert(digitalogic.i18n.error + ': ' + thrown);
                }
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
        var searchTimeout;
        $('#product-search').on('keyup', function() {
            clearTimeout(searchTimeout);
            var searchTerm = $(this).val();
            searchTimeout = setTimeout(function() {
                if (productsTable) {
                    productsTable.search(searchTerm).draw();
                }
            }, 500);
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
            
            $.ajax({
                url: digitalogic.ajax_url,
                type: 'POST',
                data: {
                    action: 'digitalogic_bulk_update',
                    nonce: digitalogic.nonce,
                    updates: changedProducts
                },
                success: function(response) {
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
                },
                error: function() {
                    alert(digitalogic.i18n.error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Save Changes');
                }
            });
        });
        
        // Export
        $('#export-btn').on('click', function() {
            var format = $('#export_format').val();
            var $btn = $(this);
            var $result = $('#export-result');
            
            $btn.prop('disabled', true).text('Exporting...');
            $result.removeClass('success error').text('');
            
            $.ajax({
                url: digitalogic.ajax_url,
                type: 'POST',
                data: {
                    action: 'digitalogic_export',
                    nonce: digitalogic.nonce,
                    format: format,
                    product_ids: []
                },
                success: function(response) {
                    if (response.success) {
                        $result.addClass('success').html(
                            'Export completed! <a href="' + response.data.url + '" download>Download file</a>'
                        );
                    } else {
                        $result.addClass('error').text('Export failed: ' + response.data);
                    }
                },
                error: function() {
                    $result.addClass('error').text('Export failed');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Export All Products');
                }
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
            var productId = $(this).data('id');
            window.open('/wp-admin/post.php?post=' + productId + '&action=edit', '_blank');
        });
    }
    
    // Auto-refresh (polling) every 60 seconds to reduce server load
    // For more real-time updates, consider implementing WebSockets or Server-Sent Events
    setInterval(function() {
        if (productsTable && $('#products-table').is(':visible')) {
            productsTable.ajax.reload(null, false); // false = don't reset paging
        }
    }, 60000); // Increased to 60 seconds
    
})(jQuery);
