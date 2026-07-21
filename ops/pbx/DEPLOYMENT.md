# Deployment and validation

This is a reviewed runbook, not an automatic installer. The verification assets
may be installed, but Digitalogic's public TCI routes remain direct-to-101 until a
separate IVR design is approved and tested.

## 1. Preflight and backups

- Confirm Node 18+, `ffmpeg`, Piper, NTP, and authoritative TCI ANI.
- Confirm AGI and DTMF debug/logging are off.
- Record active calls and wait for a maintenance window.
- Back up and hash `/etc/asterisk/extensions.conf`, the current helper/config,
  prompt symlink, and any approved BGM source.
- Export any realtime `extensions` rows that overlap `[from-tci]` or the new
  helper contexts. Digitalogic currently uses static inbound routing; if rows do
  exist, treat static and realtime definitions as one source-of-truth change.

Run repository gates:

```bash
cd ops/pbx
npm run check
npm test
bash -n bin/pbx-verification-digitalogic scripts/*.sh
```

## 2. Install immutable helper/config release

```bash
install -d -o root -g root -m 0755 /opt/digitalogic-pbx-verification/releases/1.1.0
install -o root -g root -m 0755 lib/pbx-verification-agi.js \
  /opt/digitalogic-pbx-verification/releases/1.1.0/pbx-verification-agi.js
ln -sfn releases/1.1.0 /opt/digitalogic-pbx-verification/current
install -o root -g root -m 0755 bin/pbx-verification-digitalogic \
  /usr/local/libexec/pbx-verification-digitalogic
install -o root -g asterisk -m 0640 config/pbx-verification.env.example \
  /etc/asterisk/pbx-verification-digitalogic.env
```

Keep the existing per-site base64 HMAC key in its root-owned, non-symlinked
secret file. If rotation is required, provision the new key in WordPress first,
then switch PBX and server key IDs atomically. Never place the key in the env file.

Verify both paths and both contexts exactly. `PBX_PENDING_HTTP_TIMEOUT_MS=1500`
is intentional: a WordPress outage must not delay ordinary callers for four seconds.

## 3. Generate BGM-mixed Persian prompts

Use a reviewed, licensed BGM file copied to an immutable PBX path. The generator
will fail if `PBX_BGM_FILE` is missing; every pending, entry, retry, success, and
failure prompt receives the same filtered/limited mix and cannot ship dry.

```bash
PBX_TTS_ENGINE=piper \
PBX_PIPER_BIN=/reviewed/path/to/piper \
PBX_PIPER_MODEL=/reviewed/path/to/fa_IR-model.onnx \
PBX_PIPER_CONFIG=/reviewed/path/to/fa_IR-model.onnx.json \
PBX_BGM_FILE=/reviewed/immutable/path/IVR-07.mp3 \
  bash scripts/generate-prompts.sh ./build/prompts-fa-IR
sudo bash scripts/install-prompts.sh digitalogic 1.1.0 ./build/prompts-fa-IR
```

Listen to every output and verify the BGM remains below speech, `*` can interrupt
the pending prompt, and both 8 kHz/16 kHz files are mono PCM. The installer creates
an immutable release and atomically switches `current`.

## 4. Keep the public dialplan direct to extension 101

Install only the dormant helper contexts from
`asterisk/digitalogic-pending-shortcut.conf`. Do not add its `preflight` or
`shortcut` calls to either `[from-tci]` entry path. Recheck the live checksum
immediately before install.

Both the `s` and `_X.` routes must contain only their existing inbound `NoOp`, then
`prefix-tci-callerid` → `record-call` →
`Dial(PJSIP/${OPERATOR_EXT},30,Tt)` → `Hangup(19)`. Keep `OPERATOR_EXT=101`.
Remove the former `digitalogic-call-verification-menu`, public digit 2, pending
lookup, and conditional shortcut from those public priorities completely.

Inside the existing `[prefix-tci-callerid]` subroutine, normalize `00989…` first
and `0989…` second, replacing either prefix with `09`. Then strip `021` from a
same-city Tehran landline. Only after those transformations may the existing
internal callback access prefix `9` be added. For example, `02166754123` becomes
`966754123`; the outbound `_9X.` route removes the access digit and sends
`66754123`. Non-Tehran landline prefixes remain intact.

Do not add a public fallback digit. The private `verify` context is intentionally
unrouted until a separate Digitalogic IVR is designed and approved.

If realtime rows overlap, update/export them in the same maintenance transaction,
then compare their effective priorities with the static file before reload. Never
allow one layer to retain the old digit-2 menu.

```bash
asterisk -rx 'dialplan show from-tci'
asterisk -rx 'dialplan show pbx-call-verification-pending-digitalogic'
asterisk -rx 'dialplan show pbx-call-verification-digitalogic'
asterisk -rx 'dialplan reload'
asterisk -rx 'agi set debug off'
```

## 5. Acceptance tests

Use real PSTN ANI and redacted evidence:

- Every call, including one whose ANI has a pending website challenge, bypasses
  the verification HTTP endpoint and AGI and rings extension 101 immediately.
- Asterisk shows the paired TCI and `PJSIP/101` channels, with no verification
  audio, early `Answer()`, prompt, or DTMF collection.
- `0989123456789` and `00989123456789` both normalize to `09123456789` before
  the internal callback access prefix is added; an existing `09…` ANI is unchanged.
- `02166754123` becomes extension caller ID `966754123`; one-touch redial sends
  the same-city subscriber number `66754123` without the `021` prefix.
- Both s and _X inbound paths behave identically.
- The dormant helper contexts remain loaded but unreachable from public and
  internal dialplan paths.

Inbound verification can be revisited only as part of the approved Digitalogic
IVR pass. Outbound voice remains a separate opt-in, authenticated trust boundary.
