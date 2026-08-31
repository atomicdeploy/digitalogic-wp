# Changelog

All notable changes to the Digitalogic WordPress Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.8.63] - 2026-08-31

### Fixed
- Accept WooCommerce's numeric-zero lookup bounds as an internal unavailable
  price sentinel only while raw and effective prices remain blank and the
  product remains out of stock; non-zero or mixed lookup prices fail closed.
- Verify zero-stock and unknown-stock incomplete Patris products without
  fabricating a customer price, quantity, or purchasable storefront state.

## [1.8.62] - 2026-08-31

### Fixed
- Treat a zero-row unpriced stock-status write as successful only after exact
  database readback, and keep the SQL verb first so WordPress reports affected
  rows correctly.
- Include WooCommerce stock quantity/status lookup state in the exact feed
  backup, rollback, and fresh-object verification contract.

## [1.8.61] - 2026-08-31

### Fixed
- Persist and verify the exact WooCommerce stock-status meta and lookup
  projection after hooks recalculate a positive-stock, blank-price Patris leaf,
  without changing its canonical quantity or blank prices.

## [1.8.60] - 2026-08-31

### Fixed
- Reassert the explicit out-of-stock state for positive-stock, blank-price
  Patris leaves after WooCommerce synchronizes managed stock during save, with
  exact readback and transactional rollback on persistence failure.

## [1.8.59] - 2026-08-31

### Fixed
- Keep identity-safe simple and variation products with missing or nonpositive
  canonical prices out of stock even when Patris reports positive physical
  stock, while preserving blank WooCommerce prices and later feed promotion.
- Resume bounded legacy materialization scans from a source-revision-bound
  cursor, reuse only request-local exact identity resolutions, and drain
  existing pending work before selecting another batch.

## [1.8.58] - 2026-08-31

### Fixed
- Repair markerless legacy Patris leaves through a fresh canonical feed proof
  and five metadata rows without a WooCommerce product save; feed drift is
  counted and left pending instead of falling through to a full-feed write.

## [1.8.57] - 2026-08-31

### Fixed
- Fence every relevant product-taxonomy relationship cache before the initial
  reviewed topology inspection as well as before the transaction, so a stale
  persistent relationship cannot reject an otherwise exact dry-run.

## [1.8.56] - 2026-08-31

### Fixed
- Keep every relevant product-taxonomy relationship cache group request-local
  for the complete reviewed Patris topology transaction, preventing a deferred
  shutdown write from restoring stale pre-transaction relationships.
- Persist the reviewed variable parent's complete attribute-term assignment
  explicitly and prove it from the transaction's raw relationship rows before
  accepting WooCommerce object readback.
- Restore the exact pre-transaction WooCommerce deferred parent-sync queue on
  both commit and rollback so request shutdown cannot replay transaction-local
  parent changes.

## [1.8.55] - 2026-08-31

### Fixed
- Fence exact product-type and variation-attribute relationship caches around
  the reviewed Patris topology transaction, including fail-closed rollback
  verification for persistent-cache adapters.

## [1.8.54] - 2026-08-31

### Fixed
- Release the reviewed legacy parent's canonical Product Code through the
  guarded metadata writer even when WooCommerce did not load that custom meta
  into the product object's pending deletions.
- Verify the released parent identity and exact SKU before assigning the code
  to the new base variation.

## [1.8.53] - 2026-08-31

### Fixed
- Remove WooCommerce's independent product-instance cache and invalidate the
  exact product cache prefix during Patris topology verification and rollback,
  so durable product-type changes cannot be hidden by a stale cached class.
- Fail closed with an auditable unknown outcome if exact cache invalidation is
  unavailable after rollback.

## [1.8.52] - 2026-08-31

### Fixed
- Restore WooCommerce's exact pre-transaction deferred product-sync queue after
  a Patris topology rollback, preventing a shutdown sync from replaying
  rolled-back parent-type changes.
- Clear product-term and newly inserted attribute-term caches before topology
  verification and after rollback, and report the exact stable readback
  predicate that failed without exposing product data.

## [1.8.51] - 2026-08-31

### Fixed
- Backfill canonical Patris ownership on an exact, already-current legacy
  product without repeating two expensive WooCommerce object saves. The bounded
  reconcile path proves the complete feed from fresh database state under the
  source and product locks, writes only exact provenance metadata, and falls
  back to the full canonical writer whenever any feed field differs.
- Add a dry-run-first, source-revision-pinned transaction for explicitly
  reviewed legacy variation topology. Parent/child maps, Code/SKU ownership,
  terms, locks, and post-write identity are verified exactly; failures roll back
  and an uncertain commit is never represented as safe to retry.

## [1.8.50] - 2026-08-31

### Added
- Add a supported, bounded `product-sync reconcile --materialize-current`
  administrator command to backfill exact source ownership, source revision,
  missing-field snapshots, and the canonical Patris feed on legacy products.

### Fixed
- Keep large same-value currency reconciliations bounded by moving legacy
  materialization repair outside the atomic pricing transaction, while retaining
  exact per-product source provenance and idempotent readback.
- Bootstrap a missing supplier route only from an exact, positive raw CNY fact
  and an empty WooCommerce assignment, then select and calculate that source only
  after exact identity, assignment, weight, catalog, freight, markup, FX, and
  rounding validation; no placeholder price is created.
- Publish one durable storefront currency event per committed pricing revision
  and purge WP Rocket page markup after the commit, keeping both open tabs and
  subsequent header renders on the canonical rate.

## [1.8.49] - 2026-08-31

### Fixed
- Read the report projection generation from its authoritative database row so
  stale persistent option-cache replicas cannot fork pricing revision, snapshot,
  page, or event identities across PHP workers.
- Preserve strong ETags for the complete authenticated pricing-sync contract and
  disable Apache DEFLATE for those routes, preventing representation suffixes
  such as `-gzip` from breaking exact identity and conditional requests.

## [1.8.48] - 2026-08-31

### Fixed
- Remove the translated Rank Math Slack-enhanced price field that generates
  Twitter `label1`/`data1` metadata for canonical unpriced products, while
  preserving availability and all normal metadata for priced products.

## [1.8.47] - 2026-08-31

### Fixed
- Route missing or stale identity-safe pricing targets through the canonical
  Patris feed writer, verify their exact source projection, and emit incomplete
  product alerts only after the pricing lock is released.
- Keep identity-safe products with positive physical stock public but
  non-purchasable when their canonical price is unavailable. The source stock
  remains in Patris metadata while WooCommerce operational stock stays zero
  until a valid price arrives, preventing automatic `instock` snapback and a
  permanently retrying product-sync queue.
- Materialize every identity-safe Patris source leaf even when commerce or
  enrichment data is incomplete, keeping unavailable prices blank and products
  public but non-purchasable until canonical data arrives.
- Omit unavailable Rank Math Open Graph, Twitter, and schema offer prices for
  canonical unpriced products instead of exposing a zero-price placeholder.
- Compare numeric Patris metadata using WordPress/MySQL scalar storage semantics
  during exact readback, without changing rollback backups or masking real late
  clobbers.
- Preserve each exact pricing state-event retry through scheduler lock timeouts, running-worker handoffs, and bounded Action Scheduler or WP-Cron degradation without collapsing distinct fallback identities.
- Keep a delivered state identity in the durable outbox until its bounded receipt commits, preventing replay after receipt failure or panel rotation.
- Retire normalized receipts and delivered markers before ordered source removal, while preserving one fresh composite event when the same source is rapidly reintroduced.

## [1.8.40] - 2026-08-30

### Added
- Deliver privacy-filtered, accessible storefront toasts and banners through the existing durable notification event, live SSE connection, Redis/WebSocket publisher, and cross-tab relay.
- Support broadcast, user-ID, role, exact user-attribute, device, and operator audiences with `any`/`all` matching, server-side filtering, expiry, dismissal, severity, same-origin links, and text-only rendering.
- Extend `wp digitalogic event-mesh notify` with inline storefront options and JSON receipts, and document the existing authenticated version-less n8n notification endpoint.

### Security
- Partition browser coordination and cursors by an opaque signed-in audience key, reject secret-bearing attribute selectors, and omit audience criteria, workstation actions, response fields, sources, and credentials from the public SSE projection.

## [1.8.39] - 2026-08-30

### Fixed
- Place each meaningful SSE frame before its ignored FastCGI padding so ready, update, and heartbeat data are included in the server's immediately released response slice.

## [1.8.38] - 2026-08-30

### Fixed
- Send an ignored 8 KiB SSE comment before the first event so Apache FastCGI and intermediary response buffers release the live stream immediately.

## [1.8.37] - 2026-08-30

### Fixed
- Drain every PHP output buffer and disable server compression before emitting the first storefront SSE frame, preventing Apache/PHP-FPM from holding live currency and product events until the bounded request closes.

## [1.8.36] - 2026-08-30

### Added
- Added a bounded public Server-Sent Events stream for committed USD/CNY and WooCommerce product changes, backed by the existing durable event queue and Redis wake-up publisher.
- Added one browser stream leader per origin with BroadcastChannel fan-out, a localStorage compatibility relay and currency/cursor cache, and sessionStorage tab identity and product-refresh guards.
- Added live storefront currency-card updates and product-page fragment refresh for title, description, price, gallery, variations, and availability, with a full-page safety fallback when the active theme cannot expose a compatible product fragment.

### Security
- Restricted the public stream to a minimal allowlist and projected product events to numeric identities only; user, order, workstation, panel, pricing-service, internal metadata, and webhook payloads remain private.

## [1.8.35] - 2026-08-30

### Fixed
- Give Google Sheets a cheap authenticated catalog invalidation revision that covers WooCommerce, Patris, taxonomy, pricing, and source-freshness inputs, so unchanged native syncs fail closed against drift without rebuilding the full reconciled catalog.

## [1.8.34] - 2026-08-30

### Fixed
- Let a new exact generation replace a fully terminal pricing job without carrying the previous generation's committed effect marker, while retaining marker immutability for stale writes within the same generation.

## [1.8.33] - 2026-08-30

### Fixed
- Wake due currency jobs through WordPress core's lock-aware cron spawn instead of a hard-coded loopback TLS endpoint, after releasing the pricing mutex, and keep one exact fenced action in both Action Scheduler and WP-Cron so either provider can start work promptly when automatic cron is disabled.
- Retry already-scheduled post-commit finalizers after the core cron lock expires and surface a bounded actionable terminal failure if the publication runner remains unreachable, without repeating repricing.

## [1.8.32] - 2026-08-30

### Fixed
- Repair the canonical currency effective date again at ACF's final server-side field preparation phase, covering options-page integrations that hydrate values before normal ACF load filters are registered.

## [1.8.31] - 2026-08-30

### Fixed
- Route managed CNY writes to a bounded background job at the server option boundary, so missing JavaScript or rotated ACF field identifiers cannot run full repricing inside the admin save request.
- Claim currency jobs under an atomic mutex with expiring leases, generation fences, idempotent terminal writes, and stale-completion rejection.
- Require the exact current pricing revision on every admin, REST, option, and command mutation surface, and repair ACF's legacy YYMMDD date before it can round-trip as an epoch-era value.
- Recover queued crash gaps, cap post-commit publication retries, and keep publishing or terminal operator status visible after an admin/panel reload.
- Serialize snapshot-terminal retry scheduling under a database mutex and remove its every-request shutdown publisher, preventing concurrent retry storms in Action Scheduler.
- Allow an explicit authenticated same-rate reconciliation job for safe worker/readback acceptance without changing the business rate.
- Keep WordPress-origin pricing commits terminal on the website instead of rolling them back when an unrelated Excel acknowledgement is absent; explicit Excel applies retain their acknowledgement guard.
- Emit pricing projection invalidation when unchanged settings repair real WooCommerce price drift, without emitting again for a no-op replay.

## [1.8.30] - 2026-08-29

### Fixed
- Keep duplicate WooCommerce SKU groups quarantined from identity fallback and writeback while allowing unrelated catalog rows to refresh with exact integrity diagnostics.

## [1.8.29] - 2026-08-27

- Let the authenticated ACF AJAX request claim and run its already-durable currency job directly, keeping the browser responsive while removing WP-Cron queue latency from the rate-change critical path.
- Give the asynchronous submit request a bounded 45-second network window while retaining the existing 120-second terminal status deadline and rollback behavior.

## [1.8.28] - 2026-08-27

- Rebase and retry an ACF currency change when a concurrent Patris delivery leaves only safe durable pending work, while keeping ambiguous identity and true price-readback mismatches blocking and visible.
- Persist the current ACF apply attempt before repricing and convert unexpected background exceptions into a terminal Persian failure instead of leaving the page on an unexplained running state.

## [1.8.27] - 2026-08-27

- Yield large Patris product deliveries after 25 products with durable pending-state readback, allowing the website-first currency repricer to commit between continuously arriving catalog batches without mixing revisions or overwriting newer pricing.

## [1.8.26] - 2026-08-27

- Retry transient Patris product-sync lock contention with bounded, visible Persian progress instead of leaving the currency settings page on an endless spinner.

## [1.8.25] - 2026-08-27

- Clear delivered pricing-event outboxes through verified storage when a persistent option cache does not observe deletion, keeping the terminal queue empty without duplicating events.

## [1.8.24] - 2026-08-27

- Rebase pending pricing-revision events onto the newest exact Patris source revision with a bounded retry, so continuous source changes cannot starve Excel notification.

## [1.8.23] - 2026-08-27

- Flush the committed pricing-revision event after the ACF background job releases the pricing lock, allowing the open Excel workbook to apply and ACK without waiting for cron.

## [1.8.22] - 2026-08-27

- Publish the small committed pricing-revision event immediately from the background ACF job, so an open Excel workbook can apply and acknowledge it without waiting for delayed cron.

## [1.8.21] - 2026-08-27

- Dispatch the queued ACF pricing job through the server-local HTTPS origin while preserving the production Host header, avoiding external DNS/proxy latency without blocking the browser request.

## [1.8.20] - 2026-08-27

- Emit the same committed projection-invalidation event for authenticated ACF changes as for Excel-originated applies, so the existing live pricing stream wakes the open workbook immediately.

## [1.8.19] - 2026-08-27

- Dispatch the bounded ACF pricing job through an explicit non-blocking cron loopback, so disabled automatic cron or a busy shared Action Scheduler cannot leave the settings page waiting.

## [1.8.18] - 2026-08-27

- Keep the authenticated ACF currency page responsive by queueing website-first repricing outside the browser request, with live Persian status through commit, Excel acknowledgement, or rollback.

## [1.8.17] - 2026-08-27

- Invalidate WooCommerce's actual plural `products` raw metadata cache group after committed batch repricing, so independent audits do not read a previous canonical value beside the new public price.

## [1.8.16] - 2026-08-27

- Fall back to exact per-product cache cleanup when a persistent object cache only partially completes a bulk delete.

## [1.8.15] - 2026-08-27

- Add a settings-only pricing-state projection so Excel proposal, ACK, and parity readback no longer rebuild a catalog page; normal product refresh remains unchanged.

## [1.8.14] - 2026-08-27

- Complete a full pricing change with one atomic product-state persist and reuse in-transaction price readback for already-current rows, removing duplicate catalog storage and verification from the website-first Excel flow.

## [1.8.13] - 2026-08-27

- Keep ACF validation on native `admin-ajax.php` so the currency settings page can save and complete its website-first confirmation flow instead of hanging behind the generic WebSocket proxy.

## [1.8.12] - 2026-08-27

- Give the configured Excel consumer a bounded 90-second ACK window after a full catalog repricing, with a 180-second recovery boundary, so a successful website commit is not rolled back before the confirmed workbook can read it back.

## [1.8.11] - 2026-08-27

- Keep the website-first CNY transaction readback consistent when WordPress still exposes the pre-commit shipping catalog through its option cache; all other revision mismatches remain blocking and are revalidated after commit.

## [1.8.10] - 2026-08-24

### Fixed
- Coalesced pricing state-event retries across PHP requests with an exact
  pending-action readback protected by a dedicated database mutex. A running
  worker may still schedule one replacement without creating parallel retry
  chains.
- Added a bounded per-source delivery receipt and retained-panel identity
  check. Late fallback actions can no longer repersist or replay a composite
  revision that already reached the durable panel queue, preserving the
  200-event replay window for distinct events.

## [1.8.9] - 2026-08-23

### Fixed
- Required exact `wp_next_scheduled()` readback before treating a WP-Cron
  one-shot as durable, including when a scheduling filter reports success.
- Added a source-controlled, non-overlapping systemd timer for production
  WordPress due-event execution every 10 seconds under the WordPress runtime
  identity. This removes snapshot activation's dependency on later web traffic
  or a server-origin loopback that can be rejected by the public WAF. The unit
  requires built-in web cron to be disabled, preventing concurrent all-due
  runners after the core lock expires.

## [1.8.8] - 2026-08-23

### Fixed
- Scheduled every pricing-snapshot build, retry, and random-token watchdog on
  independent Action Scheduler and WP-Cron one-shot paths. The existing worker
  lease and terminal-state fences make the first runner authoritative while a
  late sibling becomes a no-op.
- Cleared both scheduler paths for the exact build and watchdog arguments after
  ready, failed, cancelled, expired, or deleted jobs. Admission remains
  fail-closed only when neither durable activation path can be verified.

## [1.8.7] - 2026-08-23

### Fixed
- Preserved Gregorian ASCII machine-date values only inside Iconic Woo Delivery
  Slots calculations while leaving WP-Parsidate customer-facing Jalali dates
  enabled, preventing the checkout document from terminating during `wp_head`.

## [1.8.6] - 2026-08-23

### Added
- Added the durable `pricing.snapshot.build.terminal` v1 event required by the
  Patris pricing companion. Ready, failed, and cancelled builds now publish an
  exact-source, service-only, secret-free terminal envelope for every request
  attached to a single-flight build.

### Fixed
- Staged snapshot terminal events before committing terminal build state, then
  promoted them to a job-independent persistent outbox before delivery. Queue
  failures and process interruption recover without a false terminal frame;
  stable idempotency supports the stream's existing at-least-once semantics.
- Added a random-token, per-build one-shot watchdog and uncaught-worker failure
  boundary so missed actions, expired leases, process crashes, and thrown errors
  become request-bound terminal events without build-status polling.
- Restricted snapshot terminal replay to the authenticated `patris_pricing`
  principal and its exact source, with fail-closed schema, path, audience, and
  no-extra-field validation.

## [1.8.5] - 2026-08-23

### Fixed
- Kept unchanged terminal product-sync reconciliation out of the normal source
  delivery acknowledgement path. Missing or ambiguous products remain durable
  for explicit reconciliation, while changed records and transient pending
  writes still retry normally, preventing a committed receiver update from
  being reported as a transport failure after a long deferred scan.

## [1.8.4] - 2026-08-23

### Added
- Added an authenticated composite pricing revision plus asynchronous,
  single-flight WooCommerce/Patris projection snapshots with immutable bulk and
  fixed-page reads, ETags, progress, cancellation, integrity digests, bounded
  retry semantics, and backwards-compatible existing pricing routes.
- Added persistent catalog-generation invalidation for pricing applies and the
  WooCommerce, Patris, category, attachment, metadata, URL, weight, currency,
  shipping, and pricing inputs consumed by the `excel-v1` projection.
- Added focused snapshot lifecycle, rollback, replay, conditional request,
  corruption, freshness, exact-schema, route-permission, and
  production-consistency fixture tests.
- Added an exact-source, header-authenticated, read-only pricing WebSocket
  principal with ordered durable replay, cursor-gap signaling, a persistent
  retry outbox, and at-least-once composite revision events for committed
  WordPress pricing/catalog mutations.
- Added post-commit source change/removal events and one replaceable one-shot
  action for currency-effective, currency-stale, and source-stale revision
  boundaries, with activation/deactivation cleanup and no recurring poll.
- Replaced the WebSocket daemon's periodic durable-queue catch-up with a
  persistent, coalesced one-shot Redis-wake retry; replay now occurs only on a
  pushed wake, client connection, or Redis reconnection.
- Invalidated WooCommerce's versioned per-product cache group after
  `product_type` taxonomy writes and Patris materialization, preventing stale
  runtime `simple` types from contradicting canonical variable products.
- Added a bounded, administrator-only product-type cache repair command that
  derives candidates from a fresh integrity report, verifies variable
  taxonomy and variation readback, and invalidates only exact WooCommerce
  per-product cache prefixes with idempotent post-repair evidence.
- Extended that cache-only repair and the product-type mutation hook to clear
  the exact product term-relationship cache, and remove WooCommerce's optional
  product-object cache entry, before rotating the per-product prefix.

## [1.8.3] - 2026-07-27

### Fixed
- Coordinated USD, CNY, effective-date, and shared profit-margin changes with exact
  Patris repricing and WooCommerce readback in one database transaction.
- Repriced managed Google Sheets profit edits atomically and rejected direct
  regular-price writes for receiver-owned products.
- Added one revisioned Google Sheets settings contract for USD, CNY, effective
  date, and shared profit margin, with optimistic writes and post-commit readback.
- Tracked USD and CNY effective dates and freshness independently while keeping
  the legacy effective date as the CNY/storefront-compatible alias.
- Routed legacy option, ACF, admin, REST, command, CLI, and public profit-margin
  setters through the same transaction once managed Patris pricing exists.
- Deferred managed-product webhooks until the pricing transaction commits and
  failed closed when an active promotion or variable-product price cannot be
  proven equal to the customer-visible final price.
- Rejected legacy dynamic-pricing imports and setters for receiver-owned
  products so a second pricing formula cannot silently bypass Patris.
- Preserved Go-compatible numeric record hashes while regenerating stored
  product-sync pricing state without binary floating-point price arithmetic.

## [1.8.2] - 2026-07-27

### Added
- Added a Persian WooCommerce account flow for linking registered customers to
  the Digitalogic assistant with ten-minute single-use tokens, transactional
  one-account/one-identity bindings, signed replay-protected server checks,
  live account eligibility validation, rate limits, and pseudonymous audit.
- Added a locale-aware operator work center at `/panel/assistant/` that links
  only to existing same-origin panel destinations and retains the current
  WordPress session and capability boundary.

### Security
- Kept customer linking inside the normal WordPress cookie and nonce boundary,
  returned no customer contact or login fields to the external consumer, and
  left `/panel/` capabilities unchanged.
- Serialized issuance, status, consume, and revoke operations with stable
  account-row locks and a unique pending-token slot; verified exact index
  uniqueness and column order rather than index names alone.
- Added scheduled bounded retention, WordPress privacy export/erasure support,
  an explicit customer/staff role allow-list with customer-only assistant
  scope, and a fail-closed single-site topology guard.
- Kept cleanup, deletion, and privacy hooks active during a WooCommerce outage
  while leaving customer UI and signed API operations unavailable.
- Strengthened schema readiness to require exact column type, length,
  nullability, defaults, extra attributes, and exact indexes; schema-v1
  pending codes are atomically superseded and cannot be consumed after upgrade.
- Added signed-boundary identity rate limits of 60 status checks per minute and
  10 consume attempts per ten minutes, storing only re-keyed fingerprints and
  returning a bounded Persian HTTP 429 response.

## [1.8.1] - 2026-07-27

### Changed
- Restored monotonic release provenance by superseding the untagged production
  `1.8.0` build with a source-traceable `1.8.1` release whose current
  `main` source is the reviewed semantic superset.

### Added
- Added an authenticated WordPress/WebSocket workstation event mesh with
  bounded actionable responses, evidence-based presence, WooCommerce caller
  context, and privacy-preserving n8n and RouterOS deployment assets.

### Fixed
- Capped Excel live-pricing and shared catalog responses at 250 rows while
  retaining bounded 100-row WooCommerce query windows and deterministic
  pagination across the complete catalog.
- Prevented canonical product-sync and reconciliation from initiating
  per-product outbound update/stock webhooks while WooCommerce rows are being
  drained, while preserving the existing bounded post-commit sync observer and
  restoring ordinary product webhooks after success or failure.
- Pseudonymized RouterOS presence subjects before network transmission and
  omitted raw lease MAC, IP, and hostname values from the generated hook.

## [1.7.4] - 2026-07-27

### Fixed
- Normalized TCI caller IDs that arrive in bare `989...` form to the canonical
  Iranian mobile format before applying the internal callback access prefix,
  matching the existing `0989...` and `00989...` handling.
- Localized the complete product-sync page headings and descriptions for
  Persian and English while keeping the technical authenticated endpoint
  visible and left-to-right.
- Corrected the Google Sheets dashboard warning metric to count the `warning`
  sync status instead of populated shipping-method metadata, with a bilingual
  customer-facing label.

## [1.7.3] - 2026-07-26

### Fixed
- Kept guarded Excel pricing synchronization scoped to the exact configured
  source and dataset while treating a valid newer local source revision as
  non-blocking, fully Persian warning metadata without weakening state,
  idempotency, preview-digest, or explicit apply-confirmation controls.

## [1.7.2] - 2026-07-26

### Fixed
- Added the exact Product Code to every successful batch pricing-assignment
  projection so strict Patris consumers can validate nested assignment identity
  without an SKU or alternate-identity fallback.

## [1.7.1] - 2026-07-26

### Fixed
- Restored exact managed product-category Code lookup and permanent historic
  slug redirects on WooCommerce installations that inject menu-order term
  metadata, using explicit term-meta clauses and deterministic term-ID order.

## [1.7.0] - 2026-07-26

### Added
- Added a capability-gated `wp digitalogic seo-monitor status` command backed
  by a fixed privileged declassifier that exposes only bounded counters,
  controlled state, and keyed fingerprints while preserving the private
  root-owned state boundary.
- Added administrator-only WooCommerce product supplier links with a Persian
  classic-editor panel, redacted product-list summaries, protected metadata,
  exact product targeting, and stdin-only WP-CLI write commands.
- Added an explicit Patris storefront pricing policy and non-mutating `wp digitalogic pricing audit` command that report canonical Patris, WooCommerce regular, promotion, and effective prices separately.
- Added version-controlled, secret-free production sources and tests for the Apache/PHP-FPM watchdog, SIP notifier, gated n8n event workflow, and plan-first deploy/rollback procedures.
- Added a persisted, optional sticky first product column that follows the first visible/reordered column in RTL and LTR while keeping the selection control frozen beside it.
- Added reusable Digitalogic browser error pages with responsive light/dark styling, English and Persian copy, RTL/LTR layout, safe recovery actions, and stable support references.
- Added an opt-in Google Sheets product/pricing control workspace with bounded preview/apply writeback, exact Patris identity and revisions, append-only audit rows, guarded WooCommerce product writes, and an inactive credential-placeholder n8n proxy template.
- Added an idempotent professional Google Sheets control-center builder with live catalog KPIs, charts, a landed-price calculator, bilingual guidance, editable non-secret settings, protected reference tabs, and one-command synchronization and scheduling.
- Added a source-scoped Excel pricing-settings state/preview/apply contract with
  Persian catalog pages, versioned dollar/yuan/profit-margin inputs, bounded
  audit history, and companion-triggered canonical product regeneration.

### Security
- Redacted private supplier URLs, seller details, source titles, and notes from
  the default WP-CLI list output while retaining safe operational status.
- Native Digits captcha controls now remain required and visible unless the configured reCAPTCHA replacement is successfully prepared; the branding layer no longer disables an unconfigured challenge.
- Google Sheets writeback uses exact-decimal optimistic revisions, idempotent requests, a shared WooCommerce product lock, and transactional shipping compare-and-set apply/compensation so concurrent changes are preserved.
- Excel pricing settings require the existing exact-scoped Patris machine
  secret, quoted optimistic revisions, bound expiring previews, explicit APPLY
  confirmation, idempotency, transactional readback/rollback, and seven-day /
  seven-percent operator warnings without placing credentials in the workbook.

### Fixed
- Migrated integration-managed WooCommerce product categories from public
  `patris-*` slugs to stable `product-category-<code>` URLs, retained exact
  permanent redirects, preserved adopted/manual slugs, and made homepage source
  category selection resolve through the authoritative category-code metadata.
- Separated the exact source-neutral Product Code from WooCommerce SKU in
  `wp digitalogic products list`, preserving both fields without an identity
  fallback.
- Consolidated the login identity normalizer, preserved password selections through visibility toggles, localized username-step controls, added keyboard-complete language selection, and removed the duplicate checkbox glyph across RTL/LTR light/dark login states.
- Enforced explicit notification channel allow-lists in both n8n routing and the notifier, kept endpoint/category preferences after the channel gate, and counted PHP-FPM slow requests by canonical request headers instead of stack lines.
- Styled the reserved `Changes` and `Audit` support rows as professional workflow panels while preserving staged values, append-only audit data, legacy layouts, and frozen table headers.
- Made the n8n Google Sheets writeback template return the actual Digitalogic JSON envelope on n8n 2.x instead of ending the webhook early with an empty HTTP 200 response.
- Repaired critical `/panel/` rendering and inline-edit regressions: title direction is always callable, pointer edits place a collapsed caret at the clicked text position, Patris currency uses a canonical clearable selector, and render/bootstrap failures now show a localized recovery screen with structured, deduplicated console diagnostics.
- Corrected Persian product-table geometry, including the `Ctrl+K` hint, exact checkbox centering, compact/mobile metadata containment, stable action-column sizing, and removal of the empty row tail caused by responsive column tracks.
- Made the current Patris reconciliation report usable across warning and price-list views with bounded 50-row pages, category filters, request deduplication, stale-response protection, client-language labels, visible generation details, catalog-generation invalidation that cannot republish stale in-flight data, and a server-side build lock against concurrent forced refreshes.
- Prevented the private storefront request post type from registering `manage_woocommerce` as an object meta capability, which caused WordPress to deny valid administrator and shop-manager access across the panel, admin, REST, and WebSocket paths.
- Centralized panel authorization across the browser shell, in-process Laravel bridge, AJAX command dispatcher, and authenticated WebSocket path, including a safe WordPress-administrator fallback without granting storefront customers access.
- Replaced the panel's raw WordPress `wp_die()` authorization response with a scoped, escaped Digitalogic 403 document that does not expose a Query Monitor call stack.
- Made the `/panel/` and nested panel rewrite rules self-healing when WordPress retains the plugin's rewrite-version marker but another deployment or permalink refresh drops the stored routes.
- Made panel launches strictly same-origin and in-process using the existing WordPress session; removed the panel token, session handoff, external-panel mode, and copied identity headers.
- Prevented the custom Digits login and registration footer from blocking a PHP-FPM worker on remote WordPress.org translation discovery while preserving the locale already selected by WordPress.

### Changed
- Patris price ingestion now preserves existing promotions by default, leaves variable-parent storefront prices derived from variations, and invalidates product caches after safe writes; promotion replacement requires the explicit `replace_sale` policy.
- Patris catalog publication now fails closed and returns a managed leaf to hidden draft unless source and WooCommerce prices, stock and weight, reviewed WooCommerce media, currency-qualified freight, markup, exchange rate, pricing assignment, canonical shipping, identity, category, enrichment, and source warnings all pass their readiness gates.
- Canonical `air_express` assignment now remains on the in-memory WooCommerce product through its final materializer save instead of risking a stale-object overwrite.
- Product JSON-LD adds the reviewed English Patris leaf or family name as `alternateName`, removes impossible offers for empty or zero WooCommerce prices, and atomically converts complete Toman offer subtrees to their exact ISO `IRR` equivalent without float drift or partial currency relabelling.
- Standardized user-facing Persian product identity labels as `کد کالا` and `سریال کالا` across translated notices, ACF fields, WooCommerce attributes, cart/checkout data, order and invoice metadata, rendered content, and the Google Sheets catalog while preserving internal source identifiers.
- Shipping rates now carry an explicit `CNY` or `IRR` currency. Method objects use `price_per_kg` plus `currency`, while product-sync records use the required pair `shipping_price_per_kg` plus `shipping_price_per_kg_currency`.
- Final-price validation converts CNY freight with the effective CNY-to-IRT rate and converts IRR freight to IRT before applying markup and a single final rounding step.
- Shipping amounts, minimums, divisors, and tier bounds/rates now remain canonical decimal strings through storage and every outward projection, without exponent notation or binary-float loss.
- Product sync preserves missing versus explicitly null freight fields, and the one-time installed-data migration bypasses stale option caches and verifies persistence before marking completion.

## [1.6.5] - 2026-07-21

### Fixed
- Restored the narrowly scoped Woodmart/Digits sidebar compatibility layer so only the active login step is visible and the singleton call-verification control mounts immediately beneath the OTP resend action.
- Restored Persian caller-ID guidance, keyboard semantics, RTL/mobile containment, scoped notice styling, and cache-busted sidebar assets without duplicating forms or verification widgets.

## [1.6.4] - 2026-07-21

### Fixed
- Replaced the temporary public inbound verification menu with a caller-ID-gated shortcut for active 120-second challenges, preserving the original direct-to-operator call flow for everyone else.

### Changed
- Poll verified calls every 500 ms for immediate browser-bound login completion, with cancellation, expiry, replay, rate-limit, and stale-request safeguards.
- Mix all verification speech with the reviewed low-volume PBX background music and keep code collection private inside AGI.

## [1.6.3] - 2026-07-21

### Added
- Exposed the existing inbound-call login verification beneath the active Digits OTP resend control in the Woodmart sidebar, with singleton AJAX remounting, Persian/RTL and mobile containment, keyboard disclosure semantics, and live dial instructions.

## [1.6.2] - 2026-07-21

### Fixed
- Restored the Digits password/verification-code layout in the guest Woodmart login sidebar, including scoped RTL, responsive, OTP-help, loading, error, honeypot, and keyboard-accessibility safeguards.

## [1.6.1] - 2026-07-21

### Fixed
- Preserved WordPress account-policy authentication filters while exempting only WP Zero Spam's core form honeypot from a signed, browser-bound PBX login consume request.

## [1.6.0] - 2026-07-21

### Added
- Secure six-digit phone verification by inbound call on `021-66754123`, IVR option `2`, as a Digits-independent login alternative and a way to verify supplemental Iranian mobile or fixed-line contacts.
- Multiple supplemental phone and email contacts in WooCommerce My Account and WordPress user profiles, with per-phone voice consent and order-event preferences.
- Disabled-by-default, asynchronous WooCommerce status announcements through the loopback PBX callout service, including a global kill switch, per-status Persian templates, strict placeholders, quiet hours, rate limits, consent rechecks, and idempotent jobs.
- Signed, replay-resistant PBX callback contract at `/wp-json/digitalogic/v1/call-verification/pbx-confirm`, encrypted contact storage, database-backed verification rate limits, and focused PHP protocol tests.
- Transactional consent audit records, reason-required administrative consent expansion, bounded retention, per-canonical-number outbound limits, and a reconciled voice-job queue.

### Security
- Phone ownership codes are stored only as keyed MACs, browser challenges use opaque HttpOnly bindings, and verified challenges are consumed atomically once.
- PBX verification secrets and callout credentials are read only from `wp-config.php`; callback bodies, identifiers, timestamps, DID/ANI values, and exact HMAC signatures are bounded and validated before use.
- PBX schema availability is fail-closed until every required InnoDB table, column, index, cleanup task, and recovery schedule verifies successfully; signed callback attempt limits reserve capacity atomically before code matching.

## [1.5.2] - 2026-07-21

### Added
- Displayed the canonical Patris Code explicitly on product loops, Woodmart single-product layouts, selected variations, cart/checkout lines, order details, homepage product cards, and the table catalog.
- Kept unrelated WooCommerce SKUs labeled as SKU, hid only exact duplicate SKU output, and showed legacy child Codes as registered model references without implying that they are directly purchasable.
- Let table-catalog searches for a published variation Code return the parent product while blocking misleading quick-add actions for legacy code-less parents with coded child records.

## [1.5.1] - 2026-07-21

### Changed
- Prioritized products with real photos in the default table view while keeping explicit popularity, price, name, and date sorting available.
- Routed homepage category entry points into the professional table catalog instead of the legacy product grid.
- Added a touch- and keyboard-friendly carousel pause control, earlier stylesheet loading, hardened public form inputs, Persian/Arabic phone-digit normalization, and quick-add network failure recovery.

## [1.5.0] - 2026-07-21

### Added
- Restored a high-contrast, keyboard-accessible homepage carousel with original generated artwork for stocked modules, foreign sourcing, and two-/four-layer PCB production.
- Added two non-duplicated, inventory-backed product carousels with improved responsive controls.
- Added a professional RTL product-table catalog with search, category and sort filters, pagination, intentional image fallbacks, and native WooCommerce quick add.
- Added complete guest-friendly foreign-sourcing and PCB quote forms with repeatable line items, strict validation, private uploads, tracking codes, admin records, notifications, and request statuses.
- Added temporary openly licensed product-photo attribution support and a public image-credit register.

## [1.4.3] - 2026-07-21

### Fixed
- Read variation options through WooCommerce's variation-attribute API so reviewed children remain idempotent after creation and duplicate options are still rejected.

## [1.4.2] - 2026-07-20

### Added
- Hid product categories on the public storefront when they contain no catalog-visible products while preserving authoritative admin, CLI, and integration queries.
- Added an inventory-backed Persian homepage showcase with a varied in-stock product hero, focused category paths, China sourcing, and two-/four-layer PCB services without duplicated product rails.

## [1.4.1] - 2026-07-20

### Fixed
- Added an escaped, idempotent Woodmart single-product fallback so the reviewed English Patris identity renders immediately below the Persian product title even when the theme bypasses WooCommerce's standard summary hook.

## [1.4.0] - 2026-07-20

### Added
- Added an administrator-reviewed, dry-run-first Patris catalog materializer for positive-stock records from the living product-sync contract, including explicit simple-product adoption/creation and variation children under reviewed existing variable parents.
- Added stable Patris category ownership, additive category assignment, optional reviewed Persian category names, and explicitly referenced Digitalogic-only categories without overwriting unrelated manual taxonomy work.
- Added reviewed Persian product and category SEO metadata, short descriptions, part/model metadata, Rank Math sitemap cache invalidation, and publication readiness gates.
- Added a second storefront identity line for the original English Patris name, selected-variation identity updates, and product structured-data SKU/MPN enrichment.
- Added storefront and panel search coverage for Persian names, Patris names, exact Codes/SKUs, serials, part numbers, models, variation records, and product categories.

### Changed
- Reused the existing Patris feed writer for price, positive stock, weight, warehouse, warning, and pricing metadata, and assigned the canonical `air_express` supplier shipping method to materialized leaves.
- Kept newly created products and previously nonpublic reviewed targets as drafts unless every source, pricing, freight, category, identity, enrichment, and SEO publication gate passes and an administrator explicitly supplies `--publish-ready`; preserved already-published reviewed targets without counting them as newly published.

### Security
- Require strict manifests with exact source identity, reviewed target IDs, duplicate-key rejection, bounded input size, positive-stock filtering, and a named apply lock; refuse implicit variable-parent conversion or unreviewed leaf ownership.

## [1.3.6] - 2026-07-20

### Added
- Read-only, bounded Google Sheets catalog pages that reuse the canonical product, pricing, shipping-method, warehouse-stock, and category services.
- An import-ready Google Apps Script with separate Products/Categories tabs, exact Patris Code or display-only `woo:<id>` key upserts, bilingual RTL/LTR headers, manual refresh, idempotent scheduled synchronization, and Script Properties-only credentials.
- Standalone REST, WP-CLI, and n8n integration guidance with explicit status/error columns and per-record/page revisions.

### Changed
- Made Google Sheets catalog rows follow the living sparse response: missing keys mean no source/reference value, explicit upstream null remains `null`, and Patris matching never falls back to SKU.
- Apps Script now validates the requested dataset, column and row arrays, and pagination object directly.

## [1.3.5] - 2026-07-20

### Changed
- Replaced the product-sync payload families with one sparse living contract, including category and exclusion projections and explicit missing-versus-null semantics.
- Moved the four Patris-facing routes to the `digitalogic` REST namespace and removed raw-feed and pricing aliases.
- Standardized supplier shipping inputs, storage, events, and responses on one canonical field set without mirrored keys.

## [1.3.4] - 2026-07-20

### Added
- Added versioned `[dollar_rate]` and `[yuan_rate]` storefront cards that share the same currency effective-date service used by the WordPress admin and external panel.
- Added strict regression coverage for legacy YYMMDD storage, ISO dates and date-times, invalid/empty values, Persian and English locales, and the production `260629` case.

### Fixed
- Prevented legacy YYMMDD currency dates from being interpreted as Unix timestamps and displaying an epoch-era Jalali year such as `۱۳۴۸`.
- Read the raw `options_update_date` value before ACF/wp-parsidate formatting, return a blank value for invalid dates instead of silently substituting today, and provide deterministic built-in Jalali conversion with Persian digits for `fa*` locales.

## [1.3.3] - 2026-07-20

### Added
- Added product `category_code`, the complete typed category projection, and excluded catalog Codes, including Go-compatible category, source, and event identity verification.
- Persisted the catalog projection in receiver state, exposed bounded category/exclusion counts in receiver responses and status output, and mirrored each product's category Code to `_digitalogic_patris_category_code` through the shared WooCommerce writer.
- Added a Go-compatible golden fixture and focused catalog, tamper, identity, and sparse-value coverage.
- Added the production-proven pre-save WooCommerce change capture to `product.updated` webhooks, with compact `changed_fields` values and date/scalar normalization.

### Changed
- Reduced peak memory during transactional receiver readback by comparing the exact stored serialization digest instead of serializing both the expected and read-back state again.
- Ported the live login loading-state fixes so button text is hidden behind a centered spinner, loading stripes loop cleanly in LTR/RTL, and Persian retry messages no longer claim the form was released.

## [1.3.2] - 2026-07-17

### Changed
- Added grouped Dependabot maintenance for Composer and GitHub Actions so dependency updates remain reviewable and independently testable.
- Updated the release and CI workflows to current `actions/checkout`, `actions/cache`, `actions/download-artifact`, and `actions/upload-artifact` generations.
- Updated the WordPress Coding Standards development dependency from 3.3.0 to 3.4.0 without changing the production dependency set.

## [1.3.1] - 2026-07-17

### Fixed
- Restored read-only WooCommerce minimum/maximum price ranges in the product grid without removing the current editable price fields or reintroducing per-row database queries.

## [1.3.0] - 2026-07-17

### Added
- Exact product and variation access by canonical WooCommerce ID or case-sensitive SKU across REST and WP-CLI.
- Read-only product metadata diagnostics showing effective WooCommerce values, raw lookup-source values, and stale derived rows.
- Safe one-product lookup refresh on WooCommerce versions exposing the public row API, with no catalog-wide fallback.
- Server-side product table filtering, sorting, persistent views, aligned configurable columns, and bounded pagination across transports.
- Native WordPress currency-page postboxes and locale-aware zero-decimal currency summaries.
- Read-only WooCommerce base-currency monitoring with explicit IRT/Toman metadata, catalog compatibility warnings, shared REST/CLI/panel/webhook status, audit events, and non-destructive automated coverage.
- Optional signed `patris.product_sync.applied` observer summaries through the existing webhook fan-out, without exposing product payloads or affecting direct sync outcomes.
- Bounded product-sync deferred reconciliation state and administrator WP-CLI status/retry commands, separating terminal missing/ambiguous Codes from transient HTTP/database retries.
- Canonical nullable global default percentage markup with exact decimal storage, REST/command/admin controls, catalog revisioning, and result-aware delivery events.
- **WordPress Admin Bar integration** with quicklinks menu
  - Parent menu item with WooCommerce-focused cart icon (dashicons-cart)
  - Quicklinks to Dashboard, Products, Currency, Import/Export, Logs, and Status pages
  - Contextually appropriate Dashicons for each menu item (dashboard, products, money-alt, database-import, list-view, info)
  - Capability checks to ensure only authorized users see the menu
  - Works on both front-end and back-end when admin bar is displayed
  - Optimized CSS styling for admin bar menu items
- **Complete ACF function hooks** for total bidirectional synchronization
  - Hook into ACF's `acf/update_value` filter to sync back to direct options
  - Hook into ACF's `acf/load_value` filter to ensure consistency
  - Even direct ACF function calls now trigger synchronization
- **Fallback ACF-compatible functions** when ACF is not installed
  - Provides `get_field()` function if ACF not present
  - Provides `update_field()` function if ACF not present
  - Plugin now works standalone without ACF dependency
- **ACF availability detection** with `is_acf_available()` method
- **Clickable dashboard stat boxes** with hover effects and navigation
  - Total Products box links to Products page
  - USD/CNY Price boxes link to Currency page
  - Last Update box links to Currency page
  - Smooth hover animations with shadow elevation
- **Enhanced option hooks** to use plugin methods for complete control
  - All `get_option()` calls redirect to plugin methods
  - All `update_option()` calls redirect to plugin methods with logging
- **Infinite loop prevention** in all synchronization hooks
- **Activity logging** for all option updates, even when using direct WordPress functions
- Custom Digitalogic branding icon integrated throughout the plugin
- Square (1:1) SVG icon for WordPress admin menu
- Monochrome version for dashicons compatibility
- Icon assets added to repository for documentation and branding
- WooCommerce High-Performance Order Storage (HPOS) compatibility
- Full support for WooCommerce 8.2+ custom order tables
- Declaration of HPOS compatibility via FeaturesUtil
- Status & Diagnostics page with system information and HPOS status
- Global `get_field()` and `update_field()` functions for ACF-style compatibility
- Option synchronization hooks to ensure `get_option()` and `get_field()` always return the same values
- Persian date formatting support via parsidate plugin integration
- Helper functions for consistent date formatting throughout the plugin
- Automatic Persian (Jalali) calendar support when locale is fa_IR

### Changed
- Preserved the historical positional-ID `--sku` setter while introducing explicit `--set-sku` selection semantics.
- Refreshed Persian translation catalogs and removed the stale tracked binary catalog from source control.
- Made WooCommerce base-currency and Patris IRT readiness visible without mutating the store currency.
- **Plugin now works both WITH and WITHOUT ACF installed**
  - Checks ACF availability on initialization
  - All get/set methods adapt based on ACF presence
  - Graceful degradation when ACF not available
  - Full functionality maintained in both scenarios
- **CORRECTED**: Full synchronization between `get_option()` and `get_field()`
  - ACF stores options with `options_` prefix (e.g., `options_dollar_price`)
  - Added filters to redirect `get_option('dollar_price')` to `get_option('options_dollar_price')`
  - Both `get_option('dollar_price')` and `get_field('dollar_price', 'option')` now return the SAME value
  - All write operations (`update_option`, `add_option`) synchronize to both storages
  - Automatic bidirectional synchronization ensures consistency
- Automatic migration: removes incorrect `digitalogic_` prefix and syncs with ACF storage
- True field sharing with ACF when installed
- Updated product meta data handling to use WooCommerce CRUD methods
- Date formatting now supports Persian calendar via parsidate plugin
- Update date display now shows formatted date based on user's language
- Replaced direct `get_post_meta`/`update_post_meta` calls with `$product->get_meta()`/`$product->update_meta_data()`
- Updated bulk price recalculation to use WooCommerce product queries
- Improved import/export functions to be HPOS-compatible
- Fixed Persian brand name spelling: "دیجیتالوجیک" → "دیجیتالاجیک"
- Disabled dark mode to force light mode for consistent UI
- Enhanced plugin page with action links and row meta links
- Fixed WP-CLI command registration to prevent "can't have subcommands" error

## [1.2.0] - 2026-07-16

### Added
- Dedicated authenticated `patris.product-sync` REST receiver.
- Strict typed envelope validation, recursive raw-field rejection, receiver-side exact `landed_price` evaluation, Go-compatible record/source/event hash verification, and duplicate-key-safe JSON decoding.
- Ordered per-source snapshots, timestamp-bound event identities, update merging, bounded replay protection, quarantine preservation, and deletion-only tombstones that never delete WooCommerce products.
- Dedicated header-only receiver secrets with optional exact source scopes, plus a durable per-product WooCommerce outbox with record-hash CAS recovery.
- Receiver contract and staged rollout documentation.

### Changed
- The normalized Patris WooCommerce writer is shared by all current ingestion paths so Code resolution, weight, stock, price, and metadata behavior stay aligned.

## [1.1.0] - 2026-07-16

### Added
- Canonical supplier shipping-method catalog, immutable method IDs, and exact product/variation assignment APIs.
- Shared exact product identifier resolver with WooCommerce ID, SKU, and Patris Code precedence.
- `landed_price` integration catalog and percentage-markup contract for Patris Export.
- Result-aware durable panel queue, Redis/WebSocket, and multi-destination webhook delivery reporting.

### Changed
- Patris gram weights are converted into the configured WooCommerce store weight unit.
- Supplier shipping-method writes use verified InnoDB transactions, authoritative rollback, and cache invalidation.

## [1.0.0] - 2024-12-08

### Added
- Initial release of Digitalogic WooCommerce Extension
- Multi-currency support (USD and CNY exchange rates)
- Dynamic pricing engine with markup support
- Interactive product management with DataTables
- Real-time AJAX updates with 60-second polling
- Bulk product update capabilities
- REST API endpoints for external integrations
  - Products CRUD operations
  - Currency rate management
  - Bulk operations
  - Export functionality
- Webhook notifications for real-time updates
  - Product created/updated events
  - Currency rate change events
  - HMAC signature verification
- WP-CLI commands
  - Product management
  - Currency rate updates
  - Import/Export operations
  - Activity log viewing
- Import/Export functionality
  - CSV format support
  - JSON format support
  - Excel support (via composer packages)
- Activity logging and audit trail
  - User action tracking
  - IP address logging
  - Change history
- Admin interface
  - Dashboard with statistics
  - Product management page
  - Currency settings page
  - Import/Export page
  - Activity logs viewer
- UI/UX features
  - Light/dark mode support
  - RTL/LTR language support
  - Responsive design
  - Inline editing
- Internationalization support
  - POT template file
  - Persian (primary) language support
  - English (secondary) language support
- Security features
  - CSRF protection with nonces
  - Capability checks
  - SQL injection prevention
  - XSS prevention
  - Input sanitization and validation
- CI/CD workflows
  - GitHub Actions for testing
  - PHP 8.0, 8.1, 8.2, 8.3 compatibility testing
  - Code quality checks (PHPCS)
  - Automated deployment workflow
- Documentation
  - Comprehensive README
  - API documentation
  - Installation guide
  - Contributing guide
  - Code examples

### Technical Details
- Minimum PHP version: 8.0
- Minimum WordPress version: 6.0
- Minimum WooCommerce version: 7.0
- Database schema for activity logs
- Custom options for currency rates
- Product meta for dynamic pricing

### Database
- Created `wp_digitalogic_logs` table for activity logging
- Added options: `dollar_price`, `yuan_price`, `update_date`
- Added product meta keys: `_digitalogic_dynamic_pricing`, `_digitalogic_currency_type`, `_digitalogic_base_price`, `_digitalogic_markup`, `_digitalogic_markup_type`

## Roadmap

### Planned Features
- Excel import/export with PhpSpreadsheet library
- Advanced pricing rules (quantity-based, customer-based)
- Support for additional currencies (EUR, GBP, etc.)
- Integration with popular accounting software
- Mobile app API endpoints
- Advanced reporting and analytics
- Scheduled currency rate updates
- Price history tracking
- Product comparison tool
- Bulk edit templates
- Custom fields for products
- Integration with POS systems
- Barcode scanning support
- Stock alerts and notifications
- Automatic backup of product data
- Multi-warehouse support

### Known Issues
- None reported

---

## Version History

- **1.0.0** (2024-12-08) - Initial release

---

## Migration Notes

### From Custom Solutions
If migrating from a custom solution:
1. Export your existing product data to CSV
2. Install and activate Digitalogic plugin
3. Configure currency rates
4. Import your product data
5. Configure dynamic pricing rules as needed

### Upgrading
- Always backup your database before upgrading
- Test upgrades in a staging environment first
- Review changelog for breaking changes

---

## Support and Feedback

- Report bugs: https://github.com/atomicdeploy/digitalogic-wp/issues
- Feature requests: https://github.com/atomicdeploy/digitalogic-wp/discussions
- Documentation: https://github.com/atomicdeploy/digitalogic-wp/wiki
