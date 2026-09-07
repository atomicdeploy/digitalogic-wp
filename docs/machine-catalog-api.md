# Machine-readable product catalog

Use the existing `GET https://digitalogic.ir/wp-json/digitalogic/v1/products` route.
It returns a JSON object with a `data` array, not a rendered product grid. Parse
JSON in the client; no HTML scraping or regular expression is required.

## Authentication and scope

The existing read permission is preserved: an authorized WordPress session or
an appropriately scoped WooCommerce API key for a user with catalog permission.
Use HTTPS and the Authorization header. Do not put credentials in URLs or ship
a shared privileged key inside a publicly distributed Android or Windows app.
This is an internal catalog API, including operational fields and unpublished
products; it is not an anonymous storefront feed. No credentials are created
by this feature.

## Requests

Append these query strings to the route (percent-encode values using the client
URL builder):

| Purpose | Query |
| --- | --- |
| Partial name, SKU, model/part number or product code, combined with OR | `?q=12704` |
| Partial SKU only | `?sku=110002` |
| Explicit model attribute only | `?part_number=12704` |
| Partial internal product code | `?patris_product_code=110` |
| All matching products in one response | `?q=12704&all=1` |
| Entire catalog in one response | `?all=1` or `?limit=-1` |
| Published records only | `?all=1&status=publish` |
| Page of 100 rows | `?page=1&limit=100` |
| Stable ascending ID order | `?all=1&sort[field]=id&sort[direction]=asc` |
| Ascending SKU order | `?sort[field]=sku&sort[direction]=asc` |

`q` matches the title, `_sku`, `attribute_pa_model`, `_digitalogic_part_number`, `_digitalogic_model`, the `pa_model` taxonomy,
and the canonical product-code meta value. It is literal substring matching,
using the database collation; `%` and `_` in the query are escaped as literals.
An empty query means no search filter. Queries are normalized to at most 160
characters. Legacy `search` retains the existing WordPress text-search behavior.
Field filters combine with AND. `part_number` filters stored attributes; use
`q` when the part number may only be present in the product title.

The default order is date descending with ID as the tie breaker. The existing
`sort` / `sorts` contract supports one sort field. Positive `limit` values are
bounded to 100; `all=1`, `all=true`, or exactly `limit=-1` select full mode and
ignore `page`. Full mode internally reads batches of 100 but returns one array.
All modes include product and variation rows; use `id` as the unique key and
`parent_id` to relate variations. The catalog scope excludes trash and auto-drafts.

## Response contract

Illustrative subset of an object (the existing additional fields remain):

```json
{
  "success": true,
  "data": [
    {
      "id": 11904,
      "parent_id": 0,
      "name": "TEC1-12704",
      "sku": "110002",
      "part_number": "TEC1-12704",
      "price": "12345",
      "stock_quantity": null,
      "stock_status": "instock",
      "category_ids": [],
      "categories": []
    }
  ],
  "total": 1149,
  "recordsTotal": 1149,
  "recordsFiltered": 1,
  "page": 1,
  "limit": -1,
  "pages": 1
}
```

`recordsFiltered` is the matching total; `total` and `recordsTotal` are the
unfiltered total. Empty matches return `data: []` and `pages: 0`. Full mode
returns `limit: -1`, `page: 1`, and `pages: 1` when nonempty.
SKU and product codes are strings; preserve leading zeros. Prices are decimal
strings, not HTML; an empty price is not zero. Nullable stock is not zero stock.
Use the existing `/currency` route to obtain currency settings.

A missing page, count change, duplicate ID or failed row produces HTTP 503
`digitalogic_catalog_incomplete` instead of a partial successful full catalog.
A query exception produces a 503. Keep the last successful client catalog and
retry with a bounded policy. A full read is not an immutable snapshot: simultaneous
edits that do not change counts or IDs may be visible. For immutable pricing
snapshots, use the existing pricing snapshot integration contract.

## C# / WPF

Use an HttpClient configured with the application's authorized authentication.

```csharp
using System.Net.Http.Json;
using System.Text.Json;

var query = Uri.EscapeDataString(partialIdentifier);
using var response = await client.GetAsync(
    $"https://digitalogic.ir/wp-json/digitalogic/v1/products?q={query}&all=1",
    cancellationToken);
response.EnsureSuccessStatusCode();
var root = await response.Content.ReadFromJsonAsync<JsonElement>(
    cancellationToken: cancellationToken);
foreach (var product in root.GetProperty("data").EnumerateArray())
{
    int id = product.GetProperty("id").GetInt32();
    string sku = product.GetProperty("sku").GetString() ?? "";
    // Map to your view model; preserve decimal strings and nullable stock.
}
```

## Dart / Flutter

Use the application's existing authentication headers; `package:http` is assumed.

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

final uri = Uri.https('digitalogic.ir', '/wp-json/digitalogic/v1/products', {
  'q': partialIdentifier,
  'all': '1',
});
final response = await http.get(uri, headers: authHeaders);
if (response.statusCode != 200) {
  throw StateError('Catalog HTTP ${response.statusCode}');
}
final root = jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
final products = (root['data'] as List).cast<Map<String, dynamic>>();
```

A full catalog can take longer than a small search. Set an appropriate network
timeout and avoid fetching the entire catalog on every keystroke.
