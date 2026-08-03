import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const moduleDirectory = 'resources/js/flowtrack/modules';
const prototypePath = 'resources/js/flowtrack/prototype-fallback.js';
const outputDirectory = 'public/flowtrack/modules';
const manifestPath = 'resources/js/flowtrack/runtime-manifest.js';
const loaderSourcePath = 'resources/js/flowtrack/loader.js';
const searchableSourcePath = 'resources/js/flowtrack/searchable-dropdown.js';
const interactionSourcePath = 'resources/js/flowtrack/interaction-feedback.js';
const publicLoaderPath = 'public/flowtrack/loader.js';
const publicSearchablePath = 'public/flowtrack/searchable-dropdown.js';
const publicInteractionPath = 'public/flowtrack/interaction-feedback.js';

const files = {
    core: 'core.js',
    workspace: 'workspace.js',
    operations: 'operations.js',
    configuration: 'configuration.js',
    records: 'records.js',
    'job-shared': 'job-shared.js',
    'task-details': 'task-details.js',
    'job-details': 'job-details.js',
    board: 'board.js',
};

const prototype = readFileSync(prototypePath, 'utf8').replace(/\r\n/g, '\n').trim();
const moduleSources = Object.fromEntries(Object.entries(files).map(([name, filename]) => [
    name,
    readFileSync(join(moduleDirectory, filename), 'utf8').replace(/\r\n/g, '\n').trim(),
]));

const hash = createHash('sha256');
hash.update(prototype);
for (const name of Object.keys(files)) hash.update(`\n${name}\n${moduleSources[name]}`);
const build = hash.digest('hex').slice(0, 16);

mkdirSync(outputDirectory, { recursive: true });
for (const [name, filename] of Object.entries(files)) {
    const header = `/* FlowTrack runtime module: ${name}. Build: ${build}. */\n`;
    const source = name === 'core'
        ? `${prototype}\n\n${moduleSources[name]}\n`
        : `${moduleSources[name]}\n`;
    writeFileSync(join(outputDirectory, filename), header + source);
}

writeFileSync(
    manifestPath,
    `export const build = ${JSON.stringify(build)};\nexport const files = Object.freeze(${JSON.stringify(files, null, 4)});\n`,
);

// The runtime modules deliberately stay as ordered classic scripts because the
// preserved FlowTracker UI shares lexical state between modules. Keep the
// maintainable source loader in resources/ as ESM, but emit a self-contained
// production loader so Laravel never depends on a stale Vite JS manifest.
const loaderSource = readFileSync(loaderSourcePath, 'utf8').replace(/\r\n/g, '\n');
const loaderBody = loaderSource.replace(
    /^import \{ build, files \} from '\.\/runtime-manifest\.js';\s*/,
    '',
);
writeFileSync(
    publicLoaderPath,
    `/* Generated from resources/js/flowtrack/loader.js. Build: ${build}. */\nconst build = ${JSON.stringify(build)};\nconst files = Object.freeze(${JSON.stringify(files, null, 4)});\n\n${loaderBody}`,
);
copyFileSync(searchableSourcePath, publicSearchablePath);
copyFileSync(interactionSourcePath, publicInteractionPath);

console.log(`Built ${Object.keys(files).length} FlowTrack runtime modules (${build}) plus production loader assets.`);
