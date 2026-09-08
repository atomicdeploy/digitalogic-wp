# Shared single-product command

The dispatcher command `digitalogic_recalculate_product_price` accepts `product_code` and calls the existing pricing coordinator used by REST and CLI. It requires manage_woocommerce in addition to dispatcher access. No second calculator or relaxed machine principal is introduced.

Single operations invalidate cached authority, write mode and source state after acquiring pricing/source locks. On2026-09-08 the coordinator was deployed and tested in one real PHP process: before each of two calls, only that process's Redis drop-in local cache was seeded with authority go, invalid mode and empty source state. The stale reads were confirmed before invoking the operation. Both calls recovered authoritative PHP/direct_db, one real source, already-current1, pending0 in124ms and142ms. Database settings were not changed. This proves these local-cache recovery boundaries, not changed-product calculation or WebSocket E2E.

At this checkpoint the coordinator is installed; dispatcher registration is staged only, and the existing WebSocket worker has not been restarted. Remaining acceptance: authorized and unauthorized dispatch, real persistent transport calls and fresh product-cache behavior between changed inputs. Do not claim persistent pricing is operational until these pass.
