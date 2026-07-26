"use strict";

const fs = require("node:fs");
const path = require("node:path");

function required(env, key) {
  const value = String(env[key] || "").trim();
  if (!value) throw new Error(`${key} is required.`);
  return value;
}

function render(endpoint, subjectPepper) {
  const source = String(endpoint);
  if (/["\r\n]/.test(source)) {
    throw new Error("The Office Automation webhook URL contains control characters.");
  }
  const url = new URL(source);
  if (url.protocol !== "https:") {
    throw new Error("The Office Automation webhook must be an HTTPS URL without control characters.");
  }
  const pepper = String(subjectPepper || "").trim();
  if (!/^[A-Za-z0-9_-]{32,128}$/.test(pepper)) {
    throw new Error("The RouterOS subject pepper must be a 32-128 character base64url value.");
  }
  const template = fs.readFileSync(path.join(__dirname, "..", "routeros", "dhcp-lease-script.rsc"), "utf8");
  return template
    .replace("__OFFICE_AUTOMATION_WEBHOOK_URL__", url.toString())
    .replace("__ROUTEROS_SUBJECT_PEPPER__", pepper);
}

function main() {
  const output = path.resolve(required(process.env, "ROUTEROS_SCRIPT_OUTPUT"));
  fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
  fs.writeFileSync(
    output,
    render(
      required(process.env, "EVENT_MESH_OFFICE_WEBHOOK_URL"),
      required(process.env, "EVENT_MESH_ROUTER_SUBJECT_PEPPER"),
    ),
    { mode: 0o600 },
  );
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

module.exports = { render };
