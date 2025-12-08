<div align="center">
  <img src="assets/images/icon.svg" alt="Digitalogic Logo" width="200" height="200">
  <h1>Digitalogic WooCommerce Extension</h1>
  <p>A comprehensive WordPress/WooCommerce plugin for dynamic pricing, stock management, and POS integration, specifically designed for Digitalogic electronic components shop.</p>
</div>

---

## Features

### 🌍 Multi-Currency Support
- Store and update USD and CNY exchange rates
- Dynamic pricing based on real-time currency conversion
- Automatic price recalculation when rates change
- Support for custom markup (percentage or fixed)

### 📊 Product Management
- Interactive product management with DataTables
- Bulk product updates via elegant admin interface
- Real-time AJAX updates with polling
- Inline editing of prices, stock, and dimensions
- Support for simple and variable products

### 🔄 Import/Export
- CSV import/export
- JSON import/export
- Excel support (via PhpSpreadsheet - can be added)
- Bulk operations for thousands of products

### 🌐 REST API
- RESTful endpoints for external integrations
- Bulk update operations
- WooCommerce-compatible authentication
- JSON-RPC support

### 🔔 Webhooks
- Real-time notifications for product updates
- Currency rate change notifications
- HMAC signature verification
- Non-blocking async delivery

### 💻 WP-CLI Support
- Command-line product management
- Currency rate updates
- Bulk operations
- Import/export via CLI
- Activity log viewing

### 📝 Activity Logging
- Comprehensive audit trail
- User action tracking
- IP address and user agent logging
- Filterable log viewer

### 🎨 Modern UI/UX
- Light/dark mode support
- RTL/LTR language support
- Responsive design
- Persian (primary) and English (secondary) i18n support

### ⚡ WooCommerce HPOS Compatible
- Full support for High-Performance Order Storage (HPOS)
- Compatible with WooCommerce 8.2+ custom order tables
- Uses WooCommerce CRUD methods for optimal performance
- Works seamlessly with both traditional and HPOS storage

## Requirements

- PHP 8.0 or higher
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher (8.2+ recommended for HPOS)
- MySQL 5.7 or higher

## Installation

### Via WordPress Admin

1. Download the latest release from GitHub
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the zip file and activate

### Via WP-CLI

```bash
wp plugin install /path/to/digitalogic-wp.zip --activate
```

### Manual Installation

1. Extract the plugin to `wp-content/plugins/digitalogic`
2. Run `composer install --no-dev` in the plugin directory
3. Activate via WordPress Admin → Plugins

## Configuration

### Currency Settings

Update currency exchange rates:

**Admin Interface:**
Navigate to Digitalogic → Currency Settings

**WP-CLI:**
```bash
wp digitalogic currency update --usd=42000 --cny=6000 --recalculate
```

**REST API:**
```bash
curl -X POST https://yoursite.com/wp-json/digitalogic/v1/currency \
  -H "Content-Type: application/json" \
  -d '{"dollar_price": 42000, "yuan_price": 6000}'
```

### Product Management

**Admin Interface:**
Navigate to Digitalogic → Products for the interactive product table

**WP-CLI:**
```bash
# List products
wp digitalogic products list --limit=20 --format=table

# Update a product
wp digitalogic products update 123 --price=99.99 --stock=50
```

**REST API:**
```bash
# Get products
curl https://yoursite.com/wp-json/digitalogic/v1/products

# Update single product
curl -X PUT https://yoursite.com/wp-json/digitalogic/v1/products/123 \
  -H "Content-Type: application/json" \
  -d '{"regular_price": 99.99, "stock_quantity": 50}'

# Bulk update
curl -X POST https://yoursite.com/wp-json/digitalogic/v1/products/batch \
  -H "Content-Type: application/json" \
  -d '{"123": {"price": 99.99}, "124": {"stock_quantity": 50}}'
```

## API Documentation

### REST API Endpoints

#### Products
- `GET /wp-json/digitalogic/v1/products` - List products
- `GET /wp-json/digitalogic/v1/products/{id}` - Get single product
- `PUT /wp-json/digitalogic/v1/products/{id}` - Update product
- `POST /wp-json/digitalogic/v1/products/batch` - Bulk update

#### Currency
- `GET /wp-json/digitalogic/v1/currency` - Get currency rates
- `POST /wp-json/digitalogic/v1/currency` - Update currency rates

#### Pricing
- `POST /wp-json/digitalogic/v1/pricing/recalculate` - Recalculate all prices

#### Export
- `GET /wp-json/digitalogic/v1/export?format=csv` - Export products

### Authentication

The plugin uses WooCommerce REST API authentication:

1. Go to WooCommerce → Settings → Advanced → REST API
2. Create API keys (consumer key & secret)
3. Use Basic Auth with consumer key as username and secret as password

### Webhooks

Configure webhook URLs in WordPress options:

```php
update_option('digitalogic_webhook_urls', ['https://your-endpoint.com/webhook']);
update_option('digitalogic_webhook_secret', 'your-secret-key');
```

Webhook events:
- `product.created` - New product added
- `product.updated` - Product updated
- `currency.updated` - Currency rates changed

Verify webhook signatures:
```php
$signature = $_SERVER['HTTP_X_DIGITALOGIC_SIGNATURE'];
$payload = file_get_contents('php://input');
$is_valid = Digitalogic_Webhooks::verify_signature($payload, $signature);
```

## WP-CLI Commands

```bash
# Currency operations
wp digitalogic currency get
wp digitalogic currency update --usd=42000 --cny=6000 --recalculate

# Product operations
wp digitalogic products list --limit=20 --search=arduino
wp digitalogic products update 123 --price=99.99 --stock=50

# Import/Export
wp digitalogic export --format=csv --output=/path/to/export.csv
wp digitalogic import /path/to/products.csv

# Logs
wp digitalogic logs --limit=50 --action=update_product
```

## Development

### Setup Development Environment

```bash
# Clone the repository
git clone https://github.com/atomicdeploy/digitalogic-wp.git
cd digitalogic-wp

# Install dependencies
composer install

# Install coding standards
composer require --dev wp-coding-standards/wpcs
```

### Running Tests

```bash
# PHP syntax check
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Code style check
composer phpcs

# Fix code style
composer phpcbf
```

### GitHub Actions

The project includes CI/CD workflows:
- `ci-cd.yml` - Runs on push/PR (linting, testing, building)
- `deploy.yml` - Deployment workflow for releases

## Database Schema

### Activity Log Table

```sql
CREATE TABLE wp_digitalogic_logs (
  id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id bigint(20) UNSIGNED NOT NULL,
  action varchar(255) NOT NULL,
  object_type varchar(50) NOT NULL,
  object_id bigint(20) UNSIGNED DEFAULT NULL,
  old_value longtext DEFAULT NULL,
  new_value longtext DEFAULT NULL,
  ip_address varchar(45) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  KEY user_id (user_id),
  KEY action (action),
  KEY object_type (object_type),
  KEY created_at (created_at)
);
```

### Options

- `digitalogic_dollar_price` - USD exchange rate
- `digitalogic_yuan_price` - CNY exchange rate
- `digitalogic_update_date` - Last update date (YYMMDD format)

### Product Meta Keys

- `_digitalogic_dynamic_pricing` - Enable dynamic pricing (yes/no)
- `_digitalogic_currency_type` - Currency type (usd/cny)
- `_digitalogic_base_price` - Base price in foreign currency
- `_digitalogic_markup` - Markup value
- `_digitalogic_markup_type` - Markup type (percentage/fixed)

## Internationalization

The plugin supports i18n with POT file included:
- Primary language: Persian (fa_IR)
- Secondary language: English (en_US)

To add translations:
1. Use POEdit or similar tool
2. Open `languages/digitalogic.pot`
3. Create `.po` and `.mo` files for your language
4. Place in `languages/` directory

## Security

- CSRF protection with nonces
- Capability checks on all admin operations
- SQL injection prevention with prepared statements
- XSS prevention with proper escaping
- Webhook HMAC signature verification
- Audit logging for all changes

## Support

- GitHub Issues: https://github.com/atomicdeploy/digitalogic-wp/issues
- Documentation: https://github.com/atomicdeploy/digitalogic-wp/wiki

## License

GPL v2 or later. See LICENSE file for details.

## Credits

Developed for Digitalogic electronic components shop.

## Changelog

### 1.0.0
- Initial release
- Multi-currency support (USD, CNY)
- Product management with DataTables
- Dynamic pricing engine
- REST API endpoints
- Webhooks integration
- WP-CLI commands
- Import/Export (CSV, JSON)
- Activity logging
- Light/dark mode support
- RTL/LTR support
- i18n support (Persian/English)
