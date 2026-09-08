# Combined ownership predicate: live result

Two ownership checks now evaluate both current connection ID and advisory lock owner against the acquisition-captured connection ID in one SQL statement. Acquisition and release behavior remains unchanged. SQL preparation false/null is rejected without reading a previous query result.

Targeted existing lock-loss/reconnect checks passed before deployment. Live files were hash-checked before replacement and read back afterward; PHP-FPM reloaded and WebSocket restarted.

The native changed-CNY operation completed successfully in 64924.567 ms with 901 WooCommerce saves (24637.882 ms). This still fails the critical 60-second target. The helper restored CNY 34000, direct_db and target price 814600. All three checked services were active afterward. Do not claim an end-to-end speed improvement from reduced query count alone. Independent final event-price acceptance remains pending for this exact run.

Independent event-price validation for this exact run passed: 901computable products, 10unchanged after rounding, all891changed event prices matched, no missing/mismatchedchanged events. This does not establish all rendered surface coverage or performance acceptance.
