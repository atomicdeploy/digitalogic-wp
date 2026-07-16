<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array(
            'basedir' => $GLOBALS['digitalogic_test_upload_dir'],
            'baseurl' => 'https://digitalogic.test/uploads',
        );
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        return is_dir($target) || mkdir($target, 0777, true);
    }
}

require_once dirname(__DIR__) . '/includes/class-import-export.php';

final class Digitalogic_Test_Import_Export_Manager extends Digitalogic_Product_Manager {
    public $products = array();
    public $updates = array();

    public function get_products($args = array()) {
        return array_values($this->products);
    }

    public function get_product($product_id) {
        return isset($this->products[$product_id]) ? $this->products[$product_id] : null;
    }

    public function update_product($product_id, $data) {
        $this->updates[(int) $product_id] = $data;
        return true;
    }
}

final class Digitalogic_Test_Import_Export_Product {
    private $meta;

    public function __construct($meta) {
        $this->meta = $meta;
    }

    public function get_meta($key, $single = true) {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }

    public function save() {
        return true;
    }
}

final class ImportExportCompatibilityTest extends TestCase {
    private $exporter;
    private $manager;
    private $temp_dir;

    protected function setUp(): void {
        parent::setUp();

        $this->temp_dir = sys_get_temp_dir() . '/digitalogic-import-export-' . bin2hex(random_bytes(8));
        mkdir($this->temp_dir, 0777, true);
        $GLOBALS['digitalogic_test_upload_dir'] = $this->temp_dir;
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_posts'] = array(
            42 => array('post_type' => 'product', 'meta' => array()),
        );
        $GLOBALS['digitalogic_test_wc_products'] = array(
            42 => new Digitalogic_Test_Import_Export_Product(array(
                '_digitalogic_dynamic_pricing' => 'yes',
                '_digitalogic_currency_type' => 'CNY',
                '_digitalogic_base_price' => '125.50',
                '_digitalogic_markup' => '12',
                '_digitalogic_markup_type' => 'percentage',
            )),
        );

        $manager_class = new ReflectionClass(Digitalogic_Test_Import_Export_Manager::class);
        $this->manager = $manager_class->newInstanceWithoutConstructor();
        $this->manager->products[42] = array(
            'id' => 42,
            'name' => 'Round-trip product',
            'sku' => 'DG-42',
            'type' => 'simple',
            'regular_price' => '150000',
            'sale_price' => '145000',
            'stock_quantity' => 9,
            'stock_status' => 'instock',
            'weight' => '0.24',
            'length' => '10',
            'width' => '5',
            'height' => '2',
        );

        $manager_instance = new ReflectionProperty(Digitalogic_Product_Manager::class, 'instance');
        $manager_instance->setValue(null, $this->manager);

        $exporter_instance = new ReflectionProperty(Digitalogic_Import_Export::class, 'instance');
        $exporter_instance->setValue(null, null);
        $this->exporter = Digitalogic_Import_Export::instance();
    }

    protected function tearDown(): void {
        $manager_instance = new ReflectionProperty(Digitalogic_Product_Manager::class, 'instance');
        $manager_instance->setValue(null, null);
        $exporter_instance = new ReflectionProperty(Digitalogic_Import_Export::class, 'instance');
        $exporter_instance->setValue(null, null);
        $this->remove_tree($this->temp_dir);
        unset($GLOBALS['digitalogic_test_upload_dir']);
        parent::tearDown();
    }

    public function test_xlsx_template_exports_and_imports_round_trip(): void {
        $filepath = $this->exporter->export_excel(array(42));

        $this->assertFileExists($filepath);
        $workbook = IOFactory::load($filepath);
        $sheet = $workbook->getActiveSheet();
        $this->assertSame('Products', $sheet->getTitle());
        $this->assertSame('Product Export', $workbook->getProperties()->getTitle());
        $this->assertSame('Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Round-trip product', $sheet->getCell('B2')->getValue());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:Q1', $sheet->getAutoFilter()->getRange());
        $workbook->disconnectWorksheets();

        $result = $this->exporter->import_excel($filepath);

        $this->assertSame(1, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('Round-trip product', $this->manager->updates[42]['name']);
        $this->assertSame('DG-42', $this->manager->updates[42]['sku']);
        $this->assertEquals('150000', $this->manager->updates[42]['regular_price']);
    }

    public function test_xlsx_import_rejects_non_local_and_malformed_sources(): void {
        $remote = $this->exporter->import_excel('https://digitalogic.test/products.xlsx');
        $this->assertSame('invalid_source', $remote->get_error_code());

        $wrong_extension = $this->temp_dir . '/products.txt';
        file_put_contents($wrong_extension, 'not a workbook');
        $wrong_type = $this->exporter->import_excel($wrong_extension);
        $this->assertSame('invalid_file_type', $wrong_type->get_error_code());

        $malformed = $this->temp_dir . '/malformed.xlsx';
        file_put_contents($malformed, 'not a workbook');
        $read_error = $this->exporter->import_excel($malformed);
        $this->assertSame('read_error', $read_error->get_error_code());

        $invalid_template = $this->temp_dir . '/invalid-template.xlsx';
        $workbook = new Spreadsheet();
        $workbook->getActiveSheet()->fromArray(array(array('Unexpected', 'Headers')));
        (new Xlsx($workbook))->save($invalid_template);
        $workbook->disconnectWorksheets();

        $invalid_headers = $this->exporter->import_excel($invalid_template);
        $this->assertSame('invalid_headers', $invalid_headers->get_error_code());
        $this->assertSame(array(), $this->manager->updates);
    }

    public function test_spreadsheet_import_rejects_formulas_and_excessive_dimensions_without_writes(): void {
        $filepath = $this->exporter->export_excel(array(42));
        $workbook = IOFactory::load($filepath);
        $workbook->getActiveSheet()->setCellValue('B2', '=1+1');
        (new Xlsx($workbook))->save($filepath);
        $workbook->disconnectWorksheets();

        $formula_result = $this->exporter->import_excel($filepath);
        $this->assertSame(0, $formula_result['success']);
        $this->assertSame(1, $formula_result['failed']);
        $this->assertSame(array(), $this->manager->updates);

        $GLOBALS['digitalogic_test_filters']['digitalogic_max_spreadsheet_import_rows'] = static function() {
            return 1;
        };
        $dimension_result = $this->exporter->import_excel($filepath);
        $this->assertSame('spreadsheet_dimensions_exceeded', $dimension_result->get_error_code());
        $this->assertSame(array(), $this->manager->updates);

        unset($GLOBALS['digitalogic_test_filters']['digitalogic_max_spreadsheet_import_rows']);
        $GLOBALS['digitalogic_test_filters']['digitalogic_max_xlsx_total_uncompressed_bytes'] = static function() {
            return 1;
        };
        $archive_result = $this->exporter->import_excel($filepath);
        $this->assertSame('xlsx_archive_limits_exceeded', $archive_result->get_error_code());
        $this->assertSame(array(), $this->manager->updates);
    }

    public function test_legacy_xls_template_import_remains_supported(): void {
        $xlsx = $this->exporter->export_excel(array(42));
        $workbook = IOFactory::load($xlsx);
        $xls = $this->temp_dir . '/products.xls';
        (new Xls($workbook))->save($xls);
        $workbook->disconnectWorksheets();

        $result = $this->exporter->import_excel($xls);

        $this->assertSame(1, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('DG-42', $this->manager->updates[42]['sku']);
    }

    public function test_formula_like_export_text_remains_literal_in_xlsx_and_is_neutralized_in_csv(): void {
        $this->manager->products[42]['name'] = '=literal product name';

        $xlsx = $this->exporter->export_excel(array(42));
        $workbook = IOFactory::load($xlsx);
        $name_cell = $workbook->getActiveSheet()->getCell('B2');
        $this->assertSame(DataType::TYPE_STRING, $name_cell->getDataType());
        $this->assertSame('=literal product name', $name_cell->getValue());
        $workbook->disconnectWorksheets();

        $csv = $this->exporter->export_csv(array(42));
        $file = fopen($csv, 'r');
        fgetcsv($file, null, ',', '"', '');
        $row = fgetcsv($file, null, ',', '"', '');
        fclose($file);
        $this->assertSame("'=literal product name", $row[1]);
    }

    public function test_csv_and_json_fallbacks_remain_compatible(): void {
        $csv = $this->exporter->export_csv(array(42));
        $json = $this->exporter->export_json(array(42));

        $this->assertFileExists($csv);
        $this->assertFileExists($json);
        $this->assertStringContainsString('Round-trip product', file_get_contents($csv));
        $decoded = json_decode(file_get_contents($json), true);
        $this->assertSame('DG-42', $decoded[0]['sku']);
        $this->assertSame('CNY', $decoded[0]['dynamic_pricing']['currency_type']);

        $csv_result = $this->exporter->import_csv($csv);
        $this->assertSame(1, $csv_result['success']);
        $this->assertSame(0, $csv_result['failed']);

        $this->manager->updates = array();
        $json_result = $this->exporter->import_json($json);
        $this->assertSame(1, $json_result['success']);
        $this->assertSame(0, $json_result['failed']);
        $this->assertSame('Round-trip product', $this->manager->updates[42]['name']);
    }

    private function remove_tree($path) {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
