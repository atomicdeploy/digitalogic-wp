# Use the installed WooCommerce instance service for bulk cache cleanup

The receiver checked `ProductUtil::delete_product_transients_for_products` as a static callable. The installed WooCommerce method is an instance method, so the check selected the per-product fallback instead of bulk deletion. WooCommerce itself resolves ProductUtil through `wc_get_container()->get(...)` in `wc-product-functions.php`.

Resolve the same instance service and call its bulk method. Keep WooCommerce's transient deletion and per-product notification hooks; no feature or ownership validation is disabled.

Production-first verification on 2026-09-08:

- The candidate differed from freshly captured serving receiver code by four added/four removed lines and passed server PHP lint.
- Installed receiver SHA256: `e7b9ab3aaed9462381ff37f47d254ec3024820977e70cc9163aedce33c3eafa1`.
- Native changed-rate adapter refresh completed in 59.312917824 seconds with 901 WooCommerce saves. Rate changed 34000→34100; target price changed 814600→817000.
- Restoration returned rate34000, target814600 and direct_db; process exited0 and Apache/PHP-FPM/WebSocket remained active.

Subsequent read-only inspection of that run's exact Redis stream interval returned891entries without truncation or revision mismatches. Independent Node arithmetic using the owner input projection matched all891changed product event prices; ten of901positive products were unchanged after rounding. No changed product event was missing or mismatched. Public variation form readback after restoration matched29/29prices. This validates emitted price values and form data, not every rendered page or interactive client.

The preceding instrumented run took61.697769398 seconds. These are separate executions with different instrumentation and runtime conditions, not a controlled estimate of savings. One sub-60 run has little margin and does not prove sustained latency or all interfaces. Further acceptance remains open.
