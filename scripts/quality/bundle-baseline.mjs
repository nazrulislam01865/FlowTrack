#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const root = path.resolve(import.meta.dirname, '../..');
const manifestPath = path.join(root, 'public/build/manifest.json');
const outputPath = path.join(root, 'quality/frontend-bundle-baseline.json');

function bytes(file) {
  return fs.statSync(file).size;
}

function gzipBytes(file) {
  return zlib.gzipSync(fs.readFileSync(file), { level: 9 }).length;
}

const sourceFiles = [
  'resources/css/application/core.css',
  'resources/css/application/after-core.css',
  'resources/css/application/after-dashboard.css',
  'resources/css/app.css',
  'resources/css/login.css',
  'resources/css/components/management-theme.css',
  'resources/js/app.js',
].filter((relative) => fs.existsSync(path.join(root, relative)));

const source = Object.fromEntries(sourceFiles.map((relative) => {
  const file = path.join(root, relative);
  return [relative, { bytes: bytes(file), gzip_bytes: gzipBytes(file) }];
}));

const build = {};
if (fs.existsSync(manifestPath)) {
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  for (const [entry, metadata] of Object.entries(manifest)) {
    if (!metadata?.file) continue;
    const file = path.join(root, 'public/build', metadata.file);
    if (!fs.existsSync(file)) continue;
    build[entry] = {
      file: metadata.file,
      bytes: bytes(file),
      gzip_bytes: gzipBytes(file),
    };
  }
}

const totals = Object.values(build).reduce((acc, item) => {
  acc.bytes += item.bytes;
  acc.gzip_bytes += item.gzip_bytes;
  return acc;
}, { bytes: 0, gzip_bytes: 0 });

const payload = {
  schema: 1,
  generated_at: new Date().toISOString(),
  source,
  build,
  build_totals: totals,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(payload, null, 2)}\n`);
console.log(`Wrote ${path.relative(root, outputPath)}`);
