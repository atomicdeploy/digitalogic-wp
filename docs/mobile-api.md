# Digitalogic mobile storefront

The Android storefront reads an unversioned Laravel API at `/app-api`. The existing `digitalogic-wp` plugin owns the public transport, Laravel container, product projection and merchandising settings. WordPress remains the identity authority and WooCommerce remains the commerce authority.

## Read endpoints

| Route | Payload |
| --- | --- |
| `GET /app-api/bootstrap` | Site identity, supported capabilities, endpoint discovery, categories, featured products, collections and configured carousels |
| `GET /app-api/categories` | `{items, generated_at}` containing visible product categories |
| `GET /app-api/products` | `{items, pagination, generated_at}` |
| `GET /app-api/products/{id}` | Public product detail, staged gallery images, selectable variations, related IDs and reviewed documents |

Catalog routes allow GET and HEAD. Unknown paths return 404; unsupported methods return 405. The public entry point cannot dispatch the internal panel routes. Password-protected, draft and hidden products are excluded. The indexed WooCommerce lookup selects IDs; the shared WordPress/WooCommerce runtime supplies public identity and price values without HTTP fan-out.

Catalog filters: `search` (title or exact/partial SKU, at most 120 characters), `category` (ID including descendants), `page` (1–500), `per_page` (1–48), `orderby` (`date`, `title`, `price`, `popularity`), `order` (`ASC` or `DESC`), and `stock_status` (`instock`, `outofstock`, `onbackorder`). Pagination has `page`, `per_page`, `has_more`, `next_page`, and `total`. A filtered empty page is valid data.

Product fields follow Woo Store API naming where possible: `id`, `parent_id`, `sku`, `name`, `type`, `permalink`, `short_description`, `description`, `prices`, stock and purchase state, `images`, `attributes`, `categories`, `variations`, ratings and `modified_at`. `prices.price` is a decimal integer string expressed in the **explicit** `currency_code` and `currency_minor_unit`. Missing prices remain null. Never infer a currency conversion or use product names as SKU identity.

Each image exposes `thumbnail`, `medium`, `src` and `srcset`. Load the thumbnail immediately and fetch medium/full only when needed. PDP variations include their own ID, SKU, attributes, price, stock and purchase state. Add the selected variation ID to the WooCommerce cart; never add a variable parent without a selection.

## Freshness and offline behavior

Public responses use the existing object cache with a 60-second bound, catalog projection generation in the cache key, ETags and conditional GET. Authenticated responses are private and not stored in the shared cache. HTTP public caching lasts 15 seconds with a 45-second revalidation window. A stale local price is informational; checkout must recalculate online. Public SSE and freshness URLs come from bootstrap. Private notifications and a mobile WebSocket gateway are separate capabilities and remain false until verified.

## Shopping and authentication

Bootstrap discovers the official Woo Store API cart URL. Use its `Cart-Token` response header for anonymous native carts and keep it in Android secure storage. It is a bearer capability for that cart, not an account or panel credential. Do not disable nonce checking, embed WooCommerce consumer secrets, write orders directly in SQL, or accept client prices.

Native login, OTP verification, Google OAuth and native checkout require separate verified integration. The existing `/panel/` browser session/capability boundary is unchanged. No mobile bearer token is exchanged for a panel session. Browser checkout preserves the current guest login gate. Do not present unsupported native features as complete and never submit offline orders.

### Native cart continuation

`POST /app-api/checkout` accepts JSON `{items:[{id:10698,quantity:1}]}`. It accepts only integer IDs and quantities, merges duplicate IDs, caps 40 rows/999 quantity, rejects client prices, and revalidates published products/variants, visibility, stock and purchasing rules. It returns `201 {url, review_url, expires_at, expires_in:300, item_count}`. Both URLs point to the same-origin review page with a signed cart intent in the URL fragment, which does not reach HTTP request logs. Do not log or persist that URL.

The Persian review page clears the fragment from browser history, previews exact products and asks the customer to confirm replacing this browser's cart. Confirmation calls `/app-api/checkout/redeem` with the signed intent, a WordPress nonce and a strict same-origin header. An atomic unique database claim rejects concurrent or repeated redemption. WooCommerce revalidates every item and calculates totals; rejected additions restore the previous cart. The endpoint returns the existing checkout URL and never creates an order, logs in a user, or charges a payment. Expired intents require returning to the app. Anonymous create requests are limited to 12 per transport IP/minute and 120 globally/minute using keyed IP hashes.

Live acceptance on 2026-11-06: create 201, preview 200, redeem 200, independent Woo Store API readback of one selected item, replay 409, and cleanup readback of zero items. No login, OTP, order or payment was created. Catalog conditional GET returned 304 with zero body after preserving ETags through WP Rocket and reversing WordPress header magic quoting.

The current PCB and foreign-import forms remain available through the discovered website URLs. Their protected uploads and request ownership must be preserved when native adapters are added.

## Merchandising

WooCommerce → **اپ دیجیتالاجیک** lets a `manage_woocommerce` user configure up to six carousel cards. Each has a title, HTTPS image and same-store destination. Empty rows remain hidden. The signed release manifest URL is separately configurable and is accepted only after a same-origin HTTPS manifest is available with version/APK/hash fields. The app reads these at runtime; no mobile release is needed to change the cards. Product export is disabled by policy; telemetry requires explicit opt-in.

## Completion boundary

This change delivers the public catalog, runtime configuration and verified cart continuation. Account session enrollment, OTP app hash, OAuth client registration, cloud favorites/outbox, scoped uploads, admin mobile capabilities, push registration and native order completion are tracked follow-up work. Capability flags stay false until the actual downstream path is tested.

Tracking: [backend issue 437](https://github.com/atomicdeploy/digitalogic-wp/issues/437), [Android delivery ledger](https://github.com/atomicdeploy/digitalogic-app/issues/1), [Android mirror](https://git.digitalogic.ir/root/digitalogic-app).

References: [WooCommerce Cart Tokens](https://developer.woocommerce.com/docs/apis/store-api/cart-tokens), [Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart/), [Laravel 13 responses](https://laravel.com/framework/docs/13.x/responses).
