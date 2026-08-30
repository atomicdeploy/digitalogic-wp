# Storefront notifications

Digitalogic uses one durable notification event for authenticated workstation WebSockets and public-storefront SSE. The server filters the audience before it projects a notification to a browser; audience selectors and private event metadata are never included in the public SSE payload.

## Presentation

`display` accepts `toast`, `banner`, or `both`. Toast duration is bounded to 1–60 seconds. Banners remain visible until dismissed or expired. Titles, messages, and link labels are rendered as text, and links are limited to the Digitalogic origin.

The storefront keeps one SSE leader per signed-in audience partition and relays safe projected events to the user's other open tabs with `BroadcastChannel` plus a `localStorage` fallback. `sessionStorage` prevents repeated rendering in one tab, while bounded `localStorage` dismissal records keep dismissed banners closed.

## Audience

Every notification requires at least one selector:

- `broadcast: true` matches guests and signed-in users.
- `users` contains WordPress user IDs.
- `roles` contains WordPress role slugs such as `customer` or `shop_manager`.
- `attributes` contains exact user fields or user-meta values. Secret-, token-, session-, password-, and capability-bearing keys are rejected.
- Existing `devices` and `operators` selectors continue to target workstation WebSocket clients.

Selector families use `match: any` by default. Use `match: all` when a signed-in user must satisfy every supplied family. Within `attributes`, keys use `attribute_match: all` by default; `any` is also supported.

Guests can only match a broadcast notification. Role and attribute matching happens inside WordPress and requires an authenticated browser session.

## WP-CLI

```sh
wp digitalogic event-mesh notify \
  --title="اطلاعیه دیجیتالاجیک" \
  --message="قیمت‌های جدید در سایت اعمال شد." \
  --display=both \
  --level=success \
  --broadcast \
  --expires-at="2026-08-30T20:00:00+03:30" \
  --format=json
```

Target signed-in customers in Iran:

```sh
wp digitalogic event-mesh notify \
  --title="اطلاعیه مشتریان" \
  --message="شرایط ارسال برای سفارش شما به‌روزرسانی شد." \
  --display=banner \
  --roles=customer \
  --attributes='{"billing_country":"IR"}' \
  --match=all
```

Advanced actionable workstation fields remain available through a reviewed JSON file:

```sh
wp digitalogic event-mesh notify --file=/secure/reviewed-notification.json --format=json
```

## n8n

Use an HTTP Request node:

- Method: `POST`
- URL: `https://digitalogic.ir/wp-json/digitalogic/v1/event-mesh/notify`
- Header: `X-Digitalogic-Event-Key` from an n8n credential or protected environment variable
- Content type: JSON

The route uses the existing `digitalogic/v1` event-mesh contract and service credential. Never place the key in workflow exports, repository files, execution samples, or logs.

Example body:

```json
{
  "notification_id": "catalog-announcement-20260830",
  "title": "اطلاعیه موجودی",
  "message": "موجودی گروه انتخاب‌شده به‌روزرسانی شد.",
  "level": "info",
  "display": "toast",
  "duration_ms": 8000,
  "dismissible": true,
  "expires_at": "2026-08-30T20:00:00+03:30",
  "audience": {
    "roles": ["customer"],
    "attributes": {
      "billing_country": ["IR"],
      "preferred_language": ["fa_IR"]
    },
    "match": "all",
    "attribute_match": "all"
  },
  "link": {
    "href": "/shop/",
    "label": "مشاهده محصولات"
  },
  "source": "n8n"
}
```

Successful responses contain `accepted`, the durable event envelope, and any delivery warnings. Reuse a stable `notification_id`/`correlation_id` in n8n retries so operators can correlate delivery and workstation responses.

## Public SSE contract

The browser receives only:

- event ID and timestamp;
- notification ID, title, message, severity, presentation, duration, dismissal policy, expiry, and an optional same-origin link.

User IDs, roles, attributes, devices, operators, source metadata, actionable workstation fields, and service credentials are not projected to the public endpoint.
