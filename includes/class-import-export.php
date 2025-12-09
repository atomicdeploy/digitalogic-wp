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
        $headers = array(
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
            'Markup Type'
        );
        
        fputcsv($file, $headers);
        
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
                $product_data['name'],
                $product_data['sku'],
                $product_data['type'],
                $product_data['regular_price'],
                $product_data['sale_price'],
                $product_data['stock_quantity'],
                $product_data['stock_status'],
                $product_data['weight'],
                $product_data['length'],
                $product_data['width'],
                $product_data['height'],
                $product->get_meta('_digitalogic_dynamic_pricing', true),
                $product->get_meta('_digitalogic_currency_type', true),
                $product->get_meta('_digitalogic_base_price', true),
                $product->get_meta('_digitalogic_markup', true),
                $product->get_meta('_digitalogic_markup_type', true),
            );
            
            fputcsv($file, $row);
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
        $headers = fgetcsv($file);
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        
        while (($row = fgetcsv($file)) !== false) {
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
                return $value !== '';
            });
            
            $result = $manager->update_product($product_id, $update_data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['success']++;
                
                // Update dynamic pricing if set using WooCommerce methods (HPOS compatible)
                if (!empty($data['Dynamic Pricing']) && $data['Dynamic Pricing'] === 'yes') {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
                        $product->update_meta_data('_digitalogic_currency_type', $data['Currency Type']);
                        $product->update_meta_data('_digitalogic_base_price', $data['Base Price']);
                        $product->update_meta_data('_digitalogic_markup', $data['Markup']);
                        $product->update_meta_data('_digitalogic_markup_type', $data['Markup Type']);
                        $product->save();
                    }
                }
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
                
                // Update dynamic pricing if present using WooCommerce methods (HPOS compatible)
                if (isset($product_data['dynamic_pricing'])) {
                    $dp = $product_data['dynamic_pricing'];
                    if (!empty($dp['enabled']) && $dp['enabled'] === 'yes') {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
                            $product->update_meta_data('_digitalogic_currency_type', $dp['currency_type']);
                            $product->update_meta_data('_digitalogic_base_price', $dp['base_price']);
                            $product->update_meta_data('_digitalogic_markup', $dp['markup']);
                            $product->update_meta_data('_digitalogic_markup_type', $dp['markup_type']);
                            $product->save();
                        }
                    }
                }
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
        $headers = array(
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
            'Markup Type'
        );
        
        // Set headers
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        
        // Style header row
        $headerStyle = $sheet->getStyle('A1:Q1');
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
            
            $sheet->setCellValue('A' . $row, $product_data['id']);
            $sheet->setCellValue('B' . $row, $product_data['name']);
            $sheet->setCellValue('C' . $row, $product_data['sku']);
            $sheet->setCellValue('D' . $row, $product_data['type']);
            $sheet->setCellValue('E' . $row, $product_data['regular_price']);
            $sheet->setCellValue('F' . $row, $product_data['sale_price']);
            $sheet->setCellValue('G' . $row, $product_data['stock_quantity']);
            $sheet->setCellValue('H' . $row, $product_data['stock_status']);
            $sheet->setCellValue('I' . $row, $product_data['weight']);
            $sheet->setCellValue('J' . $row, $product_data['length']);
            $sheet->setCellValue('K' . $row, $product_data['width']);
            $sheet->setCellValue('L' . $row, $product_data['height']);
            $sheet->setCellValue('M' . $row, $product->get_meta('_digitalogic_dynamic_pricing', true));
            $sheet->setCellValue('N' . $row, $product->get_meta('_digitalogic_currency_type', true));
            $sheet->setCellValue('O' . $row, $product->get_meta('_digitalogic_base_price', true));
            $sheet->setCellValue('P' . $row, $product->get_meta('_digitalogic_markup', true));
            $sheet->setCellValue('Q' . $row, $product->get_meta('_digitalogic_markup_type', true));
            
            // Apply alternating row colors
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':Q' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F2F2');
            }
            
            $row++;
        }
        
        // Add borders to all cells with data
        $lastRow = $row - 1;
        $sheet->getStyle('A1:Q' . $lastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        
        // Freeze header row
        $sheet->freezePane('A2');
        
        // Add filters to header row
        $sheet->setAutoFilter('A1:Q1');
        
        // Write file
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filepath);
        
        return $filepath;
    }
    
    /**
     * Import products from Excel
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_excel($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }
        
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return new WP_Error('library_missing', __('PhpSpreadsheet library not found', 'digitalogic'));
        }
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();
            
            $results = array(
                'success' => 0,
                'failed' => 0,
                'errors' => array()
            );
            
            if (empty($data)) {
                return new WP_Error('empty_file', __('Excel file is empty', 'digitalogic'));
            }
            
            // First row is headers
            $headers = array_shift($data);
            
            $manager = Digitalogic_Product_Manager::instance();
            
            foreach ($data as $row) {
                if (empty($row[0])) {
                    continue; // Skip empty rows
                }
                
                $row_data = array_combine($headers, $row);
                
                if (empty($row_data['ID'])) {
                    $results['failed']++;
                    $results['errors'][] = 'Missing product ID';
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
                    $results['errors'][] = $result->get_error_message();
                } else {
                    $results['success']++;
                    
                    // Update dynamic pricing if set using WooCommerce methods (HPOS compatible)
                    if (!empty($row_data['Dynamic Pricing']) && $row_data['Dynamic Pricing'] === 'yes') {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
                            $product->update_meta_data('_digitalogic_currency_type', $row_data['Currency Type']);
                            $product->update_meta_data('_digitalogic_base_price', $row_data['Base Price']);
                            $product->update_meta_data('_digitalogic_markup', $row_data['Markup']);
                            $product->update_meta_data('_digitalogic_markup_type', $row_data['Markup Type']);
                            $product->save();
                        }
                    }
                }
            }
            
            return $results;
            
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            return new WP_Error('read_error', sprintf(__('Error reading Excel file: %s', 'digitalogic'), $e->getMessage()));
        } catch (Exception $e) {
            return new WP_Error('import_error', sprintf(__('Error importing Excel file: %s', 'digitalogic'), $e->getMessage()));
        }
    }
}
