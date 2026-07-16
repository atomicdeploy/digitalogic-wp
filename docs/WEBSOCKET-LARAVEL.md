# WebSocket and Laravel Panel Notes

## WordPress AJAX Model

WordPress AJAX uses `admin-ajax.php` with an `action` field. The plugin registers handlers with hooks like `wp_ajax_digitalogic_get_products`, validates a nonce, checks capabilities, runs PHP code, and returns JSON.

The WebSocket implementation keeps those command names but moves the transport from repeated HTTP POSTs to a persistent socket. A browser sends:

```json
{"id":"req_1","command":"digitalogic_get_products","data":{"page":1,"limit":50}}
```

The server responds:

```json
{"id":"req_1","event":"response","success":true,"data":{"products":[],"total":0}}
```

## Running the Server

The server is a WP-CLI process that boots WordPress, validates the logged-in admin cookie plus a WebSocket nonce, and dispatches commands through `Digitalogic_Command_Dispatcher`.

```bash
cd /var/www/wp
wp digitalogic websocket serve --host=127.0.0.1 --port=8090 --allow-root
```

Run it under `systemd` or Supervisor in production. Keep it bound to `127.0.0.1` and expose it through Apache at `/wordpress-ws`.

Panel updates are first stored in the bounded WordPress event queue with a
strictly increasing ID, then the same envelope is published to Redis. Product,
currency, user, and toast events therefore share one delivery path. The browser
keeps polling the stored queue every five seconds, so Redis or WebSocket downtime
does not remove the manual/polling fallback.

The default Redis endpoint is `127.0.0.1:6379`, database unset, channel
`digitalogic_panel_events`. Deployments that need authentication, a different
database, or a different endpoint can configure the server-side filter below.
The password is never included in browser configuration or logs.

```php
add_filter('digitalogic_panel_redis_config', function ($config) {
    $config['host'] = '127.0.0.1';
    $config['port'] = 6379;
    $config['timeout'] = 0.5;
    $config['password'] = getenv('DIGITALOGIC_REDIS_PASSWORD') ?: '';
    $config['database'] = 2;
    $config['channel'] = 'digitalogic_panel_events';
    return $config;
});
```

The WP-CLI process only marks Redis healthy after successful connection and
validated `AUTH`, `SELECT`, and `SUBSCRIBE` replies. Failed setup or a dropped
subscription is retried while clients continue using the queue-backed fallback.

## Apache

```apache
ProxyPass /wordpress-ws ws://127.0.0.1:8090/
ProxyPassReverse /wordpress-ws ws://127.0.0.1:8090/
```

Systemd service:

```ini
[Unit]
Description=Digitalogic WordPress WebSocket command server
After=network.target mariadb.service apache2.service

[Service]
Type=simple
WorkingDirectory=/var/www/wp
ExecStart=/usr/local/bin/wp digitalogic websocket serve --host=127.0.0.1 --port=8090 --allow-root
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`websocat` verification:

```bash
token="$(wp digitalogic websocket token --allow-root)"
printf '%s\n' '{"id":"1","command":"digitalogic_get_products","data":{"limit":1}}' \
  | websocat "wss://digitalogic.ir/wordpress-ws?token=${token}"
```

## External Plugin Commands

Plugins can add commands without depending on Digitalogic admin pages:

```php
add_filter('digitalogic_command_handlers', function ($commands, $transport) {
    $commands['vendor_plugin_command'] = function (array $payload) {
        return array('received' => $payload);
    };

    return $commands;
}, 10, 2);
```

The plugin also loads an admin-wide jQuery AJAX proxy for users who can manage
WooCommerce. When a request is a regular same-origin `admin-ajax.php` POST with
an `action`, the proxy attempts WebSocket first and falls back to the original
HTTP request if the socket is unavailable or rejects the request.

It intentionally skips file uploads/FormData, selected core actions like
`heartbeat`, and requests that set:

```js
$.ajax({
    url: ajaxurl,
    type: 'POST',
    digitalogicWebSocket: false,
    data: {action: 'plugin_action'}
});
```

Unknown socket commands are routed to `wp_ajax_{action}` if WordPress has a
registered authenticated AJAX handler:

```php
add_action('wp_ajax_vendor_plugin_action', function () {
    check_ajax_referer('vendor_nonce', 'nonce');
    wp_send_json_success(array('ok' => true));
});
```

Use this filter for an allow list or block list:

```php
add_filter('digitalogic_websocket_ajax_action_allowed', function ($allowed, $action) {
    return $action !== 'sensitive_plugin_action';
}, 10, 2);
```

## Laravel Panel

Laravel should call the token-authenticated panel bridge for server-side product reads and updates:

```bash
wp digitalogic panel token --allow-root
```

```php
use Illuminate\Support\Facades\Http;

$base = 'https://digitalogic.ir/wp-json/digitalogic-panel/v1';
$token = config('services.digitalogic.token');

$products = Http::withHeaders([
    'X-Digitalogic-Panel-Token' => $token,
])->get($base . '/products', [
    'page' => 1,
    'limit' => 50,
])->json();

Http::withHeaders([
    'X-Digitalogic-Panel-Token' => $token,
])->patch($base . '/products/123', [
    'regular_price' => '275000',
    'stock_quantity' => 75,
]);
```

The command endpoint can also call registered `wp_ajax_{action}` handlers, which
keeps Laravel and WordPress interoperable without duplicating WooCommerce
business logic in both applications.

For command parity with WebSocket/AJAX, Laravel can call:

```php
Http::withHeaders([
    'X-Digitalogic-Panel-Token' => $token,
])->post($base . '/commands', [
    'command' => 'digitalogic_get_products',
    'data' => ['page' => 1, 'limit' => 50],
]);
```
