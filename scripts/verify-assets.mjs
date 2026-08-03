import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';

const required = [
    'public/build/manifest.json',
    'resources/views/flowtrack-template.blade.php',
    'resources/views/flowtrack/layouts/workspace.blade.php',
    'resources/views/flowtrack/pages/screen.blade.php',
    'resources/views/flowtrack/partials/assets.blade.php',
    'resources/js/flowtrack/loader.js',
    'resources/js/flowtrack/searchable-dropdown.js',
    'resources/js/flowtrack/prototype-fallback.js',
    'resources/js/flowtrack/runtime-manifest.js',
    'public/flowtrack/loader.js',
    'public/flowtrack/searchable-dropdown.js',
    'resources/css/flowtrack/app.css',
    'scripts/build-flowtrack-runtime.mjs',
    'routes/flowtrack.php',
    'app/Services/FlowTrack/PageResponseService.php',
];

const moduleNames = ['core', 'workspace', 'operations', 'configuration', 'records', 'job-shared', 'task-details', 'job-details', 'board'];
for (const name of moduleNames) {
    required.push(`resources/js/flowtrack/modules/${name}.js`);
    required.push(`public/flowtrack/modules/${name}.js`);
}

const missing = required.filter((path) => !existsSync(path) || statSync(path).size === 0);
if (missing.length) {
    console.error(`Missing required FlowTrack modular assets: ${missing.join(', ')}`);
    process.exit(1);
}

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
for (const entry of [
    'resources/css/flowtrack/app.css',
]) {
    if (!manifest[entry]) {
        console.error(`Vite manifest is missing ${entry}.`);
        process.exit(1);
    }
}

const sources = moduleNames.map((name) => readFileSync(`resources/js/flowtrack/modules/${name}.js`, 'utf8')).join('\n');
const built = moduleNames.map((name) => readFileSync(`public/flowtrack/modules/${name}.js`, 'utf8')).join('\n');
const blade = readFileSync('resources/views/flowtrack-template.blade.php', 'utf8');
const workspace = readFileSync('resources/views/flowtrack/layouts/workspace.blade.php', 'utf8');
const screen = readFileSync('resources/views/flowtrack/pages/screen.blade.php', 'utf8');
const assets = readFileSync('resources/views/flowtrack/partials/assets.blade.php', 'utf8');
const routes = readFileSync('routes/flowtrack.php', 'utf8');

const forbiddenLegacy = [
    'resources/js/flowtrack/flowtrack-dynamic-source.js',
    'public/flowtrack-dynamic.js',
    'public/flowtrack-livewire.js',
    'public/flowtrack-prototype-fallback.js',
    'public/flowtrack/modules/manifest.json',
    'app/Http/Controllers/FlowTrack/TemplatePageController.php',
    'scripts/split-flowtrack-assets.mjs',
];
const presentLegacy = forbiddenLegacy.filter(existsSync);
if (presentLegacy.length) {
    console.error(`Legacy compatibility assets still exist: ${presentLegacy.join(', ')}`);
    process.exit(1);
}

if (workspace.includes('<livewire:flow-track.workspace-shell') || blade.includes('@livewireScripts') || blade.includes('@livewireStyles')) {
    console.error('The legacy fake Livewire workspace shell is still mounted.');
    process.exit(1);
}
if (!workspace.includes("@include('flowtrack.pages.screen')") || !screen.includes('data-route-page="true"')) {
    console.error('The route-level FlowTrack page shell is missing.');
    process.exit(1);
}
if (!assets.includes("asset('flowtrack/searchable-dropdown.js')") || !assets.includes("asset('flowtrack/loader.js')")) {
    console.error('FlowTrack generated runtime entry points are not mounted.');
    process.exit(1);
}
if (!assets.includes('FLOWTRACK_ACTIVE_PAGE') || !assets.includes('FLOWTRACK_PAGE_ROUTES')) {
    console.error('Route-aware runtime configuration is missing.');
    process.exit(1);
}

const controllerMarkers = [
    'DashboardPageController', 'JobPageController', 'ClientPageController', 'BoardPageController',
    'ReadApiController', 'ClientApiController', 'JobApiController', 'TaskApiController', 'DocumentApiController',
    'ConfigurationController', 'WorkflowController', 'AccessController', 'ProfileController', 'BrandingController',
];
for (const marker of controllerMarkers) {
    if (!routes.includes(marker)) {
        console.error(`Modular route controller missing: ${marker}.`);
        process.exit(1);
    }
}

const parityMarkers = [
    'openTask-stage-4-final-template',
    'openJob-stage-5-final-reference-template',
    'data-job-v4-add-product',
    'data-ft-task-assignee',
    'renderOperationsTaskBoard',
    'renderOperationsJobBoard',
    'Management Dashboard',
    'Task Pack Setup',
    'Workflow Setup',
    'Master Data',
    'flowtrackFormatDateTime',
];
for (const marker of parityMarkers) {
    if (!sources.includes(marker)) {
        console.error(`Existing UI/behavior parity marker missing: ${marker}.`);
        process.exit(1);
    }
}

const coreBuilt = readFileSync('public/flowtrack/modules/core.js', 'utf8');
if (!coreBuilt.includes("const phases=['Request Received'") || !coreBuilt.includes('function flowtrackNormalizePageName')) {
    console.error('Core runtime was not assembled with the prototype state and application core.');
    process.exit(1);
}

if (built.length < 500_000) {
    console.error('Built runtime appears incomplete.');
    process.exit(1);
}

const publicLoader = readFileSync('public/flowtrack/loader.js', 'utf8');
if (!publicLoader.includes('Generated from resources/js/flowtrack/loader.js') || !publicLoader.includes('FlowTrackAssets')) {
    console.error('Generated FlowTrack production loader is missing or stale.');
    process.exit(1);
}

console.log('FlowTrack route pages, modular controllers, generated runtime assets, CSS build, and UI parity markers verified.');
