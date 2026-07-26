#!/usr/bin/node
"use strict";

const fs = require("node:fs");
const { createHmac } = require("node:crypto");
const { TextDecoder } = require("node:util");

const OUTPUT_SCHEMA = "digitalogic.seo-monitor.status/v1";
const PRIVATE_ROOT = "/var/lib/digitalogic/seo-monitor";
const HMAC_KEY_PATH = "/etc/digitalogic/seo-monitor-status.hmac";
const PRIVATE_PARENT_DIRS = Object.freeze([
  "/var",
  "/var/lib",
  "/var/lib/digitalogic",
]);
const KEY_PARENT_DIRS = Object.freeze([
  "/etc",
  "/etc/digitalogic",
]);
const MAX_RECORDS = 100000;
const MAX_OPAQUE_LENGTH = 4096;
const MAX_FUTURE_SKEW_MS = 5 * 60 * 1000;
const EARLIEST_TIMESTAMP_MS = Date.parse("2000-01-01T00:00:00.000Z");

const SOURCE_ORDER = Object.freeze([
  "latest_completed",
  "owner_approvals",
  "pending_decisions",
  "google_docs_scan_state",
  "google_docs_migration_ledger",
]);

const SOURCE_CONFIG = Object.freeze({
  latest_completed: Object.freeze({
    path: `${PRIVATE_ROOT}/latest-completed.json`,
    maxBytes: 1024 * 1024,
    versions: Object.freeze(["1.0", "1.1"]),
  }),
  owner_approvals: Object.freeze({
    path: `${PRIVATE_ROOT}/owner-approvals.json`,
    maxBytes: 4 * 1024 * 1024,
    versions: Object.freeze(["1.0", "1.1"]),
  }),
  pending_decisions: Object.freeze({
    path: `${PRIVATE_ROOT}/pending-decisions.json`,
    maxBytes: 4 * 1024 * 1024,
    versions: Object.freeze(["1.0", "1.1"]),
  }),
  google_docs_scan_state: Object.freeze({
    path: `${PRIVATE_ROOT}/google-docs-scan-state.json`,
    maxBytes: 8 * 1024 * 1024,
    versions: Object.freeze(["1.0", "1.1"]),
  }),
  google_docs_migration_ledger: Object.freeze({
    path: `${PRIVATE_ROOT}/google-docs-migration-ledger.json`,
    maxBytes: 16 * 1024 * 1024,
    versions: Object.freeze(["1.0", "1.1"]),
  }),
});

const READ_STATES = new Set([
  "available",
  "missing",
  "invalid",
  "unsupported_schema",
  "unsafe",
  "too_large",
  "changed_during_read",
]);

const RUN_STATES = new Set([
  "completed",
  "attention",
  "blocked",
  "failed",
  "unknown",
]);

const PRODUCER_RUN_STATE_MAP = Object.freeze({
  completed: "completed",
  completed_with_findings: "attention",
  attention: "attention",
  blocked: "blocked",
  failed: "failed",
  unknown: "unknown",
});

const APPROVAL_STATES = new Set([
  "pending",
  "approved",
  "rejected",
  "expired",
]);

const FINDING_STATES = new Set([
  "critical",
  "significant",
  "tracked",
]);

const LEDGER_STATES = new Set([
  "discovered",
  "not_product_content",
  "needs_mapping",
  "staged_conflict",
  "applied_verified",
  "failed_retryable",
  "blocked",
]);

class StatusError extends Error {
  constructor(readState) {
    super(readState);
    this.name = "StatusError";
    this.readState = READ_STATES.has(readState) ? readState : "invalid";
  }
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function requireObject(value) {
  if (!isObject(value)) {
    throw new StatusError("invalid");
  }
  return value;
}

function requireBoolean(value) {
  if (typeof value !== "boolean") {
    throw new StatusError("invalid");
  }
  return value;
}

function requireArray(value) {
  if (!Array.isArray(value) || value.length > MAX_RECORDS) {
    throw new StatusError("invalid");
  }
  return value;
}

function requireOpaque(value) {
  if (
    typeof value !== "string" ||
    value.length < 1 ||
    value.length > MAX_OPAQUE_LENGTH
  ) {
    throw new StatusError("invalid");
  }
  return value;
}

function requireSmallToken(value) {
  if (typeof value !== "string" || value.length < 1 || value.length > 64) {
    throw new StatusError("invalid");
  }
  return value;
}

function requireUnsignedInteger(value) {
  if (!Number.isSafeInteger(value) || value < 0) {
    throw new StatusError("invalid");
  }
  return value;
}

function checkedAdd(left, right) {
  const result = left + right;
  if (!Number.isSafeInteger(result) || result < 0) {
    throw new StatusError("invalid");
  }
  return result;
}

function requireUtcTimestamp(value, nowMs) {
  if (
    typeof value !== "string" ||
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value)
  ) {
    throw new StatusError("invalid");
  }

  const parsed = Date.parse(value);
  if (
    !Number.isFinite(parsed) ||
    new Date(parsed).toISOString() !== value ||
    parsed < EARLIEST_TIMESTAMP_MS ||
    parsed > nowMs + MAX_FUTURE_SKEW_MS
  ) {
    throw new StatusError("invalid");
  }

  return value;
}

function requireSupportedSchema(value, sourceName) {
  if (typeof value !== "string") {
    throw new StatusError("unsupported_schema");
  }
  if (!SOURCE_CONFIG[sourceName].versions.includes(value)) {
    throw new StatusError("unsupported_schema");
  }
  return value;
}

function decodeJson(buffer) {
  let text;
  try {
    text = new TextDecoder("utf-8", { fatal: true }).decode(buffer);
  } catch {
    throw new StatusError("invalid");
  }

  try {
    return requireObject(JSON.parse(text));
  } catch (error) {
    if (error instanceof StatusError) {
      throw error;
    }
    throw new StatusError("invalid");
  }
}

function hmacHex(key, domain, payload) {
  return createHmac("sha256", key)
    .update(domain, "utf8")
    .update("\0", "utf8")
    .update(payload, "utf8")
    .digest("hex");
}

function recordToken(key, domain, values) {
  return hmacHex(key, `${domain}/record`, JSON.stringify(values));
}

function sourceFingerprint(key, domain, projection, tokens) {
  const sortedTokens = [...tokens].sort();
  const digest = hmacHex(
    key,
    `${domain}/source`,
    JSON.stringify([projection, sortedTokens]),
  );
  return `hmac-sha256:${digest}`;
}

function parseLatestCompleted(data, key, nowMs) {
  requireSupportedSchema(data.schema_version, "latest_completed");
  const updatedAt = requireUtcTimestamp(data.completed_at, nowMs);
  const producerStatus = requireSmallToken(data.status);
  const runState = PRODUCER_RUN_STATE_MAP[producerStatus];
  if (!RUN_STATES.has(runState)) {
    throw new StatusError("invalid");
  }

  const summary = {
    run_state: runState,
    critical_findings: requireUnsignedInteger(data.critical_findings),
    significant_findings: requireUnsignedInteger(data.significant_findings),
    tracked_findings: requireUnsignedInteger(data.tracked_findings),
    owner_decisions: requireUnsignedInteger(data.owner_decisions),
  };
  const auditToken = recordToken(
    key,
    "latest_completed/audit",
    [requireOpaque(data.audit_id)],
  );

  return {
    updated_at: updatedAt,
    summary,
    fingerprint: sourceFingerprint(
      key,
      "latest_completed",
      [
        summary.run_state,
        summary.critical_findings,
        summary.significant_findings,
        summary.tracked_findings,
        summary.owner_decisions,
      ],
      [auditToken],
    ),
  };
}

function parseOwnerApprovals(data, key, nowMs) {
  requireSupportedSchema(data.schema_version, "owner_approvals");
  const updatedAt = requireUtcTimestamp(data.updated_at, nowMs);
  const counts = {
    pending: 0,
    approved: 0,
    rejected: 0,
    expired: 0,
  };
  const tokens = [];
  const approvals = requireArray(data.approvals);

  for (const rawApproval of approvals) {
    const approval = requireObject(rawApproval);
    const opaque = requireOpaque(approval.fingerprint);
    const status = requireSmallToken(approval.status);
    if (!APPROVAL_STATES.has(status)) {
      throw new StatusError("invalid");
    }
    counts[status] = checkedAdd(counts[status], 1);
    tokens.push(recordToken(key, "owner_approvals/approval", [opaque, status]));
  }

  const summary = {
    total: approvals.length,
    pending: counts.pending,
    approved: counts.approved,
    rejected: counts.rejected,
    expired: counts.expired,
  };

  return {
    updated_at: updatedAt,
    summary,
    fingerprint: sourceFingerprint(
      key,
      "owner_approvals",
      [
        summary.total,
        summary.pending,
        summary.approved,
        summary.rejected,
        summary.expired,
      ],
      tokens,
    ),
  };
}

function parsePendingDecisions(data, key, nowMs) {
  requireSupportedSchema(data.schema_version, "pending_decisions");
  const updatedAt = requireUtcTimestamp(data.updated_at, nowMs);
  const counts = {
    critical: 0,
    significant: 0,
    tracked: 0,
    other: 0,
  };
  const tokens = [];
  const decisions = requireArray(data.decisions);

  for (const rawDecision of decisions) {
    const decision = requireObject(rawDecision);
    const opaque = requireOpaque(decision.fingerprint);
    const severity = requireSmallToken(decision.severity);
    const status = requireSmallToken(decision.status);
    const bucket = FINDING_STATES.has(severity) ? severity : "other";
    counts[bucket] = checkedAdd(counts[bucket], 1);
    tokens.push(
      recordToken(
        key,
        "pending_decisions/decision",
        [opaque, severity, status],
      ),
    );
  }

  const summary = {
    total: decisions.length,
    critical: counts.critical,
    significant: counts.significant,
    tracked: counts.tracked,
    other: counts.other,
  };

  return {
    updated_at: updatedAt,
    summary,
    fingerprint: sourceFingerprint(
      key,
      "pending_decisions",
      [
        summary.total,
        summary.critical,
        summary.significant,
        summary.tracked,
        summary.other,
      ],
      tokens,
    ),
  };
}

function parseGoogleDocsScanState(data, key, nowMs) {
  requireSupportedSchema(data.schema_version, "google_docs_scan_state");
  const updatedAt = requireUtcTimestamp(data.updated_at, nowMs);
  const inventoryComplete = requireBoolean(data.inventory_complete);
  const accessBlocked = requireBoolean(data.google_docs_access_blocked);
  const cursorPending = data.cursor !== null;
  const tokens = [];

  if (cursorPending) {
    tokens.push(
      recordToken(
        key,
        "google_docs_scan_state/cursor",
        [requireOpaque(data.cursor)],
      ),
    );
  }

  let documentsChanged = 0;
  let tabsSeen = 0;
  let errors = 0;
  const documents = requireArray(data.documents);

  for (const rawDocument of documents) {
    const document = requireObject(rawDocument);
    const opaque = requireOpaque(document.fingerprint);
    const revision = requireOpaque(document.revision_fingerprint);
    const changed = requireBoolean(document.changed);
    const documentTabs = requireUnsignedInteger(document.tabs_seen);
    const hasError = requireBoolean(document.error);

    if (changed) {
      documentsChanged = checkedAdd(documentsChanged, 1);
    }
    if (hasError) {
      errors = checkedAdd(errors, 1);
    }
    tabsSeen = checkedAdd(tabsSeen, documentTabs);
    tokens.push(
      recordToken(
        key,
        "google_docs_scan_state/document",
        [opaque, revision, changed, documentTabs, hasError],
      ),
    );
  }

  const summary = {
    inventory_complete: inventoryComplete,
    access_blocked: accessBlocked,
    cursor_pending: cursorPending,
    documents_seen: documents.length,
    documents_changed: documentsChanged,
    tabs_seen: tabsSeen,
    errors,
  };

  return {
    updated_at: updatedAt,
    summary,
    fingerprint: sourceFingerprint(
      key,
      "google_docs_scan_state",
      [
        summary.inventory_complete,
        summary.access_blocked,
        summary.cursor_pending,
        summary.documents_seen,
        summary.documents_changed,
        summary.tabs_seen,
        summary.errors,
      ],
      tokens,
    ),
  };
}

function parseGoogleDocsMigrationLedger(data, key, nowMs) {
  requireSupportedSchema(data.schema_version, "google_docs_migration_ledger");
  const updatedAt = requireUtcTimestamp(data.updated_at, nowMs);
  const counts = {
    discovered: 0,
    not_product_content: 0,
    needs_mapping: 0,
    staged_conflict: 0,
    applied_verified: 0,
    failed_retryable: 0,
    blocked: 0,
    other: 0,
  };
  const tokens = [];
  const entries = requireArray(data.entries);

  for (const rawEntry of entries) {
    const entry = requireObject(rawEntry);
    const opaque = requireOpaque(entry.fingerprint);
    const status = requireSmallToken(entry.status);
    const bucket = LEDGER_STATES.has(status) ? status : "other";
    counts[bucket] = checkedAdd(counts[bucket], 1);
    tokens.push(
      recordToken(
        key,
        "google_docs_migration_ledger/entry",
        [opaque, status],
      ),
    );
  }

  const summary = {
    total: entries.length,
    discovered: counts.discovered,
    not_product_content: counts.not_product_content,
    needs_mapping: counts.needs_mapping,
    staged_conflict: counts.staged_conflict,
    applied_verified: counts.applied_verified,
    failed_retryable: counts.failed_retryable,
    blocked: counts.blocked,
    other: counts.other,
  };

  return {
    updated_at: updatedAt,
    summary,
    fingerprint: sourceFingerprint(
      key,
      "google_docs_migration_ledger",
      [
        summary.total,
        summary.discovered,
        summary.not_product_content,
        summary.needs_mapping,
        summary.staged_conflict,
        summary.applied_verified,
        summary.failed_retryable,
        summary.blocked,
        summary.other,
      ],
      tokens,
    ),
  };
}

const SOURCE_PARSERS = Object.freeze({
  latest_completed: parseLatestCompleted,
  owner_approvals: parseOwnerApprovals,
  pending_decisions: parsePendingDecisions,
  google_docs_scan_state: parseGoogleDocsScanState,
  google_docs_migration_ledger: parseGoogleDocsMigrationLedger,
});

function parseSource(sourceName, buffer, key, nowMs) {
  if (!Object.prototype.hasOwnProperty.call(SOURCE_PARSERS, sourceName)) {
    throw new StatusError("invalid");
  }
  return SOURCE_PARSERS[sourceName](decodeJson(buffer), key, nowMs);
}

function fileTypeMatches(stats, expectedType) {
  if (expectedType === "directory") {
    return typeof stats.isDirectory === "function" && stats.isDirectory();
  }
  return typeof stats.isFile === "function" && stats.isFile();
}

function verifyTrustedStats(stats, options) {
  if (
    !stats ||
    (typeof stats.isSymbolicLink === "function" && stats.isSymbolicLink()) ||
    !fileTypeMatches(stats, options.type) ||
    stats.uid !== 0 ||
    stats.gid !== 0 ||
    (stats.mode & 0o7777) !== options.mode
  ) {
    throw new StatusError("unsafe");
  }

  if (options.type === "file") {
    if (stats.nlink !== 1) {
      throw new StatusError("unsafe");
    }
    if (!Number.isSafeInteger(stats.size) || stats.size < 0) {
      throw new StatusError("unsafe");
    }
    if (stats.size > options.maxBytes) {
      throw new StatusError("too_large");
    }
    if (
      Number.isSafeInteger(options.exactBytes) &&
      stats.size !== options.exactBytes
    ) {
      throw new StatusError("unsafe");
    }
  }
}

function verifyTrustedParentDirectory(stats) {
  if (
    !stats ||
    (typeof stats.isSymbolicLink === "function" && stats.isSymbolicLink()) ||
    !fileTypeMatches(stats, "directory") ||
    stats.uid !== 0 ||
    stats.gid !== 0 ||
    (stats.mode & 0o022) !== 0
  ) {
    throw new StatusError("unsafe");
  }
}

function sameIdentity(left, right) {
  return left.dev === right.dev && left.ino === right.ino;
}

function sameSnapshot(left, right) {
  return (
    sameIdentity(left, right) &&
    left.size === right.size &&
    left.mtimeMs === right.mtimeMs &&
    left.ctimeMs === right.ctimeMs
  );
}

function mapFileError(error) {
  if (error instanceof StatusError) {
    return error;
  }
  if (error && error.code === "ENOENT") {
    return new StatusError("missing");
  }
  if (error && error.code === "EFBIG") {
    return new StatusError("too_large");
  }
  if (error && (error.code === "ELOOP" || error.code === "ENOTDIR")) {
    return new StatusError("unsafe");
  }
  return new StatusError("unsafe");
}

function validatePrivateRoot(fsImpl = fs) {
  for (const parentPath of PRIVATE_PARENT_DIRS) {
    try {
      verifyTrustedParentDirectory(fsImpl.lstatSync(parentPath));
    } catch (error) {
      throw mapFileError(error);
    }
  }
  try {
    verifyTrustedStats(
      fsImpl.lstatSync(PRIVATE_ROOT),
      { type: "directory", mode: 0o700 },
    );
  } catch (error) {
    throw mapFileError(error);
  }
}

function validateKeyParents(fsImpl = fs) {
  for (const parentPath of KEY_PARENT_DIRS) {
    try {
      verifyTrustedParentDirectory(fsImpl.lstatSync(parentPath));
    } catch (error) {
      throw mapFileError(error);
    }
  }
}

function readTrustedFileOnce(filePath, options, fsImpl) {
  const constants = fsImpl.constants || fs.constants;
  if (
    !Number.isInteger(constants.O_RDONLY) ||
    !Number.isInteger(constants.O_NOFOLLOW)
  ) {
    throw new StatusError("unsafe");
  }

  let pathStats;
  try {
    pathStats = fsImpl.lstatSync(filePath);
    verifyTrustedStats(pathStats, {
      type: "file",
      mode: 0o600,
      maxBytes: options.maxBytes,
      exactBytes: options.exactBytes,
    });
  } catch (error) {
    throw mapFileError(error);
  }

  const closeOnExec = Number.isInteger(constants.O_CLOEXEC)
    ? constants.O_CLOEXEC
    : 0;
  const flags = constants.O_RDONLY | constants.O_NOFOLLOW | closeOnExec;
  let descriptor;

  try {
    descriptor = fsImpl.openSync(filePath, flags);
    const before = fsImpl.fstatSync(descriptor);
    verifyTrustedStats(before, {
      type: "file",
      mode: 0o600,
      maxBytes: options.maxBytes,
      exactBytes: options.exactBytes,
    });
    if (!sameIdentity(pathStats, before)) {
      throw new StatusError("changed_during_read");
    }

    const buffer = Buffer.alloc(before.size);
    let offset = 0;
    while (offset < buffer.length) {
      const read = fsImpl.readSync(
        descriptor,
        buffer,
        offset,
        buffer.length - offset,
        offset,
      );
      if (!Number.isSafeInteger(read) || read <= 0) {
        throw new StatusError("changed_during_read");
      }
      offset += read;
    }

    const after = fsImpl.fstatSync(descriptor);
    verifyTrustedStats(after, {
      type: "file",
      mode: 0o600,
      maxBytes: options.maxBytes,
      exactBytes: options.exactBytes,
    });
    if (!sameSnapshot(before, after)) {
      throw new StatusError("changed_during_read");
    }

    let finalPathStats;
    try {
      finalPathStats = fsImpl.lstatSync(filePath);
      verifyTrustedStats(finalPathStats, {
        type: "file",
        mode: 0o600,
        maxBytes: options.maxBytes,
        exactBytes: options.exactBytes,
      });
    } catch (error) {
      const mapped = mapFileError(error);
      if (mapped.readState === "missing") {
        throw new StatusError("changed_during_read");
      }
      throw mapped;
    }
    if (!sameSnapshot(after, finalPathStats)) {
      throw new StatusError("changed_during_read");
    }
    return buffer;
  } catch (error) {
    throw mapFileError(error);
  } finally {
    if (descriptor !== undefined) {
      try {
        fsImpl.closeSync(descriptor);
      } catch {
        // A close failure cannot make untrusted data safe.
      }
    }
  }
}

function readTrustedFile(filePath, options, fsImpl = fs) {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      return readTrustedFileOnce(filePath, options, fsImpl);
    } catch (error) {
      const mapped = mapFileError(error);
      if (mapped.readState === "changed_during_read" && attempt === 0) {
        continue;
      }
      throw mapped;
    }
  }
  throw new StatusError("changed_during_read");
}

function unavailableSource(readState) {
  return {
    read_state: READ_STATES.has(readState) ? readState : "invalid",
    updated_at: null,
    summary: null,
    fingerprint: null,
  };
}

function availableSource(parsed) {
  return {
    read_state: "available",
    updated_at: parsed.updated_at,
    summary: parsed.summary,
    fingerprint: parsed.fingerprint,
  };
}

function documentForState(generatedAt, readState) {
  const sources = {};
  for (const sourceName of SOURCE_ORDER) {
    sources[sourceName] = unavailableSource(readState);
  }
  return {
    schema: OUTPUT_SCHEMA,
    generated_at: generatedAt,
    snapshot_state: "unavailable",
    sources,
  };
}

function snapshotState(sources) {
  let available = 0;
  for (const sourceName of SOURCE_ORDER) {
    if (sources[sourceName].read_state === "available") {
      available += 1;
    }
  }
  if (available === SOURCE_ORDER.length) {
    return "complete";
  }
  return available === 0 ? "unavailable" : "partial";
}

function collectStatus(options = {}) {
  const fsImpl = options.fsImpl || fs;
  const now = options.now instanceof Date ? options.now : new Date();
  if (!Number.isFinite(now.getTime())) {
    throw new StatusError("invalid");
  }
  const generatedAt = now.toISOString();

  try {
    validatePrivateRoot(fsImpl);
  } catch (error) {
    const mapped = mapFileError(error);
    return documentForState(generatedAt, mapped.readState);
  }

  let key;
  try {
    validateKeyParents(fsImpl);
    key = readTrustedFile(
      HMAC_KEY_PATH,
      { maxBytes: 32, exactBytes: 32 },
      fsImpl,
    );
  } catch {
    return documentForState(generatedAt, "unsafe");
  }

  const sources = {};
  for (const sourceName of SOURCE_ORDER) {
    try {
      const config = SOURCE_CONFIG[sourceName];
      const buffer = readTrustedFile(
        config.path,
        { maxBytes: config.maxBytes },
        fsImpl,
      );
      sources[sourceName] = availableSource(
        parseSource(sourceName, buffer, key, now.getTime()),
      );
    } catch (error) {
      const mapped = error instanceof StatusError
        ? error
        : new StatusError("invalid");
      sources[sourceName] = unavailableSource(mapped.readState);
    }
  }

  return {
    schema: OUTPUT_SCHEMA,
    generated_at: generatedAt,
    snapshot_state: snapshotState(sources),
    sources,
  };
}

function emit(document) {
  process.stdout.write(`${JSON.stringify(document)}\n`);
}

function main() {
  process.umask(0o077);
  const now = new Date();

  if (process.argv.length !== 2) {
    emit(documentForState(now.toISOString(), "unsafe"));
    process.exitCode = 64;
    return;
  }

  try {
    emit(collectStatus({ now }));
  } catch {
    emit(documentForState(now.toISOString(), "invalid"));
    process.exitCode = 1;
  }
}

if (require.main === module) {
  process.stdout.on("error", () => {
    process.exitCode = 1;
  });
  main();
}

module.exports = Object.freeze({
  HMAC_KEY_PATH,
  OUTPUT_SCHEMA,
  PRIVATE_ROOT,
  READ_STATES,
  SOURCE_CONFIG,
  SOURCE_ORDER,
  StatusError,
  collectStatus,
  decodeJson,
  documentForState,
  parseSource,
  readTrustedFile,
  sourceFingerprint,
  validateKeyParents,
  validatePrivateRoot,
});
