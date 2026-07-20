# Supplier Shipping Method API

Supplier shipping methods are pricing inputs and are separate from WooCommerce customer delivery methods. The canonical fields are `shipping_method_id` and `shipping_price_per_kg_cny`; aliases are not accepted or emitted.

Management routes remain under `/wp-json/digitalogic/v1/shipping-methods`. Patris reads the versionless integration routes documented in [PATRIS-PRODUCT-SYNC.md](PATRIS-PRODUCT-SYNC.md).

Built-in defaults are `air_express`, `air_freight`, and `sea_freight`. Product assignments are stored only in `_digitalogic_shipping_method_id`. Catalog, assignment, panel, and webhook writes use the same canonical record without mirrored option or metadata keys.

Missing pricing input keys are omitted. An explicit source null remains distinguishable from missing data; public machine responses do not add null placeholders for unavailable values.
