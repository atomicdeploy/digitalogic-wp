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
- Support for simple, variable, and variation products
- Includes all product statuses (published, draft, pending, etc.)

### 🔄 Import/Export
- CSV import/export
- JSON import/export
- Header-driven localized Excel import/export, including empty templates and bilingual product/warehouse columns ([workbook contract](docs/EXCEL-PRODUCT-WORKBOOK.md))
- Google Sheets catalog sync with separate Products/Categories tabs, bilingual headers, and manual or scheduled refresh
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
- Secret-free production watchdog, notification routing, n8n workflow, and rollback assets: [`ops/office-automation/README.md`](ops/office-automation/README.md)

### ☎️ Phone verification and PBX notifications
- From the Digits OTP sidebar, choose **Verify by calling**, or add an Iranian mobile/landline contact from My Account, then call `021-66754123` from the same number. A live caller-ID challenge collects the 120-second code immediately after «دوست عزیزم»; calls without one continue to the operator.
- Six-digit, 120-second challenge with exact caller-ID matching and single-use browser-bound consumption
- Multiple supplemental emails and verified phone contacts in WooCommerce My Account
- Per-number customer/admin consent controls for voice order updates
- Global and per-order-status switches are disabled by default; calls run asynchronously with quiet hours and rate limits
- Editable Persian templates support only `{first_name}`, `{order_number}`, `{order_status}`, and `{site_name}`
- Deployment and security details: [`docs/PBX_CALL_VERIFICATION.md`](docs/PBX_CALL_VERIFICATION.md)

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
- WordPress Admin Bar integration with quicklinks to all plugin pages
- Persian (primary) and English (secondary) i18n support

### ⚡ WooCommerce HPOS Compatible
- Full support for High-Performance Order Storage (HPOS)
- Compatible with WooCommerce 8.2+ custom order tables
- Uses WooCommerce CRUD methods for optimal performance
- Works seamlessly with both traditional and HPOS storage

## Requirements

- PHP 8.3 or higher
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher (8.2+ recommended for HPOS)
- MySQL 5.7 or higher

## Installation

### Via WordPress Admin

1. Download the stable [`digitalogic-wp.zip`](https://github.com/atomicdeploy/digitalogic-wp/releases/latest/download/digitalogic-wp.zip) asset from the latest GitHub release
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the zip file and activate

### Via WP-CLI

```bash
wp plugin install /path/to/digitalogic-wp.zip --activate
```

### Manual Installation

1. Extract the plugin to `wp-content/plugins/digitalogic-wp`
2. Run `composer install --no-dev` in the plugin directory
3. Activate via WordPress Admin → Plugins

## Configuration

### Currency Settings

Update currency exchange rates:

**Admin Interface:**
Navigate to Digitalogic → Currency Settings

Legacy WordPress dashboard and status summaries display exchange rates as
half-up rounded integers with locale-aware thousands separators. Persian
(`fa*`) administrator locales also use Persian digits. This formatting is
presentation-only: stored rates and API values keep their original precision.

Currency effective dates are read from the raw ACF `options_update_date`
option (falling back to `update_date`) and accept legacy `YYMMDD` or strict ISO
date values. The admin, external panel, `[dollar_rate]`, and `[yuan_rate]`
storefront cards all use one formatter: English locales display Gregorian
dates, while Persian (`fa*`) locales display Jalali dates with Persian digits.
Invalid or empty stored values display as blank instead of today or an
epoch-era date. See [Currency effective-date formatting](docs/CURRENCY-DATE-FORMATTING.md).

WooCommerce remains the source of truth for the local currency. `IRT` values
are Tomans. `IRR` values remain Rials and must be divided by 10 before being
represented as Tomans; the plugin therefore does not provide a symbol-only
IRR/Toman toggle.

The page reports the WooCommerce base currency without changing it. Patris
final prices require `IRT`, meaning Toman (`1 IRT = 1 Toman = 10 IRR`). `IRR`
is a distinct, incompatible base code; a mismatch blocks transformed IRT price
writes and is visible in the catalog, REST, CLI, panel, webhooks, and status
screens. See [WooCommerce base-currency status](docs/WOOCOMMERCE-CURRENCY-STATUS.md).

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

#### Google Sheets
- `GET /wp-json/digitalogic/v1/google-sheets/catalog` - Read a bounded, canonical `products` or `categories` dataset
- `POST /wp-json/digitalogic/v1/google-sheets/writeback/preview` - Validate a bounded, revision-checked product change set without mutation
- `POST /wp-json/digitalogic/v1/google-sheets/writeback/apply` - Apply the exact previewed change set with idempotency, audit, and conflict-safe compensation

The supplied Apps Script performs idempotent upserts keyed by exact Patris Code
or the display-only `woo:<id>` fallback, preserves identifier cells as text,
and stores credentials only in Script Properties. Patris matching never falls
back to SKU. Catalog rows follow the living sparse response: an absent key means
no source/reference value, while `null` is emitted only for an explicit upstream
null. The opt-in `Changes`, `Audit`, and Dashboard workflow can call the site
directly or through the supplied inactive n8n proxy template. See [Google Sheets
catalog synchronization](docs/GOOGLE-SHEETS.md) and [editable Google Sheets
writeback](docs/GOOGLE-SHEETS-WRITEBACK.md).

#### Currency
- `GET /wp-json/digitalogic/v1/currency` - Get currency rates and read-only WooCommerce base-currency status
- `POST /wp-json/digitalogic/v1/currency` - Update currency rates

#### Pricing
- `POST /wp-json/digitalogic/v1/pricing/recalculate` - Recalculate all prices
- `GET|PUT /wp-json/digitalogic/v1/pricing/default-markup` - Read, set, or explicitly clear the canonical default percentage markup

#### Supplier Shipping Method Integration
- `GET /wp-json/digitalogic/integration/catalog` - Read the living pricing and shipping catalog
- `POST /wp-json/digitalogic/integration/pricing-assignments/batch` - Read a bounded ordered Code assignment projection
- `GET /wp-json/digitalogic/integration/products/by-code/{code}/pricing` - Read one exact sparse pricing assignment
- `GET|POST /wp-json/digitalogic/v1/shipping-methods` - List or create supplier shipping methods
- `GET|PUT|DELETE /wp-json/digitalogic/v1/shipping-methods/{id}` - Manage an immutable method ID
- `GET|PUT /wp-json/digitalogic/v1/products/by-code/{code}/shipping-method` - Read or assign by exact Patris Code
- `POST /wp-json/digitalogic/v1/products/shipping-methods/batch` - Preflight and apply Code-based assignments

The default markup is nullable, exact-decimal, and used only when both product
markup metadata rows are absent or both rows are stored empty. Saving it does
not write WooCommerce prices.

A supplier shipping method is distinct from customer delivery and WooCommerce
checkout shipping. See [Supplier Shipping Method API](docs/SHIPPING-METHOD-API.md).

#### Patris Product Sync
- `POST /wp-json/digitalogic/patris/product-sync` - Accept a verified, transformed-only snapshot or update

The receiver uses a dedicated header-only secret, independently recomputes
`landed_price`, verifies record/source/event hashes, merges updates, and
keeps transient Woo failures in a durable idempotent outbox. Missing and
ambiguous Codes are bounded terminal reconciliation work, so they do not cause
Patris HTTP retries. Database prepare/query failures remain transient and can
never be reported as missing. Patris Code is canonical and deleted Codes are
receiver-state tombstones, never WooCommerce deletions. Inspect nonsecret counts
with `wp digitalogic product-sync status`; an administrator can retry only
durable pending/deferred work with `wp digitalogic product-sync reconcile
--user=<administrator>`. See [Patris Product Sync](docs/PATRIS-PRODUCT-SYNC.md).

The authenticated **Digitalogic → Patris Reports** page and
`GET /wp-json/digitalogic/reports` compare that same current receiver state
with WooCommerce. They match the exact product Code metadata only, exclude
variable parents, preserve missing-versus-explicit-null source values, and
report source-only, positive-stock source-only, WooCommerce-only, source
warnings, and price, stock-management, availability, weight, timestamp, and
record-hash drift. Every response is paginated and bounded. An administrator
can validate and compare a reviewed static canonical file stored outside the
webroot without changing receiver or WooCommerce state with
`wp digitalogic patris inspect --file=/srv/digitalogic-private/kala.json
--user=<administrator>`. Applying that file is a separate, explicit
`wp digitalogic patris ingest ... --yes` operation. See
[Current Patris Report](docs/CURRENT-PATRIS-REPORT.md).

Positive-stock products that do not yet exist in WooCommerce can be created or
explicitly adopted with the dry-run-first, administrator-reviewed catalog
materializer. It adds Persian enrichment, taxonomy and SEO metadata without
guessing product ownership or creating new variable families. See
[Patris Catalog Materializer](docs/PATRIS-CATALOG-MATERIALIZER.md).

#### Export
- `GET /wp-json/digitalogic/v1/export?format=csv` - Export products as CSV
- `GET /wp-json/digitalogic/v1/export?format=json` - Export products as JSON
- `GET /wp-json/digitalogic/v1/export?format=excel` - Export products as Excel (XLSX) with custom template

### Authentication

Interactive customers use the canonical same-origin `/login/` flow. Safe GET
requests to `wp-login.php` retain their supported action and redirect arguments
while redirecting to that route; login POSTs still terminate in WordPress so
WordPress and Digits remain the only authentication authorities. The first
field accepts username, e-mail, or mobile, normalizes Persian/Arabic digits for
machine values, and keeps registration in the configured Digits OTP flow.

The branding layer never disables a native Digits captcha. When a configured
Digits reCAPTCHA site key is available it prepares that supported replacement;
otherwise the native challenge remains visible, required, and submitted by
Digits. OAuth values, service credentials, and account-linking rules remain
outside the repository and are not copied into the client configuration.

The plugin uses WooCommerce REST API authentication:

1. Go to WooCommerce → Settings → Advanced → REST API
2. Create API keys (consumer key & secret)
3. Use Basic Auth with consumer key as username and secret as password

Patris pricing uses a separate one-way, rotatable Bearer credential confined to
`GET /digitalogic/integration/catalog` and
`POST /digitalogic/integration/pricing-assignments/batch`. See
[Patris pricing-input machine credential](docs/PRICING-INPUT-CREDENTIAL.md) for
administrator-only WP-CLI lifecycle commands and environment-name wiring. Do
not reuse write, webhook, product-sync, WooCommerce consumer, or login secrets
for this machine identity.

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
- `patris.product_sync.applied` - Safe committed Patris sync summary for optional observers

The Patris observer event uses the same HMAC signature and destination fan-out.
It contains bounded aggregate counts only. n8n can route it to alerts or audit
copies, but direct Patris delivery remains authoritative and does not depend on
a configured webhook destination.

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
wp digitalogic products get --sku=001230 --format=json
wp digitalogic products metadata --id=123 --format=json
wp digitalogic products update --sku=001230 --set-sku=001231

# Import/Export
wp digitalogic export --format=csv --output=/path/to/export.csv
wp digitalogic export --format=json --output=/path/to/export.json
wp digitalogic export --format=excel --output=/path/to/export.xlsx
wp digitalogic export --format=excel --locale=bilingual --template --output=/path/to/products-template.xlsx
wp digitalogic import /path/to/products.csv
wp digitalogic import /path/to/products.json
wp digitalogic import /path/to/products.xlsx

# Google Sheets-ready catalog pages
wp digitalogic google-sheets catalog --dataset=products --locale=bilingual --page=1 --limit=100

# Logs
wp digitalogic logs --limit=50 --action=update_product
```

See [WP-CLI product commands](docs/CLI.md) for exact ID/SKU selection,
diagnostic output, permissions, and update semantics.

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
- `package-release.yml` - Audited deterministic package workflow and GitHub release publication

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

- `dollar_price` - USD exchange rate
- `yuan_price` - CNY exchange rate
- `update_date` - Last update date (YYMMDD format)

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
3. Create `.po` files for your language
4. Place `.po` files in `languages/` directory

**Note:** `.mo` (Machine Object) files are automatically built during CI/CD packaging from `.po` (Portable Object) source files. They are not committed to the repository.

To manually build `.mo` files for local development:
```bash
# Install WP-CLI if not already installed
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp

# Build .mo files from .po files
wp i18n make-mo languages/
```

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

### 1.3.1
- Restored batched, read-only WooCommerce minimum and maximum price ranges in the configurable product grid while retaining editable regular and sale prices.

### 1.3.0
- Added exact ID/SKU product access and variation-aware metadata diagnostics across REST, WP-CLI, and WordPress admin.
- Added safe per-product lookup refresh without a hidden catalog-wide fallback on older WooCommerce versions.
- Added server-side product-table filtering and persistent views, native currency postboxes, IRT readiness monitoring, and locale-aware number formatting.
- Refreshed Persian translation catalogs and retained backward-compatible CLI update behavior.

### 1.2.0
- Added the authenticated, transformed-only `patris.product-sync` receiver with deterministic integrity and ordering checks.
- Added snapshot/delta merging, bounded replay protection, quarantine preservation, and non-destructive Code tombstones.
- Added exact receiver-side landed-price verification, a durable per-product Woo delivery outbox, and a separate source-scopeable header secret.
- Reused the exact collision-safe Patris Code resolver and normalized Patris WooCommerce writer.

### 1.1.0
- Added the canonical supplier shipping-method catalog, exact Patris Code assignments, landed-price contract, and Patris integration API.
- Normalized Patris gram weights into the WooCommerce store weight unit.

### 1.0.0
- Initial release
- Multi-currency support (USD, CNY)
- Product management with DataTables
- Dynamic pricing engine
- REST API endpoints
- Webhooks integration
- WP-CLI commands
- Import/Export (CSV, JSON, Excel with custom template)
- Activity logging
- Light/dark mode support
- RTL/LTR support
- i18n support (Persian/English)
