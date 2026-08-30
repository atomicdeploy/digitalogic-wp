# Patris Catalog Materializer

The Patris catalog materializer has two deliberately separate paths:

1. The product-sync receiver automatically creates a minimal WooCommerce leaf
   for every identity-safe Patris product, including rows with zero or missing
   price, stock, weight, freight, markup, image, or SEO data.
2. The administrator-operated WP-CLI workflow applies reviewed Persian content,
   taxonomy, images, SEO, and variable-product ownership decisions.

Reviewed enrichment is not a prerequisite for source identity to exist on the
storefront. Automatic products use the exact source name when present and the
exact Code as the honest fallback title, remain public and visible, and never
receive invented descriptive or commercial data.

The product-sync receiver remains authoritative for typed Patris stock,
pricing, weight, category, warehouse, and warning data. The enrichment manifest
supplies the human-reviewed Persian identity, taxonomy target, SEO text, and
exact WooCommerce ownership decision that cannot safely be inferred from the
source feed.

Freight arrives as the inseparable `shipping_price_per_kg` and
`shipping_price_per_kg_currency` pair. The currency must explicitly be `CNY` or
`IRR`; the materializer never infers it from the amount or shipping method.

## Safety model

- The receiver's automatic path is part of normal product-sync delivery and is
  bounded by the existing 25-product write limit. Remaining safe rows stay
  pending for the next event or `product-sync reconcile`; they are not deferred.
- Missing commercial or enrichment fields never become fake zero prices,
  weights, freight, markup, images, descriptions, or SEO values.
- A canonically unpriced automatic product is `publish`/`visible`, has blank
  WooCommerce prices, is out of stock, and is not purchasable. A later canonical
  source record updates the same product and makes it normally purchasable when
  its real price and stock allow it.
- `deferred` is reserved for a concrete identity or corruption hazard: duplicate
  or split Code/SKU ownership, quarantined/unsafe identity, or conflicting
  variable-parent ownership. Database, lock, writer, and availability failures
  remain retryable pending work.
- The reviewed CLI still defaults to dry-run. Nothing in that path changes until
  `--apply` is supplied, but it selects reviewed rows regardless of stock.
- `--publish-ready` records that every reviewed completeness gate passed; it is
  not permission to hide or demote an already-public source leaf.
- Exact source ID and dataset are required. An optional `source_revision` pins
  the review to one product-sync snapshot.
- Manifest JSON is limited to 8 MiB, parsed with duplicate-key rejection, and
  rejects missing or unknown object fields.
- Existing products and terms require exact reviewed IDs; ownership is never
  guessed from a similar title.
- Categories are assigned additively. Existing manual product categories are
  never removed.
- Existing category names and parents are preserved unless `rename` is
  explicitly true for that reviewed source category.
- New integration-managed product categories use stable, source-neutral public
  slugs in the form `product-category-<category-code>`. On an apply run, only
  terms marked with `_digitalogic_patris_category_managed=1` are migrated from
  historic `patris-*` slugs. The old exact paths are retained as permanent
  redirects; adopted/manual term slugs are never changed by this migration.
- A variable product cannot own a leaf Patris Code. The materializer can create
  a reviewed child under an existing variable parent, but it never invents a
  new variable family.
- An existing variable product may become a simple leaf only when the manifest
  explicitly sets `convert_empty_variable_to_simple` and the container still
  has zero children.
- Product images are not invented or imported by the automatic path. The
  reviewed workflow can validate an existing featured-image reference attached
  through the separate image workflow.
- Apply runs hold a named MySQL advisory lock and trigger receiver
  reconciliation after the reviewed writes finish.

## Commands

Plan the full manifest:

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --user=<administrator>
```

Apply a bounded reviewed-enrichment phase:

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --codes=10001,10002 \
  --limit=25 \
  --apply \
  --user=<administrator>
```

After a fresh Patris product sync recalculates the newly assigned
`air_express` landed prices, record and publish reviewed-ready products:

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --apply \
  --publish-ready \
  --user=<administrator>
```

`--source-id` and `--dataset` are optional assertions; when present they must
exactly match the manifest. `--codes` is a comma-separated exact Code allowlist,
and `--limit` applies after Codes are sorted bytewise. An omitted limit or exact
`--limit=0` means unlimited. Any other value must be a canonical positive
integer; negative, signed, decimal, exponent, whitespace-padded, leading-zero,
or out-of-range values are rejected instead of being widened to unlimited.

The command requires a WordPress user with `manage_options`. Keep reviewed
manifests outside the public web root and do not put credentials or private
notification destinations in them.

## Manifest root

Every object is closed: only the documented keys are allowed, and every row key
shown below is required even when its value is empty or `null`.

```json
{
  "schema": "digitalogic.patris-catalog-enrichment",
  "source": {
    "id": "patris-office",
    "dataset": "kala.db"
  },
  "source_revision": "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "products": {},
  "categories": {}
}
```

`source_revision` is the only optional root key for manifests without product
category overrides. Every manifest containing a `category_override` must pin it:
classification approval is valid only for the exact reviewed source revision.
For a deliberately reusable manifest without overrides, the source ID and
dataset still remain exact.

## Product rows

`products` is keyed by exact Patris Code. `patris_name` must exactly match the
current source name, making a changed source identity a review failure rather
than a silent overwrite.

This example reviews a new simple leaf:

```json
"10001": {
  "patris_name": "LM358P Dual Operational Amplifier",
  "target_product_id": null,
  "target_parent_id": null,
  "convert_empty_variable_to_simple": false,
  "attribute_taxonomy": "",
  "attribute_term_id": null,
  "category_override": null,
  "parent_enrichment": null,
  "variation_group": "",
  "name_fa": "آی سی آپ امپ دو کاناله LM358P",
  "short_description_fa": "آی سی تقویت کننده عملیاتی دو کاناله LM358P مناسب مدارهای آنالوگ و پروژه های الکترونیکی.",
  "seo_title_fa": "خرید آی سی آپ امپ LM358P دو کاناله",
  "seo_description_fa": "مشخصات، موجودی و قیمت آی سی آپ امپ دو کاناله LM358P را در دیجیتالاجیک بررسی کنید.",
  "focus_keyword_fa": "آی سی LM358P",
  "part_number": "LM358P",
  "model": "LM358P"
}
```

Target modes are exact:

| Mode | Required ownership fields |
| --- | --- |
| Create a new simple leaf | `target_product_id: null`, `target_parent_id: null`, empty attribute fields |
| Adopt/reconcile an existing simple leaf | exact string `target_product_id`, `target_parent_id: null`, empty attribute fields |
| Reconcile an existing variation | exact child and parent string IDs, empty attribute fields, nonempty `variation_group`, full `parent_enrichment` |
| Create a child under an existing variable parent | `target_product_id: null`, exact parent string ID, exact `pa_*` taxonomy and term ID, nonempty `variation_group`, full `parent_enrichment` |
| Convert a reviewed empty variable container | exact product ID, no parent, `convert_empty_variable_to_simple: true`; the product must still have zero children |

IDs are canonical positive integers encoded as JSON strings. For a variation,
the variable parent remains Code-less and SKU-less; each leaf owns its exact
Patris Code/SKU, stock, price, weight, freight assignment, and source metadata.
Rows sharing a variable parent must use one consistent `variation_group` and
identical parent enrichment.

`category_override` is either `null` or an exact closed object selecting one
reviewed target:

```json
{
  "category_code": "101001",
  "target_term_id": null,
  "approved_name_fa": "ماژول مبدل سطح منطقی چهار کاناله",
  "approved_source_revision": "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "evidence_urls": [
    "https://manufacturer.example/products/logic-level-converter"
  ]
}
```

or:

```json
{
  "category_code": null,
  "target_term_id": "84",
  "approved_name_fa": "ماژول مبدل سطح منطقی چهار کاناله",
  "approved_source_revision": "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "evidence_urls": [
    "https://manufacturer.example/products/logic-level-converter"
  ]
}
```

The category Code can reference a source category or a synthetic
`digitalogic:*` category declared in the manifest. A direct term override must
name an existing product category by exact term ID. Override approval is
fail-closed: `approved_name_fa` must exactly equal the product row's `name_fa`,
`approved_source_revision` must equal the root `source_revision`, and
`evidence_urls` must contain at least one unique HTTPS reference. This prevents
a category-wide generated title or stale review from silently overriding an
exact product classification.

`parent_enrichment` is `null` for simple products and is otherwise:

```json
{
  "patris_family_name": "MQ Gas Sensor Module",
  "name_fa": "ماژول سنسور گاز سری MQ",
  "short_description_fa": "خانواده ماژول های سنجش گاز MQ با انتخاب مدل مناسب برای کاربردهای مختلف.",
  "seo_title_fa": "خرید ماژول سنسور گاز سری MQ",
  "seo_description_fa": "مدل های موجود ماژول سنسور گاز سری MQ را مقایسه و انتخاب کنید.",
  "focus_keyword_fa": "ماژول سنسور گاز MQ"
}
```

## Category rows

Source categories use their exact Patris category Code as the object key. The
source name and source parent relationship must match the current living-contract state.

```json
"101001": {
  "patris_name": "Integrated Circuits",
  "target_term_id": null,
  "rename": false,
  "parent_category_code": null,
  "target_parent_term_id": null,
  "name_fa": "مدارهای مجتمع و آی سی",
  "seo_title_fa": "خرید مدار مجتمع و آی سی",
  "seo_description_fa": "انواع مدار مجتمع و آی سی موجود را با مشخصات فنی و قیمت بررسی کنید.",
  "focus_keyword_fa": "خرید آی سی"
}
```

Set `target_term_id` to adopt one exact existing `product_cat`. With
`rename: false`, its manual name and parent are retained while the stable Patris
category ownership and reviewed SEO metadata are added. With `rename: true`,
the term is updated to the reviewed `name_fa` and validated Patris parent.

Synthetic shop categories use a key beginning `digitalogic:` and an empty
`patris_name`. They are created only when a selected product references them,
and never receive a fake Patris category Code:

```json
"digitalogic:medical-sensors": {
  "patris_name": "",
  "target_term_id": null,
  "rename": false,
  "parent_category_code": "101",
  "target_parent_term_id": null,
  "name_fa": "سنسورهای پزشکی",
  "seo_title_fa": "خرید سنسور پزشکی",
  "seo_description_fa": "سنسورها و ماژول های پزشکی موجود را برای پروژه های اندازه گیری بررسی کنید.",
  "focus_keyword_fa": "سنسور پزشکی"
}
```

Choose at most one parent selector: `parent_category_code` or
`target_parent_term_id`.

## Applied product data

For each identity-safe receiver leaf, the automatic materializer first creates
only the minimum exact identity shell, then runs the same canonical Patris feed
writer used for existing WooCommerce products. It verifies the source record
hash and source ownership before making the leaf public. This ordering keeps the
reconciled report matched instead of leaving an auto-created product as
source-only or feed-drifted.

Automatic products store:

- exact Patris Code as both canonical source Code and WooCommerce SKU;
- exact source ID, dataset, revision, record hash, and missing-field snapshot;
- exact source price, stock, weight, warehouse, freight, markup, and warning
  values when present;
- the exact canonical source shipping-method assignment on automatically
  created leaves, clearing it when the source assignment is unavailable; and
- blank price plus out-of-stock state when canonical price is unavailable.

For each accepted reviewed leaf, the CLI materializer additionally:

- stores the Persian WooCommerce name and short description;
- stores the original English Patris name as the storefront second line;
- stores reviewed part number and model metadata;
- stores exact Patris source ownership and Code, and sets leaf SKU to Code;
- uses the shared feed writer for price, stock, weight, warehouses, warnings,
  currency, formula, and pricing metadata;
- assigns the canonical `air_express` supplier shipping method;
- adds the reviewed category without removing existing manual categories;
- writes Rank Math product/category title, description, focus keyword, and
  primary category metadata;
- flushes affected WooCommerce/transient/term caches and Rank Math sitemap
  cache after an apply run.

The storefront displays the Persian WooCommerce title first and the Patris
English identity below it. Selecting a variation updates the English identity
to the child value. WooCommerce/Rank Math emits server-rendered JSON-LD Product
data, adds the exact Code as SKU and the Patris name as `alternateName`, and
emits MPN only when the reviewed part number is nonempty. An unpriced or
zero-priced product keeps an honest base Product entity without a fabricated
Offer. Rank Math Open Graph and Twitter price fields are also omitted, so an
unavailable price cannot be rendered as `0 تومان`; title and availability stay
available. Priced products retain their normal price metadata and offers.
Because the storefront displays Toman while Google requires ISO 4217 currency
codes, structured offers convert
the complete `IRT` subtree atomically to its exact ten-times `IRR` equivalent.
If any inherited Toman price is noncanonical, the original subtree is retained
instead of partially relabelling its currency.

Product search includes the WooCommerce/Persian title, Patris leaf and family
names, exact SKU/Code, Patris serial, reviewed part number and model, variation
records, global attribute values, and product categories.

## Reviewed readiness gates

A reviewed leaf is marked publish-ready only when all of these remain true at
apply time:

- source and WooCommerce stock are positive;
- a positive atomic selected-price source, calculated final price, and
  WooCommerce regular/effective price are present;
- for a CNY `foreign_price` source only, source/WooCommerce weight is present
  and strictly positive,
  the supplier method is exactly `air_express`, freight is positive with an
  explicit `CNY` or `IRR` currency, WooCommerce has the same assignment, and
  the CNY-to-IRT exchange rate is positive;
- an IRR `partner_price` source instead uses the distinct positive
  `partner_price_source`, applies markup without freight, and requires the
  canonical `domestic` method with zero IRR freight;
- the disabled-by-default IRR `sale_price_direct` last fallback uses positive
  `sale_price_source`/`FOROSH` without markup or rounding and carries the same
  zero-rate domestic provenance;
- markup is present and nonnegative and rounding digits/mode are valid for the
  foreign and partner routes, and
  pricing-catalog revision and status identify the resolved calculation;
- the source has no attention-required Patris warnings; the informational
  partner-fallback and freight-not-applied notices do not block publication;
- WooCommerce has a featured image whose attachment still resolves;
- a reviewed category is available and assigned;
- Persian name, short description, SEO title, SEO description, focus keyword,
  Patris Code, and matching SKU are present.

These gates describe reviewed catalog completeness, not whether an identity-safe
source leaf may exist publicly. Missing commerce or media values remain empty
rather than being invented. The receiver preserves the leaf as public and
visible, blank-priced, out of stock, and non-purchasable until canonical data is
complete. A later event or reconciliation updates that same ID without creating
a duplicate. Existing public products are never demoted because a reviewed gate
is missing. A variable parent is not demoted when one child is incomplete.

For variations, publication also requires the reviewed variable parent
enrichment. The parent is published and made visible only after the child is
ready, while remaining Code-less and SKU-less.

## Incomplete-product warning contract

After the source/feed/publication readback succeeds and all source locks are
released, the receiver emits:

```php
do_action( 'digitalogic_patris_materializer_product_committed', $snapshot );
```

The snapshot contains only `product_id`, `product_code`, `name`, `source_id`,
`dataset`, `source_revision`, `missing_fields`, `visible`, `purchasable`, and
`price_status`. `missing_fields` is a sorted unique subset of the canonical
names `price`, `stock`, `weight`, `freight`, `markup`, `image`, and `seo`.
Alert listeners must consume this post-commit action; they must not intercept or
block product creation. Listener exceptions are contained and logged after the
verified product effect. After every nonempty dispatch batch, and still outside
all source locks, the receiver emits the no-payload action
`digitalogic_patris_materializer_product_commits_complete` so a listener can
flush its request-local buffer once.

## Recommended rollout

1. Receive a fresh complete product-sync baseline using the living contract.
2. Run the following command repeatedly until the bounded safe pending backlog
   is zero. This also migrates old `missing` deferrals into real products;
   unresolved identity conflicts remain deferred for manual review.

   ```bash
   wp digitalogic product-sync reconcile --source-id=<id> --dataset=<dataset> --user=<administrator>
   ```
3. Confirm that source-only report count is zero and spot-check incomplete
   products for public visibility, blank price, out-of-stock state, and absent
   Rank Math price/Offer metadata.
4. Run the reviewed materializer without `--apply` and review every planned
   action and skip reason.
5. Apply a small Code allowlist without `--publish-ready`. Confirm products,
   categories, variation structure, SEO fields, and the `air_express`
   assignment in WooCommerce.
6. Run a fresh Patris sync so landed prices are recalculated with the assigned
   freight method.
7. Dry-run again, then rerun with `--apply --publish-ready` to record reviewed
   readiness. Re-run it to verify idempotency and inspect only identity-hazard
   deferrals.

The reviewed result JSON includes `selected_products` and the informational
`selected_positive_stock` subset, plus planned/created/adopted/reconciled,
`published_incomplete`, and `preserved_published` counts, category summaries,
bounded per-Code reasons, and receiver reconciliation status. Archive that
nonsecret result with the reviewed manifest as the operator audit record.
