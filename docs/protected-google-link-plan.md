# Protected Google linking: review contract

This is a design checkpoint, not a live account operation. The [review schema](contracts/protected-google-link-plan.schema.json) always returns `commit.enabled=false` and has no mutation endpoint. `account_merge` remains disabled. Normal native OTP sign-in to the existing phone account is available separately; a completed `phone_link` challenge never becomes a login grant.

## What the proposed operation would mean

An authenticated phone-account owner could explicitly move a separately proven Google subject to that existing account. This would grant future Google sign-ins that account's existing privileges. It would retain both WordPress users, their capabilities, passwords, provider history and original operation receipts. It would not be described as a complete account merge.

The review must distinguish three decisions:

1. Authorize Google to access the existing phone account, including its present privileges.
2. Keep the source cart separate or copy its current canonical Woo lines once into the target cart. A copy retains the source cart and its operation receipts; it is not a move. Stock, variations, availability and conflicting quantities require review through the existing Woo adapter.
3. Keep the target addresses or fill only fields that are still empty from explicitly reviewed source fields. Existing target values and authentication phone metadata are never overwritten by this choice.

Neither usernames nor new human passwords are generated. Account labels and masked identity summaries may only be disclosed after both exact owners have been proven. The initial schema contains no WordPress IDs, bearer tokens, Google subject, phone number, grant or arbitrary redirect.

## Evidence and website compatibility

The native verifier resolves the signed Google `sub` through an exact, unique existing `auth_google_id` value. It does not claim accounts by matching email or name.

The installed Digits social add-on's entry point, handler and functions are ionCube encoded. No callback implementation or loaded social hook was inspectable in the WP-CLI runtime. The configured Google settings contain provider enablement, OAuth credentials and one-tap selection; there is no visible duplicate-account merge setting.

The vendor's [Google setup guide](https://help.unitedover.com/digits/kb/how-to-setup-google-social-login/) documents OAuth configuration and a browser sign-in check. Its [Old Account Migrator](https://digits.unitedover.com/addon/old-account-migrator/) describes adding phone metadata after social signup. Neither establishes duplicate-owner precedence, email fallback, privileged-account reassignment or a reversible merge contract. These documents do not justify a claim that moving `auth_google_id` produces a unified website identity.

Until that behavior is verified, any future limited action must clearly state that only the native exact-subject mapping is verified and website Google behavior remains unverified. Changing the canonical binding can affect the website; "native-only verification" must not imply an isolated copy of identity authority or a guarantee of no website effect. No live reassignment is enabled at this checkpoint.

## Proof and durable-operation requirements

- Authenticate the target through a fresh normal OTP login, scoped to its current native session and WordPress password epoch. Current privileged access is an explicit consequence in the consent text; neither a role name nor a long-lived bearer alone supplies freshness.
- Obtain a new signed Google nonce proof for the source subject, using a separate link purpose bound to the target session, exact source owner, expected target owner, configured audience and short expiry. Reauthentication of the current account is a different purpose. Prior phone-resolution metadata supplies no new authority.
- Record expected subject ownership, target phone ownership, target empty Google binding, both account epochs, the reviewed canonical cart/address revisions and the immutable consent payload under one operation UUID. A browser/email-only match is never a substitute.
- Serialize by exact subject and both account IDs. Recheck all expectations after acquiring the locks. Unknown or changed ownership, occupied bindings, expired/revoked proofs, incomplete provider inventory and unresolved checkout/payment/request/message operations stop the mutation.
- Retain an operation journal before entering identity mutation. Same UUID with changed choices is a conflict. Recovery reads the journal and exact canonical state; it never replays provider hooks, cart operations, notifications or payment work blindly.
- Preserve uniqueness of `auth_google_id` across canonical user metadata. Plan rollback/cache invalidation with the exact installed metadata hooks before implementing the transaction; do not publish a half-moved binding.
- Establish a binding activation time and require a new signed proof at or after that boundary for subsequent privileged native Google login. A cached ID token issued before privilege-bearing reassignment must not silently gain access. Existing source native sessions are not promoted; source-session retirement and surviving source access need explicit reviewed disposition.

## Record and device preservation

The audited case is not an empty source account: its canonical Woo cart and supporting receipts exist. The retained abandoned-cart record and any history stay with their original owner. Copying two cart lines must not reapply either prior operation UUID. If source and target change during review, re-read and present conflicts instead of overwriting.

Native checkout/request/upload/message operation keys include their original owner. Changing `user_id` alone breaks recovery. This limited link plan therefore leaves those rows, provider records and original keys intact. A later general merge needs a separately audited alias or migration strategy for each provider and must preserve exact IDs.

The app must drain or reconcile immutable pending mutations before requesting the plan. Encrypted local drafts stay owner-scoped. A successful identity link is not permission to submit the source's saved order, form, message or address draft as the target. A future explicit draft migration must handle field conflicts and retain an original copy until acknowledged.

## Required acceptance before enabling anything

Use isolated, no-send fixtures to cover both proof owners, privileged capabilities independent of role names, duplicate subject rows, expired/revoked sessions, target binding races, pre-link cached tokens, source-access preservation, changed cart/address state, operation replay and crashes around each durable transition. Validate website behavior separately through the real provider path with explicit authorization. Counts alone do not prove history preservation. No real identity mutation, account deletion, privilege transfer or production contact is part of this design checkpoint.
