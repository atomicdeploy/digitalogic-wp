# WordPress deployment adapters

`digitalogic-price-updated-display.php` is the existing production price-update
display adapter, now tracked with the shared application source. Version 1.0.2
reads currency provenance from `Digitalogic_Pricing_Service`; it contains no
pricing calculator or compatibility alias. Install it in `wp-content/mu-plugins`
within the same paused-writer activation window as Digitalogic 2.0.0.

The previous live 1.0.1 file had SHA-256
`c719417751bca5a0b9be2e70a52e870b39bf20d7c731648bf3e636b2ea4e139f`.
Preserve that file with the coordinated rollback material; reverting the plugin
also requires reverting this adapter. Do not install the old-class caller beside
the new core. Files under `ops` are intentionally not in the public plugin ZIP.

This adapter displays price-write metadata and owner rate provenance. It does not
prove that every persistence strategy records a per-product write timestamp;
direct-DB and adapter timestamp parity still needs end-to-end verification.

## Protected human-contact discovery

`digitalogic-human-contacts.php` is the maintained source for the separate
CLI-only adapter installed as
`wp-content/mu-plugins/digitalogic-human-contacts.php`. It is intentionally
excluded from the public plugin ZIP.

The production baseline supplied on 2026-09-13 has SHA-256
`56b9c0ff3db758f491b3248a312cbe9b3c8b44390a2946a1c71a306b95f7bc5d`.
The repository version is a hardened successor, not a byte-for-byte copy: it
retains the two discovery commands and the point-in-time availability
projection while bounding every output field, rejecting command-specific
arguments, and failing closed on malformed private state. Do not replace the
production file with this successor until its pull request is merged and the
owner separately approves deployment.

An operator must first resolve an existing administrator identity and then use
only the global `--user` parameter:

```bash
wp digitalogic contacts list --user=<verified-administrator>
wp digitalogic contacts get <person> --user=<verified-administrator>
```

`--user` selects a WordPress identity inside an already authorized WP-CLI
process; it is not password authentication. SSH access, the operating-system
account, sudo policy, and filesystem permissions remain the primary security
boundary and must stay restricted to trusted operators.

Do not add `--format=json`; the command already emits one JSON line and accepts
no command-specific options. Its output is limited to bounded names, symbolic
channel aliases, controlled preferences, a WordPress-linkage boolean, approved
field-presence names, route-presence counters/booleans, and an optional
availability observation containing a controlled state, timestamp, and
`point_in_time_only: true`. An availability observation describes only its
recorded instant: `busy`, `available`, or `unavailable` must never be treated as
permanent or assumed current.

Directory discovery is not authorization to contact a person. A separate,
explicitly authorized human-coordination workflow must resolve any returned
symbolic alias against the current PBX directory and verify the result. Never
read, export, or print the underlying `digitalogic_human_contacts` option, raw
phone or email destinations, numeric chat routing, credentials, or account
login data. The adapter does not write the registry, change its non-autoload
setting, register REST routes, or perform contact actions.

### Reviewed rollout and rollback

After merge and separate owner approval, preserve the current live file as the
rollback artifact, record its digest and metadata, lint the reviewed successor,
and replace only this MU-plugin file atomically while retaining the established
owner and mode. Re-run command discovery, then perform a bounded safe-output
readback with a verified administrator. Confirm that only `list` and `get` are
registered and that no private destination appears.

Rollback restores only the preserved prior MU-plugin file atomically and repeats
lint and command discovery. It must not restore, edit, or overwrite the private
WordPress option. Source integration, merge, and issue closure are not deployment
authorization.
