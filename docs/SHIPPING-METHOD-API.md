# Supplier Shipping Method API

Supplier shipping methods are pricing inputs and are separate from WooCommerce customer delivery methods. A method object uses `price_per_kg` and a required `currency`; management input accepts only the exact strings `CNY` or `IRR`, without case or whitespace normalization. Product-sync records flatten the selected method as the paired fields `shipping_price_per_kg` and `shipping_price_per_kg_currency`. Neither key may appear without the other, and aliases are not accepted or emitted. Either paired value may be explicitly null; that makes pricing incomplete and requires `final_price` to be omitted.

Management routes remain under `/wp-json/digitalogic/v1/shipping-methods`. Patris reads the integration routes documented in [PATRIS-PRODUCT-SYNC.md](PATRIS-PRODUCT-SYNC.md).

Built-in defaults are `air_express`, `air_freight`, and `sea_freight`. Product assignments are stored only in `_digitalogic_shipping_method_id`. Catalog, assignment, panel, and webhook writes use the same canonical record without mirrored option or metadata keys.

`price_per_kg`, optional `minimum_charge`, optional volumetric divisor, and tier bounds/rates are canonical non-negative base-10 strings. They support up to 18 integer digits and 12 fractional digits; exponent and grouping notation are rejected. Tier objects inherit their method's required currency and cannot silently switch currencies. When calculating the final IRT amount, a CNY freight rate is converted using the effective CNY-to-IRT rate. An IRR freight rate is divided by 10 before it is combined with IRT goods cost. Markup is applied once to the combined IRT amount and the result is rounded half up once.

Missing pricing input keys are omitted. An explicit source null remains distinguishable from missing data; public machine responses do not add null placeholders for unavailable values.
