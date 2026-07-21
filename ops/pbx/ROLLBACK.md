# Rollback

Rollback must preserve the original direct-operator and recording flow.

1. Disable the WordPress inbound-call-verification and outbound-voice kill switches.
2. Remove only the inserted verification snapshot/DID/Gosub lines from both `[from-tci]`
   s/_X paths. Leave original `prefix-tci-callerid`, `record-call`, and operator lines
   byte-for-byte intact, then reload the dialplan.
3. Confirm a call once again follows the original prefix → recording → operator path.
4. If only helper code is faulty, atomically repoint
   `/opt/digitalogic-pbx-verification/current` to the previously validated release.
5. If only audio is faulty, repoint
   `/var/lib/asterisk/sounds/custom/call-verification/digitalogic/current` to the prior
   immutable prompt release.
6. Revoke the affected inbound HMAC key ID in WordPress before deleting its secret file.
   If `/call` credentials may be exposed, revoke/rotate that separate token too.
7. Preserve only redacted event IDs/timestamps needed for diagnosis. Do not copy phone,
   DTMF, raw callback bodies, or secrets into tickets or rollback notes.

Do not delete old releases during the incident. After rollback, verify digit 2 no longer
enters the helper, timeout/0 still reaches the operator, recording starts only on that
operator path, and no queued outbound job can call while the kill switch is off.
