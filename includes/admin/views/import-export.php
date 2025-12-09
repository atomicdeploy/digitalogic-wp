<div class="wrap digitalogic-import-export">
    <h1><?php _e('Import/Export Products', 'digitalogic'); ?></h1>
    
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <div id="post-body-content">
                <!-- Export Products Postbox -->
                <div id="export-products" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Export Products', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Export Products', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('Export your products to CSV, JSON, or Excel format', 'digitalogic'); ?></p>
                        
                        <form id="export-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="export_format"><?php _e('Format', 'digitalogic'); ?></label>
                                    </th>
                                    <td>
                                        <select name="export_format" id="export_format">
                                            <option value="csv"><?php _e('CSV', 'digitalogic'); ?></option>
                                            <option value="json"><?php _e('JSON', 'digitalogic'); ?></option>
                                            <option value="excel"><?php _e('Excel (XLSX)', 'digitalogic'); ?></option>
                                        </select>
                                        <p class="description"><?php _e('Excel format uses a custom branded template with styling', 'digitalogic'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <button type="button" id="export-btn" class="button button-primary"><?php _e('Export All Products', 'digitalogic'); ?></button>
                        </form>
                        
                        <div id="export-result" style="margin-top: 20px;"></div>
                    </div>
                </div>
                
                <!-- Import Products Postbox -->
                <div id="import-products" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Import Products', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Import Products', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('Import products from CSV, JSON, or Excel file', 'digitalogic'); ?></p>
                        
                        <form id="import-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="import_file"><?php _e('File', 'digitalogic'); ?></label>
                                    </th>
                                    <td>
                                        <input type="file" name="import_file" id="import_file" accept=".csv,.json,.xlsx,.xls">
                                        <p class="description"><?php _e('Select a CSV, JSON, or Excel file to import', 'digitalogic'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <button type="button" id="import-btn" class="button button-primary"><?php _e('Import Products', 'digitalogic'); ?></button>
                        </form>
                        
                        <div id="import-result" style="margin-top: 20px;"></div>
                    </div>
                </div>
            </div>
            
            <div id="postbox-container-1" class="postbox-container">
                <!-- Help/Information Postbox -->
                <div id="import-export-info" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Information', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Information', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <h4><?php _e('Supported Formats', 'digitalogic'); ?></h4>
                        <ul>
                            <li><strong>CSV:</strong> <?php _e('Comma-separated values file', 'digitalogic'); ?></li>
                            <li><strong>JSON:</strong> <?php _e('JavaScript Object Notation file', 'digitalogic'); ?></li>
                            <li><strong>Excel:</strong> <?php _e('Microsoft Excel XLSX file with custom styling', 'digitalogic'); ?></li>
                        </ul>
                        <h4><?php _e('Notes', 'digitalogic'); ?></h4>
                        <ul>
                            <li><?php _e('Export includes all product data', 'digitalogic'); ?></li>
                            <li><?php _e('Import will update existing products based on ID or SKU', 'digitalogic'); ?></li>
                            <li><?php _e('Excel files use a custom branded template', 'digitalogic'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes with unique page identifier
    postboxes.add_postbox_toggles('digitalogic_import_export');
});
</script>
