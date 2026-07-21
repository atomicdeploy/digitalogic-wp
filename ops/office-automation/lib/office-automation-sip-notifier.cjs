#!/usr/bin/env node
"use strict";

const fs = require("node:fs");
const http = require("node:http");
const net = require("node:net");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

function loadEnv(file) {
  const out = {};
  try {
    for (const raw of fs.readFileSync(file, "utf8").split(/\r?\n/)) {
      const line = raw.trim();
      if (!line || line.startsWith("#")) continue;
      const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);
      if (!match) continue;
      let value = match[2].trim();
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1);
      out[match[1]] = value;
    }
  } catch (_) {
    // The service can still run with process environment only.
  }
  return out;
}

const envFile = process.env.OFFICE_AUTOMATION_SIP_ENV || "/etc/office-automation-sip-notifier.env";
const env = { ...process.env, ...loadEnv(envFile) };
const config = {
  office: env.OFFICE_NAME || "office",
  httpHost: env.HTTP_HOST || "127.0.0.1",
  httpPort: Number(env.HTTP_PORT || 0),
  apiToken: env.API_TOKEN || "",
  defaultExtensions: parseCsv(env.DEFAULT_EXTENSIONS || ""),
  subscribersFile: env.SUBSCRIBERS_FILE || "/var/lib/office-automation/sip-notifier-subscribers.json",
  preferencesFile: env.PREFERENCES_FILE || "/var/lib/office-automation/n8n-sip-preferences.json",
  messageFrom: env.MESSAGE_FROM || "",
  amiHost: env.AMI_HOST || "127.0.0.1",
  amiPort: Number(env.AMI_PORT || 0),
  amiUser: env.AMI_USER || "",
  amiSecret: env.AMI_SECRET || "",
  amiTimeoutMs: Number(env.AMI_TIMEOUT_MS || "5000"),
  maxBodyLength: Number(env.MAX_BODY_LENGTH || "900"),
  interofficeMessageEndpoint: env.INTEROFFICE_MESSAGE_ENDPOINT || "",
  interofficeMessageFrom: env.INTEROFFICE_MESSAGE_FROM || "",
  interofficeForwardTarget: env.INTEROFFICE_FORWARD_TARGET || "",
  interofficeAliasRegex: new RegExp(env.INTEROFFICE_ALIAS_REGEX || "$^"),
  interofficeTargetPrefix: env.INTEROFFICE_TARGET_PREFIX || "",
  interofficeSenderAliasRegex: new RegExp(env.INTEROFFICE_SENDER_ALIAS_REGEX || "$^"),
  interofficeSenderAliasPrefix: env.INTEROFFICE_SENDER_ALIAS_PREFIX || "",
  commandLogFile: env.COMMAND_LOG_FILE || "/var/log/office-automation-sip-commands.log",
};

let actionCounter = 0;

function parseCsv(value) {
  return String(value || "").split(",").map(item => item.trim()).filter(Boolean);
}

function validateConfig() {
  const missing = [];
  if (!Number.isInteger(config.httpPort) || config.httpPort < 1 || config.httpPort > 65535) missing.push("HTTP_PORT");
  if (!config.apiToken) missing.push("API_TOKEN");
  if (!config.messageFrom) missing.push("MESSAGE_FROM");
  if (!Number.isInteger(config.amiPort) || config.amiPort < 1 || config.amiPort > 65535) missing.push("AMI_PORT");
  if (!config.amiUser) missing.push("AMI_USER");
  if (!config.amiSecret) missing.push("AMI_SECRET");
  if (config.interofficeMessageEndpoint && !config.interofficeForwardTarget) missing.push("INTEROFFICE_FORWARD_TARGET");
  if (missing.length) throw new Error(`Missing required configuration: ${missing.join(", ")}`);
  return true;
}

function unique(values) {
  return [...new Set(values.map(item => String(item || "").trim()).filter(Boolean))].sort();
}

const categoryAliases = {
  nvr: "camera",
  cameras: "camera",
  camera: "camera",
  motion: "camera",
  dahua: "camera",
  led: "camera",
  host: "host",
  pc: "host",
  netwatch: "host",
  wifi: "wifi",
  wireless: "wifi",
  routeros: "routeros",
  dhcp: "wifi",
  sip: "sip_trunk",
  trunk: "sip_trunk",
  sip_trunk: "sip_trunk",
  oauth: "oauth",
  wordpress: "wordpress",
  wp: "wordpress",
  security: "security",
  login: "security",
  error: "error",
  errors: "error",
  failure: "error",
  important: "important",
  critical: "important",
  system: "system",
  all: "all",
};

const knownCategories = ["important", "error", "security", "sip_trunk", "oauth", "wordpress", "host", "wifi", "routeros", "camera", "system", "all"];

function normalizeCategory(value) {
  const key = String(value || "").trim().toLowerCase().replace(/[^a-z0-9_]+/g, "_").replace(/^_+|_+$/g, "");
  return categoryAliases[key] || key;
}

function normalizeCategories(values) {
  const items = Array.isArray(values) ? values : parseCsv(values);
  return unique(items.map(normalizeCategory).filter(Boolean));
}

function normalizeChannel(value) {
  const channel = String(value || "").trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
  if (["pbx", "phone", "call", "voice"].includes(channel)) return "sip";
  if (["telegram_bot", "telegrambot"].includes(channel)) return "telegram";
  if (["ntfy_sh", "ntfysh"].includes(channel)) return "ntfy";
  return channel;
}

function channelFlagEnabled(value) {
  if (value === true || value === 1) return true;
  if (typeof value !== "string") return false;
  return ["true", "1", "yes", "on"].includes(value.trim().toLowerCase());
}

function explicitRequestedChannels(payload) {
  const source = payload && typeof payload === "object" ? payload : {};
  const hasNotifyChannels = Object.prototype.hasOwnProperty.call(source, "notify_channels");
  const hasChannels = Object.prototype.hasOwnProperty.call(source, "channels");
  if (!hasNotifyChannels && !hasChannels) return null;

  const allowLists = [];
  for (const raw of [hasNotifyChannels ? source.notify_channels : undefined, hasChannels ? source.channels : undefined]) {
    if (raw === undefined) continue;
    const requested = [];
    if (Array.isArray(raw)) {
      requested.push(...raw);
    } else if (raw && typeof raw === "object") {
      requested.push(...Object.entries(raw).filter(([, enabled]) => channelFlagEnabled(enabled)).map(([channel]) => channel));
    } else if (raw !== null) {
      requested.push(...parseCsv(raw));
    }
    allowLists.push(new Set(requested.map(normalizeChannel).filter(Boolean)));
  }
  if (!allowLists.length) return new Set();
  const candidates = new Set(allowLists.flatMap(allowed => [...allowed].filter(channel => channel !== "all")));
  if (!candidates.size && allowLists.every(allowed => allowed.has("all"))) return new Set(["all"]);
  return new Set([...candidates].filter(channel => allowLists.every(allowed => allowed.has(channel) || allowed.has("all"))));
}

function payloadRequestsChannel(payload, channel) {
  const requested = explicitRequestedChannels(payload);
  if (requested === null) return true;
  return requested.has("all") || requested.has(normalizeChannel(channel));
}

function decodeArg(value) {
  return Buffer.from(String(value || ""), "base64").toString("utf8");
}

function commandLogRecord(record, at = new Date().toISOString()) {
  const knownError = record.error === "could_not_detect_sender_extension" ? record.error : "command_failed";
  return {
    at,
    office: config.office,
    direction: String(record.direction || "unknown"),
    ...(Object.prototype.hasOwnProperty.call(record, "ok") ? { ok: Boolean(record.ok) } : {}),
    ...(record.error ? { error: knownError } : {}),
    ...(record.command ? { command: String(record.command) } : {}),
    ...(record.categories ? { categories: normalizeCategories(record.categories) } : {}),
    endpoint_present: Boolean(record.endpoint),
    sender_present: Boolean(record.from),
    body_bytes: Buffer.byteLength(String(record.body || ""), "utf8"),
  };
}

function appendCommandLog(record) {
  try {
    const safeRecord = commandLogRecord(record);
    fs.appendFileSync(config.commandLogFile, `${JSON.stringify(safeRecord)}\n`);
  } catch {
    // Logging must never prevent command handling.
  }
}

function endpointFromIdentity(identity) {
  const text = String(identity || "");
  const sipUser = text.match(/sip:([^@;>]+)/i);
  if (sipUser) return sipUser[1].replace(/^"|"$/g, "");
  const bare = text.match(/\b([0-9]{2,5})\b/);
  return bare ? bare[1] : "";
}

function loadSubscribers() {
  try {
    const parsed = JSON.parse(fs.readFileSync(config.subscribersFile, "utf8"));
    return new Set(Array.isArray(parsed.subscribers) ? parsed.subscribers.map(String) : []);
  } catch {
    return new Set();
  }
}

function saveSubscribers(subscribers) {
  fs.mkdirSync(path.dirname(config.subscribersFile), { recursive: true });
  const payload = JSON.stringify({ subscribers: [...subscribers].sort() }, null, 2);
  fs.writeFileSync(config.subscribersFile, `${payload}\n`, { mode: 0o640 });
}

function loadPreferences() {
  try {
    return normalizePreferences({ cameraNotifications: false, cameraSubscribers: [], ...JSON.parse(fs.readFileSync(config.preferencesFile, "utf8")) });
  } catch {
    return normalizePreferences({ cameraNotifications: false, cameraSubscribers: [] });
  }
}

function savePreferences(preferences) {
  fs.mkdirSync(path.dirname(config.preferencesFile), { recursive: true });
  fs.writeFileSync(config.preferencesFile, `${JSON.stringify(normalizePreferences(preferences), null, 2)}\n`, { mode: 0o640 });
}

function defaultChannelCategories(channel) {
  if (channel === "sip") return normalizeCategories(env.DEFAULT_SIP_CATEGORIES || "important,error,security,sip_trunk,oauth,wordpress,host,wifi");
  if (channel === "telegram") return normalizeCategories(env.DEFAULT_TELEGRAM_CATEGORIES || "all");
  if (channel === "ntfy") return normalizeCategories(env.DEFAULT_NTFY_CATEGORIES || "important,error,security,sip_trunk,oauth,wordpress,host,wifi,camera");
  return ["all"];
}

function normalizePreferences(preferences) {
  const prefs = preferences && typeof preferences === "object" ? preferences : {};
  prefs.channels = prefs.channels && typeof prefs.channels === "object" ? prefs.channels : {};
  for (const channel of ["sip", "telegram", "ntfy"]) {
    const current = prefs.channels[channel] && typeof prefs.channels[channel] === "object" ? prefs.channels[channel] : {};
    current.categories = normalizeCategories(current.categories && current.categories.length ? current.categories : defaultChannelCategories(channel));
    current.mutedCategories = normalizeCategories(current.mutedCategories || []);
    current.endpoints = current.endpoints && typeof current.endpoints === "object" ? current.endpoints : {};
    prefs.channels[channel] = current;
  }

  const cameraSubscribers = Array.isArray(prefs.cameraSubscribers) ? prefs.cameraSubscribers.map(String) : [];
  for (const endpoint of cameraSubscribers) {
    const item = prefs.channels.sip.endpoints[endpoint] || {};
    item.categories = normalizeCategories([...(item.categories || []), "camera"]);
    item.mutedCategories = normalizeCategories(item.mutedCategories || []);
    prefs.channels.sip.endpoints[endpoint] = item;
  }
  return prefs;
}

function eventCategories(payload) {
  const explicit = normalizeCategories(payload.categories || payload.category || "");
  const text = [
    payload.event_type,
    payload.type,
    payload.source,
    payload.title,
    payload.message,
    payload.status,
    payload.severity,
  ].map(value => String(value || "").toLowerCase()).join(" ");
  const categories = new Set(explicit);
  const severity = String(payload.severity || "").toLowerCase();

  if (/(error|failure|failed|critical|unauthorized|forbidden|busy)/.test(text) || ["error", "critical", "warning", "warn"].includes(severity)) {
    categories.add("error");
    categories.add("important");
  }
  if (/(camera|dahua|nvr|motion|videomotion|smartmotion|\bled\b)/.test(text)) categories.add("camera");
  if (/(netwatch|host|pc|online|offline|arrived|left)/.test(text)) categories.add("host");
  if (/(wifi|wireless|registration|dhcp|lease)/.test(text)) categories.add("wifi");
  if (/(routeros|mikrotik)/.test(text)) categories.add("routeros");
  if (/(sip_trunk|sip trunk|trunk|pjsip|busy_cleared)/.test(text)) {
    categories.add("sip_trunk");
    categories.add("important");
  }
  if (/oauth/.test(text)) {
    categories.add("oauth");
    categories.add("important");
  }
  if (/(wordpress|wp_login|wp login)/.test(text)) categories.add("wordpress");
  if (/(security|login|fail2ban|ban|pam|unknown|unauthorized)/.test(text)) {
    categories.add("security");
    categories.add("important");
  }
  if (!categories.size) categories.add("system");
  return [...categories].sort();
}

function categoryMatches(allowed, categories) {
  const allow = normalizeCategories(allowed);
  const cats = normalizeCategories(categories);
  if (allow.includes("all")) return true;
  return cats.some(category => allow.includes(category));
}

function channelAllowed(preferences, channel, payload) {
  if (!payloadRequestsChannel(payload, channel)) return false;
  if (channel === "telegram" && isFailedLoginEvent(payload)) return false;
  const categories = eventCategories(payload);
  const channelPrefs = normalizePreferences(preferences).channels[channel] || {};
  if (categoryMatches(channelPrefs.mutedCategories || [], categories)) return false;
  return categoryMatches(channelPrefs.categories || [], categories);
}

function isFailedLoginEvent(payload) {
  const text = [
    payload.event_type,
    payload.type,
    payload.source,
    payload.title,
    payload.message,
    payload.text,
    payload.status,
    payload.severity,
    payload.reason,
  ].map(value => String(value || "").toLowerCase()).join(" ");
  return /(failed|failure|denied|invalid|incorrect|unauthorized|forbidden)/.test(text)
    && /(login|log in|signin|sign in|wordpress|wp_login|wp login|authentication|password)/.test(text);
}

function endpointAllowed(preferences, endpoint, payload, subscribers, defaultExtensions = config.defaultExtensions) {
  if (!payloadRequestsChannel(payload, "sip")) return false;
  const prefs = normalizePreferences(preferences);
  const sipPrefs = prefs.channels.sip;
  const endpointKey = String(endpoint);
  const hasEndpointPreferences = Object.prototype.hasOwnProperty.call(sipPrefs.endpoints, endpointKey);
  const isSubscriber = subscribers && subscribers.has(endpointKey);
  const isDefault = defaultExtensions.map(String).includes(endpointKey);
  if (!hasEndpointPreferences && !isSubscriber && !isDefault) return false;
  const endpointPrefs = sipPrefs.endpoints[endpointKey] || {};
  const categories = eventCategories(payload);
  const endpointCategories = normalizeCategories(endpointPrefs.categories || []);
  const muted = normalizeCategories(endpointPrefs.mutedCategories || []);
  if (categoryMatches(muted, categories)) return false;
  if (endpointCategories.length) return categoryMatches(endpointCategories, categories);
  return categoryMatches(sipPrefs.categories || [], categories);
}

function routeDecision(payload, dependencies = {}) {
  const preferences = dependencies.preferences || loadPreferences();
  return {
    ok: true,
    categories: eventCategories(payload),
    channels: {
      sip: channelAllowed(preferences, "sip", payload),
      telegram: channelAllowed(preferences, "telegram", payload),
      ntfy: channelAllowed(preferences, "ntfy", payload),
    },
  };
}

function applyPreferenceUpdate(body) {
  const prefs = loadPreferences();
  const channel = String(body.channel || "sip").trim().toLowerCase();
  if (!["sip", "telegram", "ntfy"].includes(channel)) throw new Error("channel must be one of sip, telegram, ntfy");
  const action = String(body.action || "").trim().toLowerCase();
  const categories = normalizeCategories(body.categories || body.category || "all");
  const endpoint = body.endpoint ? String(body.endpoint).trim() : "";

  if (channel === "sip" && endpoint) {
    const next = updateEndpointSubscription(prefs, normalizeCommandEndpoint(endpoint), action, categories);
    savePreferences(next);
    return { ok: true, channel, endpoint: normalizeCommandEndpoint(endpoint), action, categories, preferences: next };
  }

  const channelPrefs = prefs.channels[channel];
  const current = new Set(normalizeCategories(channelPrefs.categories || []));
  const muted = new Set(normalizeCategories(channelPrefs.mutedCategories || []));
  if (action === "sub" || action === "subscribe" || action === "allow") {
    if (categories.includes("all")) muted.clear();
    for (const category of categories) {
      current.add(category);
      muted.delete(category);
    }
  } else if (action === "unsub" || action === "unsubscribe" || action === "mute" || action === "block") {
    if (categories.includes("all")) {
      current.clear();
      muted.clear();
      muted.add("all");
    } else {
      for (const category of categories) {
        current.delete(category);
        muted.add(category);
      }
    }
  } else if (action === "set") {
    channelPrefs.categories = categories;
    channelPrefs.mutedCategories = [];
    savePreferences(prefs);
    return { ok: true, channel, action, categories, preferences: prefs };
  } else {
    throw new Error("action must be sub, unsub, set, allow, mute, or block");
  }
  channelPrefs.categories = [...current].sort();
  channelPrefs.mutedCategories = [...muted].sort();
  savePreferences(prefs);
  return { ok: true, channel, action, categories, preferences: prefs };
}

function amiHeaderValue(value) {
  return String(value ?? "").replace(/[\r\n]/g, " ").trim();
}

function writeAmiAction(socket, fields) {
  const lines = Object.entries(fields)
    .filter(([, value]) => value !== undefined && value !== null)
    .map(([key, value]) => `${key}: ${amiHeaderValue(value)}`);
  socket.write(`${lines.join("\r\n")}\r\n\r\n`);
}

function parseAmiFrame(frame) {
  const parsed = {};
  for (const line of frame.split(/\r\n/)) {
    const idx = line.indexOf(":");
    if (idx === -1) continue;
    parsed[line.slice(0, idx).trim().toLowerCase()] = line.slice(idx + 1).trim();
  }
  return parsed;
}

function amiAction(fields) {
  return new Promise((resolve, reject) => {
    if (!config.amiSecret) {
      reject(new Error("AMI_SECRET is required"));
      return;
    }
    const socket = net.createConnection({ host: config.amiHost, port: config.amiPort });
    const actionId = `n8n-sip-${Date.now()}-${++actionCounter}`;
    let buffer = "";
    let loggedIn = false;
    let settled = false;
    const finish = (error, result) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      socket.end();
      if (error) reject(error);
      else resolve(result);
    };
    const timer = setTimeout(() => finish(new Error(`AMI action timed out after ${config.amiTimeoutMs}ms`)), config.amiTimeoutMs);
    socket.on("connect", () => {
      writeAmiAction(socket, { Action: "Login", Username: config.amiUser, Secret: config.amiSecret, ActionID: `${actionId}-login` });
    });
    socket.on("data", chunk => {
      buffer += chunk.toString("utf8");
      const frames = buffer.split(/\r\n\r\n/);
      buffer = frames.pop() || "";
      for (const frame of frames) {
        const parsed = parseAmiFrame(frame);
        if (!loggedIn && parsed.response) {
          if (parsed.response === "Error") return finish(new Error(parsed.message || "AMI login failed"));
          loggedIn = true;
          writeAmiAction(socket, { ...fields, ActionID: actionId });
          continue;
        }
        if (loggedIn && parsed.actionid === actionId) {
          if (parsed.response === "Error") finish(new Error(parsed.message || "AMI action failed"));
          else finish(null, parsed);
        }
      }
    });
    socket.on("error", finish);
    socket.on("close", () => {
      if (!settled) finish(new Error("AMI connection closed before action completed"));
    });
  });
}

async function sendSipMessage(endpoint, body, from = config.messageFrom) {
  const route = resolvePjsipMessageDestination(String(endpoint));
  const messageFrom = route.route === "interoffice" && config.interofficeMessageFrom ? config.interofficeMessageFrom : from;
  await sendRawSipMessage(route.destination, body, messageFrom);
  return route;
}

async function sendRawSipMessage(destination, body, from) {
  await amiAction({
    Action: "MessageSend",
    Destination: destination,
    From: from,
    Base64Body: Buffer.from(String(body), "utf8").toString("base64"),
  });
}

function forwardedSenderFromIdentity(identity) {
  const endpoint = endpointFromIdentity(identity);
  const aliasRegex = new RegExp(config.interofficeSenderAliasRegex);
  if (endpoint && config.interofficeSenderAliasPrefix && aliasRegex.test(endpoint)) {
    return `sip:${config.interofficeSenderAliasPrefix}${endpoint.slice(1)}@127.0.0.1`;
  }
  return String(identity || config.messageFrom).replace(/[\r\n]/g, " ");
}

async function forwardMessageToRemoteN8n(remoteEndpoint, from, body) {
  const endpoint = String(remoteEndpoint || "").trim();
  if (!endpoint) throw new Error("remote endpoint is required");
  if (!config.interofficeForwardTarget) throw new Error("INTEROFFICE_FORWARD_TARGET is required");
  if (!pjsipEndpointExists(endpoint)) throw new Error("configured interoffice PJSIP endpoint is unavailable");
  const forwardedFrom = forwardedSenderFromIdentity(from);
  const destination = `pjsip:PJSIP/${config.interofficeForwardTarget}@${endpoint}`;
  await sendRawSipMessage(destination, body, forwardedFrom);
  return { ok: true, route: "interoffice" };
}

function pjsipEndpointExists(endpoint) {
  const result = spawnSync("asterisk", ["-rx", `pjsip show endpoint ${endpoint}`], { encoding: "utf8" });
  const output = `${result.stdout || ""}\n${result.stderr || ""}`;
  if (/Unable to find object/i.test(output)) return false;
  if (result.status !== 0 && !/Endpoint:/i.test(output)) {
    throw new Error("could not verify the requested PJSIP endpoint");
  }
  return /Endpoint:/i.test(output);
}

function resolvePjsipMessageDestination(endpoint) {
  const target = String(endpoint || "").trim();
  const aliasRegex = new RegExp(config.interofficeAliasRegex);
  if (config.interofficeMessageEndpoint && aliasRegex.test(target)) {
    if (!pjsipEndpointExists(config.interofficeMessageEndpoint)) {
      throw new Error("configured interoffice PJSIP endpoint is unavailable");
    }
    const rewrittenTarget = `${config.interofficeTargetPrefix}${target.slice(1)}`;
    return {
      route: "interoffice",
      rewritten_target: rewrittenTarget,
      interoffice_endpoint: config.interofficeMessageEndpoint,
      destination: `pjsip:PJSIP/${rewrittenTarget}@${config.interofficeMessageEndpoint}`,
    };
  }

  if (!pjsipEndpointExists(target)) {
    throw new Error("requested PJSIP endpoint is unavailable");
  }
  return { route: "local", rewritten_target: target, destination: `pjsip:${target}` };
}

function normalizeCommandEndpoint(endpoint) {
  const target = String(endpoint || "").trim();
  if (!target) return "";
  const aliasRegex = new RegExp(config.interofficeSenderAliasRegex);
  if (config.interofficeMessageEndpoint && aliasRegex.test(target) && !pjsipEndpointExists(target)) {
    return `${config.interofficeSenderAliasPrefix}${target.slice(1)}`;
  }
  return target;
}

function formatNotification(payload) {
  const title = payload.title || payload.event_type || "n8n notification";
  const status = payload.status ? `Status: ${payload.status}` : "";
  const severity = payload.severity ? `Severity: ${payload.severity}` : "";
  const reason = payload.reason ? `Reason: ${payload.reason}` : "";
  const message = payload.message || payload.text || "";
  const lines = [`[n8n ${config.office}] ${title}`, status, severity, message, reason].filter(Boolean);
  const text = lines.join("\n");
  return text.length > config.maxBodyLength ? `${text.slice(0, config.maxBodyLength - 3)}...` : text;
}

function isCameraNotification(payload) {
  return eventCategories(payload).includes("camera");
}

async function notify(payload, dependencies = {}) {
  const categories = eventCategories(payload);
  if (!payloadRequestsChannel(payload, "sip")) {
    return { ok: true, skipped: true, reason: "sip_channel_not_requested", endpoints: [], categories, results: [] };
  }
  const preferences = dependencies.preferences || loadPreferences();
  const subscribers = dependencies.subscribers instanceof Set ? dependencies.subscribers : loadSubscribers();
  const defaultExtensions = Array.isArray(dependencies.defaultExtensions) ? dependencies.defaultExtensions.map(String) : config.defaultExtensions;
  const send = typeof dependencies.sendSipMessage === "function" ? dependencies.sendSipMessage : sendSipMessage;
  const requested = Array.isArray(payload.extensions) ? payload.extensions.map(String) : parseCsv(payload.extensions || "");
  const cameraSubscribers = Array.isArray(preferences.cameraSubscribers) ? preferences.cameraSubscribers.map(String) : [];
  const cameraEndpoints = cameraSubscribers.length
    ? cameraSubscribers
    : preferences.cameraNotifications === true
      ? defaultExtensions
      : [];
  let endpoints = isCameraNotification(payload)
    ? [...new Set(cameraEndpoints)].filter(Boolean)
    : [...new Set(requested.length ? requested : [...defaultExtensions, ...subscribers])].filter(Boolean);
  endpoints = endpoints.filter(endpoint => endpointAllowed(preferences, endpoint, payload, subscribers, defaultExtensions));
  if (isCameraNotification(payload) && (preferences.cameraNotifications === false || endpoints.length === 0)) {
    return { ok: true, skipped: true, reason: "camera_notifications_unsubscribed", endpoints: [] };
  }
  const body = formatNotification(payload);
  const results = [];
  for (const endpoint of endpoints) {
    try {
      const route = await send(endpoint, body);
      results.push({ ok: true, route: route.route });
    } catch {
      results.push({ ok: false, error: "sip_send_failed" });
    }
  }
  return {
    ok: results.every(item => item.ok),
    endpoint_count: endpoints.length,
    sent_count: results.filter(item => item.ok).length,
    failed_count: results.filter(item => !item.ok).length,
    categories,
    results,
  };
}

function commandHelp() {
  return [
    "n8n automation alerts:",
    "SUB IMPORTANT - receive important alerts",
    "UNSUB IMPORTANT - stop important alerts",
    "NVR SUB / NVR UNSUB - camera and motion alerts",
    "SUB ALL / UNSUB ALL - all SIP alerts on or off",
    "STATUS - show this extension's subscriptions",
    "Use the office routing aliases configured by the administrator.",
    `Categories: ${knownCategories.join(", ")}`,
    "HELP - show this help",
  ].join("\n");
}

function commandStatus(endpoint, preferences) {
  const prefs = normalizePreferences(preferences);
  const sipPrefs = prefs.channels.sip;
  const endpointPrefs = sipPrefs.endpoints[String(endpoint)] || {};
  const categories = normalizeCategories(endpointPrefs.categories || []);
  const muted = normalizeCategories(endpointPrefs.mutedCategories || []);
  return [
    `n8n SIP subscriptions for ${config.office} ext ${endpoint}:`,
    `Subscribed: ${categories.length ? categories.join(", ") : "(office defaults)"}`,
    `Muted: ${muted.length ? muted.join(", ") : "(none)"}`,
    `Office SIP default: ${(sipPrefs.categories || []).join(", ")}`,
    `Telegram channel: ${(prefs.channels.telegram.categories || []).join(", ")}`,
    `ntfy channel: ${(prefs.channels.ntfy.categories || []).join(", ")}`,
  ].join("\n");
}

function parseSubscriptionCommand(command) {
  const text = String(command || "").trim().toUpperCase();
  if (["STATUS", "STATE", "GET", "LIST", "EVENTS"].includes(text)) return { action: "status", categories: [] };
  const parts = text.split(/\s+/).filter(Boolean);
  if (!parts.length) return { action: "help", categories: [] };
  if (parts.length === 2 && ["STATUS", "STATE", "GET", "LIST", "EVENTS"].includes(parts[1])) return { action: "status", categories: normalizeCategories(parts[0]) };
  if (parts.length === 1 && ["SUB", "SUBSCRIBE", "START"].includes(parts[0])) return { action: "sub", categories: ["all"] };
  if (parts.length === 1 && ["UNSUB", "UNSUBSCRIBE", "STOP"].includes(parts[0])) return { action: "unsub", categories: ["all"] };
  let action = "";
  let categoryText = "";
  if (["SUB", "SUBSCRIBE", "START"].includes(parts[0])) {
    action = "sub";
    categoryText = parts.slice(1).join(",");
  } else if (["UNSUB", "UNSUBSCRIBE", "STOP"].includes(parts[0])) {
    action = "unsub";
    categoryText = parts.slice(1).join(",");
  } else if (["SUB", "SUBSCRIBE", "START"].includes(parts[parts.length - 1])) {
    action = "sub";
    categoryText = parts.slice(0, -1).join(",");
  } else if (["UNSUB", "UNSUBSCRIBE", "STOP"].includes(parts[parts.length - 1])) {
    action = "unsub";
    categoryText = parts.slice(0, -1).join(",");
  }
  if (!action) return { action: "help", categories: [] };
  const categories = normalizeCategories(categoryText || "all");
  return { action, categories: categories.length ? categories : ["all"] };
}

function updateEndpointSubscription(preferences, endpoint, action, categories) {
  const prefs = normalizePreferences(preferences);
  const sipPrefs = prefs.channels.sip;
  const item = sipPrefs.endpoints[String(endpoint)] || {};
  const current = new Set(normalizeCategories(item.categories || []));
  const muted = new Set(normalizeCategories(item.mutedCategories || []));
  const requested = normalizeCategories(categories);
  if (action === "sub") {
    if (requested.includes("all")) muted.clear();
    for (const category of requested) {
      current.add(category);
      muted.delete(category);
    }
  } else if (action === "unsub") {
    if (requested.includes("all")) {
      current.clear();
      muted.clear();
      muted.add("all");
    } else {
      for (const category of requested) {
        current.delete(category);
        muted.add(category);
      }
    }
  }
  item.categories = [...current].sort();
  item.mutedCategories = [...muted].sort();
  sipPrefs.endpoints[String(endpoint)] = item;

  const cameraSubscribers = new Set(Array.isArray(prefs.cameraSubscribers) ? prefs.cameraSubscribers.map(String) : []);
  if (item.categories.includes("all") || item.categories.includes("camera")) cameraSubscribers.add(String(endpoint));
  else cameraSubscribers.delete(String(endpoint));
  prefs.cameraSubscribers = [...cameraSubscribers].sort();
  prefs.cameraNotifications = prefs.cameraSubscribers.length > 0;
  prefs.cameraNotificationsUpdatedAt = new Date().toISOString();
  prefs.cameraNotificationsUpdatedBy = endpoint;
  return prefs;
}

async function handleMessageEvent(from, body) {
  const endpoint = normalizeCommandEndpoint(endpointFromIdentity(from));
  appendCommandLog({ direction: "inbound", from, endpoint, body: String(body || "") });
  if (!endpoint) {
    appendCommandLog({ direction: "result", ok: false, error: "could_not_detect_sender_extension", from, body: String(body || "") });
    return { ok: false, error: "could_not_detect_sender_extension" };
  }
  const command = String(body || "").trim().toUpperCase();
  const subscribers = loadSubscribers();
  const preferences = loadPreferences();
  const parsed = parseSubscriptionCommand(command);
  if (parsed.action === "status") {
    await sendSipMessage(endpoint, commandStatus(endpoint, preferences));
    appendCommandLog({ direction: "result", ok: true, endpoint, command: "STATUS" });
    return { ok: true, command: "STATUS" };
  }
  if (parsed.action === "sub" || parsed.action === "unsub") {
    const nextPreferences = updateEndpointSubscription(preferences, endpoint, parsed.action, parsed.categories);
    savePreferences(nextPreferences);
    const text = commandStatus(endpoint, nextPreferences);
    await sendSipMessage(endpoint, `${parsed.action === "sub" ? "Subscribed" : "Unsubscribed"} ${parsed.categories.join(", ")}.\n${text}`);
    appendCommandLog({ direction: "result", ok: true, endpoint, command: parsed.action.toUpperCase(), categories: parsed.categories });
    return { ok: true, command: parsed.action.toUpperCase(), categories: parsed.categories };
  }
  if (["SUB", "SUBSCRIBE", "START"].includes(command)) {
    subscribers.add(endpoint);
    saveSubscribers(subscribers);
    await sendSipMessage(endpoint, `Subscribed to n8n automation alerts for ${config.office}.`);
    appendCommandLog({ direction: "result", ok: true, endpoint, command: "SUB" });
    return { ok: true, command: "SUB" };
  }
  if (["UNSUB", "UNSUBSCRIBE", "STOP"].includes(command)) {
    subscribers.delete(endpoint);
    saveSubscribers(subscribers);
    await sendSipMessage(endpoint, `Unsubscribed from n8n automation alerts for ${config.office}.`);
    appendCommandLog({ direction: "result", ok: true, endpoint, command: "UNSUB" });
    return { ok: true, command: "UNSUB" };
  }
  await sendSipMessage(endpoint, commandHelp());
  appendCommandLog({ direction: "result", ok: true, endpoint, command: "HELP" });
  return { ok: true, command: "HELP" };
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on("data", chunk => chunks.push(chunk));
    req.on("end", () => {
      try {
        const text = Buffer.concat(chunks).toString("utf8");
        resolve(text ? JSON.parse(text) : {});
      } catch (error) {
        reject(error);
      }
    });
    req.on("error", reject);
  });
}

function sendJson(res, statusCode, payload) {
  const body = Buffer.from(JSON.stringify(payload));
  res.writeHead(statusCode, { "content-type": "application/json", "content-length": body.length });
  res.end(body);
}

function authorized(req) {
  if (!config.apiToken) return true;
  return req.headers.authorization === `Bearer ${config.apiToken}` || req.headers["x-api-token"] === config.apiToken;
}

async function serve() {
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, `http://${config.httpHost}:${config.httpPort}`);
      if (req.method === "GET" && url.pathname === "/health") {
        sendJson(res, 200, { ok: true, office: config.office, defaultEndpointCount: config.defaultExtensions.length, subscriberCount: loadSubscribers().size });
        return;
      }
      if (!authorized(req)) {
        sendJson(res, 401, { ok: false, error: "unauthorized" });
        return;
      }
      if (req.method === "POST" && url.pathname === "/v1/notify") {
        sendJson(res, 200, await notify(await readJson(req)));
        return;
      }
      if (req.method === "GET" && url.pathname === "/v1/preferences") {
        sendJson(res, 200, { ok: true, office: config.office, preferences: loadPreferences(), subscribers: [...loadSubscribers()].sort(), categories: knownCategories });
        return;
      }
      if (req.method === "POST" && url.pathname === "/v1/preferences") {
        sendJson(res, 200, applyPreferenceUpdate(await readJson(req)));
        return;
      }
      if (req.method === "POST" && url.pathname === "/v1/route") {
        sendJson(res, 200, routeDecision(await readJson(req)));
        return;
      }
      sendJson(res, 404, { ok: false, error: "not_found" });
    } catch (error) {
      sendJson(res, 400, { ok: false, error: error.message });
    }
  });
  server.listen(config.httpPort, config.httpHost, () => {
    console.log(`${new Date().toISOString()} office automation SIP notifier listening on ${config.httpHost}:${config.httpPort}`);
  });
}

async function main() {
  const mode = process.argv[2] || "serve";
  if (mode === "check-config") {
    validateConfig();
    console.log(JSON.stringify({ ok: true }));
    return;
  }
  validateConfig();
  if (mode === "serve") return serve();
  if (mode === "message-event") {
    const result = await handleMessageEvent(decodeArg(process.argv[3]), decodeArg(process.argv[5]));
    console.log(JSON.stringify(result));
    return;
  }
  if (mode === "forward-message") {
    const result = await forwardMessageToRemoteN8n(process.argv[3], decodeArg(process.argv[4]), decodeArg(process.argv[6]));
    console.log(JSON.stringify(result));
    return;
  }
  if (mode === "send") {
    const payload = JSON.parse(process.argv[3] || "{}");
    console.log(JSON.stringify(await notify(payload)));
    return;
  }
  throw new Error("Usage: office-automation-sip-notifier.cjs [check-config|serve|message-event <from64> <to64> <body64>|forward-message <remote-endpoint> <from64> <to64> <body64>|send <json>]");
}

module.exports = {
  categoryMatches,
  channelFlagEnabled,
  commandLogRecord,
  endpointAllowed,
  eventCategories,
  explicitRequestedChannels,
  normalizeChannel,
  notify,
  payloadRequestsChannel,
  routeDecision,
  validateConfig,
};

if (require.main === module) {
  main().catch(error => {
    console.error(error.stack || error.message);
    process.exit(1);
  });
}
