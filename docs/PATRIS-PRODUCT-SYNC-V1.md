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

- `X-Digitalogic-Token: <Patris push token>`; this v1 route accepts the token in
  a header only, never in the query string; or
- an authenticated Digitalogic write scope, including a WooCommerce REST API
  key whose user can manage WooCommerce.

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
- `source.revision` from the resulting complete source snapshot; and
- `event_id` from the schema, source identity, sorted record hashes,
  tombstones, and quarantined Codes.

The JSON decoder rejects duplicate object keys and preserves exact decimal
tokens while hashing. Events are ordered per `{source.id, source.dataset}` by
RFC3339-nanosecond `generated_at`. An update requires an existing snapshot.
Older events and conflicting events at the same source timestamp are rejected.

The most recent 128 event IDs per source are retained. Replaying one of those
IDs returns `status: replayed` without another option write, WooCommerce save,
log entry, or domain event.

## Snapshot, update, deletion, and quarantine behavior

- `snapshot` replaces the receiver snapshot for that source.
- `update` merges complete changed products into the existing snapshot.
- `deleted_codes` is valid on updates, including an update containing only
  tombstones. It removes exact Codes from receiver state only.
- A tombstone never trashes, drafts, or deletes a WooCommerce product.
- `quarantined_codes` protects the previously stored record for that Code. A
  quarantined Code cannot also appear as a product or tombstone in the event.

Incoming changed products are optionally mirrored to WooCommerce only through
the shared exact Code/SKU resolver. Missing or ambiguous identifiers are
reported and never resolved by fuzzy name matching.

Receiver state is written under `digitalogic_product_sync_v1_state`, evicted
from the option cache, and read back with a digest check before WooCommerce
writes and the `digitalogic_product_sync_v1_applied` domain action. This state
is independent of the legacy Patris feed option.

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
