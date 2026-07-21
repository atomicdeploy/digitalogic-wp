# Patris Catalog Materializer

The Patris catalog materializer converts reviewed, positive-stock records from
the validated living `patris.product-sync` state into WooCommerce products.
It is deliberately an administrator-operated WP-CLI workflow. It is not called
from HTTP, cron, the receiver, or normal storefront requests.

The product-sync receiver remains authoritative for typed Patris stock,
pricing, weight, category, warehouse, and warning data. The enrichment manifest
supplies the human-reviewed Persian identity, taxonomy target, SEO text, and
exact WooCommerce ownership decision that cannot safely be inferred from the
source feed.

Freight arrives as the inseparable `shipping_price_per_kg` and
`shipping_price_per_kg_currency` pair. The currency must explicitly be `CNY` or
`IRR`; the materializer never infers it from the amount or shipping method.

## Safety model

- Dry run is the default. Nothing changes until `--apply` is supplied.
- Only records with current `total_stock > 0` are selected.
- A new or previously nonpublic product is published only with both
  `--apply --publish-ready` and only when every publication gate passes.
- Newly created leaves start as drafts. Exact reviewed targets preserve their
  pre-existing status, so adopting an already-published manual leaf never
  removes it from the storefront merely because a new readiness gate is absent.
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
- A variable product cannot own a leaf Patris Code. The materializer can create
  a reviewed child under an existing variable parent, but it never invents a
  new variable family.
- An existing variable product may become a simple leaf only when the manifest
  explicitly sets `convert_empty_variable_to_simple` and the container still
  has zero children.
- Product images are outside this workflow and are not read or changed.
- Apply runs hold a named MySQL advisory lock and trigger receiver
  reconciliation after the reviewed writes finish.

## Commands

Plan the full manifest:

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --user=<administrator>
```

Apply a bounded first phase as drafts:

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --codes=10001,10002 \
  --limit=25 \
  --apply \
  --user=<administrator>
```

After a fresh Patris product sync recalculates the newly assigned
`air_express` landed prices, publish only ready products:

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

`source_revision` is the only optional root key. Omit it when the same reviewed
manifest must be deliberately reused after a fresh sync, such as the second
publication phase. The source ID and dataset still remain exact.

## Product rows

`products` is keyed by exact Patris Code. `patris_name` must exactly match the
current source name, making a changed source identity a review failure rather
than a silent overwrite.

This example creates a new simple draft:

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
  "seo_description_fa": "مشخصات، موجودی و قیمت آی سی آپ امپ دو کاناله LM358P را در دیجیتالوجیک بررسی کنید.",
  "focus_keyword_fa": "آی سی LM358P",
  "part_number": "LM358P",
  "model": "LM358P"
}
```

Target modes are exact:

| Mode | Required ownership fields |
| --- | --- |
| Create a new simple draft | `target_product_id: null`, `target_parent_id: null`, empty attribute fields |
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
  "target_term_id": null
}
```

or:

```json
{
  "category_code": null,
  "target_term_id": "84"
}
```

The category Code can reference a source category or a synthetic
`digitalogic:*` category declared in the manifest. A direct term override must
name an existing product category by exact term ID.

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

For each accepted leaf, the materializer:

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
emits MPN only when the reviewed part number is nonempty. An unpriced product
keeps an honest base Product entity without a fabricated Offer; real existing
offers are preserved. Because the storefront displays Toman while Google
requires ISO 4217 currency codes, structured offers convert `IRT` amounts to
their exact ten-times `IRR` equivalent without changing the represented value.

Product search includes the WooCommerce/Persian title, Patris leaf and family
names, exact SKU/Code, Patris serial, reviewed part number and model, variation
records, global attribute values, and product categories.

## Publication gates

A leaf is publish-ready when all of these remain true at apply time:

- source and WooCommerce stock are positive;
- WooCommerce has the canonical `air_express` supplier shipping assignment;
- the source has no blocking Patris warnings; missing product price, weight,
  image, freight, markup, or pricing-assignment warnings are informational and
  never block publication;
- a reviewed category is available and assigned;
- Persian name, short description, SEO title, SEO description, focus keyword,
  Patris Code, and matching SKU are present.

Missing product price, weight, or image values remain empty rather than being
invented. WooCommerce publishes those pages normally; an empty price remains
non-purchasable until a real price arrives. Product rich-result and merchant
listing eligibility may remain limited until Google-required price or image
properties truthfully exist, but ordinary crawling and indexing are not gated.

For variations, publication also requires the reviewed variable parent
enrichment. The parent is published and made visible only after the child is
ready, while remaining Code-less and SKU-less.

## Recommended two-phase rollout

1. Receive a fresh complete product-sync baseline using the living contract.
2. Run the materializer without `--apply` and review every planned action and
   skip reason.
3. Apply a small Code allowlist without `--publish-ready`. Confirm products,
   categories, variation structure, SEO fields, and the `air_express`
   assignment in WooCommerce.
4. Run a fresh Patris sync so landed prices are recalculated with the assigned
   freight method.
5. Dry-run again, then rerun with `--apply --publish-ready`. Only rows with no
   publication gates are published.
6. Re-run the same command to verify idempotency and inspect product-sync status
   for any remaining deferred records.

The result is JSON and includes planned/created/adopted/reconciled/published
counts, `preserved_published` for exact reviewed targets that were already
public, category summaries, bounded per-Code reasons, and receiver
reconciliation status. Archive that nonsecret result with the reviewed manifest
as the operator audit record.
