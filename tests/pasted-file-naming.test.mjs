import assert from "node:assert/strict";
import test from "node:test";

import { preparePastedProjectFile } from "../assets/pasted-file-naming.mjs";

const fixedTimestamp = new Date(2026, 9, 12, 6, 9, 32, 123);
const expectedBase = "screenshot-20261012-060932-123";

function png(name = "image.png", type = "image/png") {
  return new File(["png-bytes"], name, { type, lastModified: 1234 });
}

test("exact generic pasted PNG screenshot receives a sortable timestamp name", async () => {
  const source = png();
  const prepared = preparePastedProjectFile(source, new Set(), fixedTimestamp);

  assert.equal(prepared.name, `${expectedBase}.png`);
  assert.equal(prepared.type, source.type);
  assert.equal(prepared.lastModified, source.lastModified);
  assert.deepEqual(Buffer.from(await prepared.arrayBuffer()), Buffer.from(await source.arrayBuffer()));
});

test("known-folder and further in-batch collisions advance deterministically", () => {
  const usedNames = new Set([`${expectedBase}.png`]);

  assert.equal(preparePastedProjectFile(png(), usedNames, fixedTimestamp).name, `${expectedBase}-2.png`);
  assert.equal(preparePastedProjectFile(png(), usedNames, fixedTimestamp).name, `${expectedBase}-3.png`);
});

test("case variants and meaningful filenames remain unchanged", () => {
  for (const name of ["Image.png", "IMAGE.PNG", "quarterly-chart.png"]) {
    const source = png(name);
    assert.equal(preparePastedProjectFile(source, new Set(), fixedTimestamp), source);
  }
});

test("generic filename with a non-PNG MIME remains unchanged", () => {
  const source = png("image.png", "application/octet-stream");
  assert.equal(preparePastedProjectFile(source, new Set(), fixedTimestamp), source);
});
