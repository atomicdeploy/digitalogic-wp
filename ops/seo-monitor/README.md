# SEO monitor status declassifier

Status: **pending owner approval**. These files are review artifacts only.
Do not install or deploy the helper, HMAC key, or sudoers rule from an
unmerged branch. A merge or issue closure does not itself record owner
approval.

## Purpose and trust boundary

The SEO monitor keeps private state under
`/var/lib/digitalogic/seo-monitor` as `root:root` with directory mode `0700`
and file mode `0600`. Those permissions remain unchanged.

The direct executable in `bin/digitalogic-seo-monitor-status.cjs` is a
least-information declassifier. It reads five fixed JSON files as root and
returns only counters, booleans, controlled states, timestamps, and keyed
fingerprints. It never returns file paths, record identifiers, product data,
document or tab identifiers, source URLs, content, collaborator information,
credentials, or notification routing.

The WordPress command must perform its administrator and `manage_options`
checks before invoking this executable. That application-level identity does
not cross the `sudo` boundary: every local process running as `www-data` can
invoke a permitted fixed helper. The output and every failure shape therefore
must remain safe for any local UID 33 caller.

## Fixed inputs

The executable accepts no arguments, configuration environment variables, or
stdin data. It uses only these paths:

| Logical source | Fixed private path | Maximum size |
| --- | --- | ---: |
| `latest_completed` | `/var/lib/digitalogic/seo-monitor/latest-completed.json` | 1 MiB |
| `owner_approvals` | `/var/lib/digitalogic/seo-monitor/owner-approvals.json` | 4 MiB |
| `pending_decisions` | `/var/lib/digitalogic/seo-monitor/pending-decisions.json` | 4 MiB |
| `google_docs_scan_state` | `/var/lib/digitalogic/seo-monitor/google-docs-scan-state.json` | 8 MiB |
| `google_docs_migration_ledger` | `/var/lib/digitalogic/seo-monitor/google-docs-migration-ledger.json` | 16 MiB |

The HMAC key is a raw, exactly 32-byte file at
`/etc/digitalogic/seo-monitor-status.hmac`. It must be provisioned outside the
repository as `root:root` mode `0600`. The status helper never creates,
rotates, or prints it.

The private directory must be a real `root:root` directory with exact mode
`0700`. Every input and the key must be a regular, non-symlink, single-link
`root:root` file with exact mode `0600`. Files are opened read-only with
`O_NOFOLLOW`, checked with `fstat`, size-bounded, and checked again after the
read. Each fixed parent path component is also required to be a real
root-owned directory that is not group/other writable. A file that changes
during both bounded attempts is not parsed.

## Producer contracts

Each producer file is a JSON object with `schema_version` exactly `1.0` or
`1.1`; every other version is `unsupported_schema`. Timestamps are
millisecond-precision UTC. The source-specific required projections are:

- Latest completed: an opaque bounded `audit_id`, `completed_at`, a controlled `status`, and direct
  nonnegative integer `critical_findings`, `significant_findings`,
  `tracked_findings`, and `owner_decisions` counters. Producer status
  `completed` maps to `completed`; `completed_with_findings` and `attention`
  map to `attention`; `blocked`, `failed`, and `unknown` map to themselves.
  Other source statuses are rejected instead of being echoed.
- Owner approvals: `updated_at` and `approvals[]` with opaque `fingerprint` and one of
  `pending`, `approved`, `rejected`, or `expired`.
- Pending decisions: `updated_at` and `decisions[]` with opaque `fingerprint`,
  a controlled `severity`, and a bounded `status`; unrecognized but bounded
  severities count only as `other`. Private `current`, `recommended_reply`,
  and `blocked_workflows` data is ignored.
- Google Docs scan: `updated_at`, `inventory_complete`,
  `google_docs_access_blocked`, an opaque or null `cursor`, and `documents[]`
  with opaque document and revision fingerprints, `changed`, `tabs_seen`, and
  `error`.
- Google Docs ledger: `updated_at` and `entries[]` with opaque `fingerprint` and status.
  Unrecognized but bounded statuses count only as `other`.

Additional private producer fields are ignored and never fingerprinted or
emitted. An unknown schema is `unsupported_schema`; malformed required data is
`invalid`. The included fixtures cover both accepted producer revisions and
contain deliberately sensitive ignored fields to exercise non-disclosure.

## Output contract

Stdout contains exactly one compact JSON line:

```json
{
  "schema": "digitalogic.seo-monitor.status/v1",
  "generated_at": "2026-07-26T10:00:00.000Z",
  "snapshot_state": "complete",
  "sources": {
    "latest_completed": {
      "read_state": "available",
      "updated_at": "2026-07-26T09:59:00.000Z",
      "summary": {
        "run_state": "attention",
        "critical_findings": 1,
        "significant_findings": 2,
        "tracked_findings": 3,
        "owner_decisions": 1
      },
      "fingerprint": "hmac-sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
    }
  }
}
```

The real `sources` object always has exactly these five keys, each with exactly
`read_state`, `updated_at`, `summary`, and `fingerprint`. Read state is one of
`available`, `missing`, `invalid`, `unsupported_schema`, `unsafe`,
`too_large`, or `changed_during_read`. A non-available source always has null
`updated_at`, `summary`, and `fingerprint`; zero is never used to represent
unavailable data.

`snapshot_state` is `complete` when all five sources are available, `partial`
when some are available, and `unavailable` when none are available.

Fingerprints are domain-separated HMAC-SHA-256 values over canonical,
whitelisted projections and sorted per-record HMAC tokens. Raw identifiers and
URLs are not emitted or directly hashed into an unkeyed public digest.

## Reviewed deployment prerequisites

Deployment remains a separate, explicitly approved operation:

1. Run `npm run check` and `npm test` in this directory.
2. Install the reviewed executable atomically as
   `/usr/local/libexec/digitalogic-seo-monitor-status`, owned by `root:root`
   and mode `0555` or `0755`. All parent directories must be root-owned and
   not group/other writable.
3. Provision the 32-byte HMAC key outside source control as `root:root` mode
   `0600`.
4. Calculate the installed helper digest, replace the sudoers template
   placeholder, and validate the completed file with `visudo -cf`.
5. Install the completed sudoers file as `root:root` mode `0440`.
6. Confirm `www-data` can run only the exact no-argument executable. Any
   argument, alternate executable, environment preservation, or modified
   helper digest must be denied.
7. Exercise the capability-checked WP-CLI command and confirm its response
   matches the exact output allowlist without exposing private fixture values.

No step above authorizes weakening the existing `0700` or `0600` permissions.
