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
    $definitions = array(
        'price_drift' => array('قیمت با منبع فعلی مغایرت دارد', 'danger'),
        'missing_foreign_price' => array('قیمت یوان موجود نیست', 'warning'),
        'missing_weight' => array('وزن موجود نیست', 'warning'),
        'zero_price' => array('قیمت محاسبه‌شده صفر یا منفی است', 'danger'),
        'missing_in_woocommerce' => array('در پاتریس موجود اما در ووکامرس ناموجود', 'danger'),
    );
    $all_rows = array();
    for ($index = 1; $index <= 60; $index++) {
        $issue = $index <= 30 ? 'price_drift' : ($index <= 42 ? 'missing_foreign_price' : ($index <= 50 ? 'missing_weight' : ($index <= 56 ? 'zero_price' : 'missing_in_woocommerce')));
        $source = array(
            'name' => 'ماژول پاتریس ' . $index,
            'total_stock' => (string) (20 - ($index % 7)),
            'foreign_currency' => 'CNY',
            'weight_grams' => '150',
            'final_price' => $issue === 'zero_price' ? '0' : (string) (1250000 + $index),
        );
        if ('missing_foreign_price' !== $issue) {
            $source['foreign_price'] = '42.5';
        }
        if ('missing_weight' === $issue) {
            unset($source['weight_grams']);
        }
        $row = array(
            'product_code' => sprintf('DG-QA-%05d', $index),
            'status' => 'missing_in_woocommerce' === $issue ? 'source_only' : 'matched',
            'source' => $source,
            'issues' => array($issue),
        );
        if ('source_only' !== $row['status']) {
            $row['woo_id'] = 1000 + $index;
            $row['woocommerce'] = array(
                'name' => 'ماژول ووکامرس ' . $index,
                'stock_quantity' => (string) (20 - ($index % 7)),
                'active_price' => (string) (1240000 + $index),
            );
            $row['edit_url'] = '#edit-' . (1000 + $index);
        }
        $all_rows[] = $row;
    }

    $view = 'price_list' === ($payload['view'] ?? '') ? 'price_list' : 'warnings';
    $requested_category = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($payload['category'] ?? '')));
    $filtered_rows = array_values(array_filter($all_rows, static function($row) use ($view, $requested_category) {
        if ('warnings' === $view && empty($row['issues'])) return false;
        return '' === $requested_category || in_array($requested_category, $row['issues'], true);
    }));
    $per_page = max(1, min(100, (int) ($payload['per_page'] ?? 50)));
    $pages = max(1, (int) ceil(count($filtered_rows) / $per_page));
    $page = max(1, min($pages, (int) ($payload['page'] ?? 1)));
    $rows = array_slice($filtered_rows, ($page - 1) * $per_page, $per_page);
    $categories = array();
    foreach ($definitions as $key => $definition) {
        $count = count(array_filter($all_rows, static function($row) use ($key) { return in_array($key, $row['issues'], true); }));
        $categories[] = array('key' => $key, 'title' => $definition[0], 'severity' => $definition[1], 'count' => $count, 'items' => array());
    }

    return array(
        'status' => 'current',
        'view' => $view,
        'counts' => array('woocommerce_products' => 967, 'patris_products' => 819, 'matched_products' => 56, 'positive_source_only_products' => 4, 'drift_products' => 30),
        'categories' => $categories,
        'rows' => $rows,
        'filters' => array('category' => $requested_category),
        'pagination' => array('page' => $page, 'per_page' => $per_page, 'total' => count($filtered_rows), 'pages' => $pages),
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
            'regularPrice' => 'قیمت عادی', 'salePrice' => 'قیمت فروش', 'effectivePrice' => 'قیمت فعال فروشگاه', 'patrisPriceStatus' => 'وضعیت قیمت پاتریس', 'patrisSalePolicy' => 'سیاست تخفیف', 'minPrice' => 'حداقل قیمت', 'maxPrice' => 'حداکثر قیمت',
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
            'warnings' => $dir === 'rtl' ? 'هشدارها' : 'Warnings', 'priceList' => $dir === 'rtl' ? 'فهرست قیمت' : 'Price list',
            'allWarnings' => $dir === 'rtl' ? 'همه هشدارها' : 'All warnings', 'reportState' => $dir === 'rtl' ? 'وضعیت' : 'State',
            'reportMatched' => $dir === 'rtl' ? 'تطبیق‌یافته' : 'Matched', 'reportSourceOnly' => $dir === 'rtl' ? 'فقط در منبع' : 'Source only',
            'reportWooOnly' => $dir === 'rtl' ? 'فقط در ووکامرس' : 'WooCommerce only', 'reportAmbiguous' => $dir === 'rtl' ? 'مبهم' : 'Ambiguous',
            'reportSourceUnavailable' => $dir === 'rtl' ? 'منبع فعلی در دسترس نیست.' : 'No current source is available.',
            'exactCodeMatches' => $dir === 'rtl' ? 'تطبیق دقیق کد' : 'Exact Code matches', 'driftProducts' => $dir === 'rtl' ? 'کالاهای مغایر' : 'Drift products',
            'positiveSourceOnly' => $dir === 'rtl' ? 'موجودی مثبت فقط در منبع' : 'Positive source-only',
            'current' => $dir === 'rtl' ? 'به‌روز' : 'Current', 'findings' => $dir === 'rtl' ? 'یافته‌ها' : 'Findings', 'missing' => $dir === 'rtl' ? 'ناموجود' : 'Missing',
            'reportMissingInWooCommerce' => $dir === 'rtl' ? 'در پاتریس موجود اما در ووکامرس ناموجود' : 'In Patris but missing in WooCommerce',
            'reportMissingInPatris' => $dir === 'rtl' ? 'در ووکامرس موجود اما در پاتریس ناموجود' : 'In WooCommerce but missing in Patris',
            'reportDuplicateSku' => $dir === 'rtl' ? 'کد کالا / SKU تکراری' : 'Duplicate product code / SKU',
            'reportZeroStock' => $dir === 'rtl' ? 'موجودی صفر یا منفی' : 'Zero or negative stock',
            'reportZeroPrice' => $dir === 'rtl' ? 'قیمت محاسبه‌شده صفر یا منفی است' : 'Zero or negative calculated price',
            'reportMissingForeignPrice' => $dir === 'rtl' ? 'قیمت یوان موجود نیست' : 'Missing CNY price',
            'reportMissingWeight' => $dir === 'rtl' ? 'وزن موجود نیست' : 'Missing weight',
            'reportPriceDrift' => $dir === 'rtl' ? 'قیمت با منبع فعلی مغایرت دارد' : 'Price differs from the current source',
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
