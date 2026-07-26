"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const { spawnSync } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { render } = require("../scripts/render-n8n-workflow.cjs");
const { patch, FILTER_NAME, DISPATCH_NAME } = require("../scripts/patch-office-workflow.cjs");
const { render: renderRouterOs } = require("../scripts/render-routeros-script.cjs");

test("renders an inactive, credential-referenced event workflow", () => {
  const workflow = render({
    EVENT_MESH_WORKFLOW_ID: "synthetic-event-mesh",
    EVENT_MESH_CREDENTIAL_ID: "synthetic-credential",
    EVENT_MESH_CREDENTIAL_NAME: "Synthetic Digitalogic Credential",
    EVENT_MESH_CALLER_AUDIENCE_JSON: '{"devices":["synthetic-workstation"]}',
  });
  assert.equal(workflow.active, false);
  assert.equal(workflow.nodes.some((node) => node.type === "n8n-nodes-digitalogic.digitalogic"), true);
  assert.equal(JSON.stringify(workflow).includes("__"), false);
  assert.equal(JSON.stringify(workflow).includes("synthetic-workstation"), true);
});

test("tracked assets contain no concrete webhook path or credential secret", () => {
  const root = path.resolve(__dirname, "..");
  for (const file of [
    path.join(root, "n8n", "digitalogic-event-mesh.template.json"),
    path.join(root, "routeros", "dhcp-lease-script.rsc"),
  ]) {
    const content = fs.readFileSync(file, "utf8");
    assert.doesNotMatch(content, /https?:\/\/[^\s"']+\/webhook\/[A-Za-z0-9_-]+/i);
    assert.doesNotMatch(content, /(?:api[_-]?key|password|secret)\s*[:=]\s*["'][^_"'][^"']+/i);
  }
});

test("patches the Office workflow without losing its existing raw-event branch", () => {
  const workflow = {
    name: "Office - Automation Events",
    nodes: [
      {
        id: "webhook",
        name: "Office Automation Events Webhook",
        type: "n8n-nodes-base.webhook",
        position: [0, 0],
      },
      { id: "normalizer", name: "Normalize Office Event", type: "n8n-nodes-base.code", position: [300, 0] },
    ],
    connections: {
      "Office Automation Events Webhook": {
        main: [[{ node: "Normalize Office Event", type: "main", index: 0 }]],
      },
    },
  };
  const once = patch(workflow, { childWorkflowId: "event-mesh-id", filterCode: "return $input.all();" });
  const twice = patch(once, { childWorkflowId: "event-mesh-id", filterCode: "return $input.all();" });

  assert.equal(twice.nodes.filter((node) => node.name === FILTER_NAME).length, 1);
  assert.equal(twice.nodes.filter((node) => node.name === DISPATCH_NAME).length, 1);
  assert.deepEqual(twice.connections[FILTER_NAME].main[0], [
    { node: "Normalize Office Event", type: "main", index: 0 },
  ]);
  assert.equal(twice.connections["Office Automation Events Webhook"].main[0].length, 2);
  assert.equal(twice.settings.saveDataSuccessExecution, "none");
  assert.equal(twice.settings.saveDataErrorExecution, "none");
  assert.equal(twice.settings.saveExecutionProgress, false);
});

test("preserves the single-workflow export envelope during CLI patching", () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), "digitalogic-event-mesh-"));
  const input = path.join(directory, "office-export.json");
  const output = path.join(directory, "office-import.json");
  const workflow = {
    name: "Office - Automation Events",
    nodes: [
      {
        id: "webhook",
        name: "Office Automation Events Webhook",
        type: "n8n-nodes-base.webhook",
        position: [0, 0],
      },
      { id: "normalizer", name: "Normalize Office Event", type: "n8n-nodes-base.code", position: [300, 0] },
    ],
    connections: {
      "Office Automation Events Webhook": {
        main: [[{ node: "Normalize Office Event", type: "main", index: 0 }]],
      },
    },
  };

  try {
    fs.writeFileSync(input, `${JSON.stringify([workflow])}\n`, "utf8");
    const result = spawnSync(process.execPath, [path.join(__dirname, "..", "scripts", "patch-office-workflow.cjs")], {
      encoding: "utf8",
      env: {
        ...process.env,
        EVENT_MESH_WORKFLOW_ID: "synthetic-event-mesh",
        OFFICE_WORKFLOW_INPUT: input,
        OFFICE_WORKFLOW_OUTPUT: output,
      },
    });

    assert.equal(result.status, 0, result.stderr);
    const patched = JSON.parse(fs.readFileSync(output, "utf8"));
    assert.equal(Array.isArray(patched), true);
    assert.equal(patched.length, 1);
    assert.equal(patched[0].settings.saveDataSuccessExecution, "none");
    assert.equal(patched[0].settings.saveDataErrorExecution, "none");
    assert.equal(patched[0].settings.saveExecutionProgress, false);
  } finally {
    fs.rmSync(directory, { force: true, recursive: true });
  }
});

test("renders only an HTTPS RouterOS webhook target", () => {
  const rendered = renderRouterOs("https://automation.example.test/webhook/private-path");
  assert.match(rendered, /https:\/\/automation\.example\.test\/webhook\/private-path/);
  assert.throws(() => renderRouterOs("http://automation.example.test/webhook/path"), /HTTPS/);
  assert.throws(() => renderRouterOs("https://example.test/\"\n/tool fetch"), /control/);
});
