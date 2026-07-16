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
    public function export_excel($product_ids = array()) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return new WP_Error('library_missing', __('PhpSpreadsheet library not found', 'digitalogic'));
        }
        
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'products-export-' . date('Y-m-d-His') . '.xlsx';
        $filepath = $export_dir . '/' . $filename;
        
        // Create new Spreadsheet object
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set custom template properties
        $spreadsheet->getProperties()
            ->setCreator('Digitalogic WooCommerce Extension')
            ->setTitle('Product Export')
            ->setSubject('Product Data')
            ->setDescription('Product export from Digitalogic WooCommerce Extension')
            ->setCategory('Products');
        
        // Custom template styling
        $sheet->setTitle('Products');
        
        // Headers with custom styling
        $headers = self::get_product_headers();
        
        $num_columns = count($headers);
        $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($num_columns);
        
        // Set headers
        foreach ($headers as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $header);
        }
        
        // Style header row
        $headerStyle = $sheet->getStyle('A1:' . $last_column . '1');
        $headerStyle->getFont()->setBold(true)->setSize(12);
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');
        $headerStyle->getFont()->getColor()->setRGB('FFFFFF');
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(12);
        $sheet->getColumnDimension('K')->setWidth(12);
        $sheet->getColumnDimension('L')->setWidth(12);
        $sheet->getColumnDimension('M')->setWidth(18);
        $sheet->getColumnDimension('N')->setWidth(15);
        $sheet->getColumnDimension('O')->setWidth(15);
        $sheet->getColumnDimension('P')->setWidth(12);
        $sheet->getColumnDimension('Q')->setWidth(15);
        
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
        $row = 2;
        foreach ($products as $product_data) {
            $product = wc_get_product($product_data['id']);
            if (!$product) {
                continue;
            }
            
            self::set_spreadsheet_value($sheet, 'A' . $row, $product_data['id'], true);
            self::set_spreadsheet_value($sheet, 'B' . $row, $product_data['name']);
            self::set_spreadsheet_value($sheet, 'C' . $row, $product_data['sku']);
            self::set_spreadsheet_value($sheet, 'D' . $row, $product_data['type']);
            self::set_spreadsheet_value($sheet, 'E' . $row, $product_data['regular_price'], true);
            self::set_spreadsheet_value($sheet, 'F' . $row, $product_data['sale_price'], true);
            self::set_spreadsheet_value($sheet, 'G' . $row, $product_data['stock_quantity'], true);
            self::set_spreadsheet_value($sheet, 'H' . $row, $product_data['stock_status']);
            self::set_spreadsheet_value($sheet, 'I' . $row, $product_data['weight'], true);
            self::set_spreadsheet_value($sheet, 'J' . $row, $product_data['length'], true);
            self::set_spreadsheet_value($sheet, 'K' . $row, $product_data['width'], true);
            self::set_spreadsheet_value($sheet, 'L' . $row, $product_data['height'], true);
            self::set_spreadsheet_value($sheet, 'M' . $row, $product->get_meta('_digitalogic_dynamic_pricing', true));
            self::set_spreadsheet_value($sheet, 'N' . $row, $product->get_meta('_digitalogic_currency_type', true));
            self::set_spreadsheet_value($sheet, 'O' . $row, $product->get_meta('_digitalogic_base_price', true), true);
            self::set_spreadsheet_value($sheet, 'P' . $row, $product->get_meta('_digitalogic_markup', true), true);
            self::set_spreadsheet_value($sheet, 'Q' . $row, $product->get_meta('_digitalogic_markup_type', true));
            
            // Apply alternating row colors
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':' . $last_column . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
            
            $row++;
        }
        
        // Add borders to all cells with data
        $lastRow = $row - 1;
        $sheet->getStyle('A1:' . $last_column . $lastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        
        // Freeze header row
        $sheet->freezePane('A2');
        
        // Add filters to header row
        $sheet->setAutoFilter('A1:' . $last_column . '1');
        
        // Write file and release workbook resources even when the writer fails.
        try {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        
        return $filepath;
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
            
            // First row is headers
            $headers = array_map(
                static function($header) {
                    return trim((string) $header);
                },
                array_shift($data)
            );
            $missing_headers = array_diff(self::get_product_headers(), $headers);
            if (!empty($missing_headers) || count($headers) !== count(array_unique($headers))) {
                return new WP_Error('invalid_headers', __('Excel file does not match the product import template', 'digitalogic'));
            }
            
            $manager = Digitalogic_Product_Manager::instance();
            
            $current_row_num = 2; // Start at 2 because row 1 is headers
            foreach ($data as $row) {
                if (empty($row[0])) {
                    $current_row_num++;
                    continue; // Skip empty rows
                }
                
                // Validate row has same number of columns as headers
                if (count($headers) !== count($row)) {
                    $results['failed']++;
                    $results['errors'][] = sprintf('Row %d has %d columns but expected %d', $current_row_num, count($row), count($headers));
                    $current_row_num++;
                    continue;
                }

                $has_formula = false;
                foreach ($row as $value) {
                    if (is_string($value) && str_starts_with(ltrim($value), '=')) {
                        $has_formula = true;
                        break;
                    }
                }
                if ($has_formula) {
                    $results['failed']++;
                    $results['errors'][] = sprintf('Row %d: Formula cells are not supported', $current_row_num);
                    $current_row_num++;
                    continue;
                }
                
                $row_data = array_combine($headers, $row);
                
                // Additional safety check (should never happen due to earlier validation)
                if ($row_data === false) {
                    $results['failed']++;
                    $results['errors'][] = sprintf('Row %d: Failed to parse data', $current_row_num);
                    $current_row_num++;
                    continue;
                }
                
                if (empty($row_data['ID'])) {
                    $results['failed']++;
                    $results['errors'][] = sprintf('Row %d: Missing product ID', $current_row_num);
                    $current_row_num++;
                    continue;
                }
                
                $product_id = intval($row_data['ID']);
                
                $update_data = array(
                    'name' => $row_data['Name'],
                    'sku' => $row_data['SKU'],
                    'regular_price' => $row_data['Regular Price'],
                    'sale_price' => $row_data['Sale Price'],
                    'stock_quantity' => $row_data['Stock Quantity'],
                    'stock_status' => $row_data['Stock Status'],
                    'weight' => $row_data['Weight'],
                    'length' => $row_data['Length'],
                    'width' => $row_data['Width'],
                    'height' => $row_data['Height'],
                );
                
                // Remove empty values
                $update_data = array_filter($update_data, function($value) {
                    return $value !== '' && $value !== null;
                });
                
                $result = $manager->update_product($product_id, $update_data);
                
                if (is_wp_error($result)) {
                    $results['failed']++;
                    $results['errors'][] = sprintf('Row %d: %s', $current_row_num, $result->get_error_message());
                } else {
                    $results['success']++;
                    
                    // Update dynamic pricing using helper method
                    $this->update_dynamic_pricing($product_id, $row_data);
                }
                
                $current_row_num++;
            }
            
            return $results;
            
        } catch (\Throwable $e) {
            return new WP_Error('import_error', __('Unable to import Excel file', 'digitalogic'));
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
