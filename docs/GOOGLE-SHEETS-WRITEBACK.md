# Editable Google Sheets writeback

The Google spreadsheet remains safe to edit without turning the canonical
catalog tabs into an uncontrolled database. `Products` and `Categories` are
managed, read-only reference tabs. Product changes are staged in `Changes`,
validated with a preview, explicitly applied, and recorded per row in `Audit`.
The workbook-owned `Dashboard` presents business analytics without being
rewritten by the integration script.

No edit event can apply a change. The integration has no `onEdit` apply path,
and `Apply` always requires a separate menu action and confirmation.

## Workbook layout

| Tab | Owner | Purpose |
| --- | --- | --- |
| `Products` | Digitalogic sync | Canonical product, price, stock, warehouse, shipping, and revision data. Integration-managed reference data. |
| `Categories` | Digitalogic sync | Canonical category data. Integration-managed reference data. |
| `Changes` | Spreadsheet editors | Bounded product updates with explicit selection, exact Patris key, and expected record revision. |
| `Audit` | Digitalogic bridge | Append-only preview/apply result rows, including status, code, message, revisions, and changed fields. |
| `Dashboard` | Workbook builder | Professional business analytics and presentation. The integration never clears or restyles it and writes only reserved status cells on the recognized design. |

Spreadsheet **Editor** access is a privileged, trusted-operator role. In a
bound project, spreadsheet editors can also inspect or modify Apps Script and
its shared properties, including API or n8n credentials. Give untrusted users
Viewer or Commenter access only. If untrusted users must submit edits, move
secrets and authorization to an external identity-validated service; sheet
protections do not create that security boundary.

The bound script protects the two canonical tabs, the Changes header, and the
Audit log for the executing workbook owner only; non-owner protection editors
and domain-wide edit access are removed. Run setup, sync, preview, and apply
menus as that owner. Normal collaborator edits are directed to the Changes data
rows. A catalog sync never clears `Changes`
or `Audit`. It never clears or restyles `Dashboard`. When `A1` exactly matches
`DIGITALOGIC | PRODUCT & PRICING CONTROL CENTER`, it updates only the reserved `J13:K15`
integration-status block; an unrecognized Dashboard is left completely
untouched.

The supported physical layouts are explicit:

| Tabs | Machine keys | Display headers | First data row |
| --- | ---: | ---: | ---: |
| `Products`, `Categories` | Row 1 | Row 2 | Row 3 |
| `Changes`, `Audit` professional workbook | Row 5 | Row 6 | Row 7 |
| `Changes`, `Audit` legacy adapter | Row 1 | Row 2 | Row 3 |

The professional row-5/6 layout is detected first. Existing title, KPI, help,
formatting, and formula regions above the support-table data are preserved. An
unknown machine-header layout fails closed instead of moving or clearing rows.

## Writeback contract

The WordPress bridge has two explicit endpoints:

```text
POST /wp-json/digitalogic/v1/google-sheets/writeback/preview
POST /wp-json/digitalogic/v1/google-sheets/writeback/apply
```

Both require a normal Digitalogic/WooCommerce write-scope REST credential. A
request is limited to 50 rows and has this shape:

```json
{
  "idempotency_key": "digitalogic:preview:generated-content-key",
  "changes": [
    {
      "sync_key": "00123",
      "patris_code": "00123",
      "expected_record_revision": "sha256:64-lowercase-hex-characters",
      "fields": {
        "regular_price": 1250000,
        "sale_price": 1190000,
        "stock_quantity": 4,
        "stock_status": "instock",
        "shipping_method_id": "air",
        "profit_percent": 18
      }
    }
  ]
}
```

`sync_key` and `patris_code` are both required and must be exactly equal. This
prevents a row from changing a different product through an SKU or display-key
fallback. `expected_record_revision` must be the current
`sha256:<64 hex>` value from `Products`. A stale revision returns a conflict
instead of overwriting a newer WooCommerce/Patris value.

Only these fields are writable:

- `regular_price`: positive decimal, at most six decimal places;
- `sale_price`: positive decimal with at most six places, or `<clear>`;
- `stock_quantity`: integer from 0 through 1,000,000,000;
- `stock_status`: `instock`, `outofstock`, or `onbackorder`;
- `shipping_method_id`: canonical lowercase method ID or `<clear>`;
- `profit_percent`: number from 0 through 1000 with at most six decimal places,
  or `<clear>`.

An empty cell means “do not request this field” and is omitted. The literal
`<clear>` is deliberately converted to JSON `null` only for `sale_price`,
`shipping_method_id`, and `profit_percent`. It is rejected for price and stock
fields that must remain typed. Names, publication status, categories, IDs, and
arbitrary metadata cannot be changed from this sheet.

The script creates a change-set-bound idempotency key for each explicit preview
or apply attempt and sends the same value in the request body and
`Idempotency-Key` header. An uncertain/failed client attempt reuses its key, so
retrying cannot create a duplicate write. A completed request releases that
client attempt and a later explicit preview receives a fresh key. Apply is
enabled only when the exact selected change set was the last successful preview
and every result was `ready` or `unchanged`.

## Apps Script installation

Install the existing import-ready files:

```text
assets/integrations/google-apps-script/Code.gs
assets/integrations/google-apps-script/appsscript.json
```

Keep all credentials in **Apps Script -> Project Settings -> Script
Properties**. Never put them in cells, formulas, screenshots, logs, or source
files.

Required catalog properties:

| Property | Purpose |
| --- | --- |
| `DIGITALOGIC_API_BASE` | HTTPS site root, such as `https://digitalogic.ir` |
| `DIGITALOGIC_CONSUMER_KEY` | Read-scope `ck_...` catalog credential |
| `DIGITALOGIC_CONSUMER_SECRET` | Matching read credential secret |
| `DIGITALOGIC_LOCALE` | `en`, `fa`, or `bilingual` |

Direct writeback properties:

| Property | Purpose |
| --- | --- |
| `DIGITALOGIC_WRITEBACK_CONSUMER_KEY` | Separate write-scope `ck_...` credential |
| `DIGITALOGIC_WRITEBACK_CONSUMER_SECRET` | Matching write credential secret |
| `DIGITALOGIC_WRITEBACK_MAX_CHANGES` | Optional local limit from 1 through 50; default 50 |

Editable decimal cells are formatted as text and sent/returned as canonical
decimal strings. This preserves up to six fractional digits exactly, including
near the 15-digit price ceiling, instead of rounding through JavaScript or PHP
floating-point values.

If the separate writeback properties are omitted, direct mode falls back to
the catalog key. That succeeds only when the catalog key itself has write
scope. A separate least-privilege write credential is recommended.

After saving properties:

1. Bind this project to the target spreadsheet, then run `syncCatalog` once as
   the workbook owner and approve the manifest scopes.
2. Reload the spreadsheet.
3. Select **Digitalogic Changes -> Set up editable workspace**.
4. Verify all five tabs are present, `Changes` and `Audit` have machine keys on
   row 5 with display headers on row 6, and the designed Dashboard is unchanged.

## Day-to-day operation

1. Run **Digitalogic Sync -> Sync now**.
2. On `Products`, select one or more complete product data rows.
3. Choose **Digitalogic Changes -> Stage selected Products**. The script copies
   only the exact Patris/sync key and current record revision. Existing staged
   field edits are preserved when a row is restaged.
4. Edit only the allowed fields in `Changes`. Leave unrequested fields blank.
5. Check `Selected` for at most 50 ready rows.
6. Choose **Preview selected changes**. Review each typed result in `Audit`.
   Workbook-owned Dashboard formulas may summarize those rows. Apps Script
   updates only its reserved `J13:K15` status block on the recognized design.
7. If every row is ready, choose **Apply last preview...** and confirm the
   explicit write action.
8. Applied/no-op rows are unselected, revisions are advanced, and the catalog
   is refreshed.

If a row conflicts, sync the catalog, select that product on `Products`, and
stage it again. The staged field edits remain, but its expected revision is
replaced with the current one. Preview again before applying.

Formulas are rejected on every selected Changes row. This prevents a formula
from changing between preview and apply or being used to smuggle external cell
content into a request. Use literal values only.

## Optional n8n bridge

The importable workflow is:

```text
assets/integrations/n8n/digitalogic-google-sheets-writeback.json
```

It is intentionally inactive and contains credential placeholders only. Its
two authenticated webhook paths proxy revision-checked preview/apply requests
to Digitalogic. Catalog refresh deliberately remains in the bound Apps Script
menu and its installed scheduled trigger; the n8n workflow has no remote Apps
Script execution branch.

To configure it:

1. Import the JSON into n8n, but leave the workflow inactive.
2. Create an n8n **Header Auth** credential. Set its header name to
   `X-Digitalogic-Bridge-Token` and use a newly generated high-entropy value.
   Replace the placeholder credential on both Webhook nodes.
3. Create an n8n **HTTP Basic Auth** credential backed by a write-scope
   WooCommerce REST key. Replace the placeholder on the two Digitalogic HTTP
   nodes. The key lives in n8n's credential store, not the workflow JSON.
4. Activate the workflow and copy the production webhook base ending before
   `/preview` or `/apply`. For the supplied paths it normally resembles:

   ```text
   https://automation.example/webhook/digitalogic-google-sheets
   ```

5. In Apps Script Properties, set:

   | Property | Value |
   | --- | --- |
   | `DIGITALOGIC_N8N_WRITEBACK_BASE` | The query-free HTTPS production webhook base |
   | `DIGITALOGIC_N8N_WRITEBACK_TOKEN` | The same Header Auth value from step 2 |

When the n8n base is set, Apps Script sends only the bridge token to n8n. It
does **not** send the WooCommerce key or secret; n8n injects its stored
credential when calling WordPress. Apply requests also require the fixed
`X-Digitalogic-Confirm-Apply: APPLY` header, which Apps Script emits only after
the user confirms Apply. Removing the n8n base returns the script to direct
writeback mode. The proxy returns the upstream response body and bounded HTTP
status, so authentication errors, revision conflicts, validation errors, and
server failures do not become false `200` responses. Failed, successful, and
manual execution payloads are not retained by the template, preventing
authenticated webhook headers from being stored in n8n execution history. Use
the Sheet Audit tab and Digitalogic server audit/log as the troubleshooting
trail.

## Validation and recovery

Repository checks:

```text
node --test tests/google-sheets-apps-script.test.js
```

Common failures:

- `401`/`403`: confirm the direct or n8n-stored WooCommerce credential has
  write scope and the authenticated user can manage WooCommerce products.
- `conflict`: refresh and restage the product revision; never copy a revision
  from another row.
- `invalid`: correct the exact field named in the Audit result. Empty and
  `<clear>` are intentionally different.
- `failed`: review the Sheet Audit code/message and the Digitalogic server
  audit/log. Retrying the unchanged request is idempotent.
- `may_have_applied: true` in Audit recovery means the server reported a
  post-write persistence/response failure. Do not create a new key; retry the
  exact unchanged request so idempotency can resolve it safely.
- catalog refresh failure: use the bound Apps Script menu or verify its
  installed scheduled trigger; n8n intentionally does not execute Apps Script.

Do not delete or edit Audit rows to make a failed request appear successful.
The source of truth is the typed server result plus the current product record
revision.
