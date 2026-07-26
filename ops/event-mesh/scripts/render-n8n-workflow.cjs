"use strict";

const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..");
const templatePath = path.join(root, "n8n", "digitalogic-event-mesh.template.json");

function required(env, key) {
  const value = String(env[key] || "").trim();
  if (!value) throw new Error(`${key} is required.`);
  return value;
}

function callerIds(env) {
  const ids = required(env, "EVENT_MESH_CALLER_IDS")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
  if (
    ids.length < 1
    || ids.length > 10
    || ids.some((value) => !/^[A-Za-z0-9_-]{1,100}$/.test(value))
    || new Set(ids).size !== ids.length
  ) {
    throw new Error("EVENT_MESH_CALLER_IDS must contain 1-10 unique n8n workflow IDs.");
  }
  return ids.join(",");
}

function render(env = process.env) {
  const audience = JSON.parse(required(env, "EVENT_MESH_CALLER_AUDIENCE_JSON"));
  if (!audience || typeof audience !== "object" || Array.isArray(audience)) {
    throw new Error("EVENT_MESH_CALLER_AUDIENCE_JSON must be an object.");
  }
  const replacements = {
    __EVENT_MESH_WORKFLOW_ID__: required(env, "EVENT_MESH_WORKFLOW_ID"),
    __DIGITALOGIC_NODE_TYPE__: String(env.EVENT_MESH_NODE_TYPE || "n8n-nodes-digitalogic.digitalogic"),
    __DIGITALOGIC_CREDENTIAL_ID__: required(env, "EVENT_MESH_CREDENTIAL_ID"),
    __DIGITALOGIC_CREDENTIAL_NAME__: required(env, "EVENT_MESH_CREDENTIAL_NAME"),
    __CALLER_NOTIFICATION_AUDIENCE_JSON__: JSON.stringify(audience),
    __EVENT_MESH_CALLER_IDS__: callerIds(env),
  };
  let source = fs.readFileSync(templatePath, "utf8");
  const code = {
    __ROUTE_EVENT_CODE__: fs.readFileSync(path.join(root, "n8n", "route-event.code.js"), "utf8").trim(),
    __BUILD_CALLER_NOTIFICATION_CODE__: fs.readFileSync(path.join(root, "n8n", "build-caller-notification.code.js"), "utf8").trim(),
  };
  for (const [placeholder, value] of Object.entries(code)) {
    source = source.replace(JSON.stringify(placeholder), JSON.stringify(value));
  }
  for (const [placeholder, value] of Object.entries(replacements)) {
    source = source.split(placeholder).join(value);
  }
  const workflow = JSON.parse(source);
  workflow.active = false;
  return workflow;
}

function main() {
  const output = path.resolve(required(process.env, "EVENT_MESH_WORKFLOW_OUTPUT"));
  fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
  fs.writeFileSync(output, `${JSON.stringify(render(), null, 2)}\n`, { mode: 0o600 });
  process.stdout.write(`${output}\n`);
}

if (require.main === module) {
  try {
    main();
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}

module.exports = { callerIds, render };
