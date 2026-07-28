import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(root, 'public/js/fleetman.js');
const recordApiPath = path.join(root, 'public/js/fleetman-record-api.js');
const outputDir = path.join(root, 'resources/js/fleetman/generated');

const markers = {
    people: '/* Vendor / Party and Large List Trip page logic. Kept separate so existing FleetMan pages stay untouched. */',
    master: '/* Master Data page logic: dynamic database-backed lookup rows for app-wide dropdowns. */',
    contracts: '/* Contract page logic: dynamic database-backed contract form, assignments, documents, and list. */',
};
const operationsMarker = "\n\n(function () {\n    'use strict';\n\n    const data = window.FLEETMAN || {};\n    const options = data.options || {};\n    const samples = data.samples || {};\n    const records = data.records || samples || {};\n    const resources = data.resources || {};\n";

const source = await readFile(sourcePath, 'utf8');
const positions = {
    operations: source.indexOf(operationsMarker),
    people: source.indexOf(markers.people),
    master: source.indexOf(markers.master),
    contracts: source.indexOf(markers.contracts),
};

for (const [name, position] of Object.entries(positions)) {
    if (position < 0) throw new Error(`Unable to locate FleetMan ${name} asset boundary.`);
}
if (!(positions.operations < positions.people && positions.people < positions.master && positions.master < positions.contracts)) {
    throw new Error('FleetMan asset boundaries are out of order.');
}

await mkdir(outputDir, { recursive: true });
const assets = {
    core: source.slice(0, positions.operations),
    operations: source.slice(positions.operations, positions.people),
    people: source.slice(positions.people, positions.master),
    master: source.slice(positions.master, positions.contracts),
    contracts: source.slice(positions.contracts),
    'record-api': await readFile(recordApiPath, 'utf8'),
};

for (const [name, content] of Object.entries(assets)) {
    await writeFile(path.join(outputDir, `${name}.js`), `${content.trim()}\n`, 'utf8');
}

console.log(`Split FleetMan into ${Object.keys(assets).length} build entries.`);
