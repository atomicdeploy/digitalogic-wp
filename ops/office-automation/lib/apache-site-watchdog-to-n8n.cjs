#!/usr/bin/env node
"use strict";

const fs = require("node:fs");
const http = require("node:http");
const https = require("node:https");
const os = require("node:os");
const path = require("node:path");
const { execFile } = require("node:child_process");

function loadEnv(file) {
  const env = {};
  try {
    for (const raw of fs.readFileSync(file, "utf8").split(/\r?\n/)) {
      const line = raw.trim();
      if (!line || line.startsWith("#")) continue;
      const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);
      if (!match) continue;
      let value = match[2].trim();
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1);
      env[match[1]] = value;
    }
  } catch {
    // The service can still run with process environment defaults.
  }
  return env;
}

function csv(value) {
  return String(value || "").split(",").map(item => item.trim()).filter(Boolean);
}

function num(env, key, fallback) {
  const parsed = Number(env[key]);
  return Number.isFinite(parsed) ? parsed : fallback;
}

const env = { ...process.env, ...loadEnv(process.env.APACHE_SITE_WATCHDOG_ENV || "/etc/apache-site-watchdog.env") };
const config = {
  office: env.OFFICE_NAME || "office",
  webhookUrl: env.WEBHOOK_URL || "",
  statusUrl: env.APACHE_STATUS_URL || "http://127.0.0.1/server-status?auto",
  siteUrls: csv(env.SITE_URLS || env.PUBLIC_URL || ""),
  stateFile: env.STATE_FILE || "/var/lib/office-automation/apache-site-watchdog-state.json",
  eventLogFile: env.EVENT_LOG_FILE || "/var/lib/office-automation/apache-site-watchdog-events.jsonl",
  notifyChannels: csv(env.NOTIFY_CHANNELS || "ntfy,telegram"),
  intervalSeconds: num(env, "CHECK_INTERVAL_SECONDS", 20),
  repeatSeconds: num(env, "ALERT_REPEAT_SECONDS", 900),
  httpTimeoutMs: num(env, "HTTP_TIMEOUT_MS", 12000),
  siteMethod: String(env.SITE_METHOD || "HEAD").toUpperCase(),
  siteFailuresBeforeDown: num(env, "SITE_FAILURES_BEFORE_DOWN", 2),
  webhookTimeoutMs: num(env, "WEBHOOK_TIMEOUT_MS", 60000),
  maxRequestWorkers: num(env, "MAX_REQUEST_WORKERS", 150),
  idleWorkersCritical: num(env, "IDLE_WORKERS_CRITICAL", 0),
  idleWorkersWarning: num(env, "IDLE_WORKERS_WARNING", 3),
  busyWorkersCriticalPercent: num(env, "BUSY_WORKERS_CRITICAL_PERCENT", 90),
  busyWorkersWarningPercent: num(env, "BUSY_WORKERS_WARNING_PERCENT", 75),
  closeWaitCritical: num(env, "CLOSE_WAIT_CRITICAL", 250),
  closeWaitWarning: num(env, "CLOSE_WAIT_WARNING", 100),
  slowSiteMs: num(env, "SLOW_SITE_MS", 8000),
  diagnosticsEnabled: String(env.DIAGNOSTICS_ENABLED || "true").toLowerCase() !== "false",
  diagnosticsTimeoutMs: num(env, "DIAGNOSTICS_TIMEOUT_MS", 8000),
  diagnosticsMaxLines: num(env, "DIAGNOSTICS_MAX_LINES", 40),
  wpPath: env.WP_PATH || "/var/www/html",
  phpFpmService: env.PHP_FPM_SERVICE || "php8.5-fpm.service",
  phpFpmLog: env.PHP_FPM_LOG || "/var/log/php8.5-fpm.log",
  phpFpmSlowLog: env.PHP_FPM_SLOW_LOG || "/var/log/php8.5-fpm-www-slow.log",
  phpFpmStatusUrl: env.PHP_FPM_STATUS_URL || "http://127.0.0.1/fpm-status?json&full",
  queryMonitorLog: env.QUERY_MONITOR_LOG || "/var/log/wordpress/query-monitor-slow.jsonl",
  logAlertsEnabled: String(env.LOG_ALERTS_ENABLED || "true").toLowerCase() !== "false",
  logAlertMaxLines: num(env, "LOG_ALERT_MAX_LINES", 20),
  phpFpmSlowLogNotifyCooldownSeconds: num(env, "PHP_FPM_SLOWLOG_NOTIFY_COOLDOWN_SECONDS", 3600),
  phpFpmSlowLogSummaryMaxLines: num(env, "PHP_FPM_SLOWLOG_SUMMARY_MAX_LINES", 8),
  apacheErrorLog: env.APACHE_ERROR_LOG || "/var/log/apache2/error.log",
  autoRestartApache: String(env.AUTO_RESTART_APACHE || "false").toLowerCase() === "true",
  autoRestartCooldownSeconds: num(env, "AUTO_RESTART_COOLDOWN_SECONDS", 900),
};

function validateConfig() {
  const missing = [];
  if (!config.webhookUrl) missing.push("WEBHOOK_URL");
  if (!config.siteUrls.length) missing.push("SITE_URLS");
  if (!config.notifyChannels.length) missing.push("NOTIFY_CHANNELS");
  if (config.webhookUrl) {
    try {
      const url = new URL(config.webhookUrl);
      if (!/^https?:$/.test(url.protocol)) missing.push("WEBHOOK_URL(http-or-https)");
    } catch {
      missing.push("WEBHOOK_URL(valid-url)");
    }
  }
  if (missing.length) throw new Error(`Missing or invalid configuration: ${[...new Set(missing)].join(", ")}`);
  return true;
}

function mkdirFor(file) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
}

function loadState() {
  let state;
  try {
    state = { states: {}, fileOffsets: {}, lastRestartAt: 0, ...JSON.parse(fs.readFileSync(config.stateFile, "utf8")) };
  } catch {
    state = { states: {}, fileOffsets: {}, lastRestartAt: 0 };
  }

  const slowLogState = state.logAlerts && typeof state.logAlerts === "object"
    ? state.logAlerts["php-fpm-slow-log"]
    : null;
  if (slowLogState && typeof slowLogState === "object") {
    // Version 1 counted capped stack-trace lines, which cannot be converted to requests.
    // Discard that legacy counter instead of reporting it as a request count.
    delete slowLogState.suppressedLineCount;
    slowLogState.counterVersion = 2;
  }
  return state;
}

function saveState(state) {
  mkdirFor(config.stateFile);
  fs.writeFileSync(config.stateFile, `${JSON.stringify(state, null, 2)}\n`, { mode: 0o640 });
}

function appendJsonl(file, payload) {
  mkdirFor(file);
  fs.appendFileSync(file, `${JSON.stringify(payload)}\n`, { mode: 0o640 });
}

function run(command, args, timeout = 5000) {
  return new Promise(resolve => {
    execFile(command, args, { timeout, maxBuffer: 1024 * 1024 }, (error, stdout, stderr) => {
      resolve({ ok: !error, code: error?.code ?? 0, stdout, stderr: stderr || error?.message || "" });
    });
  });
}

function redactText(value) {
  return String(value || "")
    .replace(/("(?:secret|token|password|authorization|api[_-]?key)"\s*:\s*")[^"]*(")/gi, "$1***$2")
    .replace(/((?:secret|token|password|authorization|api[_-]?key)=)[^\s&]+/gi, "$1***");
}

function tailText(text, maxLines = config.diagnosticsMaxLines, maxChars = 1200) {
  const lines = String(text || "").split(/\r?\n/).filter(Boolean).slice(-maxLines);
  return lines.map(line => {
    const redacted = redactText(line);
    return redacted.length > maxChars ? `${redacted.slice(0, maxChars)}...` : redacted;
  });
}

function requestUrl(urlString, timeoutMs) {
  return new Promise(resolve => {
    const started = Date.now();
    const timings = {};
    let settled = false;
    const mark = key => {
      if (timings[key] == null) timings[key] = Date.now() - started;
    };
    const finish = result => {
      if (settled) return;
      settled = true;
      resolve({
        ...result,
        ms: Date.now() - started,
        timing_ms: timings,
      });
    };
    const url = new URL(urlString);
    const client = url.protocol === "https:" ? https : http;
    const req = client.request(url, {
      method: config.siteMethod === "GET" ? "GET" : "HEAD",
      timeout: timeoutMs,
      rejectUnauthorized: false,
      headers: { "User-Agent": `office-apache-site-watchdog/${config.office}` },
    }, res => {
      mark("ttfb");
      res.resume();
      res.on("end", () => finish({
        ok: res.statusCode >= 200 && res.statusCode < 400,
        statusCode: res.statusCode,
      }));
    });
    req.on("socket", socket => {
      socket.once("lookup", () => mark("dns"));
      socket.once("connect", () => mark("connect"));
      socket.once("secureConnect", () => mark("tls"));
    });
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", error => finish({ ok: false, error: error.message }));
    req.end();
  });
}

function getText(urlString, timeoutMs) {
  return new Promise(resolve => {
    const url = new URL(urlString);
    const client = url.protocol === "https:" ? https : http;
    const req = client.request(url, { method: "GET", timeout: timeoutMs, rejectUnauthorized: false }, res => {
      const chunks = [];
      res.on("data", chunk => chunks.push(chunk));
      res.on("end", () => resolve(res.statusCode >= 200 && res.statusCode < 400 ? Buffer.concat(chunks).toString("utf8") : ""));
    });
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", () => resolve(""));
    req.end();
  });
}

function postJson(payload) {
  return new Promise(resolve => {
    const url = new URL(config.webhookUrl);
    const client = url.protocol === "https:" ? https : http;
    const body = Buffer.from(JSON.stringify(payload), "utf8");
    const req = client.request(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Content-Length": body.length },
      timeout: config.webhookTimeoutMs,
    }, res => {
      res.resume();
      res.on("end", () => resolve({ ok: res.statusCode >= 200 && res.statusCode < 300, statusCode: res.statusCode }));
    });
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.on("error", error => resolve({ ok: false, error: error.message }));
    req.end(body);
  });
}

function parseStatusAuto(text) {
  const out = {};
  for (const line of String(text || "").split(/\r?\n/)) {
    const idx = line.indexOf(":");
    if (idx === -1) continue;
    const key = line.slice(0, idx).trim();
    const value = line.slice(idx + 1).trim();
    out[key] = /^\d+(\.\d+)?$/.test(value) ? Number(value) : value;
  }
  return out;
}

function parseJsonMaybe(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function readNewLines(state, key, file, maxBytes = 256 * 1024) {
  state.fileOffsets = state.fileOffsets && typeof state.fileOffsets === "object" ? state.fileOffsets : {};
  let stat;
  try {
    stat = fs.statSync(file);
  } catch {
    return [];
  }

  const previous = state.fileOffsets[key];
  const fileId = `${stat.dev}:${stat.ino}`;
  if (!previous || previous.fileId !== fileId) {
    state.fileOffsets[key] = { fileId, offset: stat.size };
    return [];
  }

  let offset = Number(previous.offset || 0);
  if (stat.size < offset) offset = 0;
  if (stat.size === offset) return [];

  const bytesToRead = Math.min(maxBytes, stat.size - offset);
  const start = stat.size - offset > maxBytes ? stat.size - maxBytes : offset;
  const fd = fs.openSync(file, "r");
  try {
    const buffer = Buffer.alloc(bytesToRead);
    fs.readSync(fd, buffer, 0, bytesToRead, start);
    state.fileOffsets[key] = { fileId, offset: stat.size };
    return tailText(buffer.toString("utf8"), config.logAlertMaxLines, 2000);
  } finally {
    fs.closeSync(fd);
  }
}

function isPhpFpmSlowLogHeader(line) {
  return /^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]\s+\[pool [^\]]+\]\s+pid \d+\s*$/.test(String(line || ""));
}

function readNewPhpFpmSlowLog(state, key, file, chunkBytes = 64 * 1024) {
  state.fileOffsets = state.fileOffsets && typeof state.fileOffsets === "object" ? state.fileOffsets : {};
  let stat;
  try {
    stat = fs.statSync(file);
  } catch {
    return { requestCount: 0, observedLineCount: 0, bytesRead: 0, sampleLines: [] };
  }

  const previous = state.fileOffsets[key];
  const fileId = `${stat.dev}:${stat.ino}`;
  if (!previous || previous.fileId !== fileId) {
    state.fileOffsets[key] = { fileId, offset: stat.size, partialLine: "" };
    return { requestCount: 0, observedLineCount: 0, bytesRead: 0, sampleLines: [] };
  }

  let offset = Number(previous.offset || 0);
  let partialLine = String(previous.partialLine || "");
  if (stat.size < offset) {
    offset = 0;
    partialLine = "";
  }
  if (stat.size === offset) {
    return { requestCount: 0, observedLineCount: 0, bytesRead: 0, sampleLines: [] };
  }

  const sampleLimit = Math.max(1, config.logAlertMaxLines, config.phpFpmSlowLogSummaryMaxLines);
  const sampleLines = [];
  let requestCount = 0;
  let observedLineCount = 0;
  let position = offset;
  const fd = fs.openSync(file, "r");
  try {
    while (position < stat.size) {
      const length = Math.min(Math.max(1024, chunkBytes), stat.size - position);
      const buffer = Buffer.alloc(length);
      const read = fs.readSync(fd, buffer, 0, length, position);
      if (!read) break;
      position += read;

      const lines = `${partialLine}${buffer.subarray(0, read).toString("utf8")}`.split(/\r?\n/);
      partialLine = lines.pop() || "";
      for (const line of lines) {
        if (isPhpFpmSlowLogHeader(line)) requestCount += 1;
        if (!line.trim()) continue;
        observedLineCount += 1;
        sampleLines.push(line);
        if (sampleLines.length > sampleLimit) sampleLines.shift();
      }
    }
  } finally {
    fs.closeSync(fd);
  }

  state.fileOffsets[key] = {
    fileId,
    offset: stat.size,
    partialLine: partialLine.slice(-8192),
  };
  return {
    requestCount,
    observedLineCount,
    bytesRead: stat.size - offset,
    sampleLines: tailText(sampleLines.join("\n"), config.logAlertMaxLines, 2000),
  };
}

function logAlertState(state, key) {
  state.logAlerts = state.logAlerts && typeof state.logAlerts === "object" ? state.logAlerts : {};
  const item = state.logAlerts[key] && typeof state.logAlerts[key] === "object" ? state.logAlerts[key] : {};
  state.logAlerts[key] = item;
  return item;
}

function suppressSlowLogAlert(state, key, batch) {
  const item = logAlertState(state, key);
  const now = Date.now();
  delete item.suppressedLineCount;
  item.counterVersion = 2;
  item.suppressedRequestCount = Number(item.suppressedRequestCount || 0) + batch.requestCount;
  item.firstSuppressedAt = Number(item.firstSuppressedAt || now);
  item.lastSuppressedAt = now;
  item.suppressedSamples = [
    ...(Array.isArray(item.suppressedSamples) ? item.suppressedSamples : []),
    ...batch.sampleLines,
  ].slice(-Math.max(0, config.phpFpmSlowLogSummaryMaxLines));
}

async function emitPhpFpmSlowLogAlert(state, batch) {
  const key = "php-fpm-slow-log";
  const item = logAlertState(state, key);
  const now = Date.now();
  const cooldownMs = Math.max(0, config.phpFpmSlowLogNotifyCooldownSeconds) * 1000;
  const lastNotifiedAt = Number(item.lastNotifiedAt || 0);
  if (lastNotifiedAt && cooldownMs && now - lastNotifiedAt < cooldownMs) {
    suppressSlowLogAlert(state, key, batch);
    console.log(`${new Date().toISOString()} suppressed PHP-FPM slowlog alert for ${batch.requestCount} new request(s); cooldown ${config.phpFpmSlowLogNotifyCooldownSeconds}s`);
    return;
  }

  const suppressedRequestCount = Number(item.suppressedRequestCount || 0);
  const suppressedSamples = Array.isArray(item.suppressedSamples) ? item.suppressedSamples : [];
  const suppressedFirstAt = item.firstSuppressedAt ? new Date(Number(item.firstSuppressedAt)).toISOString() : null;
  const suppressedLastAt = item.lastSuppressedAt ? new Date(Number(item.lastSuppressedAt)).toISOString() : null;
  item.lastNotifiedAt = now;
  delete item.suppressedLineCount;
  item.counterVersion = 2;
  item.suppressedRequestCount = 0;
  item.firstSuppressedAt = 0;
  item.lastSuppressedAt = 0;
  item.suppressedSamples = [];

  await emit({
    event_type: "php_fpm_slowlog_watchdog",
    status: "warning",
    severity: "warning",
    title: "PHP-FPM slow request logged",
    message: suppressedRequestCount
      ? `PHP-FPM logged ${batch.requestCount} new slow request(s) in ${config.office}; ${suppressedRequestCount} request(s) occurred during the cooldown.`
      : `PHP-FPM logged ${batch.requestCount} new slow request(s) in ${config.office}.`,
    log_file: config.phpFpmSlowLog,
    slow_request_count: batch.requestCount,
    slowlog_lines: batch.sampleLines,
    slowlog_observed_line_count: batch.observedLineCount,
    slowlog_bytes_read: batch.bytesRead,
    suppressed_request_count: suppressedRequestCount,
    suppressed_first_at: suppressedFirstAt,
    suppressed_last_at: suppressedLastAt,
    suppressed_samples: suppressedSamples,
    cooldown_seconds: config.phpFpmSlowLogNotifyCooldownSeconds,
  });
}

async function checkLogAlerts(state) {
  if (!config.logAlertsEnabled) return;
  const fpmLines = readNewLines(state, "php-fpm-log", config.phpFpmLog);
  for (const line of fpmLines.filter(item => /server reached pm\.max_children setting/i.test(item))) {
    await emit({
      event_type: "php_fpm_capacity_watchdog",
      status: "warning",
      severity: "warning",
      title: "PHP-FPM max_children reached",
      message: `PHP-FPM reached pm.max_children in ${config.office}: ${line}`,
      log_file: config.phpFpmLog,
      log_line: line,
    });
  }

  const slowLogBatch = readNewPhpFpmSlowLog(state, "php-fpm-slow-log", config.phpFpmSlowLog);
  if (slowLogBatch.requestCount > 0) {
    await emitPhpFpmSlowLogAlert(state, slowLogBatch);
  }

  const qmLines = readNewLines(state, "query-monitor-log", config.queryMonitorLog);
  if (qmLines.length) {
    const entries = qmLines.map(raw => parseJsonMaybe(raw) || raw);
    const parsedEntries = entries.filter(item => item && typeof item === "object");
    const slowDbCount = parsedEntries.reduce((sum, item) => sum + (item.db?.slow_queries?.length || 0), 0);
    const slowHttpCount = parsedEntries.reduce((sum, item) => sum + (item.http?.slow_calls?.length || 0), 0);
    const topRequests = parsedEntries
      .map(item => item.request || {})
      .sort((a, b) => Number(b.seconds || 0) - Number(a.seconds || 0))
      .slice(0, Math.min(config.logAlertMaxLines, 10));
    await emit({
      event_type: "wordpress_query_monitor_slow_requests",
      status: "warning",
      severity: "warning",
      title: "WordPress slow requests",
      message: `WordPress logged ${qmLines.length} slow request(s) in ${config.office}: ${slowDbCount} slow DB queries, ${slowHttpCount} slow HTTP calls.`,
      log_file: config.queryMonitorLog,
      query_monitor_entries: entries.slice(0, config.logAlertMaxLines),
      top_requests: topRequests,
      slow_db_query_count: slowDbCount,
      slow_http_call_count: slowHttpCount,
    });
  }
}

function stateChangedOrRepeat(state, key, status) {
  const now = Date.now();
  const previous = state.states[key] || { status: "unknown", notifiedAt: 0 };
  if (previous.status !== status || (status !== "up" && now - Number(previous.notifiedAt || 0) >= config.repeatSeconds * 1000)) {
    state.states[key] = { status, notifiedAt: now };
    return previous.status !== "unknown" || status !== "up";
  }
  return false;
}

function buildEventPayload(event, overrides = {}) {
  const requestedChannels = Array.isArray(overrides.notifyChannels) ? overrides.notifyChannels : config.notifyChannels;
  return {
    ...event,
    event_type: event.event_type || "apache_site_watchdog",
    category: "web_health",
    source: "apache-site-watchdog",
    office: overrides.office || config.office,
    host: overrides.host || os.hostname(),
    channels: [...requestedChannels],
    notify_channels: [...requestedChannels],
    created_at: overrides.createdAt || event.created_at || new Date().toISOString(),
  };
}

async function emit(event) {
  const payload = buildEventPayload(event);
  appendJsonl(config.eventLogFile, payload);
  await run("logger", ["-p", payload.status === "down" || payload.severity === "critical" ? "daemon.crit" : "daemon.warning", "-t", "apache-site-watchdog", payload.message], 3000);
  const result = await postJson(payload);
  if (!result.ok) console.error(`failed posting Apache site watchdog event to n8n: ${result.error || result.statusCode}`);
  else console.log(`${new Date().toISOString()} sent ${payload.status || payload.severity} event for ${payload.site_url || "apache"}`);
}

async function apacheActive() {
  const result = await run("systemctl", ["is-active", "apache2.service"], 5000);
  return result.stdout.trim() === "active";
}

async function countCloseWait() {
  const result = await run("ss", ["-tan", "state", "close-wait"], 5000);
  if (!result.ok) return 0;
  return result.stdout.split(/\r?\n/).filter(line => line.trim() && !line.startsWith("Recv-Q")).length;
}

async function tailFile(file) {
  const result = await run("tail", ["-n", String(config.diagnosticsMaxLines), file], config.diagnosticsTimeoutMs);
  return result.ok ? tailText(result.stdout) : [];
}

async function collectSiteDiagnostics() {
  if (!config.diagnosticsEnabled) return null;
  const [active, statusText, closeWait, phpFpmActive, phpFpmStatusText, phpFpmLog, phpFpmSlowLog, queryMonitorLog, apacheErrors, dbProcesslist, topProcesses] = await Promise.all([
    apacheActive(),
    getText(config.statusUrl, Math.min(config.httpTimeoutMs, config.diagnosticsTimeoutMs)),
    countCloseWait(),
    run("systemctl", ["is-active", config.phpFpmService], config.diagnosticsTimeoutMs),
    getText(config.phpFpmStatusUrl, config.diagnosticsTimeoutMs),
    tailFile(config.phpFpmLog),
    tailFile(config.phpFpmSlowLog),
    tailFile(config.queryMonitorLog),
    tailFile(config.apacheErrorLog),
    run("wp", ["--allow-root", `--path=${config.wpPath}`, "db", "query", "SHOW FULL PROCESSLIST"], config.diagnosticsTimeoutMs),
    run("ps", ["-eo", "pid,ppid,stat,pcpu,pmem,etime,comm", "--sort=-pcpu"], config.diagnosticsTimeoutMs),
  ]);
  const serverStatus = statusText ? parseStatusAuto(statusText) : null;
  const phpFpmStatus = phpFpmStatusText ? parseJsonMaybe(phpFpmStatusText) || parseStatusAuto(phpFpmStatusText) : null;
  return {
    apache_active: active,
    apache_status: serverStatus,
    close_wait_sockets: closeWait,
    php_fpm: {
      service: config.phpFpmService,
      active: phpFpmActive.stdout.trim(),
      status: phpFpmStatus,
      recent_log: phpFpmLog,
      recent_slowlog: phpFpmSlowLog,
    },
    wordpress: {
      path: config.wpPath,
      db_processlist: dbProcesslist.ok ? tailText(dbProcesslist.stdout, config.diagnosticsMaxLines, 600) : [],
      db_processlist_error: dbProcesslist.ok ? null : tailText(dbProcesslist.stderr, 5, 600),
      query_monitor_slow_requests: queryMonitorLog,
    },
    apache_recent_errors: apacheErrors,
    top_processes: topProcesses.ok ? tailText(topProcesses.stdout, Math.min(config.diagnosticsMaxLines, 20), 1000) : [],
  };
}

async function maybeRestartApache(state, status) {
  if (status !== "critical" || !config.autoRestartApache) return false;
  const now = Date.now();
  if (now - Number(state.lastRestartAt || 0) < config.autoRestartCooldownSeconds * 1000) return false;
  state.lastRestartAt = now;
  const result = await run("systemctl", ["restart", "apache2.service"], 30000);
  return result.ok;
}

function apacheSeverity(active, status, closeWait) {
  if (!status) return active ? "warning" : "critical";
  const busy = Number(status.BusyWorkers || 0);
  const idle = Number(status.IdleWorkers || 0);
  const busyPercent = config.maxRequestWorkers > 0 ? (busy / config.maxRequestWorkers) * 100 : 0;
  if (busy < 5 && closeWait < config.closeWaitWarning) return "ok";
  if (busyPercent >= config.busyWorkersCriticalPercent || closeWait >= config.closeWaitCritical) return "critical";
  if (idle <= config.idleWorkersCritical && busyPercent >= config.busyWorkersWarningPercent) return "critical";
  if (busyPercent >= config.busyWorkersWarningPercent || closeWait >= config.closeWaitWarning) return "warning";
  if (idle <= config.idleWorkersWarning && busyPercent >= 25) return "warning";
  return "ok";
}

async function checkApacheCapacity(state) {
  const active = await apacheActive();
  const statusText = await getText(config.statusUrl, config.httpTimeoutMs);
  const status = statusText ? parseStatusAuto(statusText) : null;
  const closeWait = await countCloseWait();
  const severity = apacheSeverity(active, status, closeWait);
  const restarted = await maybeRestartApache(state, severity);
  if (!stateChangedOrRepeat(state, "apache-capacity", severity === "ok" ? "up" : severity)) return;

  const busy = Number(status?.BusyWorkers || 0);
  const idle = Number(status?.IdleWorkers || 0);
  const busyPercent = config.maxRequestWorkers > 0 ? (busy / config.maxRequestWorkers) * 100 : 0;
  await emit({
    event_type: "apache_capacity_watchdog",
    status: severity === "ok" ? "recovered" : severity,
    severity,
    title: severity === "ok" ? "Apache capacity recovered" : "Apache capacity problem",
    message: severity === "ok"
      ? `Apache capacity recovered in ${config.office}: ${busy} busy workers, ${idle} idle workers, ${closeWait} CLOSE-WAIT sockets.`
      : `Apache capacity ${severity} in ${config.office}: ${busy} busy workers, ${idle} idle workers (${busyPercent.toFixed(1)}% of MaxRequestWorkers ${config.maxRequestWorkers}), ${closeWait} CLOSE-WAIT sockets.${restarted ? " Apache was restarted by the watchdog." : ""}`,
    apache_active: active,
    busy_workers: busy,
    idle_workers: idle,
    busy_percent: Number(busyPercent.toFixed(1)),
    close_wait_sockets: closeWait,
    auto_restarted: restarted,
    server_status: status,
  });
}

async function checkSite(state, siteUrl) {
  const result = await requestUrl(siteUrl, config.httpTimeoutMs);
  const rawStatus = result.ok ? (result.ms < config.slowSiteMs ? "up" : "slow") : "down";
  const key = `site:${siteUrl}`;
  const previous = state.states[key] || { status: "unknown", notifiedAt: 0, failures: 0 };
  const failures = rawStatus === "down" ? Number(previous.failures || 0) + 1 : 0;
  const status = rawStatus === "down" && failures < config.siteFailuresBeforeDown ? previous.status === "down" ? "down" : "up" : rawStatus;
  const now = Date.now();
  const shouldNotify = previous.status !== status || (status !== "up" && now - Number(previous.notifiedAt || 0) >= config.repeatSeconds * 1000);
  state.states[key] = {
    ...previous,
    status,
    failures,
    notifiedAt: shouldNotify ? now : previous.notifiedAt,
    lastRawStatus: rawStatus,
    lastCheckedAt: now,
    lastResponseMs: result.ms,
  };
  if (rawStatus === "down" && failures < config.siteFailuresBeforeDown) return;
  if (!shouldNotify || (previous.status === "unknown" && status === "up")) return;
  const diagnostics = status === "up" ? null : await collectSiteDiagnostics();
  const reason = result.statusCode ? `HTTP ${result.statusCode}` : result.error;
  const timingText = result.timing_ms?.ttfb != null ? `, TTFB ${result.timing_ms.ttfb}ms` : "";
  await emit({
    status: status === "up" ? "recovered" : status,
    severity: status === "up" ? "info" : status === "slow" ? "warning" : "critical",
    title: status === "up" ? "Website recovered" : status === "slow" ? "Website slow" : "Website down",
    message: status === "up"
      ? `${siteUrl} recovered in ${config.office}: HTTP ${result.statusCode} in ${result.ms}ms${timingText}.`
      : status === "slow"
        ? `${siteUrl} is slow in ${config.office}: HTTP ${result.statusCode} in ${result.ms}ms${timingText}.`
        : `${siteUrl} is down in ${config.office}: ${reason} in ${result.ms}ms after ${failures} consecutive failed checks.`,
    site_url: siteUrl,
    http_status: result.statusCode || null,
    response_ms: result.ms,
    timing_ms: result.timing_ms || null,
    consecutive_failures: failures,
    error: result.error || null,
    diagnostics,
  });
}

async function checkOnce(state) {
  await checkApacheCapacity(state);
  await checkLogAlerts(state);
  for (const siteUrl of config.siteUrls) await checkSite(state, siteUrl);
  saveState(state);
}

async function main() {
  const mode = process.argv[2] || "serve";
  if (mode === "check-config") {
    validateConfig();
    console.log(JSON.stringify({ ok: true }));
    return;
  }
  validateConfig();
  const state = loadState();
  if (mode === "check-once") {
    await checkOnce(state);
    return;
  }
  if (mode !== "serve") throw new Error("Usage: apache-site-watchdog-to-n8n.cjs [check-config|serve|check-once]");
  console.log(`${new Date().toISOString()} Apache site watchdog started for ${config.siteUrls.join(", ")}`);
  let running = false;
  const guardedCheck = async () => {
    if (running) return;
    running = true;
    try {
      await checkOnce(state);
    } catch (error) {
      console.error(`watchdog check failed: ${error.stack || error.message}`);
      saveState(state);
    } finally {
      running = false;
    }
  };
  await guardedCheck();
  setInterval(() => {
    guardedCheck();
  }, Math.max(5, config.intervalSeconds) * 1000);
}

module.exports = {
  buildEventPayload,
  isPhpFpmSlowLogHeader,
  readNewPhpFpmSlowLog,
  redactText,
  validateConfig,
};

if (require.main === module) {
  main().catch(error => {
    console.error(error.stack || error.message);
    process.exit(1);
  });
}
