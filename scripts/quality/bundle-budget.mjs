#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const root = path.resolve(import.meta.dirname, '../..');
const manifestPath = path.join(root, 'public/build/manifest.json');
const budgetPath = path.join(root, 'quality/frontend-bundle-budgets.json');
if (!fs.existsSync(manifestPath)) {
  console.error('Missing public/build/manifest.json. Run npm run build before the bundle budget.');
  process.exit(2);
}
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const budget = JSON.parse(fs.readFileSync(budgetPath, 'utf8'));
const files = new Set();
for (const metadata of Object.values(manifest)) {
  if (metadata?.file) files.add(metadata.file);
  for (const file of metadata?.css || []) files.add(file);
  for (const file of metadata?.assets || []) files.add(file);
}
const gzipSize = (relative) => zlib.gzipSync(fs.readFileSync(path.join(root, 'public/build', relative)), { level: 9 }).length;
let total = 0;
let maxSingle = 0;
for (const file of files) {
  const absolute = path.join(root, 'public/build', file);
  if (!fs.existsSync(absolute)) continue;
  const gzip = gzipSize(file);
  total += gzip;
  maxSingle = Math.max(maxSingle, gzip);
}
const failures = [];
if (total > budget.total_gzip_bytes) failures.push(`total gzip ${total} > ${budget.total_gzip_bytes}`);
if (maxSingle > budget.max_single_asset_gzip_bytes) failures.push(`largest asset gzip ${maxSingle} > ${budget.max_single_asset_gzip_bytes}`);
for (const [entry, ceiling] of Object.entries(budget.entries || {})) {
  const metadata = manifest[entry];
  if (!metadata?.file) {
    failures.push(`manifest entry missing: ${entry}`);
    continue;
  }
  const gzip = gzipSize(metadata.file);
  if (gzip > ceiling) failures.push(`${entry} gzip ${gzip} > ${ceiling}`);
  console.log(`${entry}: ${gzip} gzip bytes (ceiling ${ceiling})`);
}
console.log(`Total manifest assets: ${total} gzip bytes; largest single asset: ${maxSingle}`);
if (failures.length) {
  console.error('Bundle budget FAILED:\n - ' + failures.join('\n - '));
  process.exit(1);
}
console.log('Bundle budget PASS');
