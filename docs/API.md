# API Documentation

## REST API Reference

Base URL: `https://yoursite.com/wp-json/digitalogic/v1`

### Authentication

All management endpoints require an authenticated WordPress user with the
`manage_woocommerce` capability. This normally includes administrators and shop
managers, but excludes subscriber and customer accounts.

The API accepts WordPress cookie authentication with a REST nonce, WordPress
Application Passwords, and WooCommerce REST API consumer keys. A WooCommerce
consumer key must belong to a user with `manage_woocommerce`; its configured
read/write permission is also enforced for the HTTP method.

```bash
# Using Basic Auth
curl -u consumer_key:consumer_secret https://yoursite.com/wp-json/digitalogic/v1/products

# Or with Authorization header
curl -H "Authorization: Basic $(echo -n 'consumer_key:consumer_secret' | base64)" \
  https://yoursite.com/wp-json/digitalogic/v1/products
```

Use a `read` or `read/write` WooCommerce key for GET routes. POST and PUT routes
require a `write` or `read/write` key.

Routes use three explicit permission scopes:

- `read`: product and currency GET routes
- `write`: product/currency updates, recalculation, and Patris pull-sync
- `diagnostic`: reports and exports

Trusted integrations that do not authenticate as a WooCommerce manager can
continue using the legacy permission filter, but access is denied by default.
The callback must explicitly return the boolean `true` for only the scopes it
supports:

```php
add_filter(
    'digitalogic_rest_api_permission',
    function ($allowed, $scope, $request) {
        if (!my_integration_authenticates_request($request)) {
            return false;
        }

        return in_array($scope, array('read', 'write'), true);
    },
    10,
    3
);
```

A one-argument callback still grants all three general API scopes when it
returns `true`. New callbacks should accept the scope and request arguments for
least-privilege access.

---

## Products Endpoints

### Google Sheets catalog

`GET /wp-json/digitalogic/v1/google-sheets/catalog` returns a bounded,
read-only `products` or `categories` page for Google Apps Script, n8n, or
another client. It uses the normal read permission scope and supports
`dataset`, `locale`, `page`, and `limit` (maximum 100). See
[Google Sheets catalog synchronization](GOOGLE-SHEETS.md) for the schema,
credential storage, manual/scheduled refresh, and recovery guidance.

### List Products

**GET** `/products`

Query Parameters:
- `page` (int): Page number (default: 1)
- `limit` (int): Results per page (default: 50, max: 100)
- `search` (string): Search term
- `sku` (string): Filter by product code

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products?page=1&limit=20&search=arduino"
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Arduino Uno R3",
      "sku": "ARD-UNO-R3",
      "type": "simple",
      "regular_price": "250000",
      "stock_quantity": 50
    }
  ],
  "total": 1250,
  "page": 1,
  "limit": 20
}
```

---

### Update Product

**PUT** `/products/{id}`

**Request Body:**
```json
{
  "regular_price": 275000,
  "sale_price": 250000,
  "stock_quantity": 75
}
```

The same product can be read or updated by an exact, case-sensitive SKU:

- **GET** `/products/sku/{sku}`
- **PUT** `/products/sku/{sku}`

Numeric and leading-zero SKUs remain strings. Duplicate exact SKUs return HTTP
409 instead of selecting an arbitrary product or variation.

### Product Metadata Diagnostics

- **GET** `/products/{id}/metadata`
- **GET** `/products/sku/{sku}/metadata`

These routes use the `diagnostic` permission scope. They return effective
WooCommerce values, a whitelisted snapshot of current product post meta, the
expected raw lookup-source values, the matching derived
`wc_product_meta_lookup` row, and structured inconsistency records. This keeps
variation inheritance visible without comparing inherited display values to a
row WooCommerce builds from child post meta. Lookup data is never copied back
implicitly. On WooCommerce versions that expose the public per-product
data-store API, administrators can refresh one row from **Digitalogic → Product
Diagnostics**. Older supported versions refuse that row action and never fall
back to a catalog-wide rebuild.

---

### Bulk Update Products

**POST** `/products/batch`

**Request Body:**
```json
{
  "123": {"regular_price": 275000},
  "124": {"stock_quantity": 100}
}
```

---

## Currency Endpoints

### Get Currency Rates

**GET** `/currency`

The response includes the configured USD/CNY rates and a read-only
`woocommerce_base` status object. IRT is represented as Toman (10 IRR per
unit); a different WooCommerce base reports `base_currency_mismatch` rather
than being converted or changed automatically.

### Update Currency Rates

**POST** `/currency`

**Request Body:**
```json
{
  "dollar_price": 42500,
  "yuan_price": 6100
}
```

---

## Supplier Shipping Method Integration

A supplier shipping method describes supplier-to-Digitalogic transport. It does not use
WooCommerce checkout, shipping-zone, or customer delivery APIs.

- **GET** `/wp-json/digitalogic/integration/catalog` - sparse CNY/IRT rate, `landed_price`, selected warehouses, and methods
- **GET** `/shipping-methods` - list canonical methods
- **POST** `/shipping-methods` - create a method with an immutable ID
- **GET|PUT|DELETE** `/shipping-methods/{id}` - read, update, or delete an unassigned method
- **GET** `/wp-json/digitalogic/integration/products/by-code/{code}/pricing` - read one exact sparse pricing assignment
- **GET|PUT** `/products/by-code/{code}/shipping-method` - manage an assignment by exact Patris Code
- **POST** `/products/shipping-methods/batch` - preflight and apply an atomic assignment batch
- **POST** `/wp-json/digitalogic/integration/pricing-assignments/batch` - read up to 500 exact assignments in an ordered, no-write response

GET routes use the `read` permission scope. Mutating routes use `write`.
Deleting an assigned method returns HTTP 409; disabling it remains available.
Method and tier payloads use `shipping_price_per_kg_cny`; aliases are not
accepted or emitted. See [Supplier Shipping Method API](SHIPPING-METHOD-API.md)
and [Patris Product Sync](PATRIS-PRODUCT-SYNC.md).

---

## Webhooks

Webhook events:

- `shipping_method.created`
- `shipping_method.updated`
- `shipping_method.deleted`
- `shipping_method.assignment.updated`
- `product.created`
- `product.updated`
- `currency.updated`

See full documentation at: [README.md](../README.md)

---

## Shared Commands and WebSocket

Digitalogic admin commands are dispatched by `Digitalogic_Command_Dispatcher`, so the same command names can be called by AJAX, WebSocket, or trusted integrations.

Supported command names:
- `digitalogic_get_products`
- `digitalogic_get_product`
- `digitalogic_update_product`
- `digitalogic_bulk_update`
- `digitalogic_get_currency`
- `digitalogic_update_currency`
- `digitalogic_export`
- `digitalogic_get_logs`
- `digitalogic_get_integration_catalog`
- `digitalogic_list_shipping_methods`
- `digitalogic_create_shipping_method`
- `digitalogic_get_shipping_method`
- `digitalogic_update_shipping_method`
- `digitalogic_delete_shipping_method`
- `digitalogic_get_product_shipping_method`
- `digitalogic_assign_product_shipping_method`
- `digitalogic_batch_assign_product_shipping_methods`

Browser WebSocket request:
```json
{
  "id": "req_1",
  "command": "digitalogic_get_products",
  "data": {
    "page": 1,
    "limit": 50,
    "search": "arduino"
  }
}
```

WebSocket response:
```json
{
  "id": "req_1",
  "event": "response",
  "command": "digitalogic_get_products",
  "success": true,
  "data": {
    "products": [],
    "total": 0
  }
}
```

Run the WebSocket server with WP-CLI:
```bash
wp digitalogic websocket serve --host=127.0.0.1 --port=8090 --allow-root
```

Apache proxy used on Digitalogic:
```apache
ProxyPass /wordpress-ws ws://127.0.0.1:8090/
ProxyPassReverse /wordpress-ws ws://127.0.0.1:8090/
```

Production service:
```ini
[Service]
WorkingDirectory=/var/www/wp
ExecStart=/usr/local/bin/wp digitalogic websocket serve --host=127.0.0.1 --port=8090 --allow-root
Restart=always
RestartSec=5
```

Smoke test with `websocat`:
```bash
token="$(wp digitalogic websocket token --allow-root)"
printf '%s\n' '{"id":"1","command":"digitalogic_get_products","data":{"limit":1}}' \
  | websocat "wss://digitalogic.ir/wordpress-ws?token=${token}"
```

Other plugins can add commands:
```php
add_filter('digitalogic_command_handlers', function ($commands, $transport) {
    $commands['my_plugin_fast_command'] = function ($payload) {
        return array('ok' => true, 'payload' => $payload);
    };

    return $commands;
}, 10, 2);
```

The admin-wide AJAX proxy also reroutes ordinary authenticated `admin-ajax.php`
POST requests over WebSocket when possible. It skips uploads/FormData, selected
core actions such as `heartbeat`, and any request that opts out with
`digitalogicWebSocket: false` in the jQuery AJAX settings. Unknown WebSocket
commands fall through to existing `wp_ajax_{action}` callbacks when registered.

Server-side opt-out or allow-listing:
```php
add_filter('digitalogic_websocket_ajax_action_allowed', function ($allowed, $action, $payload, $transport) {
    if ($action === 'sensitive_plugin_action') {
        return false;
    }

    return $allowed;
}, 10, 4);
```

---

## Laravel Panel Bridge

Base URL: `https://yoursite.com/wp-json/digitalogic-panel/v1`

Get the bridge token:
```bash
wp digitalogic panel token --allow-root
```

Rotate the token:
```bash
wp digitalogic panel token --rotate --allow-root
```

Laravel request example:
```php
$response = Http::withHeaders([
    'X-Digitalogic-Panel-Token' => config('services.digitalogic.token'),
])->get('https://digitalogic.ir/wp-json/digitalogic-panel/v1/products', [
    'page' => 1,
    'limit' => 50,
]);
```

Panel endpoints:
- `GET /products`
- `GET /products/{id}`
- `PATCH /products/{id}`
- `POST /commands`
- `POST /session/consume`
- `GET /theme`
- `GET /laravel/status`
- `POST /laravel/request`

The `/commands` endpoint can call Digitalogic commands, custom
`digitalogic_command_handlers`, or registered `wp_ajax_{action}` callbacks. Use
this for WordPress/Laravel interoperability when the Laravel panel needs to
trigger the same server-side behavior that the WordPress admin already uses.

WordPress admins can enter the panel from **Digitalogic > Panel**. The launch
URL creates a short-lived, one-time handoff code and redirects to the configured
panel URL. By default, the temporary route is:

`https://digitalogic.ir/panel/?code=...`

Laravel consumes that code with the bridge token:
```php
$session = Http::withHeaders([
    'X-Digitalogic-Panel-Token' => config('services.digitalogic.token'),
])->post($base . '/session/consume', [
    'code' => $request->query('code'),
])->json();
```

The `GET /theme` endpoint exposes the shared Digitalogic visual identity,
including logo URLs, direction, locale, color tokens, and the `/digitalogic-ui/`
asset base.
