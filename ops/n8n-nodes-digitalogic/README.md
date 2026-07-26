# Digitalogic n8n node

This private node connects n8n to the Digitalogic event mesh without embedding WordPress, RouterOS, PBX, or customer credentials in workstation software.

Operations:

- send a targeted actionable notification;
- record RouterOS or manual presence evidence;
- read current composite presence;
- resolve a caller against WooCommerce plus the bounded local Asterisk CDR reader;
- fetch correlated workstation responses.

The credential contains an event key generated specifically for n8n. WordPress stores only its SHA-256 hash. The key must be imported into n8n's encrypted credential store and must not be committed, logged, or copied into workflow JSON.

Caller history reads only `/var/log/asterisk/cdr-csv/Master.csv` (or the explicitly configured `DIGITALOGIC_ASTERISK_CDR_PATH`), rejects files over 20 MiB, and returns only bounded timing, direction, disposition, duration, and unique-ID fields. Google Sheets or other CRM candidates can be supplied by earlier n8n nodes in `external_candidates`; the Digitalogic node merges them without taking ownership of those source credentials.

Router identities are SHA-256 hashed by the node. WordPress resolves the digest through a private server-side device-to-operator map, so MAC addresses and personnel mappings do not appear in workflow JSON.
