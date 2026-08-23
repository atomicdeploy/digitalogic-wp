# Pricing projection snapshot API

This additive machine API lets a trusted Patris companion validate pricing
state cheaply, build the reconciled WooCommerce/Patris projection once, and
then download immutable bulk or paged data without rebuilding the union for
each page. Pricing state, preview, apply, snapshot, and event delivery form one
Living contract; no versioned compatibility alias is retained.

## Security and credential boundary

Every route requires the existing `X-Patris-Product-Sync-Secret` and a
configured exact `{source_id, source_dataset}` scope. The secret remains in the
server-side Patris companion; it must never be placed in a workbook, VBA,
response, URL, or log.

GET, HEAD, status, cancel, bulk, and page requests repeat these nonsecret query
parameters:

- `source_id`
- `source_dataset`
- `source_revision` as lowercase `sha256:` plus 64 hexadecimal characters
- `locale=fa` (the `fa_IR` alias normalizes to `fa`)
- `page_size=250`

The revision and build-start surfaces require the submitted source revision to
equal the revision currently materialized in WordPress. An already-created
build or snapshot remains readable until its own expiry after the source moves,
provided its stored source identity still equals the request query.

## Cheap composite revision

`GET` or `HEAD /wp-json/digitalogic/pricing/sync/revision` returns a strong
`ETag` without loading the WooCommerce product projection. `If-None-Match`
returns `304` with the same private revalidation policy.

WP Rocket normally writes a global `Header unset ETag` directive into its
Apache marker. Digitalogic filters `rocket_htaccess_etag` so that exact stock
directive remains active everywhere except the pricing revision request. The
generated Apache 2.4 rule matches `THE_REQUEST`, rather than `REQUEST_URI`,
because WordPress's per-directory rewrite can change the latter before response
headers are finalized. WP Rocket's `FileETag None` static-file policy remains
unchanged. If WP Rocket changes the expected generator shape, the filter leaves
the complete upstream block untouched instead of broadening the exception.

The persistent report generation is installed by plugin activation and the
bounded admin migration hook. Revision GET/HEAD never creates that option (or
the receiver secret); if installation has not completed, it fails closed with
`digitalogic_report_generation_uninitialized` instead of inventing an
unstable request-local revision.

The composite `state_revision` binds:

1. the exact canonical Patris source revision;
2. the persistent catalog/report generation, including bounded source
   freshness state;
3. the pricing settings revision and pricing-policy schema;
4. the stable Living `excel` projection schema identity.

Locale and the fixed transport page size are part of the build key, while the
state revision itself represents pricing-relevant state.

```json
{
  "schema": "digitalogic.pricing-sync-revision",
  "projection": "excel",
  "projection_schema": "digitalogic.pricing-projection/excel",
  "state_revision": "sha256:COMPOSITE_STATE",
  "source": {
    "id": "patris-office",
    "dataset": "kala.db",
    "revision": "sha256:SOURCE_REVISION"
  },
  "catalog_revision": "sha256:CATALOG_GENERATION",
  "pricing_state_revision": "sha256:PRICING_SETTINGS",
  "pricing_policy_revision": "sha256:PRICING_POLICY",
  "locale": "fa",
  "page_size": 250
}
```

## Event-driven revision changes

The Patris companion subscribes outbound to
`wss://digitalogic.ir/wordpress-ws` with WebSocket subprotocol
`digitalogic.pricing`. It supplies the existing product-sync credential and
exact source scope only in these handshake headers:

- `X-Patris-Product-Sync-Secret`
- `X-Patris-Source-Id`
- `X-Patris-Source-Dataset`
- `Last-Event-ID` when resuming a previously persisted cursor

Each protected or resume header must occur exactly once. `Last-Event-ID` is a
nonnegative decimal integer no larger than the server's integer range; an
ambiguous duplicate or invalid cursor fails the handshake instead of silently
starting at the newest event.

The credential remains server-side in the companion. It must not appear in a
query string, workbook, VBA, event, response, or log. The resulting
`patris_pricing` principal is read-only: it may ping but cannot dispatch any
WordPress command. It receives only these exact-source event kinds:

- `pricing.source.changed` with change `added` or `changed` and schema
  `digitalogic.pricing-source-change`;
- `pricing.source.removed` with change `removed` and the same source schema;
- `pricing.state.changed` with schema
  `digitalogic.pricing-state-change` and cause
  `projection-invalidated` or `freshness-boundary`;
- `pricing.snapshot.build.terminal` with schema
  `digitalogic.pricing-snapshot-build-event` when a request-bound snapshot
  build becomes `ready`, `failed`, or `cancelled`;
- `pricing.stream.reset` with schema
  `digitalogic.pricing-stream-reset` when durable replay has a gap.

Every data event has the globally increasing durable panel `id`. Source
lifecycle envelopes contain `source`, `previous_source_revision`,
`idempotency_key`, `revision_validation_required=true`, and `revision_path`.
A state envelope additionally contains the composite `state_revision`, its
exact quoted strong `etag`, and the component `catalog_revision`,
`pricing_state_revision`, and `pricing_policy_revision`. All envelopes are
nonsecret and carry `audience.services=["patris_pricing"]`. A removal retains
the last valid source identity so an exact-source subscriber can consume the
terminal event; the following conditional revision request is authoritative
and fails closed because that source is no longer current.

A snapshot terminal envelope contains the exact `build_id`, request-bound
`request_id`, source, composite/pricing/catalog revisions, stable
`idempotency_key`, and boolean `retryable`. A `ready` envelope additionally
contains `snapshot_token`, equal `snapshot_revision`/`digest`, and the exact
source-bound `snapshot_path`; `failed` and `cancelled` envelopes contain only a
bounded machine `code` instead of snapshot fields. The envelope permits no
additional top-level or nested source fields.

The initial frame is exactly a normal JSON WebSocket message shaped as:

```json
{
  "event": "connected",
  "success": true,
  "data": {
    "user_id": 0,
    "principal": "patris_pricing",
    "cursor": 123,
    "oldest_event_id": 100,
    "latest_event_id": 123,
    "cursor_reset_required": false,
    "revision_validation_required": true,
    "revision_path": "/wp-json/digitalogic/pricing/sync/revision"
  }
}
```

Each replay/live data frame uses
`{event,name,success:true,data,time,id}`; `id` is the durable cursor and
`event`/`name` are the dotted event kind above. A live gap uses event
`pricing.stream.reset` with reason `cursor_gap`, the reset cursor and retained
window, and `revision_validation_required=true`. The reset frame itself is a
control message and does not allocate a new panel event ID.

Committed WordPress catalog, product, variation, attachment, category,
shipping, currency, pricing, and source-sync mutations first advance the
persistent report generation. Exact receiver-state commits and successful
direct receiver-option additions, changes, or deletions also write a durable
source-lifecycle outbox after commit. Source lifecycle entries are drained in
insertion order before the composite state entry derived from them. A failed
head entry remains durable and blocks later entries until the bounded
asynchronous retry succeeds, preserving causal order. State-event retries use
one exact pending action across PHP requests; a per-fallback scheduler mutex
permits a running worker to create one replacement without parallel retry
chains. After a mutex timeout, WP-Cron is accepted only after exact persisted
readback; if that path is unavailable, Action Scheduler atomically coalesces the
exact fallback in a content-addressed group without evicting a distinct identity.
If that group's primary action is already in progress, one alternate
content-addressed handoff remains pending and converts under the same exact
scheduler mutex to one primary successor without contending on the
already-running hook. A throwing Action Scheduler, WP-Cron, or mutex adapter is
isolated so the other verified scheduler path can still retain the intent. If
the handoff mutex itself is unavailable, one additional atomic recovery relay
retains the exact arguments until a later transition can insert the primary.
Pending readback is tri-state: an unreadable scheduler never enables a
non-unique insert, and persistent degraded retries remain bounded by the three
content-addressed primary/handoff/recovery hooks without collapsing identities.
Deployment preflight and post-deploy readback must count the primary, handoff,
and recovery hooks in both Action Scheduler and WP-Cron. A rollback to a plugin
version that does not register the relay hooks must preserve any residual rows
as incident evidence until an operator reviews their exact arguments and state;
cleanup is deliberate, not an automatic or wildcard deletion.
The outbox carries the exact delivered identity until its one-hour receipt
commits. Receipts retain only the newest 200 source identities, and exact source
removal durably clears both its normalized receipt and delivered-state outbox
marker before the ordered lifecycle event is published. If the same source is
already current again, removal instead rebuilds its current outbox entry without
the retired delivered marker, preserving ordered removal/addition before the
fresh composite event. The retained panel
identity is a secondary fence, so late
fallback actions cannot repersist or append the same composite idempotency key
after panel rotation. Redis is only a
low-latency wake-up. If that publish fails, the newest failed wake is retained
in a persistent outbox and retried by one coalesced, non-recurring WP-Cron
action. Any valid wake makes the WebSocket process drain the ordered durable
panel queue from each connected cursor. The daemon also replays once at the
initial handshake and after Redis reconnect; it has no timer-driven pricing
state or durable-queue poll. Delivery is at-least-once, and the companion
deduplicates by `idempotency_key` before persisting its local cursor. An
idempotent apply replay creates no new invalidation, outbox item, or event.

The connected frame includes `cursor`, `oldest_event_id`, `latest_event_id`,
`cursor_reset_required`, and `revision_validation_required`. A cursor outside
the retained window, or behind a durable sequence whose retained queue is
empty, is clamped safely and explicitly requires validation. The global panel
queue retains at most 200 data events; event IDs remain strictly increasing
across event kinds and Redis delivery order never defines the cursor.
The companion performs one conditional revision `HEAD`/`GET` after every
initial connection, reconnect, or cursor reset, then follows WSS events
continuously. This request closes delivery gaps; it is not a polling loop.
Excel never polls WordPress. Before starting an asynchronous build, the Patris
companion registers its request-bound terminal waiter; a `202` response is then
completed only by the durable terminal event. Build-status routes remain for
diagnostics and recovery, not for that production wait.

The daemon sends no timer-driven WSS heartbeat. It replies to a
WebSocket control Ping with Pong and also accepts the read-only JSON
`{"id":"...","command":"ping"}` form, replying with event `pong`; the
companion owns its idle-timeout, disconnect, exponential-backoff reconnect,
and Ping cadence. It persists `Last-Event-ID` only after the corresponding
event has been durably accepted by its local journal. On every reconnect it
repeats the exact headers and performs the required conditional revision
validation before trusting a cached snapshot.

The daemon's socket-select timeout and bounded Redis reconnect backoff are
transport liveness mechanics only: while waiting or while a reconnect attempt
fails they do not query pricing state or the durable event queue. One durable
replay runs only after a Redis subscription is successfully re-established.

Time-derived revision changes do not use a recurring poll or cron loop. When a
currency effective date, source timestamp, or stale-window setting is stored,
the plugin cancels/replaces one Action Scheduler action for only the earliest
future transition. The action emits one `pricing.state.changed` event at the
currency effective boundary, the currency stale boundary, or a source-product
stale boundary, then schedules only the next future transition. Activation
installs the one-shot action, deactivation removes it, and a bounded admin
migration hook repairs a missing action without recomputing an already-tracked
schedule.

Action Scheduler and the WP-Cron fallback are traffic-driven and cannot
guarantee execution at the exact wall-clock second on a quiet or unhealthy
site. The event therefore may be delivered late, but it is never deliberately
scheduled before the threshold and no recurring poll is installed. The
mandatory conditional revision validation on initial connection, reconnect,
or reset remains authoritative if delivery was late or the retained event
window was missed.

Proxy forwarding of the three protected headers, WSS listener health, Redis
durability, and the SLOs below require a reviewed production smoke test. Never
use the legacy query-token WebSocket example for the Patris pricing principal.

## Start and single-flight build

`POST /wp-json/digitalogic/pricing/sync/snapshots` accepts:

```json
{
  "schema": "digitalogic.pricing-snapshot-request",
  "operation": "snapshot",
  "client_id": "patris-export",
  "channel": "excel-workbook",
  "request_id": "snapshot-20260816-0001",
  "idempotency_key": "snapshot-20260816-0001",
  "source": {
    "id": "patris-office",
    "dataset": "kala.db",
    "revision": "sha256:SOURCE_REVISION"
  },
  "locale": "fa",
  "page_size": 250,
  "max_age_seconds": 900,
  "expected_state_revision": "sha256:COMPOSITE_STATE"
}
```

The `Idempotency-Key` header, `request_id`, and `idempotency_key` must be
identical. `If-Match` must contain the exactly quoted composite revision. The
complete normalized request, including provenance and `max_age_seconds`, is
bound to the idempotency fingerprint.

Only one cold projection build owns the bounded worker slot. An identical
build coalesces to its leader; a different cold build receives fast `429` with
`Retry-After`. Independent Action Scheduler and WP-Cron one-shots run the cold
work outside the HTTP request. The existing worker lease makes the first runner
authoritative and a late sibling a no-op. If neither activation path persists,
the worker misses its 30-second start window, stops heartbeating, exceeds its
fixed lifetime, or loses storage, the build becomes a machine-readable
retryable `503` rather than waiting in an HTTP queue.

Production also installs the source-controlled
`digitalogic-wordpress-cron.timer`. Its non-overlapping oneshot executes due
WordPress events under the WordPress runtime identity every 10 seconds. This is
the autonomous no-traffic runner for the durable WP-Cron record; it does not
poll pricing state or invoke the snapshot worker directly. Production disables
built-in visit-triggered WP-Cron so this non-overlapping timer is the sole core
cron executor; the Action Scheduler record remains an independent wake path.

Every coalesced request ID is persisted on the leader and receives its own
request-bound terminal envelope. Before the job becomes terminal, all of those
envelopes are staged in a persistent outbox. After the exact job commits, they
are promoted to a job-independent committed phase, so delivery survives expiry
of the short-lived build record. Delivery rejects request or payload mismatch
and uses a retained panel event as a near-term receipt. The stream remains
at-least-once: after receipt rotation, the companion deduplicates any replay by
the stable `idempotency_key` before advancing its cursor.

Admission also schedules the random-token, per-build watchdog independently on
Action Scheduler and WP-Cron before returning `202`. It is re-armed only at the
next queue, lease, or fixed-build deadline and terminalizes a missed action,
expired worker lease, or crashed process without a status request. Exact
build/token fencing makes the first watchdog authoritative, and every terminal
path clears both build and watchdog schedules. The worker converts an uncaught
throwable into the same bounded, secret-free failure path. Terminal-event
delivery continues to require at least one durable one-shot retry path before
terminal state can be committed.

The worker obtains the complete report once, rejects truncation or non-current
source state, and rejects every projection-integrity warning, including
`product_type_cache_drift`. It transforms the union in bounded chunks, verifies
unique nonempty `sync_key` values, stores immutable pages, and publishes the
ready pointer only with a terminal ready job. Cancellation, progress, failure,
and ready publication use the same admission mutex and exact worker lease.

## Build status and cancellation

- `GET /wp-json/digitalogic/pricing/sync/builds/{build_id}`
- `DELETE /wp-json/digitalogic/pricing/sync/builds/{build_id}`

Build statuses are `queued`, `running`, `ready`, `failed`, and `cancelled`.
Status responses include bounded progress, strong ETags, private revalidation,
and `Retry-After` for active or retryable-failed states. Conditional requests
never turn an active `202` or retryable `503` into `304`.

Cancellation records a monotonic tombstone. A queued cancellation releases the
slot immediately. A running cancellation releases it only after worker
acknowledgement or stale-owner cleanup. Ready snapshots are immutable and
return `409` to cancellation.

## Immutable payload and pages

- `GET /wp-json/digitalogic/pricing/sync/snapshots/{snapshot_token}` returns the
  complete bulk projection.
- `GET /wp-json/digitalogic/pricing/sync/snapshots/{snapshot_token}/pages/{page}`
  returns one fixed page.

Both support strong `ETag`/`If-None-Match`, private bounded cache headers, and
expiry. Every page response contains enough global metadata for independent
validation: source, composite and pricing revisions, settings, mutation guard,
reconciliation counts, ordered page digests, schema, columns, pagination, and
integrity fields. The server recomputes a page digest before considering a
conditional `304`; corrupt or missing cache state fails closed.

The Living `excel` projection pins exactly the 26 fields consumed by the
Excel calculator, in this order:

`sync_key`, `reconciliation_status`, `patris_code`, `woocommerce_id`, `sku`,
`weight_grams`, `foreign_price`, `patris_location`, `categories`,
`foreign_currency`, `shipping_price_per_kg`,
`shipping_price_per_kg_currency`, `profit_margin_percent`,
`price_source_amount`, `price_source_currency`, `price_source_kind`,
`effective_price`, `patris_total_stock`, `stock_quantity`, `name`,
`updated_at`, `record_revision`, `permalink`, `patris_final_price`,
`sale_price`, `publication_status`.

The wider canonical reconciliation surface remains an internal input. Missing,
duplicated, reordered, or unexpected public projection fields fail closed until
a compatible contract migration is reviewed. Top-level integrity metadata includes:

- `row_count`
- `distinct_sync_keys`
- `remote_total`
- `digest` and `snapshot_revision`
- `dataset_revision`
- `page_count` and ordered `page_digests`
- `state_digest`, `catalog_metadata_digest`, and `page_revisions_digest`
- reconciliation counts and warning count
- `created_at` and `expires_at`

For a healthy union, `row_count`, `distinct_sync_keys`, and `remote_total` must
be equal. Counts are computed from the current immutable build and are never
frozen from a production observation. The synthetic algebra fixture proves
that the projected union equals `matched + source_only + woo_only`, source rows
equal `matched + source_only`, usable WooCommerce rows equal
`matched + woo_only`, and raw WooCommerce rows equal usable rows plus excluded
variable parents. A deployment must read back the current totals; no historical
row count, source identifier, timestamp, or revision is a runtime constant.

## Mutation guard and invalidation

The payload's `mutation_guard.expected_state_revision` is the existing
pricing-only revision used by `/pricing/sync/preview` and
`/pricing/sync/apply`; it is intentionally distinct from the snapshot's
composite `state_revision`. Existing mutation requirements remain unchanged:
explicit full settings, `product_changes=[]`, exact idempotency, quoted
`If-Match`, preview digest, and literal `confirmation: "APPLY"`.

Only the original successful apply emits the committed invalidation action and
advances the catalog/report generation. An idempotent apply replay returns its
stored terminal result before the action, so it creates no new invalidation or
effect. Product, variation, category, attachment, consumed product-meta,
`pa_model` relationship/term, shipping/pricing, currency, URL, weight-unit,
source-sync, and Patris-feed mutations also advance the generation. Cached
reports additionally bind the time-relative source-freshness revision, so a
stale threshold transition cannot reuse a pre-transition report.

## Machine errors and SLO targets

Errors retain a root lowercase `code`, `message`, `retryable`, optional
`retry_after`, and bounded `details`. `429` and retryable `503` responses include
the HTTP `Retry-After` header.

The implementation is designed for these service targets, which require live
measurement after deployment:

- revision HEAD/GET, status, and cancel: 250-500 ms;
- warm bulk/page response or `304`: at most 500 ms;
- cancellation visibility at cooperative checkpoints: under 2 seconds;
- cold build: asynchronous, with no blocked workbook/UI request.

## Patris-Export adapter compatibility

The production Patris companion uses the WordPress snapshot API while
preserving its local Excel/VBA boundary. It subscribes to the Living pricing
WSS stream, persists a cursor only after local acceptance,
validates the composite revision on connect/reconnect/reset, registers the
request-bound terminal waiter before the snapshot POST, and consumes the bulk
snapshot only after a matching `ready` event. A cold build therefore uses no
paged `/pricing/sync/state` fallback and no build-status polling.

The companion distinguishes composite `state_revision` from pricing-only
`pricing_state_revision`, validates exact source/build/request/revision and
ETag identities, and injects the protected credential only on same-origin
remote calls. No credential belongs in the workbook, VBA, event payload, or
log.

## Living contract migration

Deployment removes the former versioned schemas, subprotocol, option/action
identifiers, and `/excel/pricing-sync/*` aliases in one coordinated cutover.
The migration first backs up exact state, cancels the former scheduled actions,
deletes only their exact derived queue/cache records, deploys WordPress and the
Patris companion together, and verifies the single Living routes and WSS event
stream. Rollback restores the exact plugin and companion packages plus the
captured state; production must not run mixed contracts.
