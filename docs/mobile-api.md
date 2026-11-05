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

Public responses use the existing object cache with a 60-second bound, catalog projection generation in the cache key, ETags and conditional GET. Authenticated responses are private and not stored in the shared cache. HTTP public caching lasts 15 seconds with a 45-second revalidation window. A stale local price is informational; checkout must recalculate online. Public SSE and freshness URLs come from bootstrap. The verified self-hosted WebSocket transport supplies scoped notifications; device push remains a separate capability.

### Self-hosted realtime

Bootstrap now advertises `endpoints.websocket` and `realtime.protocol=digitalogic.shopper`. Connect to the WSS URL with that subprotocol, optional native `Authorization: Bearer …` header and optional numeric `Last-Event-ID`. Never put native tokens in URLs. Guests omit Authorization. This principal is read-only, even for a customer whose WordPress account also has administrative capabilities. It accepts only `ping`; panel and generic commands are rejected.

Hello has `event:connected` and data containing `principal`, `user_id`, `cursor`, `oldest_event_id`, `latest_event_id`, `cursor_reset_required`. Event envelopes have `id,event,name,time,data`. Catalog data uses the same strict public projector as SSE. Owner-only profile/order changes are reduced to invalidations; explicitly addressed notifications use the existing validated notification projection. Revoked, expired or password-invalidated sessions close within 30 seconds. Reconnect using the last received cursor; when reset is required, refresh current data because history is bounded.

Send a ping every 25 seconds and reconnect with jitter/backoff starting at 3 seconds. Keep the connection while Android permits the process to run; background process suspension requires catchup on resume. Firebase is not required for catalog, OTP, local drafts or this transport. Public SSE remains the fallback. Live WSS acceptance verified protocol negotiation, 105 public replay events, ping/pong and generic-command denial without creating notifications or customer activity.

## Shopping and authentication

Bootstrap discovers the official Woo Store API cart URL. Use its `Cart-Token` response header for anonymous native carts and keep it in Android secure storage. It is a bearer capability for that cart, not an account or panel credential. Do not disable nonce checking, embed WooCommerce consumer secrets, write orders directly in SQL, or accept client prices.

Native account sessions are separate from WordPress browser sessions. The existing `/panel/` browser session/capability boundary is unchanged. No mobile bearer token is exchanged for a panel session. Native checkout requires an existing authenticated WordPress customer; browsing and cart creation remain public. Never submit offline orders.

### Native identity and account data

| Endpoint | Contract |
| --- | --- |
| `POST /app-api/auth/otp/request` | `{phone,request_id}` → `202 {challenge_id,retry_after,expires_in,expires_at,delivery_status}` |
| `POST /app-api/auth/otp/verify` | `{challenge_id,code,device_name}` → session or registration continuation |
| `POST /app-api/auth/google` | `{id_token,device_name}` → the same session response |
| `GET/PUT /app-api/account/profile` | `{profile}`; PUT allows first_name, last_name, display_name and allowlisted billing fields |
| `GET /app-api/account/orders?page=1` | Owner-only items and pagination, 20 per page |
| `GET /app-api/auth/sessions` | Owner-only device names, creation/last-use/expiry and current-session marker |
| `POST /app-api/auth/logout` | Optional session_id; default current session, always owner scoped |
| `GET/PUT /app-api/account/favorites` | `{items:[product IDs],revision}`; PUT requires base_revision, conflicts return 409/current data |

A successful enrollment returns `{registered:true,access_token,token_type:"Bearer",session_id,expires_at,profile}`. A verified identity without an existing account binding returns `{registered:false,signup_url}` and creates no account. Native bearer tokens are 256-bit random values; only SHA256 hashes persist server-side. Sessions expire after 30 days and password changes invalidate them. Store tokens only in Android secure storage. Customer endpoints are private/no-store and never accept another customer's ID as an authority.

OTP accepts Persian/Arabic digits and normalizes Iranian mobile numbers. Challenge proofs are keyed hashes, expire after 5 minutes, permit at most 5 attempts and have an atomic one-use claim. A stable request_id prevents automatic resends after uncertain delivery. Limits apply globally, per transport IP and per phone. Existing server-local SMS.ir template configuration and Shatel interception are reused; no secrets enter the app. `delivery_status=accepted` is provider acceptance, not proof of handset receipt; `pending` is uncertain and must not be automatically retried. Existing Digits phone identity metadata is the account authority, never a billing phone field.

Google verification uses a pinned, namespace-scoped Firebase JWT 7.2.1 dependency, Google HTTPS JWKs/cache lifetime, RS256 signatures and strict issuer, audience, presenter, expiry, issued-time and verified-email checks. It maps only the existing Digits `auth_google_id` subject association, never email alone. The web audience is the public `auth.google_server_client_id`; client secrets remain server-local. Unknown/unlinked identities continue through website registration or phone login. A verified token creates at most one native session.

On 2026-11-06, 24 focused tests/139 assertions passed. Live no-send sandbox verified OTP expiry/replay/attempt limits and unknown-account continuation, then cleaned fixtures. Public HTTP readback rejects unauthenticated profile/orders/favorites/sessions, invalid OTPs and forged Google tokens. Google cert retrieval and runtime library isolation were verified. Real handset SMS delivery and real Google account enrollment remain interactive acceptance checks; no human messages, new users, orders or payments were created by these tests. SMS Retriever template hash is not configured, so otp_autofill remains false.

### One WooCommerce cart, native checkout

The former `/app-checkout/` page and checkout preview/redeem handoff are removed. The app stays native. The existing website checkout remains the canonical website flow.

`POST /app-api/cart` creates a guest WooCommerce session behind an opaque mobile token. `GET /cart` reconciles the installed Woo session/persistent customer cart and returns `{cart_token,revision,items:[{id,product,quantity,issues}],checkout_ready,owner}`. Account carts use the existing numeric WordPress customer session key and `_woocommerce_persistent_cart_{blogId}`. Mobile tables hold only token, revision, operation records and a reconciled projection. They are not a second shopping authority.

`PATCH /cart {operation_id,base_revision,items:[{id,quantity,base_quantity}]}` applies touched lines through Woo cart validation/actions. Different-item edits merge; conflicting same-item edits return409 with current cart and three-way quantities. The same immutable UUID/payload replays without another addition. `POST /cart/claim {operation_id}` merges a guest into the authenticated owner's Woo cart and retargets its token. Changed stock or unsupported custom line options require review. No client price is accepted. `DELETE /cart` discards a guest session only.

`GET /app-api/checkout` returns Woo-derived `schema:{billing,shipping}`, profile, shipping_packages, payment_methods, totals, currency and terms. Keys in field descriptors are unprefixed. Money is an integer string in currency minor units. `POST /checkout/quote {cart_revision,billing,shipping,ship_to_different_address,shipping_methods,payment_method,coupons,customer_note}` returns the same presentation and validation issues. Only a valid complete quote gets a quote_id and ISO expires_at (10 minutes). `shipping_methods` maps package IDs to exact Woo rate IDs.

`POST /checkout {operation_id,quote_id,terms_accepted}` performs explicit order submission. `GET /checkout/operations/{UUID}` recovers its result. A durable UUID tombstone with `operation_status:rejected` proves no order was created; unknown404/processing202 never does. Price/stock/totals/shipping are recalculated and compared before invoking the installed WC_Checkout customer/order engine and hooks. Actual order/payment state remains in WooCommerce. BACS is the initial audited adapter; Zarinpal is withheld until its contained WebView callback path is audited. The native session never becomes a WordPress browser or panel login.

Acceptance 2026-11-06: an app-side guest addition appeared in the actual existing website cart HTML; posting that website's own quantity form changed the quantity observed by mobile. Canonical sessions and fixtures were cleaned. Public API replay/conflict/client-price checks passed. No-order checkout sandbox returned 12 billing fields,10 shipping fields,4 Tehran rates,BACS and stable exact IRT totals, with durable rejection recovery. No real account/order/payment was created. Account cross-device acceptance and explicit final real order remain pending interactive testing. The earlier isolated cart transport probe did not prove shared-store acceptance; this adapter corrects that implementation gap.

### Native PCB and import requests

`GET /app-api/forms` returns descriptors for `pcb` and `foreign`, each with a revision, field labels/types/options/bounds, file restrictions and conditional rules. The native screen uses the same posted field names and canonical validation as the website. PCB needs a completed Gerber archive; import needs at least one named/identified/linked item or a completed BOM file. The consent field must be `yes`. No price or production promise is inferred from a request.

Authenticated customers create an upload with `POST /app-api/uploads {request_id,form_type,filename,size,sha256}`. Files are limited to 20 MiB and stored outside the web root. The response has `upload_id,offset,size,chunk_size,state,expires_at`; the chunk limit is 512 KiB. Continue with `PUT /app-api/uploads/{id}`, `application/octet-stream`, numeric `Upload-Offset`, and optional matching `Content-Range`. Pause by stopping chunks. Resume with GET, which also recovers a final chunk written before a process exit. Identical retries are safe; conflicting bytes return 409 with the current offset. State becomes `complete` only after the full SHA256, file signature and database update succeed. DELETE cancels an unsubmitted upload, including an expired one. Owner checks, row locks, quota reservation, 24-hour expiry and bounded cleanup protect storage.

`POST /app-api/requests {request_id,form_type,fields,upload_id?,schema_revision?}` creates one canonical private `digitalogic_request`. The UUID and normalized payload are bound transactionally: an identical retry returns the saved result, while changed content with the same UUID returns `request_id_conflict`. `GET /app-api/requests/{request_id}` resolves an uncertain response. Successful responses contain `{request:{id,code,type,status,created_at}}`. `GET /app-api/account/requests?page=1` lists only this customer's requests, 20 per page. Status comes from the existing admin workflow: new, reviewing, quoted, accepted, completed or cancelled.

Native admin notifications are deferred through the existing Action Scheduler. A one-use delivery claim prevents uncertain mail outcomes from causing duplicate messages. The existing signed webhook transport receives `storefront.request.created` with identifiers/type/status only; it does not expose private file paths or customer contact details. Downstream n8n workflow handling remains a separate acceptance check.

Live disposable upload fixtures verified first-chunk progress, pause/resume, digest completion, identical replay, conflicting-byte rejection, cross-owner denial, interrupted final-state recovery, bad-digest removal, expired cancellation and cleanup. All fixture files/rows were removed. No real request, email, order or payment was submitted during verification.

## Merchandising

WooCommerce → **اپ دیجیتالاجیک** lets a `manage_woocommerce` user configure up to six carousel cards. Each has a title, HTTPS image and same-store destination. Empty rows remain hidden. The signed release manifest URL is separately configurable and is accepted only after a same-origin HTTPS manifest is available with version/APK/hash fields. The app reads these at runtime; no mobile release is needed to change the cards. Product export is disabled by policy; telemetry requires explicit opt-in.

## Completion boundary

This change delivers the public catalog, runtime configuration, verified cart continuation and native account/session/favorites contracts. OTP app hash, cloud drafts, scoped uploads, admin mobile capabilities, push registration and native order completion remain tracked follow-up work. Capability flags stay false until the corresponding backend path is available and verified.

Tracking: [backend issue 437](https://github.com/atomicdeploy/digitalogic-wp/issues/437), [Android delivery ledger](https://github.com/atomicdeploy/digitalogic-app/issues/1), [Android mirror](https://git.digitalogic.ir/root/digitalogic-app).

References: [WooCommerce Cart Tokens](https://developer.woocommerce.com/docs/apis/store-api/cart-tokens), [Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart/), [Laravel 13 responses](https://laravel.com/framework/docs/13.x/responses).
