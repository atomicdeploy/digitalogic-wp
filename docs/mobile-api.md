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

Catalog filters: `search` (title or exact/partial SKU, at most 120 characters), `category` (ID including descendants), `page` (1–500), `per_page` (1–48), `orderby` (`relevance`, `date`, `title`, `price`, `popularity`), `order` (`ASC` or `DESC`), and `stock_status` (`instock`, `outofstock`, `onbackorder`). Pagination has `page`, `per_page`, `has_more`, `next_page`, and `total`. A filtered empty page is valid data.

Product fields follow Woo Store API naming where possible: `id`, `parent_id`, `sku`, `name`, `type`, `permalink`, `short_description`, `description`, `prices`, stock and purchase state, `images`, `attributes`, `categories`, `variations`, ratings and `modified_at`. `prices.price` is a decimal integer string expressed in the **explicit** `currency_code` and `currency_minor_unit`. Missing prices remain null. Never infer a currency conversion or use product names as SKU identity.

Each image exposes `thumbnail`, `medium`, `src` and `srcset`. Load the thumbnail immediately and fetch medium/full only when needed. PDP variations include their own ID, SKU, attributes, price, stock and purchase state. Add the selected variation ID to the WooCommerce cart; never add a variable parent without a selection.

## Freshness and offline behavior

Public responses use the existing object cache with a 60-second bound, catalog projection generation in the cache key, ETags and conditional GET. Authenticated responses are private and not stored in the shared cache. HTTP public caching lasts 15 seconds with a 45-second revalidation window. A stale local price is informational; checkout must recalculate online. Public SSE and freshness URLs come from bootstrap. The verified self-hosted WebSocket transport supplies scoped notifications; device push remains a separate capability.

### Self-hosted realtime

Bootstrap now advertises `endpoints.websocket` and `realtime.protocol=digitalogic.shopper`. Connect to the WSS URL with that subprotocol, optional native `Authorization: Bearer …` header and optional numeric `Last-Event-ID`. Never put native tokens in URLs. Guests omit Authorization. This principal is read-only, even for a customer whose WordPress account also has administrative capabilities. It accepts only `ping`; panel and generic commands are rejected.

Hello has `event:connected` and data containing `principal`, `user_id`, `cursor`, `oldest_event_id`, `latest_event_id`, `cursor_reset_required`. Event envelopes have `id,event,name,time,data`. Catalog data uses the same strict public projector as SSE. Owner-only profile/order changes are reduced to invalidations; explicitly addressed notifications use the existing validated notification projection. Revoked, expired or password-invalidated sessions close within 30 seconds. Reconnect using the last received cursor; when reset is required, refresh current data because history is bounded.

Send a ping every 25 seconds and reconnect with jitter/backoff starting at 3 seconds. Keep the connection while Android permits the process to run; background process suspension requires catchup on resume. Firebase is not required for catalog, OTP, local drafts or this transport. Public SSE remains the fallback. Live WSS acceptance verified protocol negotiation, 105 public replay events, ping/pong and generic-command denial without creating notifications or customer activity.

## Shopping and authentication

Native cloud carts use an opaque `X-Digitalogic-Cart` token over the installed Woo session/persistent-cart engine. The official Store API URL remains discoverable for compatible callers, but the app does not maintain a second Store API cart. A cart token grants access to its cart, not an account or panel credential; store it in Android secure storage. Do not disable nonce checking, embed WooCommerce consumer secrets, write orders directly in SQL, or accept client prices.

Native account sessions are separate from WordPress browser sessions. The existing `/panel/` browser session/capability boundary is unchanged. No mobile bearer token is exchanged for a panel session. Native checkout requires an existing authenticated WordPress customer; browsing and cart creation remain public. Never submit offline orders.

### Native identity and account data

| Endpoint | Contract |
| --- | --- |
| `POST /app-api/auth/otp/request` | `{phone,request_id}` → `202 {challenge_id,retry_after,expires_in,expires_at,delivery_status}` |
| `POST /app-api/auth/otp/verify` | `{challenge_id,code,device_name}` → session or registration continuation |
| `POST /app-api/auth/google` | `{id_token,device_name}` → the same session response |
| `GET/PUT /app-api/account/profile` | `{profile}`; PUT allows first_name, last_name, display_name, canonical billing/shipping fields and optional address_locations |
| `GET /app-api/account/orders?page=1` | Owner-only items and pagination, 20 per page |
| `GET /app-api/auth/sessions` | Owner-only device names, creation/last-use/expiry and current-session marker |
| `POST /app-api/auth/logout` | Optional session_id; default current session, always owner scoped |
| `GET/PUT /app-api/account/favorites` | `{items:[product IDs],revision}`; PUT requires base_revision, conflicts return 409/current data |

A successful enrollment returns `{registered:true,access_token,token_type:"Bearer",session_id,expires_at,profile}`. A verified unknown phone returns `{registered:false,registration_token,expires_at,terms}` and creates no account until explicit native confirmation. `POST /auth/register {registration_token,request_id,device_name,terms_accepted}` creates the existing WordPress customer/Digits identity under the configured deterministic phone username policy. It uses an unusable password hash, never a generated human password. A stable request UUID replays the same encrypted session response for the proof lifetime. Unknown Google identities require native phone verification; email alone never claims another account. Native bearer tokens are 256-bit random values; only SHA256 hashes persist server-side. Sessions expire after 30 days and password changes invalidate them. Store tokens only in Android secure storage. Customer endpoints are private/no-store and never accept another customer's ID as an authority.

OTP accepts Persian/Arabic digits and normalizes Iranian mobile numbers. Challenge proofs are keyed hashes, expire after 5 minutes, permit at most 5 attempts and have an atomic one-use claim. A stable request_id prevents automatic resends after uncertain delivery. Limits apply globally, per transport IP and per phone. Existing server-local SMS.ir template configuration and Shatel interception are reused; no secrets enter the app. `delivery_status=accepted` is provider acceptance, not proof of handset receipt; `pending` is uncertain and must not be automatically retried. Existing Digits phone identity metadata is the account authority, never a billing phone field.

Google verification uses a pinned, namespace-scoped Firebase JWT 7.2.1 dependency, Google HTTPS JWKs/cache lifetime, RS256 signatures and strict issuer, audience, presenter, expiry, issued-time and verified-email checks. It maps only the existing Digits `auth_google_id` subject association, never email alone. The web audience is the public `auth.google_server_client_id`; client secrets remain server-local. Unknown/unlinked identities continue through native phone verification and explicit native registration consent. A verified token creates at most one native session.

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

Available capabilities are public catalog/bootstrap, exact public-link resolution, native OTP/Google registration and sessions, fresh-proof phone linking and reviewed account consolidation, canonical Woo carts and BACS checkout, canonical Woodmart favorites, private service forms/uploads, owner-scoped self-hosted realtime, and canonical customer addresses. Unsupported historical merge adapters, Fluent native messages, cloud form drafts, native admin, optional Firebase fallback and additional gateway adapters remain gated until their own acceptance evidence exists. Server-only checkout fixtures do not claim real handset order acceptance. See [account consolidation](mobile-account-merge.md) for the exact supported preservation boundary.

Tracking: [backend issue 437](https://github.com/atomicdeploy/digitalogic-wp/issues/437), [Android delivery ledger](https://github.com/atomicdeploy/digitalogic-app/issues/1), [Android mirror](https://git.digitalogic.ir/root/digitalogic-app).

References: [WooCommerce Cart Tokens](https://developer.woocommerce.com/docs/apis/store-api/cart-tokens), [Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart/), [Laravel 13 responses](https://laravel.com/framework/docs/13.x/responses).

### Shared website favorites and account acceptance

Native favorites now adapt the installed Woodmart Wishlist/DB_Storage APIs, including their cache invalidation and remove hook. Existing website entries remain authoritative. Legacy mobile IDs, if present, are merged once into that canonical wishlist; the mobile table is only a revision projection. GET observes website edits; PUT uses base_revision and a canonical sorted-ID hash, returns409 with the current list for competing edits, and treats identical desired-state replay as success. No legacy favorite rows existed at the rollout checkpoint.

A controlled, clearly marked WordPress customer fixture verified both account surfaces on2026-11-06: native PATCH updated the numeric Woo session and existing persistent cart; the authenticated website cart form changed the quantity subsequently read by native GET; the website wishlist read a native addition and native GET read its removal; authenticated checkout returned the canonical schema. Outgoing mail was blocked, the fixture registration notification hook was suppressed only in the test process, no orders existed, and all fixture customer/session/cart/favorite data was cleaned. This is server acceptance, not proof of real device OTP/Google sign-in.

Website and native Woo add/remove/quantity/empty hooks enqueue an owner-scoped cart.updated invalidation after normal Woo session persistence. Guest sockets receive no cart events. Native metadata locks serialize native edits; existing Woo browser sessions retain Woo's ordinary concurrent-request behavior, so this does not claim strict cross-channel transactional isolation.

Recovery tests also verified that a dead precreation worker is terminally rejected under its operation lock, successful BACS recovery finalizes the canonical cart once, and later website edits remain intact with cart_review_required when quantities differ. These tests used an in-memory order stub and created no Woo order/payment. Real order acceptance requires an actual verified customer context and explicit final confirmation.


### Fresh identity proof and phone linkage

`POST /account/reauth/google {id_token}` requires the existing native bearer, validates the Google signature and exact current WordPress subject owner, and returns `{reauthenticated:true,verified_at,profile}` without switching the account or token. Proof freshness is bounded by the signed token issuance time (10 minutes); replay cannot renew it. Legacy sessions require this reauthentication. `reauth_required` is HTTP403, distinct from an expired bearer401.

`POST /account/phone/request {phone,request_id}` returns the normal challenge shape. `POST /account/phone/verify {challenge_id,code}` returns `{status:'linked'|'review_required',profile,message,account_merge_available:false}`. Challenges bind purpose, WordPress owner and native session. Login proofs cannot be used for phone linking or vice versa. A phone already assigned elsewhere, ambiguous ownership, or replacing an existing different phone requires review; no accounts, roles or histories are transferred. Profile includes `identity:{phone_verified,google_linked,phone_masked,email_masked,requires_phone_verification}`.

No-send production fixtures verified cross-session denial, purpose isolation, fresh-proof enforcement, the five-guess limit, same-result replay, canonical Digits attachment and duplicate preservation. Fixtures and sessions were removed; no real SMS/order/payment was sent.

### Canonical customer addresses and optional map locations

Profile includes `billing`, `shipping`, `address_schema`, `address_locations` and `address_map`. Schema comes from country-aware Woo address fields; `options_by_country` supplies actual dependent state choices. Profile PUT validates and saves through `WC_Customer`, preserving the other address section and authentication identity. A billing/shipping phone is contact data and never updates Digits verification.

Optional `address_locations:{billing:{lat,lon,accuracy?}|null,shipping:{...}|null}` uses finite bounded coordinates. Absent keys preserve existing values; null explicitly clears a pin. The metadata lives on the existing Woo customer/order objects (`_digitalogic_billing_location`, `_digitalogic_shipping_location`), with no separate address authority. Checkout quotes bind effective pins into their fingerprint and final orders retain that snapshot. When shipping uses billing, its effective pin follows billing. The initial map viewport never supplies a destination automatically. Public tile URL/attribution are configurable in WooCommerce's app settings, with OpenStreetMap as the default.

### Published business information and Android links

`bootstrap.business` projects the existing published contact/about pages, including source URLs. Visitor address comes from the published contact page; the Woo fulfillment-origin address differs and was deliberately left unchanged. Published hours currently name days only, so no opening times are invented. Telephone extensions are separate from dialable base numbers.

`GET /links/resolve?url=...` accepts bounded same-host HTTPS public Woo product/category permalinks only. It validates the exact canonical URL after WordPress resolution, without fetching remote URLs, following redirects or guessing product IDs from text. Queries, credentials, ports, fragments and encoded path/action ambiguity are rejected. The permanent Android certificate association is served by the existing Apache `/.well-known/` alias at `/var/www/html/.well-known/assetlinks.json`; Android manifest/parser paths remain narrowly scoped.

On2026-11-06 product10698, exact SKU113004003, received a targeted content correction: removed internal catalog variation/matching instructions and replaced the unresolved board-detail note with a customer support prompt. Existing chip facts, SKU and excerpt were preserved. Prior revision14671 plus the product's scoped content backup preserve the previous text. Both public API and rendered product page were independently read back.


### Fresh Google proof for phone linking

`POST /account/reauth/google/challenge {request_id:UUID}` returns `{challenge_id,nonce,expires_at}` for the current authenticated native session. Its unpredictable nonce expires after five minutes; the same request UUID/session replays that exact unexpired challenge. Pass the nonce unchanged to Android Credential Manager. `POST /account/reauth/google {challenge_id,id_token}` verifies the signed nonce, existing Google subject owner, configured audience/presenter, issuer, expiry and issuance after challenge creation. It atomically consumes the proof and stamps only the existing native session. The exact same completed proof can recover a lost response during the challenge lifetime, returning its original timestamp without renewing authority. Wrong owner/session, missing or wrong nonce, stale/expired proof and changed-proof replay do not mutate authority. Older app versions retain the strict ten-minute issuance check on the path without a challenge. Neither path switches accounts or creates browser cookies.

On2026-11-06, source tests and a no-send production metadata fixture verified nonce ownership, freshness, request/proof replay, transactional session readback and complete fixture cleanup. The physical device had exposed cached Google ID tokens on the earlier issuance-only path; nonce-bound verification addresses that trigger without relaxing proof freshness. No user tokens, OTPs or orders were used in the probe.

### Source and quality checkpoint

The exact private mirror is `root/digitalogic-wp`, with WIP PR1 tracking GitHub PR439 and issue437. Native adapter files pass the existing WordPress standard; the full repository scan remains inside its unchanged historical error/warning budget. Cleanup parameterizes adapter table identifiers and retains explicit narrowly documented exceptions only where WordPress filesystem/cache conventions do not represent transactional metadata or resumable private streams. Existing unrelated source is not broadly reformatted.

Exact product-code media inspection found no thumbnail, gallery, or exact-SKU attachment filename for113001020,113004003,113003157,113004059. Images remain absent until an authoritative existing source establishes that mapping; similar names or appearance are not evidence.


### Phone prerequisites and durable resolution

`GET /account/phone/eligibility` returns `{reauthentication_required,methods,verified_until,phone_verified,pending_resolution}` before the app enables SMS requests. Fresh Google completion only refreshes this view; it never sends an SMS automatically. `methods` contains only working current-account proof methods. An expired or absent proof requires explicit reauthentication first.

`pending_resolution` is null or `{status,challenge_id,phone,verified_at,resolution}` from the latest used phone-link outcome belonging to the exact current actor and native session. The phone is only that session's own previously submitted and verified input. No other account identifier, email, role list or history is exposed. `GET /account/phone/resolution` returns the same nested value. Older rows without a verification timestamp return null for that timestamp. Reading a resolution grants no authentication and renews no proof. Stable latest-result ordering prefers a completed linked result at equal timestamps; a currently resolved ownership clears stale guidance, and changed ownership is explained without rewriting historical proof metadata.

New phone verification stores the conflict reason and verification timestamp atomically. Reasons are `protected_phone_account`, `phone_account_exists`, `ambiguous_phone_owner`, `current_phone_differs`, or `review_required`. With the reviewed merge capability enabled, existing-owner conflicts advertise `merge_accounts`, which opens a fresh-proof plan and explicit consent flow; reading the resolution itself never authorizes a merge. The legacy `sign_in_phone` action remains an explicit login only and does not reuse a phone-link proof. `try_another_phone` preserves the current identity. Unsupported provider histories or unresolved operations block consolidation; records are never discarded to bypass that check.

The2026-11-06 no-send resolution fixture passed16 checks: purpose isolation, duplicate preservation, stored reason, same-session restart/replay, different-session and other-actor exclusion, failed-proof exclusion, revoked-session rejection, no orders and complete cleanup. The production handset subsequently completed fresh Google verification and phone proof; its protected conflict was assessed and represented as a durable resolution, without modifying any identity or role.

### Fluent Support adapter checkpoint

The source and gated deployment use existing `fs_persons`, `fs_tickets` and `fs_conversations` through Fluent Support models/services. `digitalogic_mobile_message_operations` holds only operation metadata. Customer ownership is exact WordPress `user_id`; email never claims or merges a provider identity. Read-only browsing creates no provider customer. Another owner's ticket remains inaccessible even when its provider privacy flag is public. Internal notes and attachment paths are excluded.

| Route | Contract |
| --- | --- |
| `GET /account/messages/schema` | Canonical fields/statuses and upload capability |
| `GET /account/messages?page=&search=` | `items`, `pagination:{page,has_more,next_page}`; unread count remains null |
| `GET /account/messages/{id}?before_id=` | Initial `ticket.body` plus chronological visible responses; bounded ID cursor |
| `POST /account/messages` | Explicit `{operation_id,subject,body,priority?,product_id?,attachments:[]}` |
| `POST /account/messages/{id}/messages` | Explicit `{operation_id,body,attachments:[]}` |
| `POST /account/messages/{id}/close` or `/reopen` | Existing provider status services, explicit operation UUID |
| `GET /account/messages/operations/{uuid}` | Exact owner operation recovery without repeating provider writes |

Results use flat `operation_id` and `operation_status`. Definitive rejection is persisted before reporting `rejected`; unknown404 never proves no prior write. Immutable payload changes conflict without overwriting the original operation. A plain provider `message_id` survives Fluent's sanitizer and supports exact create/reply crash recovery. Ambiguous interrupted close/reopen remains processing instead of automatically replaying provider hooks. Known UUID recovery bypasses the quota for new operations. New requests are rate limited before allocating operation metadata;429 retains the same pending client UUID for later retry.

Current canonical schema has subject/body and optional normal/medium/critical priority. No support product configuration is invented. Required unsupported provider fields block creation. Attachments remain disabled until a canonical Fluent upload/ownership adapter is audited. The live no-send fixture passed12 checks covering empty reads, schema, ownership denial, durable invalid-payload/replay/conflict/unknown responses, zero provider customer/ticket creation and cleanup. Actual message submission/notifications were not exercised with a human contact. `native_messages` remains false at this checkpoint until the next authorized activation and acceptance stage.

Owner-only WebSocket source projects `ticket.updated` and `message.created` metadata without content. Stable `event_id` is `wp-panel:<numeric canonical id>`; numeric `id` remains the separate replay cursor. This WebSocket source delta has not yet replaced the running service at the gated checkpoint.

### Canonical purchase review and transfer receipt

`GET /checkout` and `POST /checkout/quote` include `items` from the exact prepared Woo cart used for their totals. Each row contains `product_id`, `variation_id` (zero for a simple product), `name`, `sku`, `quantity`, `unit_price`, `line_subtotal`, `line_total` and the canonical thumbnail URL or null. Amounts are decimal strings in the response currency's minor unit; line amounts exclude tax, while the totals object carries the canonical tax calculation. Review these server lines rather than a cached client cart.

Initial checkout, immutable operation recovery and authenticated `GET /account/orders` use one owner-checked payment projection. An on-hold BACS order returns `payment:{status:'awaiting_bank_transfer',instructions,bank_accounts}`. Accounts contain only configured published `account_name`, `account_number`, `bank_name`, `sort_code`, `iban` and `bic`. The canonical Woo account filter still applies. Paid orders return `complete`; uncertain or nonpayable states return `requires_review` without transfer instructions. Readback never repeats `process_payment`.

### Public model counts and availability

`variation_count` is an integer or null on variable products. It counts the complete public canonical child inventory, including unavailable models, independently of the detail page's bounded variations list. It is never inferred from attribute combinations. A failed count remains unknown.

Unavailable catalog products and variation options expose availability without saved price, sale amount or price range. Their product modification timestamp is suppressed as well. A mixed product's public amount and range use only available, visible canonical child offers; the displayed regular/sale amount belongs to the selected lowest-price available offer. Internal prices and update provenance are unchanged. The same product projection serves listings, details and canonical cart items.


### Catalog search and purchase verification — 2026-11-14

Nonempty search defaults to `orderby=relevance` when the caller omits a sort. Browsing still defaults to date; an explicit date/price/title/popularity sort is preserved for older clients. Relevance applies before SQL pagination: exact SKU, then exact full title, then other matches; availability, title prefix, canonical sales, date and unique ID break ties. An unavailable exact model remains ahead of a related available result. Mixed board/accessory categories are not treated as an authoritative product-role classifier.

The query policy normalizes Persian/Arabic digits, Arabic/Persian letter presentation, spacing and bidi marks while retaining meaningful model punctuation. It reads the existing Woodmart `search_synonyms` setting, with bounded symmetric native query alternatives. The original query is always retained. The deployed empty canonical setting was populated with reciprocal Arduino Persian/English and ESP spaced aliases while preserving every other option field. The theme itself expands exact whole search terms directionally; native multiword normalization is not a claim about the rendered website parser. Alias terms participate in the catalog cache key.

Exact variation SKU search returns the actual published variation with its own `id`, `sku` and `parent_id`. Parent visibility, publication and category restrictions apply, so it cannot expose a hidden parent through a child match. Broad model searches continue to show product parents. Public readback found SKU `113001017` as variation11944 of parent14382, Persian-digit parity, and the same 29-item ordered pool for Arduino and آردوینو. Sampled unavailable results retained no public saved price or update date.

The isolated WooCommerce11.3.0 acceptance created one synthetic BACS order and passed34 assertions for quote lines/totals, same-operation POST/GET recovery, owner order readback, wrong-owner rejection, paid/cancelled bank-detail suppression and canonical cart clearing. The namespace blocked external network and mail; no production orders or payments were created. Production BACS was independently checked after the payment owner configured it: enabled, instructions present and one account with a usable identifier. This readiness and isolated smoke do not replace the pending real handset/customer purchase acceptance.
