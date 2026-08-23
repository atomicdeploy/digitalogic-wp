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
that lock. A default-deny metadata boundary rejects generic WordPress,
WooCommerce CRUD/import, and product/variation REST writes (including by-meta-ID
and case-variant keys). Each supported internal writer receives an immutable,
one-product, one-operation, one-value scope while holding the shared lock, then
must prove one exact database row and global uniqueness before releasing it.
Permanent product deletion has a separate exact-post cleanup scope; a soft
deleted product continues to own its code until permanent deletion.

The edit then takes a site edit/audit lock and the existing
per-product write lock. All three use a zero-second wait. Exact uniqueness is
checked across WooCommerce products and variations, including trash, before
and after the write. Only the target post/meta and WooCommerce product cache
group are directly invalidated. The canonical meta hook also advances the
Living report projection generation and durably coalesces its pricing/catalog
state-revision event. A successful replay creates neither a new generation nor
a new event. No catalog refresh, product-sync delivery, price application,
notification, or publication operation is invoked by this service.

An active source record, delivery binding, source-owned desired code, record
hash, or malformed provenance stops the operation. Such an identity must be
corrected in its owning source and delivered through the reviewed product-sync
workflow. WooCommerce-only/unmanaged rows may be edited here.

The private durable request record and bounded audit summary record the actor
ID, request fingerprint, exact previous presence/value/revision, backup
reference, terminal readback, retry/recovery state, and rollback verification.
Before any possible product effect, a per-product recovery pointer is persisted
and exactly read back. It makes an incomplete request discoverable after a full
page reload and blocks every different actor/request until terminal state is
proven. A terminal per-request record is authoritative, so failure to physically
delete an old pointer never blocks a later edit. Recovery preserves the
original effect actor and separately attributes a different recovery operator.
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
retryable service response preserves the same request ID. HTTP success is not
accepted unless schema, request ID, request fingerprint, exact before/after
values, revisions, verification flags, backup, and projection evidence all
match the submitted request. The UI always leaves its saving state after the
bounded request settles. Source-managed and outcome-unknown rows are shown
read-only; outcome-unknown offers manual reconciliation rather than an
automatic retry loop. The exact backend guard remains authoritative.
