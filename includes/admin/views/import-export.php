<div class="wrap digitalogic-import-export">
    <h1><?php _e('Import/Export Products', 'digitalogic'); ?></h1>
    
    <div class="digitalogic-section">
        <h2><?php _e('Export Products', 'digitalogic'); ?></h2>
        
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
                <tr>
                    <th scope="row"><label for="export_locale"><?php esc_html_e('Excel headers', 'digitalogic'); ?></label></th>
                    <td>
                        <select name="export_locale" id="export_locale">
                            <option value="en"><?php esc_html_e('English', 'digitalogic'); ?></option>
                            <option value="fa"><?php esc_html_e('Persian', 'digitalogic'); ?></option>
                            <option value="bilingual"><?php esc_html_e('English / Persian', 'digitalogic'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Excel imports recognize English, Persian, bilingual, legacy, and machine-key headers in any order.', 'digitalogic'); ?></p>
                    </td>
                </tr>
            </table>
            
            <button type="button" id="export-btn" class="button button-primary"><?php _e('Export All Products', 'digitalogic'); ?></button>
            <button type="button" id="excel-template-btn" class="button"><?php esc_html_e('Download Empty Excel Template', 'digitalogic'); ?></button>
        </form>
        
        <div id="export-result" style="margin-top: 20px;"></div>
    </div>
    
    <hr>
    
    <div class="digitalogic-section">
        <h2><?php _e('Import Products', 'digitalogic'); ?></h2>
        
        <p><?php _e('Import products from CSV, JSON, or Excel file', 'digitalogic'); ?></p>
        <p class="description"><?php esc_html_e('Excel formulas are not calculated or trusted. If any formula is present, the import stops before product writes begin.', 'digitalogic'); ?></p>
        
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
