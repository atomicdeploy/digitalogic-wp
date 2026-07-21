# Patris storefront pricing policy

Digitalogic keeps three commercial values separate:

- **Canonical Patris final price** is stored in `_digitalogic_patris_final_price` and records the reviewed source calculation.
- **WooCommerce regular price** is the product or exact-code variation's normal storefront price.
- **Effective storefront price** is selected by WooCommerce after promotion dates, product type, and variation pricing are evaluated.

The safe default is `preserve_sale`. A Patris sync may update the regular price,
but it does not clear or override an existing promotion and never writes a
misleading variable-parent `_price`. A variation is priced only when it is the
exact resolved Patris Code target.

`replace_sale` is an explicit administrator policy for removing existing sale
prices as canonical Patris prices are applied. Read or change the policy with:

```text
wp digitalogic pricing policy
wp digitalogic pricing policy --set=replace_sale --user=<administrator>
wp digitalogic pricing policy --set=preserve_sale --user=<administrator>
```

Changing the policy does not rewrite existing products. Inspect a bounded page
without mutation before any reviewed reconciliation:

```text
wp digitalogic pricing audit --limit=100 --page=1 --format=table
```

The audit reports canonical, regular, sale, and effective values separately,
along with the price source, active policy, and review status. The product panel
uses the same explicit projection and exposes effective price, policy status,
and promotion policy as read-only columns.
