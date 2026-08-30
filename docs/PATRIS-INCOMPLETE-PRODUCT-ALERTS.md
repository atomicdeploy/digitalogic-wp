# Patris incomplete-product operator alerts

Identity-safe Patris products are materialized even when commercial or content
fields are incomplete. Product creation is authoritative and does not depend on
notification storage, scheduling, or delivery.

After a materializer row has passed final readback and all materializer/source
locks have been released, it emits
`digitalogic_patris_materializer_product_committed` with one bounded snapshot:

```text
product_id, product_code, name, source_id, dataset, source_revision,
missing_fields, visible, purchasable, price_status
```

`missing_fields` is a sorted unique subset of `price`, `stock`, `weight`,
`freight`, `markup`, `image`, and `seo`. Unknown fields invalidate only the
alert snapshot; they never fail or roll back product creation.

## Transition and routing contract

- The first non-empty missing set emits a warning.
- An unchanged set emits nothing, even when the source revision changes.
- A partial improvement updates state but emits nothing.
- Any newly missing field emits the current exact missing set.
- The first empty set after an incomplete state emits one recovery event.
- Event identity is deterministic from source ID/dataset/revision, Product
  Code, transition, the exact missing-set fingerprint, and the monotonic
  product transition sequence. This keeps repeated missing/recovery cycles
  unique even when the Patris source revision does not change.

The durable payload uses the secret-free `digitalogic.alert-event` schema,
`event_type: catalog.product_incomplete`, `category: catalog`,
`source: digitalogic-patris-materializer`, an explicit
`notify_channels: [telegram]` allow-list, and
`audience.operators: [shokri]`. It deliberately does not add the legacy
`channels` field or a payload-version field. The canonical `observed_at`,
`source_id`, `resource`, `condition`, `state`, `severity`, and sanitized
`summary` fields are also present. No URL, phone number, chat identifier,
credential, customer data, or direct destination is stored in the event.

Delivery is fail-closed through the injectable
`digitalogic_patris_incomplete_product_alert_delivery_adapter`. The adapter is
owned by the existing private operator-notification route and receives the
whole canonical event. It must reuse `event_id` as the route idempotency key.
This plugin never selects a Telegram chat or calls a provider directly.

The Persian warning includes Product Code/SKU, product name, exact missing
fields, storefront impact, and the fields that must be corrected in Patris.
Missing price is never represented by zero or another placeholder.

## Durability and retries

Latest per-product state, pending events, and delivery receipts are committed
together in `digitalogic_patris_incomplete_product_alert_store` under a MySQL
advisory mutex. Delivery is asynchronous. Each event is attempted at most three
times with bounded backoff. A failed event remains durably visible as
`exhausted`; it is not discarded and it does not block later product commits.
Committed snapshots are captured in memory for the duration of one materializer
pass and durably flushed as one batch by
`digitalogic_patris_materializer_product_commits_complete`, with `shutdown` as
a fallback. Delivery claims preserve global occurrence order and permit at most
one in-flight transition per product, so a recovery cannot overtake its warning.

An hourly, paged repair worker inspects at most 200 published products per run.
It reconstructs a missed snapshot only when the canonical Patris owner/source,
dataset, Product Code, source revision, SKU, visibility, price status, and
sorted missing-field metadata are mutually consistent. This closes a process
gap between a successful product commit and alert-store persistence without
using `_digitalogic_patris_auto_materialized` as ownership evidence. Invalid or
ambiguous rows are skipped, and the bounded page cursor remains durable.

Relay acceptance (`202`/pending), Redis publication, or a local queue write is
not treated as delivery. The outbox closes only after the adapter returns the
same event/idempotency identity, `channel: telegram`, `audience: shokri`, and a
successful Telegram provider receipt with a provider message ID and delivery
time. Only bounded, non-secret route evidence is persisted; unknown fields are
dropped. The route adapter must deduplicate replay of the deterministic event
ID if provider delivery succeeds immediately before the WordPress receipt
write is interrupted.

Each worker claims at most 10 events at once under a five-minute lease. The
private route adapter must enforce a hard end-to-end timeout below 20 seconds
per call so the whole claim batch remains inside that lease, and it must be
safe to replay the exact idempotency key.

## Deployment prerequisites

1. Deploy the paired materializer change that emits the committed snapshot only
   after exact final readback and after releasing both catalog locks, emits the
   batch-complete hook, and persists the repair metadata named above (including
   the sorted JSON missing-field list, even when it is `[]`).
2. Deploy the separately owned private-relay repair that resolves the exact
   `shokri` audience to the approved Telegram route, preserves `schema`,
   `event_id`, `audience`, and `notify_channels`, and injects the adapter above.
   Its URL, credentials, route map, and Telegram destination remain root-owned.
   Do not add them to WordPress options, this repository, or this feature.
3. Confirm WordPress one-shot cron execution is healthy; activation is not a
   substitute for a working cron runner. Confirm both the delivery and hourly
   repair hooks are being executed.
4. Before enabling the materializer pass, read back that the alert store is
   valid, no old event is unexpectedly exhausted, and no worker is stuck with
   an expired lease.
5. With explicit approval for a production notification test, materialize one
   controlled incomplete fixture, then verify the same event ID in WordPress
   outbox, private relay/spool, successful n8n execution, Telegram provider
   message receipt, and the final WordPress receipt. Implementation and CI
   tests use only a local fake adapter and send no real notification.

The reference regression fixture is Product Code/SKU `101001001`,
`LM2576S-ADJ`: public and visible, out of stock, blank-priced,
non-purchasable, and `canonical_missing_unpriced` until Patris supplies the
missing commercial data.
