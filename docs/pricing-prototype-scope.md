# Website pricing prototype

Owner priority, 2026-09-08: correct calculation from Patris inputs and configured currency rates, and correct prices on digitalogic.ir.

Snapshot construction is temporarily disabled. New starts return `digitalogic_pricing_snapshot_disabled` (503); queued builds are terminalized under their existing worker lease. Owner revision and calculation input access remain available. Website pricing must not wait for consumer snapshots. Snapshot restoration, interface progress, complete bidirectional sync, Excel acceptance, notifications, engine speed comparison and additional revision/rollback work are deferred in issue #296.

Live verification: paired Go 2.0.2-no-snapshot completed packaged bulk in 14.058 seconds with an already-current rate, one delivery attempt and zero pending/deferred products. Post-run readback verified 901 positive leaf prices without mismatch; one public product HTML price metadata matched. This is not changed-rate, all-rendered-products, or browser-click latency acceptance.

Develop and verify on the actual website before PR merge. Do not report raw PHP input records as final consumer prices when snapshots are disabled.

## Single-product recalculation

Run `wp digitalogic pricing recalculate --product-code=113001002`, or POST JSON `{"product_code":"113001002"}` to `/wp-json/digitalogic/v1/pricing/products/recalculate` using the existing authorized WordPress session. The existing `/pricing/recalculate` bulk route remains separate.

This recalculates from the latest committed Patris source; it does not fetch the Patris database. PHP must be the configured authority. The selected adapter/direct-DB mode is used, with shared pricing locks and a scoped receiver. Missing product codes and unrelated pending source delivery return errors rather than broadening the operation.

Live in-process REST validation: anonymous401, authorized200, one target product,110ms dispatch including108ms coordinator work. The product was already current: this does not prove changed-price write latency or external HTTP latency. CLI also completed; subsequent901-price readback matched and services remained active. `elapsed_ms` excludes WordPress startup and network time.
