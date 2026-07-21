<?php
/**
 * Browser-only Digitalogic panel fixture.
 *
 * Run from the repository root with:
 * php -S 127.0.0.1:8765
 */

if ('cli-server' !== PHP_SAPI || !in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), array('127.0.0.1', '::1'), true)) {
    http_response_code(404);
    exit;
}

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
    header('Content-Type: application/json; charset=utf-8');
    $command = isset($_POST['command']) ? (string) $_POST['command'] : '';
    $payload = isset($_POST['data']) ? json_decode((string) $_POST['data'], true) : array();
    $products = panel_fixture_products();
    $response = array();

    switch ($command) {
        case 'digitalogic_get_products':
            $response = array(
                'products' => $products,
                'recordsTotal' => count($products),
                'recordsFiltered' => count($products),
                'page' => 1,
                'pages' => 1,
            );
            break;
        case 'digitalogic_get_product':
            $id = (int) ($payload['product_id'] ?? 0);
            $product = current(array_filter($products, static function($item) use ($id) {
                return (int) $item['id'] === $id;
            }));
            $response = array('product' => $product ?: $products[0]);
            break;
        case 'digitalogic_update_product':
            $id = (int) ($payload['product_id'] ?? 0);
            $product = current(array_filter($products, static function($item) use ($id) {
                return (int) $item['id'] === $id;
            })) ?: $products[0];
            $product = array_merge($product, is_array($payload['data'] ?? null) ? $payload['data'] : array());
            $response = array('product' => $product);
            break;
        case 'digitalogic_get_reports':
            $response = panel_fixture_reports($payload);
            break;
        case 'digitalogic_panel_summary':
            $response = array(
                'products' => count($products),
                'currency' => array('dollar_price' => '170000', 'yuan_price' => '25300'),
                'categories' => array(array('id' => 1, 'name' => 'ماژول‌ها')),
                'logs' => array(),
                'cli' => array(),
                'patris' => array(),
            );
            break;
        case 'digitalogic_panel_settings':
            $response = array('urls' => array(), 'bridge' => array(), 'websocket' => array());
            break;
        case 'digitalogic_panel_users':
            $response = array('users' => array());
            break;
        case 'digitalogic_panel_events':
            $response = array('events' => array());
            break;
        default:
            $response = array();
            break;
    }

    echo json_encode(array('success' => true, 'data' => $response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function panel_fixture_products() {
    return array(
        array(
            'id' => 101,
            'name' => 'ماژول کنترل دما دیجیتالاجیک',
            'part_number' => 'DG-TEMP-01',
            'sku' => 'DG-ABC-12345',
            'type' => 'simple',
            'regular_price' => '1250000',
            'sale_price' => '',
            'min_price' => '1250000',
            'max_price' => '1250000',
            'weight' => '0.15',
            'patris_foreign_currency' => 'CNY',
            'patris_foreign_price' => '42.5',
            'patris_weight_grams' => '150',
            'patris_final_price' => '1250000',
            'patris_location' => 'A-12',
            'patris_updated_at' => '2026-07-21 12:00:00',
            'stock_quantity' => '18',
            'stock_status' => 'instock',
            'status' => 'publish',
            'image' => '',
            'categories' => array(array('id' => 1, 'name' => 'ماژول‌ها')),
            'category_ids' => array(1),
            'total_sales' => 3,
            'revision_count' => 1,
            'canonical_url' => '#product-101',
            'permalink' => '#product-101',
            'edit_url' => '#edit-101',
        ),
        array(
            'id' => 102,
            'name' => 'Raspberry Pi Interface Board',
            'part_number' => 'RPI-IF-02',
            'sku' => 'RPI-IF-98765',
            'type' => 'simple',
            'regular_price' => '2450000',
            'sale_price' => '2290000',
            'min_price' => '2290000',
            'max_price' => '2450000',
            'weight' => '0.09',
            'patris_foreign_currency' => 'CNY',
            'patris_foreign_price' => '78',
            'patris_weight_grams' => '90',
            'patris_final_price' => '2450000',
            'patris_location' => 'B-04',
            'patris_updated_at' => '2026-07-21 12:05:00',
            'stock_quantity' => '7',
            'stock_status' => 'instock',
            'status' => 'publish',
            'image' => '',
            'categories' => array(array('id' => 1, 'name' => 'ماژول‌ها')),
            'category_ids' => array(1),
            'total_sales' => 6,
            'revision_count' => 2,
            'canonical_url' => '#product-102',
            'permalink' => '#product-102',
            'edit_url' => '#edit-102',
        ),
    );
}

function panel_fixture_reports($payload = array()) {
    $item = array(
        'product_code' => 'DG-ABC-12345',
        'woo_name' => 'ماژول کنترل دما دیجیتالاجیک',
        'name' => 'ماژول کنترل دما',
        'stock' => 18,
        'foreign_currency' => 'CNY',
        'foreign_price' => 42.5,
        'weight_grams' => 150,
        'final_price' => 1250000,
        'edit_url' => '#edit-101',
    );
    $definitions = array(
        array('zero_price', 'قیمت صفر یا نامعتبر', 'danger', 2),
        array('missing_foreign_price', 'قیمت ارزی ناموجود', 'warning', 4),
        array('bad_weight', 'وزن نامعتبر', 'warning', 1),
        array('missing_in_woocommerce', 'در پاتریس و ناموجود در ووکامرس', 'danger', 0),
        array('missing_in_patris', 'در ووکامرس و ناموجود در پاتریس', 'warning', 12),
        array('missing_image', 'تصویر ناموجود', 'warning', 30),
        array('image_duplicate', 'تصویر تکراری', 'warning', 0),
        array('image_corrupt', 'تصویر خراب', 'danger', 0),
        array('image_quality', 'کیفیت پایین تصویر', 'warning', 3),
        array('customer_missing_mobile', 'شماره همراه مشتری ناموجود', 'warning', 1),
    );
    $requested_category = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($payload['category'] ?? '')));
    $limit = max(0, min(250, (int) ($payload['item_limit'] ?? 25)));
    $offset = max(0, (int) ($payload['item_offset'] ?? 0));
    $categories = array_map(static function($definition) use ($item, $requested_category, $limit, $offset) {
        $count = $definition[3];
        $is_requested = '' === $requested_category || $requested_category === $definition[0];
        $category_offset = $is_requested ? min($offset, $count) : 0;
        $returned = $is_requested ? min(max(0, $count - $category_offset), $limit) : 0;
        $items = array();
        for ($index = 0; $index < $returned; $index++) {
            $row = $item;
            $row['product_code'] .= '-' . ($category_offset + $index + 1);
            $row['minimum_stock'] = 3;
            $row['location'] = 'A-' . ($category_offset + $index + 1);
            $row['updated_at'] = '2026-07-21 12:00:00';
            $items[] = $row;
        }
        return array(
            'key' => $definition[0],
            'title' => $definition[1],
            'severity' => $definition[2],
            'count' => $count,
            'items' => $items,
            'item_offset' => $category_offset,
            'returned_count' => $returned,
            'has_more' => $is_requested && ($category_offset + $returned) < $count,
            'truncated' => $returned < $count,
        );
    }, $definitions);

    $returned_count = array_sum(array_column($categories, 'returned_count'));

    return array(
        'counts' => array('woocommerce_products' => 967, 'patris_products' => 819, 'patris_customers' => 42),
        'categories' => $categories,
        'category' => $requested_category,
        'returned_count' => $returned_count,
        'truncated' => (bool) array_filter($categories, static function($category) { return $category['truncated']; }),
        'item_limit' => $limit,
        'item_offset' => $offset,
        'generated_at' => '2026-07-21T12:00:00+03:30',
    );
}

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');

function language_attributes() { echo 'lang="fa-IR"'; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function __($value) { return $value; }
function esc_html_e($value) { echo esc_html($value); }
function get_bloginfo($field) { return 'charset' === $field ? 'UTF-8' : 'Digitalogic Browser QA'; }
function bloginfo($field) { echo esc_attr(get_bloginfo($field)); }
function wp_print_styles() { echo '<link rel="stylesheet" href="/assets/css/panel.css">'; }
function wp_print_scripts() {
    echo '<script src="/assets/vendor/vue/vue.global.prod.js"></script>';
    if (empty($_GET['omit_product_query'])) {
        echo '<script src="/assets/js/product-query.js"></script>';
    }
    echo '<script>window.digitalogicPanel=' . json_encode(Digitalogic_Panel::instance()->client_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    echo '<script src="/assets/js/panel-app.js"></script>';
}

class Digitalogic_Panel {
    private static $instance;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function client_config() {
        $script = '/tests/fixtures/panel-browser.php';
        return array(
            'ajax_url' => $script,
            'nonce' => 'browser-fixture',
            'panel_url' => $script . '/',
            'logout_url' => '#logout',
            'legacy_panel_url' => '#legacy',
            'initial_path' => '/',
            'locale' => 'fa_IR',
            'direction' => 'rtl',
            'theme_mode' => 'light',
            'admin_color' => '#4f46e5',
            'theme_storage_key' => 'digitalogic-admin-theme-fixture',
            'event_cursor' => 0,
            'foreign_currency_options' => array(array('value' => 'CNY', 'label' => 'CNY - یوان چین', 'labels' => array('fa' => 'CNY - یوان چین', 'en' => 'CNY - Chinese yuan'))),
            'user' => array('id' => 1, 'login' => 'codex-qa', 'display_name' => 'آزمون دیجیتالاجیک', 'email' => 'qa@example.invalid', 'roles' => array('administrator')),
            'theme' => array('name' => 'Digitalogic', 'site_name' => 'Digitalogic Browser QA', 'logo_url' => '', 'logo_icon_url' => ''),
            'websocket' => array('enabled' => false),
            'i18n' => array(
                'en' => $this->translations('ltr'),
                'fa' => $this->translations('rtl'),
            ),
        );
    }

    private function translations($dir) {
        return array(
            'dir' => $dir,
            'dashboard' => 'داشبورد', 'products' => 'محصولات', 'users' => 'کاربران', 'reports' => 'گزارش‌ها',
            'cli' => 'خط فرمان', 'sync' => 'همگام‌سازی', 'settings' => 'تنظیمات', 'signedInAs' => 'ورود با',
            'connected' => 'متصل', 'fallback' => 'AJAX', 'language' => 'زبان', 'themeAppearance' => 'پوسته',
            'openWordPress' => 'وردپرس', 'logout' => 'خروج', 'totalProducts' => 'کل محصولات', 'recentActivity' => 'فعالیت اخیر',
            'search' => 'جستجوی محصول', 'editMode' => 'حالت ویرایش', 'viewMode' => 'حالت مشاهده', 'autosave' => 'ذخیره خودکار',
            'savePending' => 'ذخیره تغییرات', 'bulkActions' => 'عملیات گروهی', 'columns' => 'ستون‌ها', 'pinEditor' => 'سنجاق ویرایشگر',
            'compactTableMode' => 'جدول فشرده', 'freezeFirstColumn' => 'تثبیت ستون اول', 'resetColumns' => 'بازنشانی ستون‌ها',
            'productTitle' => 'نام محصول', 'partNumber' => 'پارت نامبر', 'sku' => 'کد محصول', 'productType' => 'نوع محصول',
            'regularPrice' => 'قیمت عادی', 'salePrice' => 'قیمت فروش', 'minPrice' => 'حداقل قیمت', 'maxPrice' => 'حداکثر قیمت',
            'weight' => 'وزن', 'patrisCurrency' => 'ارز پاتریس', 'patrisForeignPrice' => 'قیمت ارزی پاتریس',
            'patrisWeight' => 'وزن پاتریس', 'patrisFinalPrice' => 'قیمت نهایی پاتریس', 'patrisLocation' => 'مکان پاتریس',
            'patrisUpdatedAt' => 'به‌روزرسانی پاتریس', 'stock' => 'موجودی', 'availability' => 'وضعیت موجودی', 'status' => 'وضعیت',
            'actions' => 'عملیات', 'selectAll' => 'انتخاب همه', 'selectRow' => 'انتخاب ردیف', 'view' => 'مشاهده', 'edit' => 'ویرایش',
            'editWooCommerce' => 'ویرایش ووکامرس', 'modalEdit' => 'ویرایش پنجره‌ای', 'copy' => 'کپی', 'filter' => 'فیلتر',
            'clear' => 'پاک کردن', 'all' => 'همه', 'min' => 'کمینه', 'max' => 'بیشینه', 'loading' => 'در حال بارگذاری',
            'noRows' => 'رکوردی پیدا نشد', 'error' => 'خطایی رخ داد', 'previous' => 'قبلی', 'next' => 'بعدی', 'pagination' => 'صفحه‌ها',
            'publish' => 'منتشرشده', 'draft' => 'پیش‌نویس', 'pending' => 'در انتظار', 'private' => 'خصوصی',
            'instock' => 'موجود', 'outofstock' => 'ناموجود', 'onbackorder' => 'پیش‌سفارش', 'simpleProduct' => 'ساده',
            'variableProduct' => 'متغیر', 'productVariation' => 'تنوع', 'groupedProduct' => 'گروهی', 'externalProduct' => 'خارجی',
            'withImage' => 'دارای تصویر', 'withoutImage' => 'بدون تصویر', 'publishSelected' => 'انتشار', 'draftSelected' => 'پیش‌نویس',
            'markInStock' => 'موجود', 'markOutOfStock' => 'ناموجود', 'exportSelected' => 'خروجی', 'unpinEditor' => 'برداشتن سنجاق',
            'openToolbox' => 'ابزارها', 'categories' => 'دسته‌ها', 'totalSales' => 'فروش کل', 'revisions' => 'بازبینی‌ها',
            'priceReports' => 'گزارش قیمت', 'priceReportsText' => 'بررسی قیمت‌های ناقص و نامعتبر.',
            'priceSync' => 'همگام‌سازی قیمت', 'priceSyncText' => 'مقایسه پاتریس و ووکامرس.',
            'imageAudit' => 'بررسی تصویر', 'imageAuditText' => 'کنترل تصویرهای محصول.',
            'customerReports' => 'گزارش مشتری', 'customerReportsText' => 'کنترل اطلاعات مشتریان.',
            'currencyShipping' => 'ارز و حمل', 'currencyShippingText' => 'کنترل ارز، وزن و حمل.',
            'excelExports' => 'خروجی اکسل', 'excelExportsText' => 'ابزارهای خروجی اکسل.',
            'refresh' => 'به‌روزرسانی', 'problemRows' => 'ردیف‌های مسئله‌دار', 'patrisProducts' => 'محصولات پاتریس',
            'foreignPrice' => 'قیمت ارزی', 'finalPrice' => 'قیمت نهایی', 'showingRows' => 'نمایش ردیف‌ها', 'modernStyle' => 'مدرن', 'light' => 'روشن', 'dark' => 'تیره',
            'details' => 'جزئیات', 'minimumStock' => 'حداقل موجودی', 'location' => 'مکان', 'updatedAt' => 'به‌روزرسانی', 'dimensions' => 'ابعاد',
            'contact' => 'تماس', 'email' => 'ایمیل', 'address' => 'نشانی', 'page' => 'صفحه', 'generatedAt' => 'زمان تولید', 'notSet' => 'تنظیم نشده',
            'reportMissingInWooCommerce' => $dir === 'rtl' ? 'در پاتریس/API موجود است اما در ووکامرس نیست' : 'In Patris/API but missing in WooCommerce',
            'reportMissingInPatris' => $dir === 'rtl' ? 'در ووکامرس موجود است اما در پاتریس/API نیست' : 'In WooCommerce but missing in Patris/API',
            'reportDuplicateSku' => $dir === 'rtl' ? 'کد کالا / SKU تکراری' : 'Duplicate product code / SKU',
            'reportZeroStock' => $dir === 'rtl' ? 'موجودی صفر یا منفی' : 'Zero or negative stock',
            'reportZeroPrice' => $dir === 'rtl' ? 'قیمت صفر یا نامعتبر' : 'Zero or invalid price',
            'reportMissingForeignPrice' => $dir === 'rtl' ? 'قیمت ارزی ناموجود' : 'Missing foreign price',
            'reportBadWeight' => $dir === 'rtl' ? 'وزن ناموجود، نامعتبر یا مبهم' : 'Missing, bad, or ambiguous weight',
            'reportMissingMinimumStock' => $dir === 'rtl' ? 'حداقل موجودی ناموجود' : 'Missing minimum stock',
            'reportStalePrice' => $dir === 'rtl' ? 'قیمت پاتریس/API قدیمی' : 'Stale Patris/API price',
            'reportMissingImage' => $dir === 'rtl' ? 'تصویر محصول ناموجود' : 'Missing product image',
            'reportMissingDescription' => $dir === 'rtl' ? 'توضیحات محصول ناموجود' : 'Missing product description',
            'reportMismatchedName' => $dir === 'rtl' ? 'نام ووکامرس و پاتریس/API متفاوت است' : 'WooCommerce and Patris/API names differ',
            'reportImageDuplicate' => $dir === 'rtl' ? 'تصویر تکراری' : 'Duplicate image',
            'reportImageCorrupt' => $dir === 'rtl' ? 'تصویر خراب' : 'Broken image',
            'reportImageQuality' => $dir === 'rtl' ? 'کیفیت پایین تصویر' : 'Low-quality image',
            'reportCustomerMissingMobile' => $dir === 'rtl' ? 'شماره همراه/تلفن مشتری ناموجود' : 'Customer mobile/phone missing',
        );
    }
}

$panel_path = '/products';
require dirname(__DIR__, 2) . '/includes/panel/views/app.php';
