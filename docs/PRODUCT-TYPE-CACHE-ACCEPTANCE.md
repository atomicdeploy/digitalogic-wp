# Product type cache invalidation

The native adapter refresh stopped before saving one product with `variation_parent_invalid`. Raw SQL showed variation 11554 attached to published product 10932 with the `variable` taxonomy term, while WooCommerce constructed its parent as `simple`. Targeted taxonomy/type cache eviction restored `variable` and the existing identity check passed without editing catalog data.

Installed WooCommerce stores product type in the `products` group using a `product_<id>` prefix. Advancing the general `products` prefix does not invalidate that entry. Coordinated cleanup now deletes the exact type-cache key for affected leaves and parents alongside existing instance-cache invalidation. The existing cache-eviction failure regression passed (17 assertions).

After live deployment, a full changed-rate adapter run saved all 901 products and restored CNY 34000, direct_db and target price 814600. The run also exercised the scalar-metadata candidate in an isolated process; that candidate is not part of this fix. Independent event calculation matched all 891 changed prices, with 10 unchanged after rounding and no missing/mismatched changed events. A fresh process still validated product 11554 afterward; services were active.

The operation took 64.034 seconds. This fixes a correctness blocker, not the outstanding sub-60-second performance requirement. No identity guard was weakened.
