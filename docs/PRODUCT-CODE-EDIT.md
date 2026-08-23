# Living Product Code edit contract

`digitalogic_update_product_code` is the only administrator command that may
edit the canonical `_digitalogic_patris_product_code` identity. The generic
product updater does not own this field. `SKU` and `Product Code` are distinct
catalog fields and are displayed independently in both WordPress product-admin
surfaces. Canonical edits from either surface call this same operation.

The request is a small object:

```json
{
  "product_id": 741,
  "expected_code": "000741",
  "product_code": "000742",
  "if_match": "sha256:...",
  "request_id": "product-code:741:..."
}
```

Codes must be strings. This preserves leading zeroes and rejects silent numeric
coercion. `expected_code` and `if_match` must both match a fresh, cache-bypassed
database read. `request_id` is bound to the normalized request and completed
requests replay their stored terminal result before current-state validation.
Every request has a hashed, non-autoloaded durable operation record which is
never automatically pruned. That record is authoritative and is read directly
from the database. A separate best-effort audit summary keeps the newest 1,024
entries only for navigation. Summary corruption, write failure, eviction, or a
stale WordPress object-cache entry cannot cause a second effect, discard the
authoritative audit, or roll back a verified terminal operation.

The write is bounded first by the site source-identity lock shared by every
supported canonical-code writer (product-sync, legacy feed, and catalog
materializer); the legacy feed also revalidates its exact target binding under
that lock. The edit then takes a site edit/audit lock and the existing
per-product write lock. All three use a zero-second wait. Exact uniqueness is
checked across non-trashed WooCommerce products and variations before and
after the write.
Only the target post/meta and WooCommerce product cache group are invalidated.
No catalog refresh, product-sync delivery, pricing refresh, notification, or
publication operation is invoked by this service.

An active source record, delivery binding, source-owned desired code, record
hash, or malformed provenance stops the operation. Such an identity must be
corrected in its owning source and delivered through the reviewed product-sync
workflow. WooCommerce-only/unmanaged rows may be edited here.

The private durable request record and bounded audit summary record the actor
ID, request fingerprint, exact previous presence/value/revision, backup
reference, terminal readback, retry/recovery state, and rollback verification.
If the product after-state or its invariants cannot be verified, the service
restores the exact prior presence/value and verifies that restoration. If the
product after-state is exact but only the terminal audit readback is uncertain,
the product is not rolled back: the service returns a retryable
`completion_pending` response and preserves the same request identity, so the
next call either replays the terminal record or performs guarded recovery.
Recovery repeats source-ownership and global-uniqueness guards before finalizing
the after-state. A conflicting or state-mismatched recovery is terminal
`outcome_unknown` and is never retried automatically.

Both administrator surfaces send this mutation through bounded AJAX rather
than switching transports after an unknown outcome. A precondition response
supplies the exact current code/revision and rotates the next request ID while
preserving the owner's proposed value for a manual retry. A timeout or
retryable service response preserves the same request ID; the UI always leaves
its saving state after the bounded request settles. Source-managed rows are
shown read-only as a convenience, while the exact backend guard remains
authoritative.
