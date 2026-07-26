"use strict";

const fs = require("node:fs");
const path = require("node:path");

const FILTER_NAME = "Suppress Routine Presence From Office Audit";
const DISPATCH_NAME = "Dispatch to Digitalogic Event Mesh";

function required(env, key) {
  const value = String(env[key] || "").trim();
  if (!value) throw new Error(`${key} is required.`);
  return value;
}

function patch(workflow, options) {
  if (!workflow || typeof workflow !== "object" || !Array.isArray(workflow.nodes)) {
    throw new Error("The Office workflow JSON is invalid.");
  }
  const next = JSON.parse(JSON.stringify(workflow));
  next.connections = next.connections && typeof next.connections === "object" ? next.connections : {};
  const webhook = next.nodes.find(
    (node) => node && node.type === "n8n-nodes-base.webhook" && node.name === "Office Automation Events Webhook",
  );
  if (!webhook) throw new Error("The reviewed Office Automation webhook node was not found.");

  const filterCode = String(options.filterCode || "").trim();
  const childWorkflowId = String(options.childWorkflowId || "").trim();
  if (!filterCode || !/^[A-Za-z0-9_-]{1,100}$/.test(childWorkflowId)) {
    throw new Error("A valid child workflow ID and filter code are required.");
  }

  const existingMain = next.connections[webhook.name]?.main?.[0];
  if (!Array.isArray(existingMain)) throw new Error("The Office webhook has no reviewed main connection.");

  let filterNode = next.nodes.find((node) => node && node.name === FILTER_NAME);
  let dispatchNode = next.nodes.find((node) => node && node.name === DISPATCH_NAME);
  const originalTargets = filterNode
    ? next.connections[FILTER_NAME]?.main?.[0]
    : existingMain.filter((connection) => connection.node !== FILTER_NAME && connection.node !== DISPATCH_NAME);
  if (!Array.isArray(originalTargets) || originalTargets.length < 1) {
    throw new Error("The original Office workflow branch could not be preserved.");
  }

  if (!filterNode) {
    filterNode = {
      parameters: { mode: "runOnceForAllItems", jsCode: filterCode },
      id: "digitalogic-event-mesh-audit-filter",
      name: FILTER_NAME,
      type: "n8n-nodes-base.code",
      typeVersion: 2,
      position: [webhook.position[0] + 260, webhook.position[1]],
    };
    next.nodes.push(filterNode);
  } else {
    filterNode.parameters = { mode: "runOnceForAllItems", jsCode: filterCode };
  }

  const dispatchParameters = {
    workflowId: {
      __rl: true,
      value: childWorkflowId,
      mode: "list",
      cachedResultName: "Digitalogic - Event Mesh Presence and Caller Context",
    },
    workflowInputs: {
      mappingMode: "defineBelow",
      value: {},
      matchingColumns: [],
      schema: [],
      attemptToConvertTypes: false,
      convertFieldsToString: true,
    },
    mode: "once",
    options: { waitForSubWorkflow: false },
  };
  if (!dispatchNode) {
    dispatchNode = {
      parameters: dispatchParameters,
      id: "digitalogic-event-mesh-dispatch",
      name: DISPATCH_NAME,
      type: "n8n-nodes-base.executeWorkflow",
      typeVersion: 1.2,
      position: [webhook.position[0] + 260, webhook.position[1] - 220],
    };
    next.nodes.push(dispatchNode);
  } else {
    dispatchNode.parameters = dispatchParameters;
  }

  next.connections[webhook.name] = {
    main: [[
      { node: FILTER_NAME, type: "main", index: 0 },
      { node: DISPATCH_NAME, type: "main", index: 0 },
    ]],
  };
  next.connections[FILTER_NAME] = { main: [originalTargets] };
  delete next.connections[DISPATCH_NAME];
  next.settings = next.settings && typeof next.settings === "object" ? next.settings : {};
  next.settings.saveDataSuccessExecution = "none";
  next.settings.saveDataErrorExecution = "none";
  next.settings.saveExecutionProgress = false;
  return next;
}

function main() {
  const input = path.resolve(required(process.env, "OFFICE_WORKFLOW_INPUT"));
  const output = path.resolve(required(process.env, "OFFICE_WORKFLOW_OUTPUT"));
  const filterCode = fs.readFileSync(path.join(__dirname, "..", "n8n", "filter-parent-audit.code.js"), "utf8");
  const exported = JSON.parse(fs.readFileSync(input, "utf8"));
  if (Array.isArray(exported) && exported.length !== 1) {
    throw new Error("The Office workflow export must contain exactly one workflow.");
  }
  const workflow = Array.isArray(exported) ? exported[0] : exported;
  const patched = patch(workflow, {
    childWorkflowId: required(process.env, "EVENT_MESH_WORKFLOW_ID"),
    filterCode,
  });
  fs.mkdirSync(path.dirname(output), { recursive: true, mode: 0o700 });
  const importPayload = Array.isArray(exported) ? [patched] : patched;
  fs.writeFileSync(output, `${JSON.stringify(importPayload, null, 2)}\n`, { mode: 0o600 });
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

module.exports = { DISPATCH_NAME, FILTER_NAME, patch };
