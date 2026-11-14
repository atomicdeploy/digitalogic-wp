# One account across Digitalogic

The native adapter consolidates a freshly proven Google identity into the existing, freshly proven Digits phone account. WordPress remains the account authority. WooCommerce owns customers, carts and orders; Woodmart owns wishlists. The adapter stores proof, revision, delivery and recovery metadata only.

This replaces the earlier proposal in [protected-google-link-plan.md](protected-google-link-plan.md), which described a limited identity-link operation. The new flow is an explicit account consolidation. It never uses account switching as a substitute for a merge.

## Review, commit, recover

1. `GET /app-api/account/merge/plan` reads the current session's Google proof and phone proof. Expired proof produces `proof_required`; discovery never sends a message or grants a login.
2. With both proofs fresh, the response identifies the surviving phone account, exact source and target IDs, provider record counts, supported dispositions and a sealed plan. Its revision binds proofs, password epochs, roles, cart contents, addresses and reviewed provider state.
3. `POST /app-api/account/merge` accepts an immutable UUID, sealed plan, revision, explicit choices and consent. Both principals are locked, the plan is rechecked, and canonical changes plus the receipt commit in one InnoDB transaction.
4. `GET /app-api/account/merge/operations/{uuid}` recovers the exact operation. A terminal rejection is durable. An uncertain result never authorizes a second operation.

The initiating credential loses ordinary access after commit. For fifteen minutes, its exact hash can recover only its own accepted result, while its recorded password epoch remains unchanged. A freshly authenticated surviving account can retrieve the receipt later, without receiving an old session credential.

## Preserved authority

| Record | Behavior |
| --- | --- |
| WordPress users | Keep both IDs. Mark the old user as a retired audit alias; preserve the survivor's password and roles without a privilege union. |
| Google identity | Move the unique signed subject binding. Require a newly issued token or a nonce challenge created after activation; cached pre-merge proofs cannot inherit privileges. |
| Digits phone | Keep the proven canonical owner. Never use billing contact data as login ownership. |
| Woo cart | Combine reviewed standard items through Woo validation, then retire the source cart. Preserve original operation receipts. |
| Woodmart wishlist | Add source entries to the existing survivor list through the provider API. Retain source audit records. |
| Customer addresses | Fill empty contact fields. Copy a geographic address only as a unit into an empty geographic destination; never manufacture a mixed address. A map pin follows its matching address only. |
| Abandoned cart | Preserve the original post ID and content; update canonical author, owner metadata and recipient fields. Disable Woodmart's automatic empty-cart deletion only within the merge scope. |
| Sessions | Retire source WordPress cookies, application passwords, native sessions and Digits token records. Verify Cerber's canonical session cleanup. Keep login audit history. |

The first supported merge pattern requires no source orders, comments, service requests, Fluent records, uploads, cloud drafts or unresolved checkout/message operations. A nonempty unsupported provider stops the plan with `provider_migration_required`; no record is discarded to make a merge pass. Broader historical migrations require their own reviewed provider adapters.

## Transactions and delivery

Required affected tables must use InnoDB. During mutation, a private in-memory WordPress cache prevents uncommitted user or Woo session data from entering the shared cache. The original cache is restored and exact entities are invalidated after the transaction outcome.

Canonical panel, viewer, webhook and admin n8n events are deferred into an encrypted journal outbox. Delivery begins after commit. Retries preserve an operation-derived event identity; panel and viewer adapters retain their canonical deduplication. Unexpected HTTP or mail during the transaction prevents acceptance rather than pretending delivery succeeded.

## Preventing another duplicate

Incoming Iranian phone values normalize Persian/Arabic digits, presentation marks and supported local/international formats. Discovery checks all authoritative Digits phone fields for agreement and rejects ambiguous ownership. Email comparison preserves the displayed address while normalizing case, Unicode and domains. Gmail-only dot/tag aliases and `googlemail.com` are comparison equivalents. Signed Google `sub` remains the provider identity.

Unknown Google login retains a short-lived, encrypted continuation through explicit phone verification and registration. A stale provider token must first satisfy the public, subject-bound nonce challenge. Existing normalized email or phone candidates prevent an unrelated new account; neither name nor email alone grants privileged access. A retired alias is followed only for audited candidate discovery, never for credential authentication.

The website guard rejects normalized duplicates before WordPress's actual user INSERT. It covers canonical email and pre-insert Digits metadata. The observed Digits signup form signature also supplies a phone value used only to veto duplicate creation; it never populates metadata or authenticates an owner. Encoded provider paths that bypass core insertion remain unproven. The current guard is not a database uniqueness constraint; an indexed identity registry with enrollment coordination remains a follow-up.

## Acceptance evidence

- Actual serializers produce synthetic app contract fixtures, including the real AES-GCM plan shape.
- Focused tests cover nonce/session binding, normalized collisions, continuation expiry and consent, receipt replay, cache isolation and deferred effects.
- Current live discovery benchmark: 48 users and 69 Digits metadata rows; phone lookup 0.64–2.35 ms and email lookup 0.63–0.87 ms over five read-only runs. The bounded inventory fails closed beyond its limit; no stale identity cache authorizes login.
- The final isolated canonical transaction run passed 34 assertions plus 19 installed-core website guard assertions. It exercised nonempty source and target Digits, Cerber, WordPress, native and application-password sessions; rollback preserves the source, successful consolidation retires only its credentials, and recovery never discloses a profile after the issued session is revoked. The fixture had no production data, OTPs, orders or payments. Transport tracing confirmed zero successful IP or nonfixture socket connections.
- The complete backend suite passed 1,201 tests and 10,761 assertions (35 expected platform skips). A real fixture exposed an oversized encrypted merge receipt; receipts now contain compact identity/session metadata, and authorized recovery reads the current profile separately. Deferred delivery also enforces the decoder's size limit.
- Deployment on 2026-11-06 used a checked 21-file manifest, archive SHA-256 `a846514bee84ee4c4f292b91587a3460e931af4aa8507d8ac5aa4fc88d561ed0`, with rollback backup `/var/backups/digitalogic-account-merge.mwqahho4`. All affected production tables passed the InnoDB preflight. Public bootstrap returned JSON with `account_merge: true`; unauthenticated plan and recovery returned 401. Deployment itself changed no customer identity and sent no OTP.

Tracked with [backend issue 437](https://github.com/atomicdeploy/digitalogic-wp/issues/437), [GitHub PR 439](https://github.com/atomicdeploy/digitalogic-wp/pull/439), [Gitea PR 1](https://git.digitalogic.ir/root/digitalogic-wp/pulls/1) and [native app issue 1](https://github.com/atomicdeploy/digitalogic-app/issues/1).


## Provider cart defaults — 2026-11-14

A live review exposed a false migration blocker: Woo Deposits adds `is_deposit:false` and `payment_plan:0` to ordinary restored cart lines, and Tier Pricing adds a derived `tiered_pricing_data:{total_item_quantity:int}` cache. These exact default shapes are now accepted. The merge never copies a plugin candidate price; existing Woo cart hooks recompute the quantity cache and current pricing. Active deposits, nonzero plans, unknown fields and unrecognized cache shapes still block. The receiving cart is checked too when source quantities will move. If canonical hooks introduce an active or unknown option during the mutation, the transaction rolls back before retiring the source cart.

The exact one-file runtime passed21 focused tests/164 assertions and66 isolated provider-aware checks plus19 installed-core website guard checks. Actual Tier Pricing9.0.1 and Woo Deposits2.4.5 hooks ran. Source quantity1 plus target2 became3; the provider candidate80 remained subject to Digitalogic's authoritative Patris price100, producing total300. Three injected option changes during total calculation rolled back carts and identities. No production order, SMS, account change or outbound network connection occurred in the fixture.

The targeted deployment retained unrelated live patches. Read-only production inventory confirms the default metadata no longer blocks migration and the customer's existing cart remains unchanged. Fresh Google and phone proof remains mandatory; deployment itself does not consolidate an account or renew a proof.
