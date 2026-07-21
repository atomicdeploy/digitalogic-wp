# Deployment and validation

This is a reviewed runbook, not an automatic installer. Do not mutate Asterisk
or enable the feature until the WordPress pending endpoint, 120-second challenge
TTL, browser consumption, and login redirect have been deployed and tested.

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

## 4. Merge the dialplan

Manually merge `asterisk/digitalogic-pending-shortcut.conf` into both exact
`[from-tci]` s/_X paths. Recheck the live checksum immediately before install.

Mandatory ordering is: initialize fail-open variables; snapshot ANI/DID; signed
preflight; conditional shortcut (which alone answers the channel); verified
hangup; then the untouched
`prefix-tci-callerid` → `record-call` → operator flow. Remove the former
`digitalogic-call-verification-menu` and its public digit 2 completely.

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

- No active challenge: no verification audio or early `Answer()`; original operator
  flow and caller-ID prefix remain unchanged.
- Pending exact ANI: the private «دوست عزیزم…» BGM prompt plays immediately.
- Star during the prompt or between digits cancels immediately; no input times out
  once, then operator flow begins, with no callback and no verification DTMF in
  recordings or logs.
- Correct six-digit code: one confirm callback marks the challenge verified and the
  call terminates after the success prompt.
- A rejected six-digit typo gets one retry, then falls back to the operator;
  wrong/expired codes and wrong/withheld ANI cannot verify.
- Pending endpoint timeout, malformed JSON, extra response keys, bad signature,
  replay, or HTTP failure reaches the operator within the bounded timeout.
- Confirm endpoint failures remain closed and generic.
- Both s and _X inbound paths behave identically.

Only after these pass should inbound verification be enabled for test accounts.
Outbound voice remains a separate opt-in, authenticated trust boundary.
