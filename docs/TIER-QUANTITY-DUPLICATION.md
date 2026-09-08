# TierPricing duplicate quantity manager

Production inspection on 2026-09-08 found two consecutive `new QuantityManager()` calls in `tier-pricing-table/src/Addons/AdvancedQuantityOptions/AdvancedQuantityOptionsAddon.php`, inside `run()`. This registers the same quantity callbacks twice. The production correction removes the second call only; quantity limits, role rules and pricing remain enabled.

Original file SHA256: `f1f967884dde2872513733ccb0a8e5475adb2d218c3a119d431c0d4c88bd0f17`.
Corrected file SHA256: `617babde4debbb5ae000e14571532230a86c3aed6e3815a76a6ac429303ba790`.

Live read-only acceptance returned the same complete payload digest for 902 products: `87316713e002c367a99a08c77699503028ea89fd5309a142e274c2d960439ecb`. Quantity-rule callback count dropped from 2118 to 1059. Its total time did not halve because the first invocation performs the expensive work; do not claim this fixes the bulk latency target. Web services remained active. Native changed-price latency and cart quantity acceptance remain separate checks.

This correction is in an installed third-party dependency, not supplied by the Digitalogic plugin package. During the next TierPricing update, inspect whether upstream has removed the duplicate before replacing the installed file. Do not copy an older dependency file over a newer release. Retire this operational note once the upstream correction is verified; no alternate pricing implementation or compatibility layer is needed.
