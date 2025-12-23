#!/usr/bin/env php
<?php
/**
 * Manual test script for currency synchronization
 * 
 * This script verifies that currency settings are properly synchronized
 * between WordPress options, ACF fields, and WooCommerce integration.
 * 
 * Usage: php tests/manual-currency-sync-test.php
 * Or via WP-CLI: wp eval-file tests/manual-currency-sync-test.php
 */

// This script is meant to be run via WP-CLI or in a WordPress environment
if (!defined('ABSPATH')) {
    // If not in WordPress, try to load WordPress
    $wp_load_candidates = [
        __DIR__ . '/../../../../wp-load.php', // Standard WordPress installation
        __DIR__ . '/../../../wp-load.php',
        __DIR__ . '/../../wp-load.php',
        __DIR__ . '/../wp-load.php',
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_candidates as $wp_load) {
        if (file_exists($wp_load)) {
            require_once $wp_load;
            $wp_loaded = true;
            break;
        }
    }
    
    if (!$wp_loaded) {
        echo "Error: Could not load WordPress. Please run via WP-CLI:\n";
        echo "  wp eval-file tests/manual-currency-sync-test.php\n";
        exit(1);
    }
}

echo "=== Digitalogic Currency Synchronization Test ===\n\n";

// Check if plugin is active
if (!class_exists('Digitalogic_Options')) {
    echo "Error: Digitalogic plugin is not active\n";
    exit(1);
}

$options = Digitalogic_Options::instance();

// Test 1: Check current values
echo "Test 1: Check current values\n";
echo "-----------------------------\n";
$dollar_price = $options->get_dollar_price();
$yuan_price = $options->get_yuan_price();
$wc_currency = $options->get_woocommerce_currency();
$status = $options->get_currency_status();

echo "Current Dollar Price: " . $dollar_price . "\n";
echo "Current Yuan Price: " . $yuan_price . "\n";
echo "WooCommerce Currency: " . $wc_currency . "\n";
echo "Currency Symbol: " . $status['woocommerce_symbol'] . "\n";
echo "Integration Status:\n";
echo "  - Is USD: " . ($status['is_usd'] ? 'Yes' : 'No') . "\n";
echo "  - Is CNY: " . ($status['is_cny'] ? 'Yes' : 'No') . "\n";
echo "  - Needs Exchange Rates: " . ($status['needs_exchange_rates'] ? 'Yes' : 'No') . "\n";
echo "\n";

// Test 2: Update via plugin method
echo "Test 2: Update via plugin method\n";
echo "---------------------------------\n";
$test_usd = 42500.50;
$test_cny = 6100.25;

echo "Setting USD to {$test_usd} via plugin method...\n";
$options->set_dollar_price($test_usd);

echo "Setting CNY to {$test_cny} via plugin method...\n";
$options->set_yuan_price($test_cny);

// Verify sync
$verify_usd_plugin = $options->get_dollar_price();
$verify_cny_plugin = $options->get_yuan_price();
$verify_usd_option = get_option('dollar_price');
$verify_cny_option = get_option('yuan_price');
$verify_usd_acf = get_option('options_dollar_price');
$verify_cny_acf = get_option('options_yuan_price');

echo "Results:\n";
echo "  Plugin method USD: {$verify_usd_plugin}\n";
echo "  Plugin method CNY: {$verify_cny_plugin}\n";
echo "  Direct option USD: {$verify_usd_option}\n";
echo "  Direct option CNY: {$verify_cny_option}\n";
echo "  ACF storage USD: {$verify_usd_acf}\n";
echo "  ACF storage CNY: {$verify_cny_acf}\n";

$test2_passed = (
    $verify_usd_plugin == $test_usd &&
    $verify_cny_plugin == $test_cny &&
    $verify_usd_option == $test_usd &&
    $verify_cny_option == $test_cny &&
    $verify_usd_acf == $test_usd &&
    $verify_cny_acf == $test_cny
);

echo "Test 2: " . ($test2_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";

// Test 3: Update via direct option
echo "Test 3: Update via direct WordPress option\n";
echo "-------------------------------------------\n";
$test_usd2 = 43000.75;
$test_cny2 = 6200.50;

echo "Setting USD to {$test_usd2} via update_option...\n";
update_option('dollar_price', $test_usd2);

echo "Setting CNY to {$test_cny2} via update_option...\n";
update_option('yuan_price', $test_cny2);

// Verify sync
$verify_usd_plugin2 = $options->get_dollar_price();
$verify_cny_plugin2 = $options->get_yuan_price();
$verify_usd_option2 = get_option('dollar_price');
$verify_cny_option2 = get_option('yuan_price');
$verify_usd_acf2 = get_option('options_dollar_price');
$verify_cny_acf2 = get_option('options_yuan_price');

echo "Results:\n";
echo "  Plugin method USD: {$verify_usd_plugin2}\n";
echo "  Plugin method CNY: {$verify_cny_plugin2}\n";
echo "  Direct option USD: {$verify_usd_option2}\n";
echo "  Direct option CNY: {$verify_cny_option2}\n";
echo "  ACF storage USD: {$verify_usd_acf2}\n";
echo "  ACF storage CNY: {$verify_cny_acf2}\n";

$test3_passed = (
    $verify_usd_plugin2 == $test_usd2 &&
    $verify_cny_plugin2 == $test_cny2 &&
    $verify_usd_option2 == $test_usd2 &&
    $verify_cny_option2 == $test_cny2 &&
    $verify_usd_acf2 == $test_usd2 &&
    $verify_cny_acf2 == $test_cny2
);

echo "Test 3: " . ($test3_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";

// Test 4: ACF functions (if available)
echo "Test 4: Update via ACF functions (if available)\n";
echo "------------------------------------------------\n";

if (function_exists('update_field') && function_exists('get_field')) {
    $test_usd3 = 44000.00;
    $test_cny3 = 6300.00;
    
    echo "Setting USD to {$test_usd3} via update_field...\n";
    update_field('dollar_price', $test_usd3, 'option');
    
    echo "Setting CNY to {$test_cny3} via update_field...\n";
    update_field('yuan_price', $test_cny3, 'option');
    
    // Verify sync
    $verify_usd_field = get_field('dollar_price', 'option');
    $verify_cny_field = get_field('yuan_price', 'option');
    $verify_usd_plugin3 = $options->get_dollar_price();
    $verify_cny_plugin3 = $options->get_yuan_price();
    $verify_usd_option3 = get_option('dollar_price');
    $verify_cny_option3 = get_option('yuan_price');
    
    echo "Results:\n";
    echo "  get_field() USD: {$verify_usd_field}\n";
    echo "  get_field() CNY: {$verify_cny_field}\n";
    echo "  Plugin method USD: {$verify_usd_plugin3}\n";
    echo "  Plugin method CNY: {$verify_cny_plugin3}\n";
    echo "  Direct option USD: {$verify_usd_option3}\n";
    echo "  Direct option CNY: {$verify_cny_option3}\n";
    
    $test4_passed = (
        $verify_usd_field == $test_usd3 &&
        $verify_cny_field == $test_cny3 &&
        $verify_usd_plugin3 == $test_usd3 &&
        $verify_cny_plugin3 == $test_cny3 &&
        $verify_usd_option3 == $test_usd3 &&
        $verify_cny_option3 == $test_cny3
    );
    
    echo "Test 4: " . ($test4_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";
} else {
    echo "ACF functions not available - using fallback functions\n";
    echo "This is expected if ACF plugin is not installed\n";
    echo "Test 4: SKIPPED\n\n";
}

// Test 5: WooCommerce currency monitoring
echo "Test 5: WooCommerce currency monitoring\n";
echo "----------------------------------------\n";
echo "Current WooCommerce currency: {$wc_currency}\n";
echo "Note: Changing WooCommerce currency will be logged in activity log\n";
echo "Check Digitalogic → Logs for 'woocommerce_currency_change' entries\n";
echo "Test 5: INFO ONLY\n\n";

// Summary
echo "=== Summary ===\n";
$total_tests = 3; // Tests 2, 3, 4 (4 is optional)
$passed_tests = 0;
if ($test2_passed) $passed_tests++;
if ($test3_passed) $passed_tests++;

echo "Tests Passed: {$passed_tests}/{$total_tests}\n";

if ($passed_tests === $total_tests) {
    echo "\n✓ All currency synchronization tests PASSED!\n";
    echo "Currency settings are properly synchronized between:\n";
    echo "  - WordPress options (dollar_price, yuan_price)\n";
    echo "  - ACF fields (options_dollar_price, options_yuan_price)\n";
    echo "  - WooCommerce currency integration (monitored)\n";
    exit(0);
} else {
    echo "\n✗ Some tests FAILED. Please review the output above.\n";
    exit(1);
}
