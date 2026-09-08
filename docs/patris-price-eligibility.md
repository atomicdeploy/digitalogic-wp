# Patris price eligibility

Effective owner policy: 2026-09-08. Breaking policy change; old prices are not preserved when weight is missing.

- Every canonical final-price route requires positive owner-sourced weight. Missing or zero weight means no final price, even if a previous Woo price exists.
- Stock availability is independent of price eligibility. Positive owner-sourced quantity means `instock`, including missing-weight or otherwise unpriced products. Clear final/regular/sale prices without zeroing quantity or marking the product unavailable. Checkout still requires a valid price. Zero or unknown source quantity remains unavailable under the inventory policy.
- Domestic purchase uses the canonical `domestic` method with zero IRR freight. It does not require CNY when an explicit valid domestic purchase input exists. It still requires weight under the owner policy.
- A Woo sale leaf must match an exact source ID, dataset, product code, owner code and applied Woo ID in the committed Patris source. Similar names are not sufficient for mapping.
- Variable/grouped containers derive eligibility from their children. Never assign a parent's ambiguous model name to a Patris board by guessing.
- Unmapped leaves are a **CRITICAL** integrity fault. Hide their prices and deny purchasing until exact identity is resolved. Runtime display/purchase filters and Woo save/price-metadata guards protect supported writes; canonical writes retain the existing scoped authorization.
- Woo reports include critical missing-mapping warnings. The administrator notice and Woo log source `digitalogic-patris-integrity` expose unresolved quarantines. Raw external SQL bypasses WordPress hooks: callers must run catalog integrity verification and cache invalidation; it is not an authorized alternative pricing owner.

Production acceptance on 2026-09-08: 1,022 source-linked leaves preserved; 120 missing-weight rows unpriced; 118 additional unmapped leaves have blank stored prices and are unpurchasable. These 118 require owner identity resolution and are **not** complete mappings. The initial Code/SKU-only pass found no candidates; subsequent model/description review resolved eight duplicate leaves and the Mega family while retaining canonical owner IDs. 36 also have invalid variation-parent topology.

ESP32 `/product/esp-32/` now exposes C3 614,300 IRT (113004003) and S3 1,092,600 IRT (113004007). Four unmatched WROOM/WROVER children are unpriced. Independent public HTML/form verification confirms the old 400,000 IRT value is gone. Missing-weight 109001 no longer displays 1,150,000 IRT. All 901 currently positive canonical prices match Woo readback.

Live rejection probe: both Woo CRUD and direct WordPress price metadata writes to an unmapped variation were blocked; raw price remained blank. Bulk refresh after PHP/Go deployment: 2,870 ms, delivered/already_current. This is unchanged-refresh evidence, not changed-rate latency evidence. Apache, PHP-FPM, and persistent WordPress WebSocket services are ready.

One-time cleanup used ordinary Woo saves and 26 parent invalidations; it took 371,840 ms. Do not turn this remediation script into a recurring refresh. Parent hooks perform synchronous webhook formatting/network calls and report invalidation; batch/coalesce that maintenance path separately. Do not disable unrelated currency notifications.

Inventory correction verified on 2026-09-08: 74 positive-stock products without weight were incorrectly marked unavailable by the earlier PHP price policy. The canonical feed, Woo publication, direct database projections, and replay-currentness checks now keep inventory independent from price. A normal production bulk refresh completed in 6,228 ms. Independent readback of all 1,022 source-linked products found zero stock-status/lookup mismatches; all 74 affected products are in stock with blank final prices. Public HTML for M93C56 and SN74AHC132PWR exposes `is-instock` while the product price stays empty. Go already retained positive `total_stock` independently of missing weight; no Go binary change was needed. This does not authorize checkout without a valid price.
