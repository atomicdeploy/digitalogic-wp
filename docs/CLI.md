# CLI Documentation

## WP-CLI Commands Reference

WP-CLI commands for managing products and currency rates from the command line.

### Prerequisites

- WP-CLI must be installed
- User must have `manage_woocommerce` capability

---

## Currency Commands

### Get Currency Rates

Get current currency exchange rates.

```bash
wp digitalogic currency get
```

**Example Output:**
```
Currency Rates:
USD: 42000
CNY: 6000
Last Update: 2024-01-15 10:30:00
```

---

### Update Currency Rates

Update currency exchange rates and optionally recalculate product prices.

```bash
wp digitalogic currency update --usd=<price> --cny=<price> [--recalculate]
```

**Parameters:**
- `--usd=<price>` - USD price in local currency
- `--cny=<price>` - CNY price in local currency
- `--recalculate` - Recalculate all product prices after update

**Examples:**
```bash
# Update USD rate only
wp digitalogic currency update --usd=42500

# Update both rates
wp digitalogic currency update --usd=42500 --cny=6100

# Update and recalculate all product prices
wp digitalogic currency update --usd=42500 --cny=6100 --recalculate
```

---

## Product Commands

### List Products

List products with optional filtering.

```bash
wp digitalogic products list [--limit=<number>] [--search=<term>] [--format=<format>]
```

**Parameters:**
- `--limit=<number>` - Number of products to list (default: 10)
- `--search=<term>` - Search term to filter products
- `--format=<format>` - Output format: table, csv, json (default: table)

**Examples:**
```bash
# List 10 products in table format
wp digitalogic products list

# List 50 products
wp digitalogic products list --limit=50

# Search for products
wp digitalogic products list --search=arduino --limit=20

# Output as JSON
wp digitalogic products list --format=json

# Output as CSV
wp digitalogic products list --format=csv --limit=100 > products.csv
```

---

### Get Product Information

Get detailed information about a specific product by ID or SKU.

```bash
wp digitalogic products get --id=<product_id>
wp digitalogic products get --sku=<sku>
```

**Parameters:**
- `--id=<product_id>` - Product ID (use either --id or --sku)
- `--sku=<sku>` - Product SKU (use either --id or --sku)
- `--format=<format>` - Output format: table, json (default: table)

**Examples:**
```bash
# Get product by ID
wp digitalogic products get --id=123

# Get product by SKU
wp digitalogic products get --sku=113004012

# Get product as JSON
wp digitalogic products get --id=123 --format=json
```

---

### Get Product Metadata

View all metadata for a product including data from both wp_postmeta and wp_wc_product_meta_lookup tables.

```bash
wp digitalogic products metadata --id=<product_id>
wp digitalogic products metadata --sku=<sku>
```

**Parameters:**
- `--id=<product_id>` - Product ID (use either --id or --sku)
- `--sku=<sku>` - Product SKU (use either --id or --sku)
- `--format=<format>` - Output format: table, json (default: table)

**Examples:**
```bash
# View metadata by product ID
wp digitalogic products metadata --id=10659

# View metadata by SKU
wp digitalogic products metadata --sku=113004012

# Output as JSON
wp digitalogic products metadata --id=10659 --format=json
```

**Example Output:**
```
Product Metadata for Product #10659 (SKU: 113004012)

=== WooCommerce Product Meta Lookup ===
Field                   Value
product_id             10659
sku                    113004012
min_price              167000.0000
max_price              167000.0000
stock_quantity         0
stock_status           instock

=== Product Meta (wp_postmeta) ===
Field                   Value
_sku                   113004012
_regular_price         167000
_price                 167000
_stock                 0
_stock_status          instock
_manage_stock          yes

⚠ Inconsistencies detected:
  - SKU mismatch: postmeta="113004012", lookup="113004013"
```

---

### Update Product

Update a product's properties.

```bash
wp digitalogic products update --id=<product_id> [options]
wp digitalogic products update --sku=<sku> [options]
```

**Parameters:**
- `--id=<product_id>` - Product ID (use either --id or --sku for lookup)
- `--sku=<sku>` - Product SKU (use either --id or --sku for lookup)
- `--price=<price>` - Regular price
- `--sale-price=<price>` - Sale price
- `--stock=<quantity>` - Stock quantity
- `--set-sku=<sku>` - Set new SKU for the product

**Examples:**
```bash
# Update by product ID
wp digitalogic products update --id=123 --price=99.99 --stock=50

# Update by SKU
wp digitalogic products update --sku=113004012 --price=250000

# Update multiple fields
wp digitalogic products update --id=123 --price=99.99 --sale-price=89.99 --stock=100

# Change product SKU
wp digitalogic products update --id=123 --set-sku=NEW-SKU-456
```

---

## Import/Export Commands

### Export Products

Export products to CSV, JSON, or Excel format.

```bash
wp digitalogic export [--format=<format>] [--output=<file>]
```

**Parameters:**
- `--format=<format>` - Export format: csv, json, excel (default: csv)
- `--output=<file>` - Output file path (optional)

**Examples:**
```bash
# Export to CSV
wp digitalogic export --format=csv

# Export to JSON with custom path
wp digitalogic export --format=json --output=/path/to/products.json

# Export to Excel
wp digitalogic export --format=excel --output=/path/to/products.xlsx
```

---

### Import Products

Import products from CSV, JSON, or Excel file.

```bash
wp digitalogic import <file>
```

**Parameters:**
- `<file>` - Input file path (CSV, JSON, or Excel)

**Examples:**
```bash
# Import from CSV
wp digitalogic import /path/to/products.csv

# Import from JSON
wp digitalogic import /path/to/products.json

# Import from Excel
wp digitalogic import /path/to/products.xlsx
```

---

## Logs Commands

### View Activity Logs

View activity logs with optional filtering.

```bash
wp digitalogic logs [--limit=<number>] [--action=<action>] [--format=<format>]
```

**Parameters:**
- `--limit=<number>` - Number of logs to display (default: 20)
- `--action=<action>` - Filter by action type
- `--format=<format>` - Output format: table, csv, json (default: table)

**Examples:**
```bash
# View last 20 logs
wp digitalogic logs

# View last 50 logs
wp digitalogic logs --limit=50

# Filter by action
wp digitalogic logs --action=update_product --limit=100

# Output as JSON
wp digitalogic logs --format=json
```

---

## Common Use Cases

### Bulk Price Update After Currency Change

```bash
# Update currency and recalculate all prices
wp digitalogic currency update --usd=43000 --cny=6200 --recalculate
```

### Find Products by Search Term

```bash
# Search and export matching products
wp digitalogic products list --search=ESP --format=csv > esp-products.csv
```

### Check Product Information

```bash
# Get product details by ID
wp digitalogic products get --id=123

# Get product details by SKU
wp digitalogic products get --sku=113004012

# View all metadata
wp digitalogic products metadata --sku=113004012
```

### Update Specific Product

```bash
# Update stock for a specific SKU
wp digitalogic products update --sku=113004012 --stock=100

# Update price by ID
wp digitalogic products update --id=123 --price=99.99
```

---

## Error Handling

All commands will display appropriate error messages if:
- Product is not found
- Invalid parameters are provided
- Permission is denied
- System error occurs

**Example Error:**
```bash
$ wp digitalogic products get --id=99999
Error: Product not found

$ wp digitalogic products get
Error: Please specify either --id or --sku
```

---

## See Also

- [API Documentation](API.md) - REST API endpoints
- [README.md](../README.md) - General plugin documentation
- [WooCommerce CLI Documentation](https://developer.woocommerce.com/docs/wp-cli/)
