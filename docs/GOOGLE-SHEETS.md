# Google Sheets catalog synchronization

Digitalogic-WP exposes one read-only, paginated living catalog response for
Google Sheets, n8n, and other standalone clients:

```text
GET /wp-json/digitalogic/v1/google-sheets/catalog
```

The response always keeps product records and product-category records in
different datasets. The supplied Google Apps Script creates or updates the
`Products` and `Categories` tabs accordingly. It never reads credentials from
spreadsheet cells and never sends credentials as query parameters.

## What is synchronized

The `Products` dataset reuses the plugin's canonical product query and pricing
assignment services. It includes:

- string-preserved Patris Code, SKU, part number, and a stable `sync_key`;
- WooCommerce and Patris/effective prices with their currency and status;
- WooCommerce stock plus one independently selectable column per observed or
  configured Patris warehouse;
- Patris weight and foreign price;
- canonical shipping-method ID, English/Persian display names, CNY/kg rate,
  profit margin, and margin source;
- category relationships, product/image links, publication status, update
  time, record revision, and explicit sync status/notes.

An exact, case-sensitive Patris Code is the only Patris matching key. When it
exists, that Code is also the row's `sync_key`. A product without a Patris Code
remains visible with `woo:<id>` as its display/upsert `sync_key` and is marked
`warning` with `missing_patris_code`. The WooCommerce SKU remains a separate
display field: it is never a fallback for Patris matching. Duplicate
`sync_key` values make the Apps Script stop rather than silently overwrite a
product.

Rows are sparse. A missing key means the source or reference did not provide a
value. A present key whose value is `null` means the upstream source explicitly
provided null; empty strings, zero, and `false` retain their own meanings. The
REST response preserves that distinction. A spreadsheet cell cannot represent
missing-versus-null metadata, so the supplied Apps Script displays both missing
keys and explicit null values as blank cells while preserving real empty,
numeric, and boolean values in the response-processing path.

The `Categories` dataset contains category ID, name, slug, parent, product
count, description, URL, status, and revision. Categories are not mixed into
the Products tab.

## Authentication

Create a **Read** WooCommerce REST API key for the integration:

1. Open WooCommerce -> Settings -> Advanced -> REST API.
2. Add a key with `Read` permission only.
3. Keep the consumer key and secret in Google Apps Script Properties, an n8n
   credential, or environment variables in another client.

Do not put the key or secret in a sheet cell, formula, committed source file,
workflow JSON, URL, or screenshot. The route also works through an existing
WordPress administrator/shop-manager session for same-origin tools.

## Google Apps Script setup

The import-ready source is in:

```text
assets/integrations/google-apps-script/Code.gs
assets/integrations/google-apps-script/appsscript.json
```

To use it:

1. Create or open the destination Google Spreadsheet.
2. Open Extensions -> Apps Script.
3. Replace `Code.gs` with the supplied file. In Project Settings, enable the
   manifest and copy the supplied `appsscript.json` values if you use a manual
   Apps Script project.
4. Add these Script Properties:

   | Property | Value |
   | --- | --- |
   | `DIGITALOGIC_API_BASE` | HTTPS site root, such as `https://shop.example` |
   | `DIGITALOGIC_CONSUMER_KEY` | Read-only `ck_...` credential |
   | `DIGITALOGIC_CONSUMER_SECRET` | Matching `cs_...` secret |
   | `DIGITALOGIC_LOCALE` | `en`, `fa`, or `bilingual` |
   | `DIGITALOGIC_SYNC_HOURS` | Optional: `1`, `2`, `4`, `6`, `8`, or `12` |
   | `DIGITALOGIC_SPREADSHEET_ID` | Optional for a standalone script; omit for a bound sheet |

5. Run `syncCatalog` once and approve Google's requested scopes.
6. Reload the spreadsheet. Use **Digitalogic Sync -> Sync now** for a manual
   refresh, or **Enable scheduled sync** for the configured interval.

The script fetches at most 100 rows per request, follows server pagination,
unions dynamic warehouse columns, and calculates an idempotent catalog
revision. An unchanged revision avoids rewriting the tabs. Each managed tab
uses a hidden machine-key row, a localized visible header row, filters, frozen
headers and first column, text formatting for identifiers, number formatting,
row banding, and status highlighting.

The `Products` and `Categories` tabs are integration-managed. Put notes or
manual formulas on a different tab so a synchronization cannot replace them.

## REST and WP-CLI examples

Keep credentials in environment variables and use an authorization header:

```bash
curl --fail-with-body \
  --user "$DIGITALOGIC_CONSUMER_KEY:$DIGITALOGIC_CONSUMER_SECRET" \
  "https://shop.example/wp-json/digitalogic/v1/google-sheets/catalog?dataset=products&locale=bilingual&page=1&limit=100"
```

```bash
wp digitalogic google-sheets catalog --dataset=categories --locale=fa --page=1 --limit=100
```

Supported query values:

- `dataset`: `products` or `categories`;
- `locale`: `en`, `fa`, or `bilingual`;
- `page`: one-based positive integer;
- `limit`: `1` through `100`.

Every response identifies its `dataset`, supplies `columns` and sparse `rows`,
and reports `page_revision` plus `pagination.total`, `pagination.pages`, and
`pagination.has_more`. Every column includes its machine key, English label,
Persian label, selected header, and cell type. Clients validate these living
response fields directly.

## n8n path

The same API can feed an n8n workflow without making either project depend on
n8n:

1. Use an HTTP Request node with a stored Basic Auth credential and request
   `dataset=products`, `limit=100`, and the current page.
2. Continue while `data.pagination.has_more` is true.
3. Merge `data.columns` across pages because warehouse columns are dynamic.
4. Upsert `data.rows` into a Google Sheets node using `sync_key` as the match
   column. Force `patris_code`, `sku`, `part_number`, and `sync_key` to text.
5. Repeat the loop with `dataset=categories` and the `Categories` tab.
6. Store the combined page revisions in n8n workflow/static data and skip the
   sheet write when they are unchanged.

Use n8n's credential store, not Set-node fields or exported workflow JSON, for
the WooCommerce and Google credentials.

## Failure and recovery

- HTTP `401` or `403`: verify the read-only WooCommerce key and that the
  Digitalogic plugin is active.
- `missing_patris_code`: match the WooCommerce product and assign its Patris
  Code; the product remains visible meanwhile.
- `Duplicate catalog sync_key`: fix duplicate exact Patris Codes before
  retrying so no row is overwritten. `woo:<id>` fallback keys are based only on
  canonical WooCommerce IDs, never SKUs.
- Google authorization revoked: authorize `syncCatalog` again, then reinstall
  the scheduled trigger.
- A stale scheduled trigger: run `removeScheduledSync`, then
  `installScheduledSync` once.

## راهنمای فارسی

این اتصال، محصولات و دسته‌بندی‌ها را از API فقط‌خواندنی دیجیتالوجیک دریافت و
در دو برگه مستقل `Products` و `Categories` ثبت می‌کند. کد پاتریس به صورت متن
نگهداری می‌شود؛ بنابراین صفرهای ابتدای کد حذف نمی‌شوند. موجودی هر انبار در
ستون جداگانه قرار می‌گیرد و قیمت نهایی، روش حمل، هزینه حمل، حاشیه سود، وزن و
وضعیت همگام‌سازی نیز در خروجی موجود است.

تطبیق پاتریس فقط با `Code` دقیق و حساس به حروف انجام می‌شود و هرگز از SKU به
عنوان جایگزین استفاده نمی‌شود. اگر Code موجود نباشد، کلید نمایشی `woo:<id>`
صرفاً برای نگهداری ردیف شیت استفاده می‌شود. ردیف‌های API تنک هستند: نبودن کلید
یعنی منبع مقداری نداده است و مقدار `null` فقط یعنی منبع صریحاً null داده است.
در خود شیت، هر دو حالت به‌صورت سلول خالی نمایش داده می‌شوند.

برای راه‌اندازی، یک کلید WooCommerce با سطح دسترسی **Read** بسازید و سه مقدار
`DIGITALOGIC_API_BASE`، `DIGITALOGIC_CONSUMER_KEY` و
`DIGITALOGIC_CONSUMER_SECRET` را فقط در بخش Script Properties وارد کنید. این
اطلاعات را داخل سلول‌های شیت یا فایل کد قرار ندهید. مقدار
`DIGITALOGIC_LOCALE` را `fa` بگذارید تا سربرگ‌ها راست‌به‌چپ و فارسی باشند، یا
از `bilingual` برای سربرگ دوزبانه استفاده کنید.

پس از اجرای اولیه `syncCatalog` و تأیید دسترسی گوگل، از منوی
**Digitalogic Sync** همگام‌سازی دستی یا زمان‌بندی‌شده را انتخاب کنید. اگر حساب
گوگل از برنامه جدا شده باشد، ابتدا اتصال حساب را دوباره برقرار کنید؛ ساخت
شیت زنده بدون تأیید دسترسی مالک امکان‌پذیر نیست.
