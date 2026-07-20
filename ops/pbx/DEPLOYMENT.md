# Deployment and validation

These are reviewed runbook steps, not an automatic installer. Keep the website voice
feature and all outbound preferences disabled until every validation gate passes.

## 1. Preflight

- Node.js 18 or newer is installed at `/usr/bin/node`.
- Asterisk AGI debug and DTMF debug/logging are off.
- NTP is healthy; callback authentication allows no more than 60 seconds of skew.
- The inbound carrier supplies authoritative ANI. Do not trust a user-set SIP `From`.
- Capture a test call's DID/ANI format without logging production values long-term.
- Confirm `+982166754123` reaches both live s/_X paths in `[from-tci]` as expected.

Run repository checks before copying anything:

```bash
cd ops/pbx
npm run check
npm test
bash -n bin/pbx-verification-digitalogic scripts/*.sh
```

## 2. Install one immutable helper release

Replace `1.0.0` with the reviewed asset version:

```bash
install -d -o root -g root -m 0755 /opt/digitalogic-pbx-verification/releases/1.0.0
install -o root -g root -m 0755 lib/pbx-verification-agi.js \
  /opt/digitalogic-pbx-verification/releases/1.0.0/pbx-verification-agi.js
ln -sfn releases/1.0.0 /opt/digitalogic-pbx-verification/current
install -o root -g root -m 0755 bin/pbx-verification-digitalogic \
  /usr/local/libexec/pbx-verification-digitalogic
```

Create configuration and a separate 384-bit HMAC key outside the repo:

```bash
install -d -o root -g asterisk -m 0750 /etc/asterisk/secrets
umask 027
openssl rand -base64 48 > /etc/asterisk/secrets/digitalogic-pbx-verification.key
chown root:asterisk /etc/asterisk/secrets/digitalogic-pbx-verification.key
chmod 0640 /etc/asterisk/secrets/digitalogic-pbx-verification.key
install -o root -g asterisk -m 0640 config/pbx-verification.env.example \
  /etc/asterisk/pbx-verification-digitalogic.env
```

The example contains no secret. Verify its origin, exact callback path, DID, context,
key ID, prompt paths, and secret-file path. Do not put the secret in the env file or repo.

## 3. Generate and install Persian prompts

Reuse the host's existing local Piper binary/model/config from the PBX callout renderer;
no external TTS service or new TTS install is required. `ffmpeg` performs deterministic
mono PCM conversion to Asterisk 8 kHz `.wav` and 16 kHz `.wav16` variants:

```bash
PBX_TTS_ENGINE=piper \
PBX_PIPER_BIN=/reviewed/path/to/piper \
PBX_PIPER_MODEL=/reviewed/path/to/fa_IR-model.onnx \
PBX_PIPER_CONFIG=/reviewed/path/to/fa_IR-model.onnx.json \
  bash scripts/generate-prompts.sh ./build/prompts-fa-IR
sudo bash scripts/install-prompts.sh digitalogic 1.0.0 ./build/prompts-fa-IR
```

Listen to every generated WAV before deployment. Confirm natural pronunciation of
"عدد دو" and "کد شش رقمی". The installer writes an immutable release and atomically
switches `current`; prompt generation output is ignored by Git. `edge-tts` remains an
explicit optional fallback (`PBX_TTS_ENGINE=edge`) but is not part of the on-host path.

## 4. Merge the dialplan safely

The live host uses a hand-maintained `/etc/asterisk/extensions.conf` and does not
include `extensions_custom.conf`. Take a dated backup and checksum, then manually merge
`asterisk/digitalogic-digit-2.conf` into that live file and connect its Gosub only from
both s/_X inbound paths in `[from-tci]`. Recheck the source checksum immediately before
installing the reviewed replacement so a concurrent edit cannot be overwritten.

Two ordering gates are mandatory:

1. In both s/_X paths, snapshot filtered `__PBX_VERIFY_ANI` before
   `[prefix-tci-callerid]` prepends literal `9`, and set trusted literal
   `__PBX_VERIFY_DID=+982166754123`. Do not depend on blank DNID or `${EXTEN}=s`, and
   never derive ANI from the subsequently modified `CALLERID(num)`.
2. Run the digit-2 menu before `Gosub(record-call)`. Digit 2 hangs up after AGI and never
   reaches recording; timeout/0/invalid returns to the untouched recording/operator flow.

Preserve the current operator target verbatim. Check and reload:

```bash
asterisk -rx 'dialplan show from-tci'
asterisk -rx 'dialplan show digitalogic-call-verification-menu'
asterisk -rx 'dialplan show pbx-call-verification-digitalogic'
asterisk -rx 'dialplan reload'
asterisk -rx 'agi set debug off'
```

## 5. Callback validation

Use a staging challenge and real external PSTN calls; a configurable MicroSIP caller ID
is not ownership proof. Confirm without printing full numbers/codes:

- Correct ANI/code produces one signed callback and `PBX_VERIFY_RESULT=verified`.
- Correct code from wrong/withheld ANI produces no callback.
- ANI after the Digitalogic routing-prefix mutation (`9` plus national ANI) is rejected;
  only the pre-mutation snapshot works.
- Trusted pre-routing eight-digit Tehran ANI maps through fixed area `21`, and observed
  `098` plus NSN maps only when its explicit env flag is enabled. Neither rule applies
  to public website input.
- Wrong, short, expired, and replayed codes do not verify.
- Timestamp, nonce, body mutation, wrong key ID, and wrong DID are rejected server-side.
- A callback timeout lasts at most four seconds and plays the generic temporary message.
- No phone, code, raw body, secret, or DTMF appears in Asterisk, web, proxy, or PHP logs.

## 6. Call-flow validation

- Digit 2 enters website verification.
- Digit 2 audio is not recorded and never reaches `record-call` or the operator.
- No digit, digit 0, timeout, and invalid digit preserve the existing operator behavior;
  recording begins only after the verification menu returns to that existing path.
- A normal operator call still receives the TCI caller-ID prefix exactly as before.
- Both `[from-tci]` s and _X entry paths satisfy the same ordering and fallback tests.

## 7. Secure existing localhost `/call`

Before enabling outbound notifications, verify `/call` binds only to `127.0.0.1` or a
Unix socket, does not accept token/target/text in query strings, and rejects missing or
wrong Bearer authorization before validating a target. Generate a dedicated outbound
token with `openssl rand -base64 48`, store it in root-owned service/WordPress credential
files, and restart the service without printing its environment. Do not reuse the inbound
HMAC key. Validate target conversion from E.164 to the existing local `from-internal`
route, idempotency, rate limits, text length, no redirects, and log redaction with a
staging verified opt-in contact before global enablement.

## 8. Enable gradually

Keep the WordPress global kill switch off during IVR deployment. Enable inbound call
verification for test accounts first. Outbound voice remains opt-in and off by default;
enable one event/template and one verified test contact only after `/call` hardening.
