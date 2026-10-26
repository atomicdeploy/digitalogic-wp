# Unpaid stock reservations

The existing checkout integration boots `Digitalogic_Stock_Reservations`. There
is no separate plugin, cron daemon, or realtime connection.

`digitalogic_stock_reservations_enabled_at` is an activation UTC timestamp.
Only newly created `checkout`/`store-api` parent orders at or after that cutoff
are enrolled. Historical orders, preorder orders, and deposit child orders are
not enrolled. Merely adding to a cart does not reserve stock.

Each enrolled order stores `_digitalogic_stock_deadline` as its creation time
plus 3600 seconds. Views, retries, and edits never extend this deadline. A
per-order Action Scheduler action, `digitalogic_expire_unpaid_stock`, cancels
unpaid pending/on-hold orders and releases/restores stock using WooCommerce
APIs. The server's existing WordPress cron timer dispatches scheduled work.
Scheduling is subject to normal worker latency; stock holds and BACS physical
stock deductions use the same fixed order deadline.

Paid orders, paid deposits, approved receipts, and complete receipts submitted
before the deadline are protected. Receipt intake, expiration, and gateway
payment completion serialize on an order advisory lock. Rejecting a receipt
resumes the original deadline. An expired order cannot be reused for payment.

A verified gateway callback after expiry records the payment and transaction,
places the order on hold, and marks `_digitalogic_payment_stock_review` without
reducing stock. Late receipts receive the same allocation-review flag, without
claiming the receipt is a verified payment. The manager order action
"بازبررسی موجودی و تخصیص به پرداخت تأییدشده" requires verified payment or an
approved receipt and first obtains a fresh atomic WooCommerce reservation.
It refuses insufficient stock. The manager can then arrange supply or refund
through the normal operational workflow.

Cart/checkout pages use the existing product invalidation transport and a
session-specific, uncached, read-only `wc-ajax=digitalogic_cart_stock` endpoint.
Visible pages also check every 15 seconds and on focus. Shared parent stock and
other orders' active holds are included; the shopper's own reservation is
excluded. Changed quantities are displayed without silently editing the cart,
and purchase actions are disabled on shortages. Existing backorder eligibility
is respected. WooCommerce's native stock validation remains authoritative at
checkout even if the browser disconnects or JavaScript is bypassed.

Validation:

- `php vendor/bin/phpunit --filter StockReservationsTest`
- `node --check assets/js/stock-reservations.js`
- In an authorized WooCommerce environment, `wp eval-file
  tests/fixtures/stock-reservations-live.php` exercises real CRUD, competing
  reservations, the actual Action Scheduler runner, receipt protection,
  idempotent restoration, late callbacks, and manager reallocation. The harness
  suppresses outbound producers/transports in its process, creates no user,
  sends no real payment, and deletes its synthetic orders/products afterward.
- Browser acceptance must verify a stock invalidation updates an already open
  cart, blocks a shortage, and recovers after release, without a page refresh.

To stop enrolling new orders, set the activation option to zero. Already
tracked orders keep their deadlines. Preserve the class and scheduled handler
until existing tracked orders have been resolved; reverting only the loader
would strand their scheduled actions. Original integration files are backed
up server-locally during deployment.
