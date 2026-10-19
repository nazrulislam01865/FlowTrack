#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(import.meta.dirname, '../..');
const config = JSON.parse(fs.readFileSync(path.join(root, 'quality/visual-scenarios.json'), 'utf8'));
const baselineDir = path.join(root, 'tests/Visual/baselines');
const expected = [];
for (const scenario of config.scenarios) {
  for (const viewport of Object.keys(config.viewports)) expected.push(`${scenario.name}-${viewport}.png`);
}
const missing = expected.filter((name) => !fs.existsSync(path.join(baselineDir, name)));
if (missing.length) {
  console.error(`Visual baseline gate FAILED: ${missing.length}/${expected.length} approved baseline(s) are missing.`);
  console.error('Capture with npm run visual:update on stable seeded data, review manually, then commit the approved images.');
  process.exit(1);
}
console.log(`Visual baseline gate PASS: ${expected.length} approved screenshots present.`);
