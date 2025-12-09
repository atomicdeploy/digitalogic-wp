# WooCommerce HPOS Compatibility Implementation

## Overview

This document describes the implementation of WooCommerce High-Performance Order Storage (HPOS) compatibility in the Digitalogic plugin.

## What is HPOS?

WooCommerce HPOS (High-Performance Order Storage) is a feature introduced in WooCommerce 8.2 (October 2023) that stores order data in custom database tables instead of the WordPress posts system. This provides:

- Better performance for large stores
- Improved scalability
- More efficient queries
- Dedicated data structure for e-commerce

**Important:** HPOS is enabled by default for new WooCommerce installations since version 8.2.

## Implementation Details

### 1. HPOS Compatibility Declaration

**File:** `digitalogic.php`

The plugin declares HPOS compatibility on the `before_woocommerce_init` hook as required by WooCommerce:

```php
// In init_hooks() method
add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

// Declaration method
public function declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}
```

**Important:** The declaration MUST happen on the `before_woocommerce_init` hook. This is a requirement from the [WooCommerce HPOS Recipe Book](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/).

This declares to WooCommerce that our plugin is compatible with custom order tables.

### 2. Product Meta Data Handling

**Files:** `includes/class-pricing.php`, `includes/class-import-export.php`

#### Before (Not HPOS-compatible):
```php
$enable_dynamic = get_post_meta($product_id, '_digitalogic_dynamic_pricing', true);
update_post_meta($product_id, '_digitalogic_dynamic_pricing', 'yes');
```

#### After (HPOS-compatible):
```php
$enable_dynamic = $product->get_meta('_digitalogic_dynamic_pricing', true);
$product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
$product->save(); // Important: persist changes
```

### 3. Bulk Operations

**File:** `includes/class-pricing.php`

#### Before (Direct database query):
```php
$product_ids = $wpdb->get_col(
    "SELECT post_id FROM {$wpdb->postmeta} 
    WHERE meta_key = '_digitalogic_dynamic_pricing' 
    AND meta_value = 'yes'"
);
```

#### After (WooCommerce API):
```php
$args = array(
    'limit' => -1,
    'return' => 'ids',
    'meta_query' => array(
        array(
            'key' => '_digitalogic_dynamic_pricing',
            'value' => 'yes',
            'compare' => '='
        )
    )
);
$product_ids = wc_get_products($args);
```

## Key Changes

### 1. Pricing Class (`class-pricing.php`)

- ✅ `calculate_dynamic_price()`: Uses `$product->get_meta()` instead of `get_post_meta()`
- ✅ `set_dynamic_pricing()`: Uses `$product->update_meta_data()` and `$product->save()`
- ✅ `bulk_recalculate_prices()`: Uses `wc_get_products()` with meta_query
- ✅ Added memory optimization with `unset($product)` in loops
- ✅ Ensures meta data is saved even in error conditions

### 2. Import/Export Class (`class-import-export.php`)

- ✅ `export_csv()`: Uses `$product->get_meta()` for reading meta data
- ✅ `export_json()`: Uses `$product->get_meta()` for reading meta data
- ✅ `import_csv()`: Uses `$product->update_meta_data()` and `$product->save()`
- ✅ `import_json()`: Uses `$product->update_meta_data()` and `$product->save()`

## Benefits

### 1. Compatibility
- ✅ Works with WooCommerce 8.2+ HPOS (enabled by default)
- ✅ Compatible with both traditional post-based storage and custom tables
- ✅ No breaking changes for existing installations

### 2. Performance
- ✅ Uses WooCommerce's optimized data access methods
- ✅ Better query performance with HPOS
- ✅ Memory optimization for large product catalogs

### 3. Future-Proof
- ✅ Uses recommended WooCommerce CRUD methods
- ✅ Ready for future WooCommerce updates
- ✅ Follows WooCommerce best practices

## Testing Recommendations

### 1. Basic Functionality Testing

```bash
# Test currency updates
wp digitalogic currency update --usd=42000 --cny=6000 --recalculate

# Test product listing
wp digitalogic products list --limit=10

# Test import/export
wp digitalogic export --format=csv
wp digitalogic import /path/to/products.csv
```

### 2. HPOS-Specific Testing

1. **Enable HPOS:**
   - Go to WooCommerce → Settings → Advanced → Features
   - Enable "High-Performance Order Storage"
   - Enable "Compatibility mode" (optional, for transition)

2. **Test Dynamic Pricing:**
   - Create a product with dynamic pricing
   - Update currency rates
   - Verify price calculation works correctly

3. **Test Bulk Operations:**
   - Use the admin interface to bulk update products
   - Verify changes are saved correctly in HPOS

4. **Test Import/Export:**
   - Export products to CSV/JSON
   - Make changes to the file
   - Import back and verify changes

### 3. Performance Testing

For large catalogs (1000+ products with dynamic pricing):

```bash
# Use WP-CLI for better performance
time wp digitalogic currency update --usd=42000 --recalculate
```

## Migration Guide

### For Existing Installations

No migration needed! The plugin automatically works with both storage methods:

1. **Traditional storage:** Uses WooCommerce CRUD methods that read from `wp_postmeta`
2. **HPOS storage:** Same CRUD methods read from custom tables

### For New Installations

HPOS is enabled by default in WooCommerce 8.2+. The plugin works immediately without any configuration.

## Known Limitations

### Large Catalogs

The `bulk_recalculate_prices()` function loads all products with dynamic pricing into memory. For very large catalogs (>1000 products):

**Recommended:** Use WP-CLI command which has better timeout handling:

```bash
wp digitalogic currency update --usd=42000 --recalculate
```

**Alternative:** Consider implementing pagination in a future update.

## Troubleshooting

### Issue: Meta data not saving

**Solution:** Ensure `$product->save()` is called after `$product->update_meta_data()`

```php
$product->update_meta_data('_key', 'value');
$product->save(); // This is required!
```

### Issue: Performance issues with bulk operations

**Solution:** Use WP-CLI command instead of admin interface for large catalogs:

```bash
wp digitalogic currency update --usd=42000 --recalculate
```

### Issue: Products not found after HPOS migration

**Solution:** WooCommerce automatically handles migration. If issues persist:

1. Go to WooCommerce → Status → Tools
2. Run "Verify database" tool
3. Run "Recount terms" tool

## References

- [WooCommerce HPOS Documentation](https://developer.woocommerce.com/docs/high-performance-order-storage/)
- [How to Enable HPOS](https://developer.woocommerce.com/docs/how-to-enable-high-performance-order-storage/)
- [WooCommerce CRUD Documentation](https://github.com/woocommerce/woocommerce/wiki/CRUD-Objects-in-3.0)

## Support

For issues related to HPOS compatibility:

1. Check WooCommerce → Status → System Status → HPOS status
2. Verify plugin is showing as HPOS-compatible
3. Review the activity logs in Digitalogic → Logs
4. Open an issue on GitHub with system information

---

**Last Updated:** 2024-12-08
**Plugin Version:** 1.0.0+
**WooCommerce Version:** 8.2+
