# API Documentation

## REST API Reference

Base URL: `https://yoursite.com/wp-json/digitalogic/v1`

### Authentication

All endpoints require authentication using WooCommerce REST API credentials:

```bash
# Using Basic Auth
curl -u consumer_key:consumer_secret https://yoursite.com/wp-json/digitalogic/v1/products

# Or with Authorization header
curl -H "Authorization: Basic $(echo -n 'consumer_key:consumer_secret' | base64)" \
  https://yoursite.com/wp-json/digitalogic/v1/products
```

---

## Products Endpoints

### List Products

**GET** `/products`

Query Parameters:
- `page` (int): Page number (default: 1)
- `limit` (int): Results per page (default: 50, max: 100)
- `search` (string): Search term
- `sku` (string): Filter by SKU

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

## Webhooks

Webhook events:
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

Recommended Nginx proxy:
```nginx
location /wordpress-ws {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header Cookie $http_cookie;
}
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

The `/commands` endpoint can call Digitalogic commands, custom
`digitalogic_command_handlers`, or registered `wp_ajax_{action}` callbacks. Use
this for WordPress/Laravel interoperability when the Laravel panel needs to
trigger the same server-side behavior that the WordPress admin already uses.
