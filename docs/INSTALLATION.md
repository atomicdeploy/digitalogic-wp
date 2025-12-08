# Installation Guide

## Requirements

- PHP 8.0 or higher
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- MySQL 5.7 or higher
- SSL certificate (recommended for production)

## Installation Methods

### Method 1: WordPress Admin (Recommended for Non-Technical Users)

1. Download the latest release from [GitHub Releases](https://github.com/atomicdeploy/digitalogic-wp/releases)
2. Log in to your WordPress admin panel
3. Navigate to **Plugins** → **Add New** → **Upload Plugin**
4. Click **Choose File** and select the downloaded ZIP file
5. Click **Install Now**
6. After installation completes, click **Activate Plugin**

### Method 2: WP-CLI (Recommended for Developers)

```bash
# Install from local file
wp plugin install /path/to/digitalogic-wp.zip --activate

# Or install from URL
wp plugin install https://github.com/atomicdeploy/digitalogic-wp/releases/latest/download/digitalogic-wp.zip --activate
```

### Method 3: Manual Installation (Advanced)

1. Download and extract the plugin
2. Upload the `digitalogic` folder to `/wp-content/plugins/`
3. SSH into your server and navigate to the plugin directory:
   ```bash
   cd /path/to/wp-content/plugins/digitalogic
   composer install --no-dev --optimize-autoloader
   ```
4. Activate the plugin:
   - Via WordPress Admin: **Plugins** → **Installed Plugins** → **Activate**
   - Via WP-CLI: `wp plugin activate digitalogic`

### Method 4: Git Clone (For Development)

```bash
cd /path/to/wp-content/plugins/
git clone https://github.com/atomicdeploy/digitalogic-wp.git digitalogic
cd digitalogic
composer install
wp plugin activate digitalogic
```

## Post-Installation Setup

### 1. Verify Installation

Navigate to **Digitalogic** in your WordPress admin menu. You should see the dashboard.

### 2. Configure Currency Rates

1. Go to **Digitalogic** → **Currency**
2. Set your currency exchange rates:
   - USD Price: Enter the local currency value for 1 USD
   - CNY Price: Enter the local currency value for 1 CNY
3. Click **Update Currency Rates**

### 3. Set Up API Access (Optional)

For external integrations:

1. Go to **WooCommerce** → **Settings** → **Advanced** → **REST API**
2. Click **Add Key**
3. Configure:
   - Description: `Digitalogic API`
   - User: Select admin user
   - Permissions: Read/Write
4. Click **Generate API Key**
5. Save the Consumer Key and Consumer Secret securely

### 4. Configure Webhooks (Optional)

For real-time notifications to external systems:

```php
// Add to wp-config.php or theme's functions.php
update_option('digitalogic_webhook_urls', [
    'https://your-pos-system.com/webhook',
    'https://your-accounting-system.com/webhook'
]);
update_option('digitalogic_webhook_secret', 'generate-a-secure-random-string');
```

Or use WP-CLI:
```bash
wp option update digitalogic_webhook_urls '["https://your-system.com/webhook"]' --format=json
wp option update digitalogic_webhook_secret 'your-secure-secret'
```

## Troubleshooting

### Plugin Doesn't Appear in Menu

**Solution:** Ensure WooCommerce is installed and activated. Digitalogic requires WooCommerce to function.

```bash
# Check if WooCommerce is active
wp plugin list --status=active

# If not active, activate it
wp plugin activate woocommerce
```

### Database Tables Not Created

**Solution:** Deactivate and reactivate the plugin:

```bash
wp plugin deactivate digitalogic
wp plugin activate digitalogic
```

### Permission Errors

**Solution:** Ensure your user has `manage_woocommerce` capability:

```bash
wp user add-cap admin_username manage_woocommerce
```

### Composer Dependencies Missing

**Solution:** Install dependencies:

```bash
cd /path/to/wp-content/plugins/digitalogic
composer install --no-dev --optimize-autoloader
```

### 404 Errors on API Endpoints

**Solution:** Flush rewrite rules:

```bash
wp rewrite flush
```

Or via WordPress Admin:
Settings → Permalinks → Save Changes (no changes needed, just save)

## Upgrading

### Via WordPress Admin

1. Download the new version
2. Go to **Plugins** → **Installed Plugins**
3. Deactivate Digitalogic
4. Delete the old version
5. Upload and activate the new version

### Via WP-CLI

```bash
wp plugin update digitalogic --version=1.1.0
```

### Via Git (Development)

```bash
cd /path/to/wp-content/plugins/digitalogic
git pull origin main
composer install --no-dev --optimize-autoloader
wp plugin deactivate digitalogic && wp plugin activate digitalogic
```

## Uninstallation

### Complete Removal

1. Deactivate the plugin
2. Delete from WordPress Admin or via WP-CLI:
   ```bash
   wp plugin uninstall digitalogic --deactivate
   ```

### Clean Database (Optional)

If you want to remove all plugin data:

```sql
-- Remove options
DELETE FROM wp_options WHERE option_name LIKE 'digitalogic_%';

-- Remove activity logs
DROP TABLE IF EXISTS wp_digitalogic_logs;

-- Remove product meta
DELETE FROM wp_postmeta WHERE meta_key LIKE '_digitalogic_%';
```

Or via WP-CLI:
```bash
wp db query "DELETE FROM wp_options WHERE option_name LIKE 'digitalogic_%'"
wp db query "DROP TABLE IF EXISTS wp_digitalogic_logs"
wp db query "DELETE FROM wp_postmeta WHERE meta_key LIKE '_digitalogic_%'"
```

## Next Steps

- Read the [API Documentation](API.md)
- Explore [WP-CLI Commands](../README.md#wp-cli-commands)
- Configure [Dynamic Pricing](../README.md#dynamic-pricing)
- Set up [Import/Export](../README.md#importexport)
