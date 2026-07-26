"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

const HELPER_PATH = path.join(
  __dirname,
  "..",
  "bin",
  "digitalogic-seo-monitor-status.cjs",
);
const {
  HMAC_KEY_PATH,
  OUTPUT_SCHEMA,
  PRIVATE_ROOT,
  SOURCE_CONFIG,
  SOURCE_ORDER,
  StatusError,
  collectStatus,
  decodeJson,
  parseSource,
  readTrustedFile,
} = require(HELPER_PATH);

const NOW = new Date("2026-07-26T10:00:00.000Z");
const KEY_A = Buffer.alloc(32, 0x11);
const KEY_B = Buffer.alloc(32, 0x22);
const FIXTURE_ROOT = path.join(__dirname, "fixtures");
const SOURCE_FIXTURE_NAMES = Object.freeze({
  latest_completed: "latest-completed.json",
  owner_approvals: "owner-approvals.json",
  pending_decisions: "pending-decisions.json",
  google_docs_scan_state: "google-docs-scan-state.json",
  google_docs_migration_ledger: "google-docs-migration-ledger.json",
});

const SUMMARY_KEYS = Object.freeze({
  latest_completed: [
    "run_state",
    "critical_findings",
    "significant_findings",
    "tracked_findings",
    "owner_decisions",
  ],
  owner_approvals: [
    "total",
    "pending",
    "approved",
    "rejected",
    "expired",
  ],
  pending_decisions: [
    "total",
    "critical",
    "significant",
    "tracked",
    "other",
  ],
  google_docs_scan_state: [
    "inventory_complete",
    "access_blocked",
    "cursor_pending",
    "documents_seen",
    "documents_changed",
    "tabs_seen",
    "errors",
  ],
  google_docs_migration_ledger: [
    "total",
    "discovered",
    "not_product_content",
    "needs_mapping",
    "staged_conflict",
    "applied_verified",
    "failed_retryable",
    "blocked",
    "other",
  ],
});

const EXPECTED_SUMMARIES = Object.freeze({
  v1: Object.freeze({
    latest_completed: {
      run_state: "attention",
      critical_findings: 1,
      significant_findings: 1,
      tracked_findings: 1,
      owner_decisions: 1,
    },
    owner_approvals: {
      total: 4,
      pending: 1,
      approved: 1,
      rejected: 1,
      expired: 1,
    },
    pending_decisions: {
      total: 4,
      critical: 1,
      significant: 1,
      tracked: 1,
      other: 1,
    },
    google_docs_scan_state: {
      inventory_complete: false,
      access_blocked: true,
      cursor_pending: true,
      documents_seen: 2,
      documents_changed: 1,
      tabs_seen: 3,
      errors: 1,
    },
    google_docs_migration_ledger: {
      total: 8,
      discovered: 1,
      not_product_content: 1,
      needs_mapping: 1,
      staged_conflict: 1,
      applied_verified: 1,
      failed_retryable: 1,
      blocked: 1,
      other: 1,
    },
  }),
  "v1.1": Object.freeze({
    latest_completed: {
      run_state: "completed",
      critical_findings: 2,
      significant_findings: 0,
      tracked_findings: 0,
      owner_decisions: 2,
    },
    owner_approvals: {
      total: 2,
      pending: 2,
      approved: 0,
      rejected: 0,
      expired: 0,
    },
    pending_decisions: {
      total: 2,
      critical: 0,
      significant: 0,
      tracked: 1,
      other: 1,
    },
    google_docs_scan_state: {
      inventory_complete: true,
      access_blocked: false,
      cursor_pending: false,
      documents_seen: 1,
      documents_changed: 0,
      tabs_seen: 3,
      errors: 0,
    },
    google_docs_migration_ledger: {
      total: 2,
      discovered: 0,
      not_product_content: 0,
      needs_mapping: 0,
      staged_conflict: 0,
      applied_verified: 1,
      failed_retryable: 0,
      blocked: 1,
      other: 0,
    },
  }),
});

function fixtureBuffer(version, sourceName) {
  return fs.readFileSync(
    path.join(FIXTURE_ROOT, version, SOURCE_FIXTURE_NAMES[sourceName]),
  );
}

function fixtureObject(version, sourceName) {
  return JSON.parse(fixtureBuffer(version, sourceName).toString("utf8"));
}

function assertStatusError(callback, expectedState) {
  assert.throws(callback, (error) => {
    assert.ok(error instanceof StatusError);
    assert.equal(error.readState, expectedState);
    return true;
  });
}

function assertExactOutputContract(document) {
  assert.deepEqual(
    Object.keys(document),
    ["schema", "generated_at", "snapshot_state", "sources"],
  );
  assert.equal(document.schema, OUTPUT_SCHEMA);
  assert.match(
    document.generated_at,
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/,
  );
  assert.ok(["complete", "partial", "unavailable"].includes(document.snapshot_state));
  assert.deepEqual(Object.keys(document.sources), SOURCE_ORDER);

  for (const sourceName of SOURCE_ORDER) {
    const source = document.sources[sourceName];
    assert.deepEqual(
      Object.keys(source),
      ["read_state", "updated_at", "summary", "fingerprint"],
    );
    assert.ok([
      "available",
      "missing",
      "invalid",
      "unsupported_schema",
      "unsafe",
      "too_large",
      "changed_during_read",
    ].includes(source.read_state));

    if (source.read_state === "available") {
      assert.match(
        source.updated_at,
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/,
      );
      assert.deepEqual(Object.keys(source.summary), SUMMARY_KEYS[sourceName]);
      assert.match(source.fingerprint, /^hmac-sha256:[0-9a-f]{64}$/);
    } else {
      assert.equal(source.updated_at, null);
      assert.equal(source.summary, null);
      assert.equal(source.fingerprint, null);
    }
  }
}

function makeStats(entry, overrides = {}) {
  const type = overrides.type || entry.type || "file";
  return {
    uid: overrides.uid ?? entry.uid ?? 0,
    gid: overrides.gid ?? entry.gid ?? 0,
    mode: overrides.mode ?? entry.mode ?? (type === "directory" ? 0o40700 : 0o100600),
    nlink: overrides.nlink ?? entry.nlink ?? 1,
    size: overrides.size ?? entry.size ?? (entry.buffer ? entry.buffer.length : 0),
    dev: overrides.dev ?? entry.dev ?? 7,
    ino: overrides.ino ?? entry.ino,
    mtimeMs: overrides.mtimeMs ?? entry.mtimeMs ?? 1000,
    ctimeMs: overrides.ctimeMs ?? entry.ctimeMs ?? 1000,
    isDirectory: () => type === "directory",
    isFile: () => type === "file",
    isSymbolicLink: () => type === "symlink",
  };
}

class FakeFs {
  constructor(entries) {
    this.constants = {
      O_RDONLY: 0,
      O_NOFOLLOW: 0x20000,
      O_CLOEXEC: 0x80000,
    };
    this.entries = new Map();
    this.descriptors = new Map();
    this.nextDescriptor = 20;
    let nextInode = 100;
    for (const [filePath, rawEntry] of Object.entries(entries)) {
      this.entries.set(filePath, {
        type: "file",
        ...rawEntry,
        buffer: rawEntry.buffer ? Buffer.from(rawEntry.buffer) : Buffer.alloc(0),
        ino: rawEntry.ino ?? nextInode,
      });
      nextInode += 1;
    }
  }

  error(code) {
    const error = new Error(code);
    error.code = code;
    return error;
  }

  lstatSync(filePath) {
    const entry = this.entries.get(filePath);
    if (!entry) {
      throw this.error("ENOENT");
    }
    if (entry.activePathRace) {
      entry.activePathRace = false;
      return makeStats(entry, { ino: entry.ino + 100000 });
    }
    return makeStats(entry);
  }

  openSync(filePath, flags) {
    const entry = this.entries.get(filePath);
    if (!entry) {
      throw this.error("ENOENT");
    }
    if (entry.type === "symlink") {
      throw this.error("ELOOP");
    }
    if (entry.type !== "file") {
      throw this.error("EISDIR");
    }
    assert.ok((flags & this.constants.O_NOFOLLOW) !== 0);
    const descriptor = this.nextDescriptor;
    this.nextDescriptor += 1;
    const race = entry.alwaysRace || (entry.raceReadsRemaining || 0) > 0;
    if (entry.raceReadsRemaining > 0) {
      entry.raceReadsRemaining -= 1;
    }
    const pathRace = entry.alwaysPathRace ||
      (entry.pathRaceReadsRemaining || 0) > 0;
    if (entry.pathRaceReadsRemaining > 0) {
      entry.pathRaceReadsRemaining -= 1;
    }
    entry.activePathRace = pathRace;
    this.descriptors.set(descriptor, { entry, statCalls: 0, race });
    return descriptor;
  }

  fstatSync(descriptor) {
    const opened = this.descriptors.get(descriptor);
    if (!opened) {
      throw this.error("EBADF");
    }
    opened.statCalls += 1;
    const mtimeMs = opened.race && opened.statCalls > 1
      ? (opened.entry.mtimeMs ?? 1000) + 1
      : opened.entry.mtimeMs;
    return makeStats(opened.entry, {
      ino: opened.entry.fdIno ?? opened.entry.ino,
      mtimeMs,
    });
  }

  readSync(descriptor, target, offset, length, position) {
    const opened = this.descriptors.get(descriptor);
    if (!opened) {
      throw this.error("EBADF");
    }
    const available = Math.max(0, opened.entry.buffer.length - position);
    const copied = Math.min(length, available);
    opened.entry.buffer.copy(target, offset, position, position + copied);
    return copied;
  }

  closeSync(descriptor) {
    this.descriptors.delete(descriptor);
  }
}

function trustedEntries(version = "v1") {
  const entries = {
    "/var": {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40755,
    },
    "/var/lib": {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40755,
    },
    "/var/lib/digitalogic": {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40755,
    },
    [PRIVATE_ROOT]: {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40700,
    },
    "/etc": {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40755,
    },
    "/etc/digitalogic": {
      type: "directory",
      buffer: Buffer.alloc(0),
      mode: 0o40755,
    },
    [HMAC_KEY_PATH]: {
      buffer: KEY_A,
      mode: 0o100600,
    },
  };
  for (const sourceName of SOURCE_ORDER) {
    entries[SOURCE_CONFIG[sourceName].path] = {
      buffer: fixtureBuffer(version, sourceName),
      mode: 0o100600,
    };
  }
  return entries;
}

for (const version of ["v1", "v1.1"]) {
  for (const sourceName of SOURCE_ORDER) {
    test(`${sourceName} accepts the strict ${version} producer fixture`, () => {
      const parsed = parseSource(
        sourceName,
        fixtureBuffer(version, sourceName),
        KEY_A,
        NOW.getTime(),
      );
      assert.equal(
        parsed.updated_at,
        sourceName === "latest_completed"
          ? fixtureObject(version, sourceName).completed_at
          : fixtureObject(version, sourceName).updated_at,
      );
      assert.deepEqual(parsed.summary, EXPECTED_SUMMARIES[version][sourceName]);
      assert.match(parsed.fingerprint, /^hmac-sha256:[0-9a-f]{64}$/);
    });
  }
}

test("private fixture fields never appear in any declassified source", () => {
  for (const version of ["v1", "v1.1"]) {
    for (const sourceName of SOURCE_ORDER) {
      const parsed = parseSource(
        sourceName,
        fixtureBuffer(version, sourceName),
        KEY_A,
        NOW.getTime(),
      );
      const output = JSON.stringify(parsed);
      assert.doesNotMatch(output, /PRIVATE-|https:\/\/|docs\.google\.com/i);
    }
  }
});

test("fingerprints are stable across record order and timestamp-only rewrites", () => {
  const original = fixtureObject("v1", "owner_approvals");
  const reordered = structuredClone(original);
  reordered.approvals.reverse();
  reordered.updated_at = "2026-07-26T09:59:00.000Z";

  const first = parseSource(
    "owner_approvals",
    Buffer.from(JSON.stringify(original)),
    KEY_A,
    NOW.getTime(),
  );
  const second = parseSource(
    "owner_approvals",
    Buffer.from(JSON.stringify(reordered)),
    KEY_A,
    NOW.getTime(),
  );

  assert.equal(first.fingerprint, second.fingerprint);
  assert.notEqual(first.updated_at, second.updated_at);
});

test("fingerprints change when a meaningful record or HMAC key changes", () => {
  const original = fixtureObject("v1", "pending_decisions");
  const changed = structuredClone(original);
  changed.decisions[0].fingerprint = "different-opaque-record";

  const baseline = parseSource(
    "pending_decisions",
    Buffer.from(JSON.stringify(original)),
    KEY_A,
    NOW.getTime(),
  ).fingerprint;
  const recordChanged = parseSource(
    "pending_decisions",
    Buffer.from(JSON.stringify(changed)),
    KEY_A,
    NOW.getTime(),
  ).fingerprint;
  const keyChanged = parseSource(
    "pending_decisions",
    Buffer.from(JSON.stringify(original)),
    KEY_B,
    NOW.getTime(),
  ).fingerprint;

  assert.notEqual(baseline, recordChanged);
  assert.notEqual(baseline, keyChanged);
});

test("latest fingerprint tracks audit identity but ignores raw-only changes", () => {
  const original = fixtureObject("v1.1", "latest_completed");
  const rawChanged = structuredClone(original);
  rawChanged.raw_private_payload = "PRIVATE-RAW-ONLY-CHANGE";
  const auditChanged = structuredClone(original);
  auditChanged.audit_id = "different-latest-audit";

  const baseline = parseSource(
    "latest_completed",
    Buffer.from(JSON.stringify(original)),
    KEY_A,
    NOW.getTime(),
  ).fingerprint;
  const rawOnly = parseSource(
    "latest_completed",
    Buffer.from(JSON.stringify(rawChanged)),
    KEY_A,
    NOW.getTime(),
  ).fingerprint;
  const differentAudit = parseSource(
    "latest_completed",
    Buffer.from(JSON.stringify(auditChanged)),
    KEY_A,
    NOW.getTime(),
  ).fingerprint;

  assert.equal(baseline, rawOnly);
  assert.notEqual(baseline, differentAudit);
});

test("latest producer statuses map only to controlled output run states", () => {
  const expected = {
    completed: "completed",
    completed_with_findings: "attention",
    attention: "attention",
    blocked: "blocked",
    failed: "failed",
    unknown: "unknown",
  };
  for (const [producerStatus, outputStatus] of Object.entries(expected)) {
    const source = fixtureObject("v1.1", "latest_completed");
    source.status = producerStatus;
    const parsed = parseSource(
      "latest_completed",
      Buffer.from(JSON.stringify(source)),
      KEY_A,
      NOW.getTime(),
    );
    assert.equal(parsed.summary.run_state, outputStatus);
  }

  const invalid = fixtureObject("v1.1", "latest_completed");
  invalid.status = "PRIVATE-UNCONTROLLED-STATUS";
  assertStatusError(
    () => parseSource(
      "latest_completed",
      Buffer.from(JSON.stringify(invalid)),
      KEY_A,
      NOW.getTime(),
    ),
    "invalid",
  );
});

test("unknown producer versions fail as unsupported_schema", () => {
  const source = fixtureObject("v1", "latest_completed");
  source.schema_version = "2.0";
  assertStatusError(
    () => parseSource(
      "latest_completed",
      Buffer.from(JSON.stringify(source)),
      KEY_A,
      NOW.getTime(),
    ),
    "unsupported_schema",
  );
});

test("malformed JSON and malformed UTF-8 fail as invalid", () => {
  assertStatusError(() => decodeJson(Buffer.from("{")), "invalid");
  assertStatusError(() => decodeJson(Buffer.from([0xc3, 0x28])), "invalid");
});

test("invalid producer enum, timestamp, and aggregate overflow fail closed", () => {
  const approval = fixtureObject("v1", "owner_approvals");
  approval.approvals[0].status = "raw-private-status";
  assertStatusError(
    () => parseSource(
      "owner_approvals",
      Buffer.from(JSON.stringify(approval)),
      KEY_A,
      NOW.getTime(),
    ),
    "invalid",
  );

  const latest = fixtureObject("v1", "latest_completed");
  latest.completed_at = "2030-01-01T00:00:00.000Z";
  assertStatusError(
    () => parseSource(
      "latest_completed",
      Buffer.from(JSON.stringify(latest)),
      KEY_A,
      NOW.getTime(),
    ),
    "invalid",
  );

  const scan = fixtureObject("v1", "google_docs_scan_state");
  scan.documents = [
    {
      fingerprint: "overflow-a",
      revision_fingerprint: "revision-a",
      changed: false,
      tabs_seen: Number.MAX_SAFE_INTEGER,
      error: false,
    },
    {
      fingerprint: "overflow-b",
      revision_fingerprint: "revision-b",
      changed: false,
      tabs_seen: 1,
      error: false,
    },
  ];
  assertStatusError(
    () => parseSource(
      "google_docs_scan_state",
      Buffer.from(JSON.stringify(scan)),
      KEY_A,
      NOW.getTime(),
    ),
    "invalid",
  );
});

test("trusted file reading requires no-follow, exact ownership, mode, type, and link count", () => {
  const target = "/private/state.json";
  const cases = [
    [{ type: "symlink", buffer: Buffer.from("{}") }, "unsafe"],
    [{ type: "directory", buffer: Buffer.alloc(0) }, "unsafe"],
    [{ buffer: Buffer.from("{}"), uid: 33 }, "unsafe"],
    [{ buffer: Buffer.from("{}"), gid: 33 }, "unsafe"],
    [{ buffer: Buffer.from("{}"), mode: 0o100640 }, "unsafe"],
    [{ buffer: Buffer.from("{}"), mode: 0o104600 }, "unsafe"],
    [{ buffer: Buffer.from("{}"), nlink: 2 }, "unsafe"],
    [{ buffer: Buffer.alloc(10), size: 10 }, "too_large"],
  ];

  for (const [entry, state] of cases) {
    const fakeFs = new FakeFs({ [target]: entry });
    assertStatusError(
      () => readTrustedFile(target, { maxBytes: 5 }, fakeFs),
      state,
    );
  }
});

test("trusted file reading retries one race and rejects a repeated race", () => {
  const target = "/private/state.json";
  const expected = Buffer.from('{"ok":true}');
  const once = new FakeFs({
    [target]: {
      buffer: expected,
      raceReadsRemaining: 1,
    },
  });
  assert.deepEqual(
    readTrustedFile(target, { maxBytes: 100 }, once),
    expected,
  );

  const repeated = new FakeFs({
    [target]: {
      buffer: expected,
      alwaysRace: true,
    },
  });
  assertStatusError(
    () => readTrustedFile(target, { maxBytes: 100 }, repeated),
    "changed_during_read",
  );
});

test("trusted file reading rechecks the path after read and detects atomic replacement", () => {
  const target = "/private/state.json";
  const expected = Buffer.from('{"ok":true}');
  const once = new FakeFs({
    [target]: {
      buffer: expected,
      pathRaceReadsRemaining: 1,
    },
  });
  assert.deepEqual(
    readTrustedFile(target, { maxBytes: 100 }, once),
    expected,
  );

  const repeated = new FakeFs({
    [target]: {
      buffer: expected,
      alwaysPathRace: true,
    },
  });
  assertStatusError(
    () => readTrustedFile(target, { maxBytes: 100 }, repeated),
    "changed_during_read",
  );
});

test("exact-length HMAC key validation rejects short and oversized files", () => {
  const short = new FakeFs({
    [HMAC_KEY_PATH]: { buffer: Buffer.alloc(31) },
  });
  assertStatusError(
    () => readTrustedFile(
      HMAC_KEY_PATH,
      { maxBytes: 32, exactBytes: 32 },
      short,
    ),
    "unsafe",
  );

  const long = new FakeFs({
    [HMAC_KEY_PATH]: { buffer: Buffer.alloc(33) },
  });
  assertStatusError(
    () => readTrustedFile(
      HMAC_KEY_PATH,
      { maxBytes: 32, exactBytes: 32 },
      long,
    ),
    "too_large",
  );
});

test("complete, partial, and unavailable snapshots use the exact contract", () => {
  const completeFs = new FakeFs(trustedEntries("v1"));
  const complete = collectStatus({ fsImpl: completeFs, now: NOW });
  assertExactOutputContract(complete);
  assert.equal(complete.snapshot_state, "complete");
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(complete.sources[sourceName].read_state, "available");
  }
  assert.ok(JSON.stringify(complete).length < 8192);

  const partialEntries = trustedEntries("v1");
  delete partialEntries[SOURCE_CONFIG.pending_decisions.path];
  partialEntries[SOURCE_CONFIG.owner_approvals.path] = {
    buffer: Buffer.from(JSON.stringify({
      ...fixtureObject("v1", "owner_approvals"),
      schema_version: "2.0",
    })),
  };
  const partial = collectStatus({
    fsImpl: new FakeFs(partialEntries),
    now: NOW,
  });
  assertExactOutputContract(partial);
  assert.equal(partial.snapshot_state, "partial");
  assert.equal(partial.sources.pending_decisions.read_state, "missing");
  assert.equal(
    partial.sources.owner_approvals.read_state,
    "unsupported_schema",
  );
  assert.equal(partial.sources.pending_decisions.summary, null);

  const unavailableEntries = trustedEntries("v1");
  for (const sourceName of SOURCE_ORDER) {
    delete unavailableEntries[SOURCE_CONFIG[sourceName].path];
  }
  const unavailable = collectStatus({
    fsImpl: new FakeFs(unavailableEntries),
    now: NOW,
  });
  assertExactOutputContract(unavailable);
  assert.equal(unavailable.snapshot_state, "unavailable");
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(unavailable.sources[sourceName].read_state, "missing");
  }
});

test("unsafe private root or HMAC key makes every source safely unavailable", () => {
  const unsafeRootEntries = trustedEntries("v1");
  unsafeRootEntries[PRIVATE_ROOT].mode = 0o40750;
  const unsafeRoot = collectStatus({
    fsImpl: new FakeFs(unsafeRootEntries),
    now: NOW,
  });
  assertExactOutputContract(unsafeRoot);
  assert.equal(unsafeRoot.snapshot_state, "unavailable");
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(unsafeRoot.sources[sourceName].read_state, "unsafe");
  }

  const missingKeyEntries = trustedEntries("v1");
  delete missingKeyEntries[HMAC_KEY_PATH];
  const missingKey = collectStatus({
    fsImpl: new FakeFs(missingKeyEntries),
    now: NOW,
  });
  assertExactOutputContract(missingKey);
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(missingKey.sources[sourceName].read_state, "unsafe");
  }
});

test("writable or symlinked fixed parent directories fail closed", () => {
  const writableParentEntries = trustedEntries("v1");
  writableParentEntries["/var/lib/digitalogic"].mode = 0o40775;
  const writableParent = collectStatus({
    fsImpl: new FakeFs(writableParentEntries),
    now: NOW,
  });
  assertExactOutputContract(writableParent);
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(writableParent.sources[sourceName].read_state, "unsafe");
  }

  const symlinkedKeyParentEntries = trustedEntries("v1");
  symlinkedKeyParentEntries["/etc/digitalogic"].type = "symlink";
  const symlinkedKeyParent = collectStatus({
    fsImpl: new FakeFs(symlinkedKeyParentEntries),
    now: NOW,
  });
  assertExactOutputContract(symlinkedKeyParent);
  for (const sourceName of SOURCE_ORDER) {
    assert.equal(symlinkedKeyParent.sources[sourceName].read_state, "unsafe");
  }
});

test("a repeatedly changing source is isolated as changed_during_read", () => {
  const entries = trustedEntries("v1");
  entries[SOURCE_CONFIG.google_docs_migration_ledger.path].alwaysRace = true;
  const document = collectStatus({
    fsImpl: new FakeFs(entries),
    now: NOW,
  });
  assertExactOutputContract(document);
  assert.equal(document.snapshot_state, "partial");
  assert.equal(
    document.sources.google_docs_migration_ledger.read_state,
    "changed_during_read",
  );
  assert.equal(document.sources.google_docs_migration_ledger.summary, null);
});

test("unexpected executable arguments return only a fixed safe contract", () => {
  const child = spawnSync(
    process.execPath,
    [HELPER_PATH, "PRIVATE-UNEXPECTED-ARGUMENT"],
    {
      encoding: "utf8",
      env: {
        ...process.env,
        PRIVATE_ROUTING_SECRET: "PRIVATE-ENVIRONMENT-SECRET",
      },
    },
  );
  assert.equal(child.status, 64);
  assert.equal(child.stderr, "");
  assert.equal(child.stdout.trim().split(/\r?\n/).length, 1);
  const document = JSON.parse(child.stdout);
  assertExactOutputContract(document);
  assert.equal(document.snapshot_state, "unavailable");
  assert.doesNotMatch(
    child.stdout,
    /PRIVATE-UNEXPECTED|PRIVATE-ENVIRONMENT|ROUTING/i,
  );
});

test("helper source has no environment, stdin, child-process, network, or write surface", () => {
  const source = fs.readFileSync(HELPER_PATH, "utf8");
  assert.doesNotMatch(source, /process\.env|process\.stdin/);
  assert.doesNotMatch(
    source,
    /node:(?:child_process|http|https|net|tls|dgram)|\b(?:writeFile|appendFile|createWriteStream|exec|spawn)\w*\s*\(/,
  );
  const imports = [...source.matchAll(/require\("([^"]+)"\)/g)].map(
    match => match[1],
  );
  assert.deepEqual(imports, ["node:fs", "node:crypto", "node:util"]);
});

test("sudoers review template pins the direct executable and no-argument form", () => {
  const sudoers = fs.readFileSync(
    path.join(
      __dirname,
      "..",
      "sudoers.d",
      "digitalogic-seo-monitor-status",
    ),
    "utf8",
  );
  assert.match(
    sudoers,
    /sha256:__HELPER_SHA256__ \/usr\/local\/libexec\/digitalogic-seo-monitor-status ""/,
  );
  assert.match(sudoers, /NOPASSWD:NOEXEC:NOSETENV/);
  assert.match(sudoers, /env_delete\+="NODE_OPTIONS NODE_PATH"/);
  assert.doesNotMatch(sudoers, /\/usr\/bin\/node\s+\*/);
  assert.match(sudoers, /Do not install until the feature PR is merged and approved/);
});

test("README keeps pending owner approval and unchanged permission boundaries visible", () => {
  const readme = fs.readFileSync(path.join(__dirname, "..", "README.md"), "utf8");
  assert.match(readme, /pending owner approval/i);
  assert.match(readme, /Do not install or deploy/i);
  assert.match(readme, /directory mode `0700`/);
  assert.match(readme, /file mode `0600`/);
  assert.match(readme, /every local process running as `www-data`/);
});
