# Canonical saved destinations

Status: source work in progress, not deployed, capability disabled. The existing inline Woo checkout remains the production path. No production address-book schema or customer migration has run.

Each destination is an independently revised database record owned by the existing WordPress customer. It contains a label, country, province/state, city, two street fields, postcode and an optional explicitly chosen map point. Names, company, email, phone and authentication identities remain on the existing Woo customer. Prior order addresses remain immutable Woo snapshots.

The store currently has one billing and one shipping address in Woo user metadata; no existing multiple-address provider was found in the installed Woo/Woodmart stack. The planned canonical records therefore extend `digitalogic-wp` with `digitalogic_customer_addresses`, `digitalogic_customer_address_books` and metadata-only `digitalogic_customer_address_operations`. Browser/default Woo addresses remain compatibility projections.

## Wire contract

`native_saved_addresses` stays false until schema migration, owner checks, concurrency/recovery and checkout integration are verified.

| Route | Intent |
| --- | --- |
| `GET /account/addresses` | `{revision,items,defaults:{billing_id,shipping_id}}`; no read-triggered creation |
| `GET /account/addresses/schema?country=IR` | Canonical country defaults, Woo geographic descriptors, schema revision, limits and label descriptor |
| `POST /account/addresses` | `{operation_id,base_revision,address,default_for:[]}`; collection revision |
| `PATCH /account/addresses/{id}` | `{operation_id,base_revision,address}`; row revision and changed fields only |
| `DELETE /account/addresses/{id}` | `{operation_id,base_revision}`; a default requires an explicit replacement first |
| `POST /account/addresses/defaults` | `{operation_id,base_revision,billing_id,shipping_id}`; both references supplied explicitly |
| `GET /account/addresses/operations/{uuid}` | Owner-scoped immutable operation recovery |

Records expose `id`, integer `revision`, `label`, the geographic fields, `location`, `created_at` and `updated_at`. Nullable default IDs represent absent defaults; clearing an existing Woo default is not an implicit deletion action. Known UUID/different intent returns a conflict. Only an exact durable `operation_status: rejected` unlocks a rejected client intent; unknown404, timeout or processing never does.

Checkout accepts `address_selection:{billing:{id,revision},shipping:{id,revision}}`. A selected group must omit inline geographic keys and its inline location, while retaining ordinary recipient/contact fields if supplied. Shipping selection is absent when a separate shipping address is not requested. The quote resolves exact owner/ID/revision and stores an immutable snapshot and references. Submission must never silently substitute a newer record.

Choosing a saved destination for an order does not change a named default. Only explicit address-book default commands change defaults. Native saved-address checkout uses the destination in the immutable order while Woo customer processing retains default geography and updates applicable contact fields. Inline legacy and browser checkout retain existing semantics.

## Implemented source components

- `MobileSavedAddress`: bounded geography-only validation, Woo country/state/postcode authority, street newlines, no person/auth fields, geographic projection hash and country-aware schema.
- `MobileAddressBookStore`: explicit transactional schema installation, exact-owner read/list, bounded serialization and exact-revision quote snapshot resolution.
- `MobileAddressSelection`: rejects mixed geographic sources and preserves contact fields while applying the resolved snapshot.
- `MobileAddressMutation`: source transaction/UUID/CAS workflow, owner lock shared with identity consolidation, session revalidation, isolated cache, deferred canonical effects and geographic-only Woo default setters. Still requires canonical fixture acceptance.

The initial value/selection regression set passed7 tests/76 assertions; related effects/merge coverage passed23 tests/205 assertions. This does not prove storage or integration acceptance.

## Remaining release gates

1. Complete and test website/default synchronization and explicit migration of existing Woo geography, preserving incomplete legacy data without inventing fields.
2. Wire authenticated routes, capability gates, address effects/cron and exact new-route empty-string handling.
3. Include source records and pending receipts in account-merge inventory; fail closed until a separately reviewed migration is supported.
4. Complete selected-record quote/submit fingerprinting, immutable snapshots, default-preserving customer processing and multiline native Woo fields.
5. Run isolated real Woo/MySQL CRUD, ownership, revision conflicts, lost-response replay, rollback, default projection, browser edits and order-snapshot tests with external sends blocked.
6. Deploy a narrow verified manifest, then enable only after migration/readback; complete native large-font address entry and actual selection acceptance.
