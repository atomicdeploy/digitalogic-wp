# Rollback

1. Disable inbound call verification and outbound voice in WordPress.
2. Restore the dated static dialplan backup, or remove only the pending/shortcut
   calls from both `[from-tci]` paths. Dormant helper contexts may remain installed.
   Ensure the original prefix → recording → direct extension-101 priorities are
   byte-for-byte unchanged.
3. If overlapping realtime rows were changed, restore their exported snapshot in
   the same transaction. Verify static/realtime parity before reload.
4. Reload and confirm no public digit-2 menu or pending preflight exists and all
   callers ring extension 101 directly.
5. If only helper or audio is faulty, atomically repoint its `current` symlink to
   the previous immutable release instead of deleting releases.
6. Revoke/rotate the affected HMAC key if confidentiality is in doubt. Rotate
   outbound credentials separately and keep outbound dispatch disabled.
7. Preserve only redacted event IDs and timestamps. Never copy ANI, DTMF, raw JSON,
   response bodies, or secrets into incident notes.

After rollback, test both inbound entry paths and verify no queued outbound call can
dispatch while the kill switch is off.
