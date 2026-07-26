"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const {
  parseArray,
  parseObject,
  parseCsvLine,
  phoneDigits,
  readCdrHistory,
  safeBaseUrl,
} = require("../dist/nodes/Digitalogic/Digitalogic.node");

test("parses bounded configuration shapes", () => {
  assert.deepEqual(parseObject('{"operators":["shokri"]}', {}), { operators: ["shokri"] });
  assert.deepEqual(parseArray('[{"id":"ack"}]'), [{ id: "ack" }]);
  assert.throws(() => parseObject("[]", {}), /object/);
  assert.throws(() => parseArray("{}"), /array/);
});

test("requires TLS for non-loopback Digitalogic APIs", () => {
  assert.equal(safeBaseUrl("https://digitalogic.ir/wp-json/digitalogic/v1/"), "https://digitalogic.ir/wp-json/digitalogic/v1");
  assert.equal(safeBaseUrl("http://127.0.0.1:8080/"), "http://127.0.0.1:8080");
  assert.throws(() => safeBaseUrl("http://example.com/api"), /HTTPS/);
});

test("normalizes caller forms and parses quoted CDR rows", () => {
  assert.equal(phoneDigits("+98 (21) 1234-5678"), "982112345678");
  assert.equal(phoneDigits("02112345678"), "982112345678");
  assert.deepEqual(parseCsvLine('"","02112345678","100","from-trunk","Name, Example"'), ["", "02112345678", "100", "from-trunk", "Name, Example"]);
});

test("classifies customer-originated CDR rows as inbound", () => {
  const fixturePath = path.join(os.tmpdir(), `digitalogic-cdr-${process.pid}.csv`);
  fs.writeFileSync(
    fixturePath,
    [
      '"","09123456789","02166754124","","","","","","","2026-07-27 10:00:00","2026-07-27 10:00:02","2026-07-27 10:01:00","60","58","ANSWERED","","call-1"',
      '"","02166754124","09123456789","","","","","","","2026-07-27 09:00:00","","2026-07-27 09:00:10","10","0","NO ANSWER","","call-2"',
    ].join("\n"),
    "utf8",
  );
  try {
    const history = readCdrHistory("09123456789", fixturePath);
    assert.equal(history.calls[0].direction, "inbound");
    assert.equal(history.calls[1].direction, "outbound");
  } finally {
    fs.rmSync(fixturePath, { force: true });
  }
});
