# Reject failed identity reads before proceeding

An actual direct-DB refresh logged a MariaDB record-changed error in the collision SELECT yet reported success. The collision read treated an empty array as no collision without inspecting the database error. Canonical-rate/price readback was inconsistent in that run; it is rejected acceptance evidence.

Check wpdb.last_error immediately after each topology, metadata and collision read. A later query must not erase the error before validation. This does not disable identity checks, retry a partial transaction, or claim the underlying MariaDB contention is fixed.

Three focused injected empty-array error cases were rejected immediately, with no subsequent query. The deployed Feed file and repository file match SHA256440e8f2c1980f936a4d8fe006caa773f5e1ba692e612dd6b265b5ac8b8b5ab68. Subsequent live changed/restored direct-DB runs had matching rate/target and no observed SQL error. See EVENT-TRANSITION-ACCEPTANCE.md for the separate repaired event path and19.008s actual bulk result.

The experimental scalar metadata normalization is excluded. The underlying SQL concurrency defect and complete rendered storefront acceptance remain open.
