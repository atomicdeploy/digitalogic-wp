# Product Event to Codex Bridge

The repository-owned `scripts/event-product-codex-bridge.cjs` process converts
committed WooCommerce product events into wake-only Codex tasks. It is an
operator-side companion, not WordPress plugin runtime code, and is therefore
not included in the installable plugin ZIP.

The bridge subscribes to the existing Redis wake channel, then drains the
authoritative `digitalogic_panel_events` ledger. It does not poll. Its durable
local cursor and in-flight reservation live outside the repository under the
operator's Codex data directory; never commit that state, logs, routes, or
credentials.

Accepted events are only `product.created` and `product.updated`. The immutable
Panel integer event ID is the orchestration idempotency key. Patris-specific
event data is strictly limited to WordPress product IDs, Product Code, exact
source event and revision hashes, and the sorted allowlist of changed-field
names. Values such as prices, stock quantities, weights, credentials, customer
data, and private routes are never transported.

Run the checked-in validation before installation:

```powershell
node --check scripts/event-product-codex-bridge.cjs
node --test scripts/event-product-codex-bridge.test.cjs
node scripts/event-product-codex-bridge.cjs --self-test
```

Start the process from the canonical checkout with the existing trusted SSH
configuration:

```powershell
node scripts/event-product-codex-bridge.cjs --thread <codex-thread-uuid> --workspace <absolute-workspace-path>
```

The default remote is `digitalogic.ir`. The process uses strict host-key
checking and batch authentication. Supervision belongs to the operator machine;
deploying the WordPress ZIP neither installs nor starts this local process.

Acceptance requires one event-ledger row for an exact source event and Product
Code, one Codex dispatch for its immutable Panel ID, durable cursor advancement
only after Codex exits successfully, and authoritative Patris/WooCommerce plus
storefront and Woodmart AJAX readback by the awakened task.
