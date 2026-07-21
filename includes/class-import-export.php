<?php
/**
 * Import/Export Class
 * 
 * Handles CSV, JSON, and Excel import/export
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Import_Export {

    /** Maximum spreadsheet size accepted by default (20 MiB). */
    private const DEFAULT_MAX_SPREADSHEET_BYTES = 20 * 1024 * 1024;

    /** Maximum populated worksheet dimensions accepted by default. */
    private const DEFAULT_MAX_SPREADSHEET_ROWS = 100000;
    private const DEFAULT_MAX_SPREADSHEET_COLUMNS = 128;

    /** XLSX archive limits applied before any XML member is loaded. */
    private const DEFAULT_MAX_XLSX_ARCHIVE_ENTRIES = 2048;
    private const DEFAULT_MAX_XLSX_ENTRY_BYTES = 32 * 1024 * 1024;
    private const DEFAULT_MAX_XLSX_TOTAL_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;
    private const DEFAULT_MAX_XLSX_COMPRESSION_RATIO = 200;

    /** @var array<string, string> */
    private const SPREADSHEET_READERS = array(
        'xlsx' => 'Xlsx',
        'xls' => 'Xls',
    );
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor
    }

    /**
     * Return the canonical product import/export columns.
     *
     * @return string[]
     */
    private static function get_product_headers() {
        return array(
            'ID',
            'Name',
            'SKU',
            'Type',
            'Regular Price',
            'Sale Price',
            'Stock Quantity',
            'Stock Status',
            'Weight',
            'Length',
            'Width',
            'Height',
            'Dynamic Pricing',
            'Currency Type',
            'Base Price',
            'Markup',
            'Markup Type',
        );
    }

    /**
     * Write a spreadsheet value without allowing text to be inferred as a formula.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
     * @param string $coordinate Cell coordinate.
     * @param mixed $value Cell value.
     * @param bool $numeric Whether a numeric value should remain numeric.
     * @return void
     */
    private static function set_spreadsheet_value($sheet, $coordinate, $value, $numeric = false) {
        if ($numeric && is_numeric($value)) {
            $number = str_contains((string) $value, '.') ? (float) $value : (int) $value;
            $sheet->setCellValueExplicit(
                $coordinate,
                $number,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
            );
            return;
        }

        $sheet->setCellValueExplicit(
            $coordinate,
            (string) $value,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
    }

    /**
     * Neutralize text that spreadsheet applications may otherwise interpret as a formula.
     *
     * @param mixed $value CSV field value.
     * @return string
     */
    private static function get_safe_csv_text($value) {
        $value = (string) $value;
        if (preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Validate XLSX central-directory metadata before the reader expands XML members.
     *
     * @param string $filepath Canonical local XLSX path.
     * @return true|WP_Error
     */
    private function validate_xlsx_archive($filepath) {
        $max_entries = (int) apply_filters(
            'digitalogic_max_xlsx_archive_entries',
            self::DEFAULT_MAX_XLSX_ARCHIVE_ENTRIES
        );
        $max_entry_bytes = (int) apply_filters(
            'digitalogic_max_xlsx_entry_bytes',
            self::DEFAULT_MAX_XLSX_ENTRY_BYTES
        );
        $max_total_bytes = (int) apply_filters(
            'digitalogic_max_xlsx_total_uncompressed_bytes',
            self::DEFAULT_MAX_XLSX_TOTAL_UNCOMPRESSED_BYTES
        );
        $max_ratio = (float) apply_filters(
            'digitalogic_max_xlsx_compression_ratio',
            self::DEFAULT_MAX_XLSX_COMPRESSION_RATIO
        );

        if ($max_entries < 1 || $max_entry_bytes < 1 || $max_total_bytes < 1 || $max_ratio < 1) {
            return new WP_Error(
                'xlsx_archive_limits_exceeded',
                __('Excel archive exceeds the import safety limits', 'digitalogic')
            );
        }

        $archive = new \ZipArchive();
        if (true !== $archive->open($filepath, \ZipArchive::RDONLY)) {
            return new WP_Error('read_error', __('Unable to read Excel file', 'digitalogic'));
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > $max_entries) {
                return new WP_Error(
                    'xlsx_archive_limits_exceeded',
                    __('Excel archive exceeds the import safety limits', 'digitalogic')
                );
            }

            $total_bytes = 0;
            $total_compressed_bytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (false === $entry) {
                    return new WP_Error('read_error', __('Unable to read Excel file', 'digitalogic'));
                }

                $name = str_replace('\\', '/', (string) $entry['name']);
                if (
                    '' === $name
                    || str_starts_with($name, '/')
                    || str_contains($name, "\0")
                    || preg_match('#(?:^|/)\.\.(?:/|$)#', $name)
                ) {
                    return new WP_Error('invalid_xlsx_archive', __('Invalid Excel archive', 'digitalogic'));
                }

                $entry_bytes = isset($entry['size']) ? (int) $entry['size'] : -1;
                $compressed_bytes = isset($entry['comp_size']) ? (int) $entry['comp_size'] : -1;
                if ($entry_bytes < 0 || $compressed_bytes < 0 || $entry_bytes > $max_entry_bytes) {
                    return new WP_Error(
                        'xlsx_archive_limits_exceeded',
                        __('Excel archive exceeds the import safety limits', 'digitalogic')
                    );
                }

                $total_bytes += $entry_bytes;
                $total_compressed_bytes += $compressed_bytes;
                if ($total_bytes > $max_total_bytes) {
                    return new WP_Error(
                        'xlsx_archive_limits_exceeded',
                        __('Excel archive exceeds the import safety limits', 'digitalogic')
                    );
                }

                if ($entry_bytes > 1024 * 1024 && $entry_bytes / max(1, $compressed_bytes) > $max_ratio) {
                    return new WP_Error(
                        'xlsx_archive_limits_exceeded',
                        __('Excel archive exceeds the import safety limits', 'digitalogic')
                    );
                }
            }

            if ($total_bytes > 1024 * 1024 && $total_bytes / max(1, $total_compressed_bytes) > $max_ratio) {
                return new WP_Error(
                    'xlsx_archive_limits_exceeded',
                    __('Excel archive exceeds the import safety limits', 'digitalogic')
                );
            }
        } finally {
            $archive->close();
        }

        return true;
    }

    /**
     * Load a local Excel workbook with an explicit reader.
     *
     * @param string $filepath Workbook path.
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet|WP_Error
     */
    private function load_spreadsheet($filepath) {
        if (!is_string($filepath) || '' === trim($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }

        // Spreadsheet imports are local files only; never resolve stream wrappers.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $filepath)) {
            return new WP_Error('invalid_source', __('Excel imports must use a local file', 'digitalogic'));
        }

        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }

        $realpath = realpath($filepath);
        if (false === $realpath || !is_file($realpath) || !is_readable($realpath)) {
            return new WP_Error('unreadable_file', __('Excel file is not readable', 'digitalogic'));
        }

        $extension = strtolower(pathinfo($realpath, PATHINFO_EXTENSION));
        if (!isset(self::SPREADSHEET_READERS[$extension])) {
            return new WP_Error('invalid_file_type', __('Unsupported Excel file type', 'digitalogic'));
        }

        $max_bytes = (int) apply_filters(
            'digitalogic_max_spreadsheet_import_bytes',
            self::DEFAULT_MAX_SPREADSHEET_BYTES
        );
        $filesize = filesize($realpath);
        if (false === $filesize || $max_bytes < 1 || $filesize > $max_bytes) {
            return new WP_Error('file_too_large', __('Excel file exceeds the import size limit', 'digitalogic'));
        }

        if ('xlsx' === $extension) {
            $archive_validation = $this->validate_xlsx_archive($realpath);
            if (is_wp_error($archive_validation)) {
                return $archive_validation;
            }
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(self::SPREADSHEET_READERS[$extension]);
            $reader->setReadDataOnly(true);

            return $reader->load($realpath);
        } catch (\Throwable $e) {
            return new WP_Error('read_error', __('Unable to read Excel file', 'digitalogic'));
        }
    }
    
    /**
     * Update dynamic pricing metadata for a product
     * 
     * @param int $product_id Product ID
     * @param array $data Data containing dynamic pricing information
     * @return void
     */
    private function update_dynamic_pricing($product_id, $data) {
        // Check if dynamic pricing is enabled (CSV/Excel format)
        $enabled = false;
        $pricing_data = array();
        
        if (!empty($data['Dynamic Pricing']) && $data['Dynamic Pricing'] === 'yes') {
            $enabled = true;
            $pricing_data = array(
                'Currency Type' => isset($data['Currency Type']) ? $data['Currency Type'] : '',
                'Base Price' => isset($data['Base Price']) ? $data['Base Price'] : '',
                'Markup' => isset($data['Markup']) ? $data['Markup'] : '',
                'Markup Type' => isset($data['Markup Type']) ? $data['Markup Type'] : '',
            );
        } 
        // Check for JSON format (nested structure)
        elseif (isset($data['dynamic_pricing'])) {
            $dp = $data['dynamic_pricing'];
            if (!empty($dp['enabled']) && $dp['enabled'] === 'yes') {
                $enabled = true;
                $pricing_data = array(
                    'Currency Type' => isset($dp['currency_type']) ? $dp['currency_type'] : '',
                    'Base Price' => isset($dp['base_price']) ? $dp['base_price'] : '',
                    'Markup' => isset($dp['markup']) ? $dp['markup'] : '',
                    'Markup Type' => isset($dp['markup_type']) ? $dp['markup_type'] : '',
                );
            }
        }
        
        if (!$enabled) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
        
        if (!empty($pricing_data['Currency Type'])) {
            $product->update_meta_data('_digitalogic_currency_type', $pricing_data['Currency Type']);
        }
        
        if (!empty($pricing_data['Base Price'])) {
            $product->update_meta_data('_digitalogic_base_price', $pricing_data['Base Price']);
        }
        
        if (!empty($pricing_data['Markup'])) {
            $product->update_meta_data('_digitalogic_markup', $pricing_data['Markup']);
        }
        
        if (!empty($pricing_data['Markup Type'])) {
            $product->update_meta_data('_digitalogic_markup_type', $pricing_data['Markup Type']);
        }
        
        $product->save();
    }
    
    /**
     * Export products to CSV
     * 
     * @param array $product_ids
     * @return string File path
     */
    public function export_csv($product_ids = array()) {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'products-export-' . date('Y-m-d-His') . '.csv';
        $filepath = $export_dir . '/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        // Headers
        $headers = self::get_product_headers();
        
        fputcsv($file, $headers, ',', '"', '');
        
        // Get products
        $manager = Digitalogic_Product_Manager::instance();
        
        if (empty($product_ids)) {
            $products = $manager->get_products(array('limit' => -1));
        } else {
            $products = array();
            foreach ($product_ids as $id) {
                $product = $manager->get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }
        
        // Write data
        foreach ($products as $product_data) {
            $product = wc_get_product($product_data['id']);
            if (!$product) {
                continue;
            }
            
            $row = array(
                $product_data['id'],
                self::get_safe_csv_text($product_data['name']),
                self::get_safe_csv_text($product_data['sku']),
                self::get_safe_csv_text($product_data['type']),
                $product_data['regular_price'],
                $product_data['sale_price'],
                $product_data['stock_quantity'],
                self::get_safe_csv_text($product_data['stock_status']),
                $product_data['weight'],
                $product_data['length'],
                $product_data['width'],
                $product_data['height'],
                self::get_safe_csv_text($product->get_meta('_digitalogic_dynamic_pricing', true)),
                self::get_safe_csv_text($product->get_meta('_digitalogic_currency_type', true)),
                $product->get_meta('_digitalogic_base_price', true),
                $product->get_meta('_digitalogic_markup', true),
                self::get_safe_csv_text($product->get_meta('_digitalogic_markup_type', true)),
            );
            
            fputcsv($file, $row, ',', '"', '');
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Export products to JSON
     * 
     * @param array $product_ids
     * @return string File path
     */
    public function export_json($product_ids = array()) {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'products-export-' . date('Y-m-d-His') . '.json';
        $filepath = $export_dir . '/' . $filename;
        
        // Get products
        $manager = Digitalogic_Product_Manager::instance();
        
        if (empty($product_ids)) {
            $products = $manager->get_products(array('limit' => -1));
        } else {
            $products = array();
            foreach ($product_ids as $id) {
                $product = $manager->get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }
        
        // Add dynamic pricing metadata
        foreach ($products as &$product_data) {
            $product = wc_get_product($product_data['id']);
            if ($product) {
                $product_data['dynamic_pricing'] = array(
                    'enabled' => $product->get_meta('_digitalogic_dynamic_pricing', true),
                    'currency_type' => $product->get_meta('_digitalogic_currency_type', true),
                    'base_price' => $product->get_meta('_digitalogic_base_price', true),
                    'markup' => $product->get_meta('_digitalogic_markup', true),
                    'markup_type' => $product->get_meta('_digitalogic_markup_type', true),
                );
            }
        }
        
        file_put_contents($filepath, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $filepath;
    }
    
    /**
     * Import products from CSV
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_csv($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }
        
        $file = fopen($filepath, 'r');
        $headers = fgetcsv($file, null, ',', '"', '');
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        
        while (($row = fgetcsv($file, null, ',', '"', '')) !== false) {
            $data = array_combine($headers, $row);
            
            if (empty($data['ID'])) {
                $results['failed']++;
                $results['errors'][] = 'Missing product ID';
                continue;
            }
            
            $product_id = intval($data['ID']);
            
            $update_data = array(
                'name' => $data['Name'],
                'sku' => $data['SKU'],
                'regular_price' => $data['Regular Price'],
                'sale_price' => $data['Sale Price'],
                'stock_quantity' => $data['Stock Quantity'],
                'stock_status' => $data['Stock Status'],
                'weight' => $data['Weight'],
                'length' => $data['Length'],
                'width' => $data['Width'],
                'height' => $data['Height'],
            );
            
            // Remove empty values
            $update_data = array_filter($update_data, function($value) {
                return $value !== '' && $value !== null;
            });
            
            $result = $manager->update_product($product_id, $update_data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['success']++;
                
                // Update dynamic pricing using helper method
                $this->update_dynamic_pricing($product_id, $data);
            }
        }
        
        fclose($file);
        
        return $results;
    }
    
    /**
     * Import products from JSON
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_json($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }
        
        $json = file_get_contents($filepath);
        $products = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON file', 'digitalogic'));
        }
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        
        foreach ($products as $product_data) {
            if (empty($product_data['id'])) {
                $results['failed']++;
                $results['errors'][] = 'Missing product ID';
                continue;
            }
            
            $product_id = intval($product_data['id']);
            
            $result = $manager->update_product($product_id, $product_data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['success']++;
                
                // Update dynamic pricing using helper method
                $this->update_dynamic_pricing($product_id, $product_data);
            }
        }
        
        return $results;
    }
    
    /**
     * Export products to Excel with custom template
     * 
     * @param array $product_ids
     * @return string File path
     */
    public function export_excel($product_ids = array(), $options = array()) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return new WP_Error('library_missing', __('PhpSpreadsheet library not found', 'digitalogic'));
        }

        $options  = is_array($options) ? $options : array();
        $locale = isset($options['locale']) ? sanitize_key((string) $options['locale']) : 'en';
        if (!in_array($locale, array('en', 'fa', 'bilingual'), true)) {
            $locale = 'en';
        }
        $template_only = !empty($options['template']);
        
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename  = ($template_only ? 'products-template-' : 'products-export-') . $locale . '-' . date('Y-m-d-His') . '.xlsx';
        $filepath = $export_dir . '/' . $filename;

        $manager = Digitalogic_Product_Manager::instance();
        $products = array();
        if (!$template_only) {
            if (empty($product_ids)) {
                $products = $manager->get_products(array('limit' => -1));
            } else {
                foreach ($product_ids as $id) {
                    $product = $manager->get_product($id);
                    if ($product) {
                        $products[] = $product;
                    }
                }
            }
        }

        $projection     = $this->get_workbook_projection($products);
        $projected_rows = array();
        $warehouses     = array();
        if (!is_wp_error($projection)) {
            foreach ((array) ($projection['columns'] ?? array()) as $column) {
                $key = isset($column['key']) ? (string) $column['key'] : '';
                if (str_starts_with($key, 'warehouse_stock:')) {
                    $warehouses[] = Digitalogic_Product_Column_Schema::warehouse_name_from_key($key);
                }
            }
            foreach ((array) ($projection['rows'] ?? array()) as $row) {
                $id = absint($row['woocommerce_id'] ?? 0);
                if ($id > 0) {
                    $projected_rows[$id] = $row;
                }
            }
        }
        foreach ($products as $product) {
            $warehouses = array_merge($warehouses, array_keys((array) ($product['patris_warehouse_stock'] ?? array())));
        }
        $warehouses    = Digitalogic_Product_Column_Schema::normalize_warehouses($warehouses);
        $columns = Digitalogic_Product_Column_Schema::workbook_columns($warehouses);
        
        // Create new Spreadsheet object
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set custom template properties
        $spreadsheet->getProperties()
            ->setCreator('Digitalogic WooCommerce Extension')
            ->setTitle($template_only ? 'Product Import Template' : 'Product Export')
            ->setSubject('Product Data')
            ->setDescription('Header-driven Digitalogic product workbook. Formula cells are never imported.')
            ->setCategory('Products');
        
        $sheet->setTitle('Products');
        $sheet->setRightToLeft('fa' === $locale);
        $sheet->getTabColor()->setRGB('2563EB');
        
        $num_columns  = count($columns);
        $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($num_columns);
        
        foreach ($columns as $index => $column_definition) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            self::set_spreadsheet_value(
                $sheet,
                $column . '1',
                Digitalogic_Product_Column_Schema::localized_header($column_definition, $locale)
            );
            $sheet->getColumnDimension($column)->setWidth($column_definition['width']);
        }
        
        $headerStyle = $sheet->getStyle('A1:' . $last_column . '1');
        $headerStyle->getFont()->setBold(true)->setSize(11);
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('173F5F');
        $headerStyle->getFont()->getColor()->setRGB('FFFFFF');
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $headerStyle->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $headerStyle->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight('bilingual' === $locale ? 58 : 36);
        
        $row = 2;
        foreach ($products as $product_data) {
            $product = wc_get_product($product_data['id']);
            if (!$product) {
                continue;
            }

            $projected = $projected_rows[(int) $product_data['id']] ?? array();
            foreach ($columns as $index => $column_definition) {
                $column  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                $value   = $this->workbook_export_value($product_data, $projected, $product, $column_definition['key']);
                $numeric = in_array($column_definition['type'], array('integer', 'number'), true);
                self::set_spreadsheet_value($sheet, $column . $row, $value, $numeric);
                if ('text' === $column_definition['type']) {
                    $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('@');
                } elseif ('integer' === $column_definition['type']) {
                    $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('0');
                } elseif ($numeric) {
                    $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('#,##0.########');
                }
            }
            $sheet->getRowDimension($row)->setRowHeight(24);
            
            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':' . $last_column . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('EDF4F8');
            }
            
            ++$row;
        }
        
        $lastRow = max(1, $row - 1);
        $sheet->getStyle('A1:' . $last_column . $lastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('C7D5DF');
        $sheet->getStyle('A1:' . $last_column . $lastRow)->getAlignment()->setVertical(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
        );
        
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $last_column . '1');
        $this->add_workbook_instructions($spreadsheet, $columns, $locale);
        
        try {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        
        return $filepath;
    }

    /**
     * Build the optional shipping/pricing projection without making basic export brittle.
     *
     * @param array $products Product manager rows.
     * @return array|WP_Error
     */
    private function get_workbook_projection($products) {
        if (!class_exists('Digitalogic_Google_Sheets_Catalog')) {
            return new WP_Error('projection_unavailable', __('Catalog projection is unavailable', 'digitalogic'));
        }

        try {
            return Digitalogic_Google_Sheets_Catalog::instance()->transform_products((array) $products);
        } catch (\Throwable $e) {
            return new WP_Error('projection_unavailable', __('Catalog projection is unavailable', 'digitalogic'));
        }
    }

    /**
     * Resolve one exported workbook cell from canonical and legacy sources.
     */
    private function workbook_export_value($product_data, $projected, $product, $key) {
        if (array_key_exists($key, $projected)) {
            return $projected[$key];
        }

        $product_fields = array(
            'woocommerce_id'       => 'id',
            'name'                 => 'name',
            'sku'                  => 'sku',
            'patris_code'          => 'patris_product_code',
            'product_type'         => 'type',
            'publication_status'   => 'status',
            'regular_price'        => 'regular_price',
            'sale_price'           => 'sale_price',
            'effective_price'      => 'effective_price',
            'stock_quantity'       => 'stock_quantity',
            'stock_status'         => 'stock_status',
            'woocommerce_weight'   => 'weight',
            'length'               => 'length',
            'width'                => 'width',
            'height'               => 'height',
            'foreign_currency'     => 'patris_foreign_currency',
            'foreign_price'        => 'patris_foreign_price',
            'weight_grams'         => 'patris_weight_grams',
            'patris_total_stock'   => 'patris_total_stock',
            'patris_minimum_stock' => 'patris_minimum_stock',
            'patris_location'      => 'patris_location',
            'patris_final_price'   => 'patris_final_price',
            'price_status'         => 'patris_price_status',
            'patris_sale_policy'   => 'patris_sale_policy',
        );
        if (isset($product_fields[$key])) {
            return $product_data[$product_fields[$key]] ?? '';
        }
        if (str_starts_with($key, 'warehouse_stock:')) {
            $warehouse = Digitalogic_Product_Column_Schema::warehouse_name_from_key($key);
            return $product_data['patris_warehouse_stock'][$warehouse] ?? '';
        }

        $meta_fields = array(
            'dynamic_pricing' => '_digitalogic_dynamic_pricing',
            'currency_type'   => '_digitalogic_currency_type',
            'base_price'      => '_digitalogic_base_price',
            'markup'          => '_digitalogic_markup',
            'markup_type'     => '_digitalogic_markup_type',
        );
        return isset($meta_fields[$key]) ? $product->get_meta($meta_fields[$key], true) : '';
    }

    /**
     * Add operator guidance plus a machine-readable schema worksheet.
     */
    private function add_workbook_instructions($spreadsheet, $columns, $locale) {
        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->getTabColor()->setRGB('10B981');
        $instructions->setRightToLeft('fa' === $locale);
        $instructions->fromArray(array(
            array('Digitalogic Product Workbook'),
            array('Edit the Products sheet. Columns may be reordered or removed; import matches recognized headers.'),
            array('WooCommerce ID and at least one writable column are required. SKU and Patris Code are text.'),
            array('Formula policy: formulas are not trusted or calculated. Any row containing a formula is rejected before writes begin.'),
            array('Read-only context columns (shipping, profit, and warehouse stock) are exported for review and ignored on import.'),
            array('Use the Schema sheet for stable machine keys, English/Persian labels, types, and access.'),
        ));
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('173F5F');
        $instructions->getStyle('A1:A6')->getAlignment()->setWrapText(true)->setVertical(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
        );
        $instructions->getColumnDimension('A')->setWidth(112);
        foreach (range(2, 6) as $row) {
            $instructions->getRowDimension($row)->setRowHeight(34);
        }

        $schema = $spreadsheet->createSheet();
        $schema->setTitle('Schema');
        $schema->getTabColor()->setRGB('F59E0B');
        $schema->fromArray(array(array('Machine Key', 'English Header', 'Persian Header', 'Type', 'Access', 'Group')));
        $row = 2;
        foreach ($columns as $column) {
            $schema->fromArray(array(array(
                $column['key'],
                $column['label_en'],
                $column['label_fa'],
                $column['type'],
                !empty($column['writable']) ? 'writable' : 'read-only',
                $column['group'],
            )), null, 'A' . $row, true);
            $row++;
        }
        $schema->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $schema->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('173F5F');
        foreach (array('A' => 32, 'B' => 28, 'C' => 28, 'D' => 12, 'E' => 14, 'F' => 14) as $column => $width) {
            $schema->getColumnDimension($column)->setWidth($width);
        }
        $schema->freezePane('A2');
        $schema->setAutoFilter('A1:F' . max(2, $row - 1));
        $spreadsheet->setActiveSheetIndex(0);
    }
    
    /**
     * Import products from Excel
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_excel($filepath) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return new WP_Error('library_missing', __('PhpSpreadsheet library not found', 'digitalogic'));
        }

        $spreadsheet = $this->load_spreadsheet($filepath);
        if (is_wp_error($spreadsheet)) {
            return $spreadsheet;
        }

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $max_rows = (int) apply_filters(
                'digitalogic_max_spreadsheet_import_rows',
                self::DEFAULT_MAX_SPREADSHEET_ROWS
            );
            $max_columns = (int) apply_filters(
                'digitalogic_max_spreadsheet_import_columns',
                self::DEFAULT_MAX_SPREADSHEET_COLUMNS
            );
            $highest_row = $sheet->getHighestDataRow();
            $highest_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $sheet->getHighestDataColumn()
            );
            if (
                $max_rows < 1
                || $max_columns < 1
                || $highest_row > $max_rows
                || $highest_column > $max_columns
            ) {
                return new WP_Error(
                    'spreadsheet_dimensions_exceeded',
                    __('Excel worksheet exceeds the import dimension limit', 'digitalogic')
                );
            }

            // Return raw values so untrusted formulas are never evaluated during import.
            $data = $sheet->toArray(null, false, false);
            
            $results = array(
                'success' => 0,
                'failed' => 0,
                'errors' => array()
            );
            
            if (empty($data)) {
                return new WP_Error('empty_file', __('Excel file is empty', 'digitalogic'));
            }
            
            $headers          = array_map(
                static function($header) {
                    return trim((string) $header);
                },
                array_shift($data)
            );
            $resolved_headers = Digitalogic_Product_Column_Schema::resolve_workbook_headers($headers);
            if (is_wp_error($resolved_headers)) {
                return $resolved_headers;
            }

            // Formula cells are rejected across the complete sheet before the
            // first product write, so a later unsafe row cannot cause a partial import.
            $formula_rows = array();
            foreach ($data as $row_index => $row) {
                foreach ((array) $row as $value) {
                    if (is_string($value) && str_starts_with(ltrim($value), '=')) {
                        $formula_rows[] = $row_index + 2;
                        break;
                    }
                }
            }
            if ($formula_rows) {
                foreach ($formula_rows as $formula_row) {
                    ++$results['failed'];
                    $results['errors'][] = sprintf('Row %d: Formula cells are not supported', $formula_row);
                }
                return $results;
            }

            $manager         = Digitalogic_Product_Manager::instance();
            $current_row_num = 2;
            foreach ($data as $row) {
                $row       = array_pad((array) $row, count($resolved_headers), null);
                $row       = array_slice($row, 0, count($resolved_headers));
                $has_value = (bool) array_filter(
                    $row,
                    static function($value) {
                        return null !== $value && '' !== trim((string) $value);
                    }
                );
                if (!$has_value) {
                    ++$current_row_num;
                    continue;
                }

                $row_data = array();
                foreach ($resolved_headers as $index => $key) {
                    $row_data[$key] = $row[$index] ?? null;
                }

                if (empty($row_data['woocommerce_id'])) {
                    ++$results['failed'];
                    $results['errors'][] = sprintf('Row %d: Missing product ID', $current_row_num);
                    ++$current_row_num;
                    continue;
                }

                $product_id  = intval($row_data['woocommerce_id']);
                $update_data = array();
                foreach ($row_data as $key => $value) {
                    $definition = Digitalogic_Product_Column_Schema::workbook_column_by_key($key);
                    if (!$definition || empty($definition['writable']) || str_starts_with($definition['manager_field'], '_')) {
                        continue;
                    }
                    $update_data[$definition['manager_field']] = $value;
                }
                $update_data = array_filter($update_data, function($value) {
                    return $value !== '' && $value !== null;
                });

                $result = $manager->update_product($product_id, $update_data);
                if (is_wp_error($result)) {
                    ++$results['failed'];
                    $results['errors'][] = sprintf('Row %d: %s', $current_row_num, $result->get_error_message());
                } else {
                    ++$results['success'];

                    $this->update_dynamic_pricing($product_id, array(
                        'Dynamic Pricing' => $row_data['dynamic_pricing'] ?? '',
                        'Currency Type'   => $row_data['currency_type'] ?? '',
                        'Base Price'      => $row_data['base_price'] ?? '',
                        'Markup'          => $row_data['markup'] ?? '',
                        'Markup Type'     => $row_data['markup_type'] ?? '',
                    ));
                }

                ++$current_row_num;
            }
            
            return $results;
            
        } catch (\Throwable $e) {
            return new WP_Error('import_error', __('Unable to import Excel file', 'digitalogic'));
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
