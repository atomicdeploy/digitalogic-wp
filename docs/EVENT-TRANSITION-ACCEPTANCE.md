# Returning to a previously published price

Content-hash deduplication across the Redis TTL suppressed valid A→B→A state transitions. Deduplicate only against each entity's latest published fingerprint. Keep the compare, stream append and latest-fingerprint update atomic in Lua. The request-local cache follows the same consecutive-state rule. Old expiring keys are unused; no compatibility path is retained.

Live Digitalogic verification on2026-09-08:

- Exact Lua and request-local A,A,B,A checks publish A,B,A.
- Real PHP/direct_db CNY34000→34100 bulk completed in19.007880503s;901postcommit receipts, none under receiver lock.
- Independent arithmetic: all891changed product prices matched emitted values. First activation also emitted ten unchanged prices because latest-state keys were new.
- Restore34100→34000 emitted all891changed prices with zero missing/mismatched values. Prior target814600, rate34000 and direct_db restored.
- Apache, PHP-FPM and WebSocket services active after deployment.

This proves this run's destination/event path, not every rendered storefront surface, unavailable-product policy, sustained latency, Go parity or adapter performance. Those acceptance items remain open. No broad feature was disabled to pass.
