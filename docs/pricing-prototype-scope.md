# Website pricing prototype

Owner priority, 2026-09-08: correct calculation from Patris inputs and configured currency rates, and correct prices on digitalogic.ir.

Snapshot construction is temporarily disabled. New starts return `digitalogic_pricing_snapshot_disabled` (503); queued builds are terminalized under their existing worker lease. Owner revision and calculation input access remain available. Website pricing must not wait for consumer snapshots. Snapshot restoration, interface progress, complete bidirectional sync, Excel acceptance, notifications, engine speed comparison and additional revision/rollback work are deferred in issue #296.

Live verification: paired Go 2.0.2-no-snapshot completed packaged bulk in 14.058 seconds with an already-current rate, one delivery attempt and zero pending/deferred products. Post-run readback verified 901 positive leaf prices without mismatch; one public product HTML price metadata matched. This is not changed-rate, all-rendered-products, or browser-click latency acceptance.

Develop and verify on the actual website before PR merge. Do not report raw PHP input records as final consumer prices when snapshots are disabled.
