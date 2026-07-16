# Patris Product Sync v1 Receiver

Digitalogic exposes a dedicated receiver for the transformed Patris Export
contract:

```text
POST /wp-json/digitalogic/v1/patris/product-sync
```

This endpoint is intentionally separate from the legacy `/patris/push` route.
The legacy route accepts historical product/customer snapshots and keeps its
existing replacement behavior. New Patris integrations must use
`/patris/product-sync`; sending a v1 delta to the legacy route is unsupported.

Do not enable production delivery merely by deploying this code. First run the
cross-project contract tests, send a read-only staging snapshot, and verify the
stored receiver state and WooCommerce canary products.

## Authentication

Use either:

- `X-Digitalogic-Product-Sync-Secret: <v1 receiver secret>`; this credential is
  separate from the legacy Patris push token and is accepted in this header
  only, never in the query string or `X-Digitalogic-Token`; or
- an authenticated Digitalogic write scope, including a WooCommerce REST API
  key whose user can manage WooCommerce.

The receiver secret is stored in `digitalogic_product_sync_v1_secret` and is
generated on first read. Deployments should configure exact source scopes in
`digitalogic_product_sync_v1_source_scopes` as a JSON list of `{id,dataset}`
objects. An empty list is unscoped for initial setup; a non-empty list denies
every source pair not present exactly. For example:

```bash
wp option update digitalogic_product_sync_v1_source_scopes \
  '[{"id":"patris-office","dataset":"kala.db"}]' --format=json
wp eval 'echo Digitalogic_Patris_Feed::instance()->get_product_sync_secret();'
```

Patris may also send `X-Patris-Contract`, `X-Patris-Contract-Version`, and
`X-Patris-Event-ID`. When present, each header must match the JSON body.

## Envelope

The receiver accepts schema major version 1. The current producer sends:

```json
{
  "schema": "digitalogic.product-sync",
  "schema_version": "1.0",
  "event": "digitalogic.product-sync",
  "event_type": "snapshot",
  "event_id": "sha256:...",
  "local_currency": "IRT",
  "formula_id": "landed_price_v1",
  "formula_revision": "1.0.0",
  "formula_version": "landed_price_v1",
  "source": {
    "id": "patris-office",
    "dataset": "kala.db",
    "revision": "sha256:..."
  },
  "generated_at": "2026-07-16T08:00:00Z",
  "products": [],
  "deleted_codes": [],
  "quarantined_codes": [],
  "warnings": []
}
```

Every product is the fixed typed `digitalogic.product-sync` v1 shape emitted by
Patris Export. `product_code` is always a non-empty JSON string, even when it
contains only digits. Product output currency is CNY, final prices are
non-negative integer IRT values or `null`, and the product formula must be
`landed_price_v1`.

The receiver recursively rejects raw Patris/Paradox keys, including spelling,
case, and separator variants of `Sharh`, `Sharh1`, `Sharh2`, `FOROSH`,
`KHARYD`, `ALLANBAR`, and `ANBAR*`. Only transformed fields cross this boundary.

## Integrity and ordering

The receiver independently verifies:

- each `record_hash` from the typed product in Go-compatible JSON field order;
- every non-null `final_price` by independently evaluating
  `landed_price_v1` as bounded exact decimals, with one half-up round to the
  final IRT integer; incomplete formula inputs require `final_price: null`;
- `source.revision` from the resulting complete source snapshot; and
- `event_id` from the schema, source identity, sorted record hashes,
  occurrence timestamp, tombstones, and quarantined Codes.

The exact `event_id` hash material is the compact Go `encoding/json` object in
this field order:

```text
schema, schema_version, event_type, local_currency, formula_id,
formula_revision, source{id,dataset,revision}, generated_at, products,
[deleted_codes when non-empty], [quarantined_codes when non-empty]
```

`products` is a lexicographically sorted string list whose entries are
`<product_code>=<record_hash>`. `generated_at` is the exact validated RFC3339
wire string. Binding it into the identity makes same-content occurrences at
different timestamps distinct and ensures a newer same-content event advances
the ordering watermark.

The JSON decoder rejects duplicate object keys and preserves exact decimal
tokens while hashing. Events are ordered per `{source.id, source.dataset}` by
RFC3339-nanosecond `generated_at`. An update requires an existing snapshot.
Older events and conflicting events at the same source timestamp are rejected.

Formula operands allow at most 15 integer digits and 12 fractional digits;
percentage markup is bounded to `0..1000`. Required price, weight, freight,
markup, and exchange-rate inputs must be present (and the freight method must
be selected) for a non-null final price. Binary floating point is not used.

The most recent 128 event IDs per source are retained. Replaying one of those
IDs after full delivery returns `status: replayed` without another option
write, WooCommerce save, log entry, or domain event. If products remain in the
durable outbox, the identical replay retries only those pending products and
returns `recovered` or `retry_pending`.

## Snapshot, update, deletion, and quarantine behavior

- `snapshot` replaces the receiver snapshot for that source.
- `update` merges complete changed products into the existing snapshot.
- `deleted_codes` is valid on updates, including an update containing only
  tombstones. It removes exact Codes from receiver state only.
- A tombstone never trashes, drafts, or deletes a WooCommerce product.
- `quarantined_codes` protects the previously stored record for that Code. A
  quarantined Code cannot also appear as a product or tombstone in the event.

Incoming changed products are optionally mirrored to WooCommerce only through
the shared exact Code/SKU resolver. Patris Code is canonical. SKU is used only
when no exact Patris Code exists; a distinct exact SKU and Patris Code collision
is ambiguous. Missing or ambiguous identifiers are reported and never resolved
by fuzzy name matching.

Receiver state is written under `digitalogic_product_sync_v1_state`, evicted
from the option cache, and read back with a digest check before WooCommerce
writes. Each source has an applied `{record_hash,woocommerce_id}` CAS record, a
transient `pending_products` outbox, and a terminal `deferred_products`
reconciliation set. A successful persisted Woo record hash acknowledges
crash-window retries without a duplicate save. Woo lookup/storage/write
failures, including identifier database query failures, stay pending; exact
Code not-found and ambiguity move to deferred only after a successful lookup
and are never guessed. The final delivery state is read back before the result-aware
`digitalogic_product_sync_v1_applied` domain action. Existing v2 receiver state
is projected and transactionally persisted as v3 under the same advisory lock.
Because v2 could not distinguish SQL failure from not-found, its not-found
entries stay pending until one successful v3 lookup reclassifies them.
This state is independent of the legacy Patris feed option.

## Successful response

```json
{
  "success": true,
  "data": {
    "status": "accepted",
    "replayed": false,
    "event_id": "sha256:...",
    "event_type": "update",
    "received_products": 1,
    "stored_products": 2,
    "deleted_codes": 0,
    "fully_applied": true,
    "retryable": false,
    "pending_products": 0,
    "deferred_products": 0,
    "deferred_reconciliation": {
      "missing": 0,
      "ambiguous": 0,
      "details": [],
      "details_truncated": 0
    },
    "persistence_verified": true,
    "woocommerce": {
      "updated": 1,
      "missing": 0,
      "ambiguous": 0,
      "failed": 0,
      "errors": []
    }
  }
}
```

Validation errors use HTTP 400 or 422. Ordering conflicts use 409. A busy
advisory lock or unavailable lock service returns retryable HTTP 503.

Only transient WooCommerce lookup/storage/write failures make `data.status`
`partially_applied`, `retryable` true, and `pending_products` non-zero. Send the
identical event again after correcting a transient failure.

Missing and ambiguous Codes are terminal reconciliation work. A commit with
only those rows is `accepted` (or `already_current`/`replayed`), has
`retryable:false` and `pending_products:0`, and reports `deferred_products` as
an unquoted JSON integer in the range 0..2,147,483,647 plus at most 100 typed
detail rows. An identical replay is idempotent and does not re-query those
Codes. Deferred Codes are retried by the next distinct source event or
explicitly by an administrator:

```shell
wp digitalogic product-sync status
wp digitalogic product-sync reconcile --user=<administrator>
wp digitalogic product-sync reconcile \
  --source-id=patris-office --dataset=kala.db --user=<administrator>
```

The status command exposes source/event identities and aggregate counts only;
it does not print product payloads or credentials. Reconciliation processes
only pending/deferred records, never already-applied writes, and preserves the
stored source event ordering watermark.

## Optional signed observer event

After the receiver state and Woo delivery outbox are committed, the shared
webhook service emits `patris.product_sync.applied` when at least one standard
webhook destination is configured. The existing `X-Digitalogic-Signature` HMAC
and fan-out are reused; there is no n8n-specific transport.

The event's `data` object is an allowlisted projection containing only:

- `schema`, `schema_version`, `event_id`, and `event_type`;
- non-path `source.id` and `source.dataset`;
- `status`, `retryable`, `pending_products`, and `deferred_products`; and
- bounded Woo aggregate counts: `attempted`, `updated`, `already_applied`,
  `missing`, `ambiguous`, `failed`, and `errors_truncated`.

Products, names, raw fields, error details, request/response bodies, endpoint
paths, headers, credentials, source revisions, and receiver state are never
projected. Counts are bounded to the receiver's 10,000-product limit. A path-like
source identifier fails closed and produces no observer event.

Terminal replays do not emit the committed domain action, so they do not create
duplicate observer events. A transient attempt may emit `partially_applied`,
followed by `recovered` when the durable retry succeeds. Observer transport or
listener failure is isolated from the already-committed receiver response.

An n8n workflow may verify the existing signature and route this event to
Telegram alerts or an audit copy. It must not treat the observer as a data
source, acknowledge Patris delivery, or initiate receiver retries. With no
webhook URL configured, product sync remains a complete standalone deployment.

The synthetic cross-project fixture is 2,760 bytes with SHA-256
`810bdf4d8fd5e3c2a87750a02f241363f6403736c899a625f615967fea259da5`.
Its occurrence identity is
`sha256:25d5afce95dfdcf598c28f9c9639cbd54ed7f2e838a6c285844eee75d972ef06`.
