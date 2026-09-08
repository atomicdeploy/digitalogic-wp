# Preserve taxonomy relationships during public cache priming

The storefront category visibility filters were applied when product_cat appeared in a mixed-taxonomy query. WordPress update_object_term_cache requests all product taxonomies; filtering that result discarded product_type and stored an empty relationship array. WooCommerce then cached variable parents as simple, removing their public variation selector.

Skip display-category filtering and hide_empty overrides for mixed-taxonomy, object_ids and all_with_object_id queries. Ordinary category listing visibility remains covered by the existing tests.

Verification on Digitalogic,2026-09-08:

- Before patch, actual public-mode term priming for HC05 produced [] and simple. Cleanup restored variable.
- After live patch, the same operation produced [4] and variable.
- Cleared existing poisoned caches for13confirmed variable parents; all29variation display prices in public page form data match canonicalIRT.
- Ten focused tests/37assertions pass; Apache/PHP-FPM/WebSocket services active.

This is direct reproduction of the identified cache poisoning mechanism. Browser selection interaction and sustained refresh/concurrent-request coverage remain separate acceptance work.
