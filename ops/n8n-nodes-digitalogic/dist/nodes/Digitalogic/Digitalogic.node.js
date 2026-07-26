"use strict";

const fs = require("node:fs");
const crypto = require("node:crypto");

function parseObject(value, fallback) {
  if (value && typeof value === "object" && !Array.isArray(value)) return value;
  if (!value) return fallback;
  const parsed = JSON.parse(String(value));
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    throw new Error("Expected a JSON object.");
  }
  return parsed;
}

function parseArray(value) {
  if (Array.isArray(value)) return value;
  if (!value) return [];
  const parsed = JSON.parse(String(value));
  if (!Array.isArray(parsed)) throw new Error("Expected a JSON array.");
  return parsed;
}

function safeBaseUrl(value) {
  const url = new URL(String(value || ""));
  if (url.protocol !== "https:" && !(url.protocol === "http:" && ["127.0.0.1", "localhost"].includes(url.hostname))) {
    throw new Error("Digitalogic API must use HTTPS, except for an explicit loopback endpoint.");
  }
  return url.toString().replace(/\/+$/, "");
}

function parseCsvLine(line) {
  const values = [];
  let value = "";
  let quoted = false;
  for (let index = 0; index < line.length; index += 1) {
    const char = line[index];
    if (char === "\"") {
      if (quoted && line[index + 1] === "\"") {
        value += "\"";
        index += 1;
      } else {
        quoted = !quoted;
      }
    } else if (char === "," && !quoted) {
      values.push(value);
      value = "";
    } else {
      value += char;
    }
  }
  values.push(value);
  return values;
}

function phoneDigits(value) {
  const digits = String(value || "").replace(/\D+/g, "");
  if (/^\d{8}$/.test(digits)) return `9821${digits}`;
  if (/^0098\d{10}$/.test(digits)) return digits.slice(2);
  if (/^098\d{10}$/.test(digits)) return digits.slice(1);
  if (/^98\d{10}$/.test(digits)) return digits;
  if (/^0\d{10}$/.test(digits)) return `98${digits.slice(1)}`;
  return digits;
}

function readCdrHistory(phone, filePath = process.env.DIGITALOGIC_ASTERISK_CDR_PATH || "/var/log/asterisk/cdr-csv/Master.csv") {
  const target = phoneDigits(phone);
  if (!/^98\d{10}$/.test(target)) throw new Error("A supported Iranian caller number is required.");
  const stat = fs.statSync(filePath);
  if (!stat.isFile() || stat.size > 20 * 1024 * 1024) throw new Error("Asterisk CDR source is unavailable or exceeds the bounded reader limit.");
  const calls = [];
  for (const line of fs.readFileSync(filePath, "utf8").split(/\r?\n/)) {
    if (!line) continue;
    const row = parseCsvLine(line);
    if (row.length < 16) continue;
    const src = phoneDigits(row[1]);
    const dst = phoneDigits(row[2]);
    if (src !== target && dst !== target) continue;
    calls.push({
      direction: src === target ? "inbound" : "outbound",
      started_at: String(row[9] || ""),
      answered_at: String(row[10] || ""),
      ended_at: String(row[11] || ""),
      duration_seconds: Number(row[12] || 0),
      billable_seconds: Number(row[13] || 0),
      disposition: String(row[14] || "").toLowerCase(),
      unique_id: String(row[16] || "").slice(0, 80),
    });
  }
  calls.sort((left, right) => String(right.started_at).localeCompare(String(left.started_at)));
  return { available: true, calls: calls.slice(0, 20), source: "asterisk_cdr" };
}

class Digitalogic {
  constructor() {
    this.description = {
      displayName: "Digitalogic",
      name: "digitalogic",
      icon: "file:digitalogic.svg",
      group: ["transform"],
      version: 1,
      subtitle: "={{$parameter[\"operation\"]}}",
      description: "Send actionable notifications and resolve Digitalogic presence or caller context",
      defaults: { name: "Digitalogic" },
      inputs: ["main"],
      outputs: ["main"],
      usableAsTool: true,
      credentials: [{ name: "digitalogicApi", required: true }],
      properties: [
        {
          displayName: "Operation",
          name: "operation",
          type: "options",
          noDataExpression: true,
          options: [
            { name: "Send Actionable Notification", value: "notify", action: "Send an actionable notification" },
            { name: "Record Presence Evidence", value: "presenceEvidence", action: "Record presence evidence" },
            { name: "Get Presence", value: "getPresence", action: "Get presence" },
            { name: "Resolve Caller Context", value: "callerContext", action: "Resolve caller context" },
            { name: "Get Notification Responses", value: "getResponse", action: "Get notification responses" },
          ],
          default: "notify",
        },
        {
          displayName: "Title",
          name: "title",
          type: "string",
          default: "",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Message",
          name: "message",
          type: "string",
          typeOptions: { rows: 4 },
          default: "",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Level",
          name: "level",
          type: "options",
          options: [
            { name: "Info", value: "info" },
            { name: "Success", value: "success" },
            { name: "Warning", value: "warning" },
            { name: "Error", value: "error" },
          ],
          default: "info",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Audience (JSON)",
          name: "audience",
          type: "json",
          default: "{}",
          description: "Explicit users, devices, operators, or broadcast=true",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Actions (JSON)",
          name: "actions",
          type: "json",
          default: "[{\"id\":\"acknowledge\",\"label\":\"Acknowledge\",\"style\":\"primary\"}]",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Query Fields (JSON)",
          name: "fields",
          type: "json",
          default: "[]",
          displayOptions: { show: { operation: ["notify"] } },
        },
        {
          displayName: "Correlation ID",
          name: "correlationId",
          type: "string",
          default: "={{$json.correlation_id || $execution.id}}",
          displayOptions: { show: { operation: ["notify", "getResponse"] } },
        },
        {
          displayName: "Operator Key",
          name: "operatorKey",
          type: "string",
          default: "",
          description: "Used for manual evidence and presence reads. RouterOS evidence is resolved from the server-side device mapping.",
          displayOptions: { show: { operation: ["presenceEvidence", "getPresence"] } },
        },
        {
          displayName: "Router Device Subject",
          name: "routerSubject",
          type: "string",
          default: "={{$json.mac || $json.subject || \"\"}}",
          description: "MAC or another stable router identity. Only its SHA-256 digest leaves this node.",
          displayOptions: { show: { operation: ["presenceEvidence"] } },
        },
        {
          displayName: "Device ID",
          name: "deviceId",
          type: "string",
          default: "",
          displayOptions: { show: { operation: ["presenceEvidence", "getPresence"] } },
        },
        {
          displayName: "Evidence Source",
          name: "source",
          type: "options",
          options: [
            { name: "RouterOS DHCP", value: "routeros_dhcp" },
            { name: "RouterOS Wi-Fi", value: "routeros_wifi" },
            { name: "RouterOS ARP", value: "routeros_arp" },
            { name: "Manual", value: "manual" },
          ],
          default: "routeros_dhcp",
          displayOptions: { show: { operation: ["presenceEvidence"] } },
        },
        {
          displayName: "State",
          name: "state",
          type: "string",
          default: "={{$json.bound === true ? \"bound\" : ($json.bound === false ? \"unbound\" : ($json.state || \"unknown\"))}}",
          displayOptions: { show: { operation: ["presenceEvidence"] } },
        },
        {
          displayName: "Observed At",
          name: "observedAt",
          type: "string",
          default: "={{$json.observed_at || $now.toISO()}}",
          displayOptions: { show: { operation: ["presenceEvidence"] } },
        },
        {
          displayName: "Metadata (JSON)",
          name: "metadata",
          type: "json",
          default: "={{JSON.stringify($json.metadata || {})}}",
          displayOptions: { show: { operation: ["presenceEvidence"] } },
        },
        {
          displayName: "Caller Number",
          name: "phone",
          type: "string",
          default: "={{$json.caller || $json.phone || \"\"}}",
          displayOptions: { show: { operation: ["callerContext"] } },
        },
        {
          displayName: "Include Asterisk History",
          name: "includeCallHistory",
          type: "boolean",
          default: true,
          displayOptions: { show: { operation: ["callerContext"] } },
        },
      ],
    };
  }

  async execute() {
    const items = this.getInputData();
    const credentials = await this.getCredentials("digitalogicApi");
    const baseUrl = safeBaseUrl(credentials.baseUrl);
    const output = [];

    for (let index = 0; index < items.length; index += 1) {
      const operation = this.getNodeParameter("operation", index);
      let result;
      if (operation === "notify") {
        const body = {
          title: this.getNodeParameter("title", index),
          message: this.getNodeParameter("message", index),
          level: this.getNodeParameter("level", index),
          audience: parseObject(this.getNodeParameter("audience", index), {}),
          actions: parseArray(this.getNodeParameter("actions", index)),
          fields: parseArray(this.getNodeParameter("fields", index)),
          correlation_id: this.getNodeParameter("correlationId", index),
          source: "n8n",
        };
        result = await this.helpers.httpRequestWithAuthentication.call(this, "digitalogicApi", {
          method: "POST",
          url: `${baseUrl}/event-mesh/notify`,
          body,
          json: true,
        });
      } else if (operation === "presenceEvidence") {
        const source = this.getNodeParameter("source", index);
        const subject = String(this.getNodeParameter("routerSubject", index) || "").toLowerCase().replace(/[^a-f0-9]+/g, "");
        result = await this.helpers.httpRequestWithAuthentication.call(this, "digitalogicApi", {
          method: "POST",
          url: `${baseUrl}/event-mesh/presence`,
          body: {
            operator_key: this.getNodeParameter("operatorKey", index),
            device_id: this.getNodeParameter("deviceId", index),
            subject_hash: subject ? crypto.createHash("sha256").update(subject).digest("hex") : "",
            source,
            state: this.getNodeParameter("state", index),
            observed_at: this.getNodeParameter("observedAt", index),
            metadata: parseObject(this.getNodeParameter("metadata", index), {}),
          },
          json: true,
        });
      } else if (operation === "getPresence") {
        result = await this.helpers.httpRequestWithAuthentication.call(this, "digitalogicApi", {
          method: "GET",
          url: `${baseUrl}/event-mesh/presence`,
          qs: {
            operator_key: this.getNodeParameter("operatorKey", index),
            device_id: this.getNodeParameter("deviceId", index),
          },
          json: true,
        });
      } else if (operation === "callerContext") {
        const phone = this.getNodeParameter("phone", index);
        const customer = await this.helpers.httpRequestWithAuthentication.call(this, "digitalogicApi", {
          method: "POST",
          url: `${baseUrl}/event-mesh/caller-context`,
          body: { phone },
          json: true,
        });
        let callHistory = { calls: [], available: false };
        if (this.getNodeParameter("includeCallHistory", index)) {
          try {
            callHistory = readCdrHistory(phone);
          } catch {
            callHistory = { calls: [], available: false, reason: "asterisk_cdr_unavailable" };
          }
        }
        const externalCandidates = Array.isArray(items[index].json.external_candidates)
          ? items[index].json.external_candidates.slice(0, 50)
          : [];
        result = {
          ...customer,
          call_history: callHistory,
          external_candidates: externalCandidates,
          _event: {
            correlation_id: String(items[index].json.correlation_id || ""),
            event_type: String(items[index].json.event_type || ""),
            direction: String(items[index].json.direction || ""),
          },
        };
      } else if (operation === "getResponse") {
        const correlationId = encodeURIComponent(this.getNodeParameter("correlationId", index));
        result = await this.helpers.httpRequestWithAuthentication.call(this, "digitalogicApi", {
          method: "GET",
          url: `${baseUrl}/event-mesh/responses/${correlationId}`,
          json: true,
        });
      } else {
        throw new Error(`Unsupported Digitalogic operation: ${operation}`);
      }

      output.push({ json: result && typeof result === "object" ? result : { result }, pairedItem: { item: index } });
    }

    return [output];
  }
}

module.exports = {
  Digitalogic,
  parseArray,
  parseObject,
  parseCsvLine,
  phoneDigits,
  readCdrHistory,
  safeBaseUrl,
};
