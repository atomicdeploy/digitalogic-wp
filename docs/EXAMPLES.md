# Usage Examples

This document provides practical examples of using the Digitalogic plugin.

## Table of Contents

1. [Currency Management](#currency-management)
2. [Product Management](#product-management)
3. [Dynamic Pricing](#dynamic-pricing)
4. [Bulk Operations](#bulk-operations)
5. [Import/Export](#importexport)
6. [REST API](#rest-api)
7. [WP-CLI](#wp-cli)
8. [Webhooks](#webhooks)

---

## Currency Management

### Update Currency Rates via Admin

1. Navigate to **Digitalogic** → **Currency**
2. Enter USD price: `42000`
3. Enter CNY price: `6000`
4. Check "Recalculate Prices" to update all products
5. Click "Update Currency Rates"

### Update Currency Rates via PHP

```php
// Using the Options API
$options = Digitalogic_Options::instance();
$options->set_dollar_price(42000);
$options->set_yuan_price(6000);

// Or using helper functions
digitalogic_update_field('dollar_price', 42000, 'option');
digitalogic_update_field('yuan_price', 6000, 'option');

// Get current rates
$usd_rate = digitalogic_get_field('dollar_price', 'option');
$cny_rate = digitalogic_get_field('yuan_price', 'option');
$last_update = digitalogic_get_field('update_date', 'option');

echo "USD: {$usd_rate}, CNY: {$cny_rate}, Updated: {$last_update}";
```

---

## Product Management

### Get Products via PHP

```php
$manager = Digitalogic_Product_Manager::instance();

// Get all products (paginated)
$products = $manager->get_products(array(
    'limit' => 50,
    'page' => 1,
    'search' => 'arduino'
));

// Get single product
$product = $manager->get_product(123);

// Display product data
foreach ($products as $product) {
    echo "{$product['name']} - {$product['sku']} - {$product['price']}\n";
}
```

### Update Single Product via PHP

```php
$manager = Digitalogic_Product_Manager::instance();

$result = $manager->update_product(123, array(
    'regular_price' => 275000,
    'sale_price' => 250000,
    'stock_quantity' => 75,
    'weight' => 0.025
));

if (is_wp_error($result)) {
    echo "Error: " . $result->get_error_message();
} else {
    echo "Product updated successfully!";
}
```

---

## Dynamic Pricing

### Enable Dynamic Pricing for a Product

```php
$pricing = Digitalogic_Pricing::instance();

// Set product to use USD-based pricing
$pricing->set_dynamic_pricing(
    123,              // product ID
    'usd',            // currency type: 'usd' or 'cny'
    25.99,            // base price in foreign currency
    20,               // markup: 20%
    'percentage'      // markup type: 'percentage' or 'fixed'
);

// Product price will now be calculated as:
// (25.99 USD × 42000) × 1.20 = 1,309,752 IRR
```

### Manual Price Calculation

```php
$options = Digitalogic_Options::instance();
$usd_rate = $options->get_dollar_price();

// Calculate price
$base_price_usd = 25.99;
$markup_percentage = 20;

$price_local = $base_price_usd * $usd_rate;
$final_price = $price_local * (1 + ($markup_percentage / 100));

echo "Final price: " . number_format($final_price) . " IRR";
```

### Recalculate All Prices

```php
$pricing = Digitalogic_Pricing::instance();
$results = $pricing->bulk_recalculate_prices();

echo "Updated {$results['success']} products";
echo "Failed: {$results['failed']}";
```

---

## Bulk Operations

### Bulk Update via PHP

```php
$manager = Digitalogic_Product_Manager::instance();

$updates = array(
    123 => array('regular_price' => 275000, 'stock_quantity' => 50),
    124 => array('regular_price' => 150000, 'stock_quantity' => 100),
    125 => array('sale_price' => 90000),
);

$results = $manager->bulk_update($updates);

echo "Success: {$results['success']}, Failed: {$results['failed']}";

if (!empty($results['errors'])) {
    foreach ($results['errors'] as $product_id => $error) {
        echo "Product {$product_id}: {$error}\n";
    }
}
```

### Scheduled Bulk Updates

```php
// Add to theme's functions.php or custom plugin

// Schedule daily price update
add_action('init', function() {
    if (!wp_next_scheduled('digitalogic_daily_price_update')) {
        wp_schedule_event(time(), 'daily', 'digitalogic_daily_price_update');
    }
});

// Hook the actual update
add_action('digitalogic_daily_price_update', function() {
    $pricing = Digitalogic_Pricing::instance();
    $pricing->bulk_recalculate_prices();
});
```

---

## Import/Export

### Export Products to CSV

```php
$import_export = Digitalogic_Import_Export::instance();

// Export all products
$filepath = $import_export->export_csv();

// Export specific products
$filepath = $import_export->export_csv(array(123, 124, 125));

echo "Exported to: {$filepath}";

// Get download URL
$upload_dir = wp_upload_dir();
$file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filepath);
echo "Download: {$file_url}";
```

### Import Products from CSV

```php
$import_export = Digitalogic_Import_Export::instance();

$filepath = '/path/to/products.csv';
$results = $import_export->import_csv($filepath);

if (is_wp_error($results)) {
    echo "Error: " . $results->get_error_message();
} else {
    echo "Imported {$results['success']} products";
    echo "Failed: {$results['failed']}";
    
    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
            echo "Error: {$error}\n";
        }
    }
}
```

### Export to JSON

```php
$import_export = Digitalogic_Import_Export::instance();
$filepath = $import_export->export_json();

// Read and display
$json = file_get_contents($filepath);
$products = json_decode($json, true);

echo "Exported " . count($products) . " products";
```

---

## REST API

### Get Products

```bash
curl -u consumer_key:consumer_secret \
  "https://yoursite.com/wp-json/digitalogic/v1/products?limit=20&page=1"
```

### Update Product

```bash
curl -X PUT \
  -u consumer_key:consumer_secret \
  -H "Content-Type: application/json" \
  -d '{"regular_price": 275000, "stock_quantity": 75}' \
  https://yoursite.com/wp-json/digitalogic/v1/products/123
```

### Bulk Update

```bash
curl -X POST \
  -u consumer_key:consumer_secret \
  -H "Content-Type: application/json" \
  -d '{"123": {"regular_price": 275000}, "124": {"stock_quantity": 100}}' \
  https://yoursite.com/wp-json/digitalogic/v1/products/batch
```

### Update Currency

```bash
curl -X POST \
  -u consumer_key:consumer_secret \
  -H "Content-Type: application/json" \
  -d '{"dollar_price": 42500, "yuan_price": 6100}' \
  https://yoursite.com/wp-json/digitalogic/v1/currency
```

---

## WP-CLI

### Currency Operations

```bash
# Get current rates
wp digitalogic currency get

# Update rates
wp digitalogic currency update --usd=42000 --cny=6000

# Update and recalculate all prices
wp digitalogic currency update --usd=42500 --recalculate
```

### Product Operations

```bash
# List products
wp digitalogic products list --limit=20 --format=table

# Search products
wp digitalogic products list --search=arduino --format=json

# Update product
wp digitalogic products update 123 --price=275000 --stock=75
```

### Import/Export

```bash
# Export all products to CSV
wp digitalogic export --format=csv --output=/tmp/products.csv

# Export to JSON
wp digitalogic export --format=json

# Import from CSV
wp digitalogic import /path/to/products.csv

# Import from JSON
wp digitalogic import /path/to/products.json
```

### View Logs

```bash
# View recent logs
wp digitalogic logs --limit=50

# Filter by action
wp digitalogic logs --action=update_product --limit=100

# Export logs to CSV
wp digitalogic logs --limit=1000 --format=csv > logs.csv
```

---

## Webhooks

### Set Up Webhook Receiver (PHP)

```php
<?php
// webhook-receiver.php

$signature = $_SERVER['HTTP_X_DIGITALOGIC_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');

// Verify signature
$secret = 'your-webhook-secret';
$expected_signature = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected_signature, $signature)) {
    http_response_code(401);
    die('Invalid signature');
}

// Parse webhook data
$data = json_decode($payload, true);
$event = $_SERVER['HTTP_X_DIGITALOGIC_EVENT'] ?? '';

// Handle event
switch ($event) {
    case 'product.updated':
        // Update POS system
        update_pos_product($data['data']);
        break;
        
    case 'currency.updated':
        // Update accounting system
        update_accounting_rates($data['data']);
        break;
}

http_response_code(200);
echo json_encode(['status' => 'success']);
```

### Set Up Webhook Receiver (Node.js)

```javascript
const express = require('express');
const crypto = require('crypto');

const app = express();
app.use(express.text({ type: 'application/json' }));

const WEBHOOK_SECRET = 'your-webhook-secret';

app.post('/webhook', (req, res) => {
    const signature = req.headers['x-digitalogic-signature'];
    const payload = req.body;
    
    // Verify signature
    const hmac = crypto.createHmac('sha256', WEBHOOK_SECRET);
    const expectedSignature = hmac.update(payload).digest('hex');
    
    if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expectedSignature))) {
        return res.status(401).send('Invalid signature');
    }
    
    // Parse and handle webhook
    const data = JSON.parse(payload);
    const event = req.headers['x-digitalogic-event'];
    
    console.log(`Received ${event} event:`, data);
    
    // Handle event
    switch (event) {
        case 'product.updated':
            // Update your system
            break;
    }
    
    res.json({ status: 'success' });
});

app.listen(3000, () => console.log('Webhook receiver listening on port 3000'));
```

---

## Advanced Examples

### Custom Product Filter

```php
// Add to functions.php
add_filter('digitalogic_product_query_args', function($args) {
    // Only show products with stock
    $args['stock_status'] = 'instock';
    return $args;
});
```

### Custom Price Calculation

```php
// Add custom pricing logic
add_filter('woocommerce_product_get_price', function($price, $product) {
    // Add 10% tax for wholesale customers
    if (is_user_logged_in() && current_user_can('wholesale_customer')) {
        $price = $price * 1.10;
    }
    return $price;
}, 20, 2);
```

### Automatic Currency Updates

```php
// Fetch rates from external API and update
function update_currency_rates_from_api() {
    $response = wp_remote_get('https://api.exchangerate.com/rates');
    
    if (is_wp_error($response)) {
        return;
    }
    
    $rates = json_decode(wp_remote_retrieve_body($response), true);
    
    $options = Digitalogic_Options::instance();
    $options->set_dollar_price($rates['USD'] * 1000); // Convert to local currency
    $options->set_yuan_price($rates['CNY'] * 1000);
}

// Schedule to run every hour
add_action('init', function() {
    if (!wp_next_scheduled('update_currency_rates')) {
        wp_schedule_event(time(), 'hourly', 'update_currency_rates');
    }
});

add_action('update_currency_rates', 'update_currency_rates_from_api');
```

---

For more examples, see the [API Documentation](API.md) and [README](../README.md).
