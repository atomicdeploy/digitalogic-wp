# Laravel Panel Interoperability

The Laravel panel should treat WordPress as the identity and WooCommerce data
source. Users enter the temporary in-site panel from WordPress after
`wp-login.php` through **Digitalogic > Panel**.

The reserved `panel.digitalogic.ir` host is not used by default. Until the
standalone platform rewrite is ready, the live panel route is:

```text
https://digitalogic.ir/panell/
```

## Authentication Flow

1. WordPress verifies the current admin session and capability.
2. WordPress creates a one-time handoff code valid for 120 seconds.
3. WordPress redirects to `/panell/?code=...` for the in-site panel, or to an
   external `/auth/wordpress` route only when a different panel URL is
   configured deliberately.
4. Laravel consumes the code with `POST /wp-json/digitalogic-panel/v1/session/consume`.
5. Laravel creates its own app session mapped to the returned WordPress user.

Laravel request:

```php
$base = 'https://digitalogic.ir/wp-json/digitalogic-panel/v1';

$session = Http::withHeaders([
    'X-Digitalogic-Panel-Token' => config('services.digitalogic.token'),
])->post($base . '/session/consume', [
    'code' => $request->query('code'),
])->throw()->json('data');
```

The response includes the WordPress user, selected capabilities, return target,
WordPress site URLs, cookie names/domain, and shared theme tokens.

## Minimal WordPress Bootstrap

For routes that need to validate WordPress cookies directly, Laravel should load
WordPress without rendering the front-end theme:

```php
define('WP_USE_THEMES', false);
require base_path('../wp/wp-load.php');
```

Avoid loading front-end routes or templates from Laravel. For high-frequency
panel actions, prefer the bridge endpoints and command dispatcher instead of
booting the full front-end request path. That avoids theme rendering, Elementor
frontend work, and unnecessary WooCommerce page hooks.

## Shared UI

Laravel should call `GET /wp-json/digitalogic-panel/v1/theme` with the panel
token and use those values for:

- logo and site icon
- RTL/LTR direction
- locale
- color tokens
- shared Apache UI assets under `https://digitalogic.ir/digitalogic-ui/`

The Laravel UI should not embed WordPress admin chrome. It should use the shared
Digitalogic identity while providing a denser operations interface for products,
pricing, inventory, users, logs, and panel-only workflows.

## Vue Panel Transport

The temporary `/panell/` panel is a Vue.js SPA with Persian/English labels,
RTL/LTR support, system/light/dark theme modes, and history-based routing for
paths such as `/panell/products/11307`.

It sends commands to `/wordpress-ws` first. If the socket is unavailable, it
falls back to authenticated `admin-ajax.php` through the same command dispatcher.

## Direct Laravel Loading

For direct WordPress-to-Laravel calls, configure the local app path with either:

- the `digitalogic_laravel_app_path` option
- the `digitalogic_laravel_app_path` filter

When configured, the bridge can boot `bootstrap/app.php` and invoke Laravel's
HTTP kernel without using a public vhost or reserved port.

Token-protected bridge endpoints:

- `GET /wp-json/digitalogic-panel/v1/laravel/status`
- `POST /wp-json/digitalogic-panel/v1/laravel/request`

Example request:

```json
{
  "method": "POST",
  "path": "/internal/products/sync",
  "data": {"limit": 50}
}
```

If no Laravel app path is configured, the status endpoint reports
`available: false` and request calls return `503`.
