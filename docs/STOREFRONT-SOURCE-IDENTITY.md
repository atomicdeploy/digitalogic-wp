# Public display of unreviewed catalog aliases

A source catalog name can include text that looks like a voltage, a current, or a
manufacturer model. Keep the original name for reconciliation and search. When a
review identifies an ambiguous alias, set the protected product meta
`_digitalogic_source_identity_review_state` to `pending` on that exact WooCommerce
product after confirming its canonical Product Code/SKU.

While pending, the product highlights and specifications shortcodes omit the
source model, technical name, and part number. They still render the SKU and
normal product attributes. The state does not change the Patris record, commerce
fields, or links. Products without the pending state use the normal display.

After confirming the stock identity, review the source labels and clear the
pending state only when the displayed labels are safe to publish. After changing
the state, invalidate the affected product page cache through the supported
storefront mechanism and check the public page, including the highlights and
specifications on mobile. Confirm the SKU and source metadata separately.
