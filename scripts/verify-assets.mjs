import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';

function filesUnder(directory, extension) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = `${directory}/${entry.name}`;
    return entry.isDirectory() ? filesUnder(path, extension) : (path.endsWith(extension) ? [path] : []);
  });
}

const required = [
  'public/flowtrack-dynamic.js',
  'public/flowtrack/modules/manifest.json',
  'public/flowtrack/modules/core.js',
  'public/flowtrack/modules/workspace.js',
  'public/flowtrack/modules/operations.js',
  'public/flowtrack/modules/configuration.js',
  'public/flowtrack/modules/records.js',
  'public/flowtrack/modules/job-shared.js',
  'public/flowtrack/modules/task-details.js',
  'public/flowtrack/modules/job-details.js',
  'public/flowtrack/modules/board.js',
  'public/flowtrack-searchable-dropdown.js',
  'public/flowtrack-searchable-dropdown.css',
  'public/flowtrack-livewire.js',
  'public/flowtrack-prototype-fallback.js',
  'public/flowtrack-browser-compatibility.js',
  'public/build/manifest.json',
  'resources/views/flowtrack-template.blade.php',
  'resources/views/livewire/flow-track/workspace-shell.blade.php',
  'resources/views/livewire/flow-track/screen.blade.php',
  'app/Livewire/FlowTrack/WorkspaceShell.php',
  'app/Livewire/FlowTrack/Screen.php',
  'resources/js/flowtrack/flowtrack-dynamic-source.js',
  'resources/js/flowtrack/prototype-fallback.js',
  'resources/js/flowtrack/browser-compatibility.js',
  'resources/css/flowtrack/app.css',
  'resources/css/flowtrack/modules/browser-compatibility.css',
  'scripts/split-flowtrack-assets.mjs',
  'scripts/check-vite-build.php',
  'scripts/frontend-install-and-build.sh',
  'scripts/configure-xampp-upload-limits.sh',
  'deploy/99-flowtrack-uploads.ini',
  'deploy/nginx-flowtrack-performance.conf',
  'database/migrations/2026_08_01_000200_add_start_date_to_flow_tasks.php',
  'database/migrations/2026_08_02_000100_repair_flow_document_linkage_schema.php',
  'database/migrations/2026_08_02_000200_create_flowtrack_sequences_table.php',
];

const canonicalScreens = ['dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports', 'notifications', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'profile'];
required.push(...canonicalScreens.map((screen) => `resources/views/livewire/flow-track/screens/${screen}.blade.php`));

const missing = required.filter((file) => !existsSync(file) || statSync(file).size === 0);
if (missing.length) {
  console.error(`Missing required FlowTrack assets: ${missing.join(', ')}`);
  process.exit(1);
}

const dynamicLoader = readFileSync('public/flowtrack-dynamic.js', 'utf8');
const dynamicModulePaths = required.filter((file) => file.startsWith('public/flowtrack/modules/') && file.endsWith('.js'));
const dynamicAsset = dynamicModulePaths.map((file) => readFileSync(file, 'utf8')).join('\n');
const flowtrackCssFiles = filesUnder('resources/css/flowtrack', '.css');
const flowtrackCss = flowtrackCssFiles.map((file) => readFileSync(file, 'utf8')).join('\n');
const prototypeFallback = readFileSync('resources/js/flowtrack/prototype-fallback.js', 'utf8');
const frontendSource = `${dynamicAsset}\n${flowtrackCss}`;
const lazyManifest = JSON.parse(readFileSync('public/flowtrack/modules/manifest.json', 'utf8'));

const expectedLazyModules = ['core', 'workspace', 'operations', 'configuration', 'records', 'job-shared', 'task-details', 'job-details', 'board'];
const missingLazyModules = expectedLazyModules.filter((name) => !lazyManifest.modules?.[name]);
if (missingLazyModules.length) {
  console.error(`FlowTrack lazy module manifest is incomplete: ${missingLazyModules.join(', ')}`);
  process.exit(1);
}
if (statSync('public/flowtrack-dynamic.js').size > 32768) {
  console.error('FlowTrack route loader is too large; screen code must remain in lazy modules.');
  process.exit(1);
}
const lazyLoaderMarkers = [
  'FlowTrack route-aware JavaScript loader',
  'ensureForPage',
  "await load('workspace')",
  "await load('operations')",
  "await load('task-details')",
  "await load('job-details')",
  "await load('board')",
  'script.dataset.flowtrackModule',
  'flowtrack:module-loaded',
];
const missingLazyLoaderMarkers = lazyLoaderMarkers.filter((marker) => !dynamicLoader.includes(marker));
if (missingLazyLoaderMarkers.length) {
  console.error(`FlowTrack route loader is incomplete: ${missingLazyLoaderMarkers.join(', ')}`);
  process.exit(1);
}
if (dynamicLoader.includes('data-ft-task-description-input') || dynamicLoader.includes('data-job-v4-add-product')) {
  console.error('Route-specific templates leaked back into the always-loaded FlowTrack loader.');
  process.exit(1);
}
const taskTemplateMarkers = [
  'openTask-stage-4-final-template',
  'data-ft-task-assignee',
  'data-ft-task-status-value',
  'data-ft-task-priority',
  'data-ft-task-start-date',
  'data-ft-task-due-date',
  'data-ft-task-description-input',
  'data-ft-task-dropzone',
  'data-ft-task-activity-tab',
];
const missingMarkers = taskTemplateMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingMarkers.length) {
  console.error(`Final Task details template is incomplete: ${missingMarkers.join(', ')}`);
  process.exit(1);
}

const jobTemplateMarkers = [
  'openJob-stage-5-final-reference-template',
  'data-job-v4-add-product',
  'data-job-v4-edit-field',
  'data-job-v4-expand-all',
  'data-job-v4-collapse-all',
  'data-job-v4-filter-toggle',
  'data-job-v4-dropzone',
  'data-job-v4-activity-tab=\"comments\"',
  'data-job-v4-activity-tab=\"history\"',
  'data-job-v4-activity-tab=\"worklog\"',
];
const missingJobMarkers = jobTemplateMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingJobMarkers.length) {
  console.error(`Final Job details template is incomplete: ${missingJobMarkers.join(', ')}`);
  process.exit(1);
}

const createJobTemplateMarkers = [
  'flowtrackApplyCreateJobPrototypeLayout',
  'data-job-create-section=\"basics\"',
  'data-job-create-section=\"products\"',
  'data-job-create-section=\"schedule\"',
  'data-job-create-section=\"workflow\"',
  'data-job-create-section=\"attachments\"',
  'data-job-create-workflow-summary',
  'data-job-create-product-summary',
  'newJobPrioritySelect',
  'Generated automatically',
  'job-product-stack-field',
  'job-create-task-dropzone',
  'data-new-job-browse',
  'Create Job vertical-field refinement',
];
const missingCreateJobMarkers = createJobTemplateMarkers.filter((marker) => !frontendSource.includes(marker));
if (missingCreateJobMarkers.length) {
  console.error(`Final Create Job template is incomplete: ${missingCreateJobMarkers.join(', ')}`);
  process.exit(1);
}


const myWorkAdminMarkers = [
  'function myWorkIsAdministrativeView()',
  'return myWorkIsAdministrativeView() ? visibleTasks : visibleTasks.filter(taskAssignedToCurrentUser)',
  'const ownJobs = myWorkBaseJobs();',
  'const jobList = myWorkFilteredJobs(taskList);',
  'renderOperationsTaskBoard(taskList, jobList)',
  'const myWorkOpenTasks = myWorkIsAdministrativeView() ? visibleOpenTasks : visibleOpenTasks.filter(taskAssignedToCurrentUser)',
];
const missingMyWorkAdminMarkers = myWorkAdminMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingMyWorkAdminMarkers.length) {
  console.error(`My Work Admin/Super Admin visibility fix is incomplete: ${missingMyWorkAdminMarkers.join(', ')}`);
  process.exit(1);
}


const bladeFiles = filesUnder('resources/views', '.blade.php');
const bladeEntry = readFileSync('resources/views/flowtrack-template.blade.php', 'utf8');
const bladeTemplate = bladeFiles.map((file) => readFileSync(file, 'utf8')).join('\n');
const livewireWorkspace = readFileSync('resources/views/livewire/flow-track/workspace-shell.blade.php', 'utf8');
const livewireScreen = canonicalScreens.map((screen) => readFileSync(`resources/views/livewire/flow-track/screens/${screen}.blade.php`, 'utf8')).join('\n');
const livewireBridge = readFileSync('public/flowtrack-livewire.js', 'utf8');
const livewireMarkers = [
  bladeTemplate.includes('@livewireStyles'),
  bladeTemplate.includes('@livewireScripts'),
  bladeTemplate.includes('<livewire:flow-track.workspace-shell />'),
  livewireWorkspace.includes('<livewire:flow-track.screen'),
  livewireScreen.includes('data-livewire-screen'),
  livewireScreen.includes('wire:ignore'),
  livewireBridge.includes("window.Livewire.dispatch('flowtrack:navigate'"),
];
if (livewireMarkers.some((present) => !present)) {
  console.error('Livewire workspace boundary or compatibility bridge is incomplete.');
  process.exit(1);
}

const previousFrontendParityMarkers = [
  bladeTemplate.includes('method="POST"'),
  bladeTemplate.includes("action=\"{{ route('flowtrack.login.store') }}\""),
  bladeTemplate.includes('@csrf'),
  bladeTemplate.includes('data-native-secure-login'),
  bladeTemplate.includes('autocomplete="off"'),
  bladeTemplate.includes('name="remember" value="1"'),
  bladeTemplate.includes('<script\n    async\n    src="https://js.pusher.com/8.4.0/pusher.min.js"'),
  bladeTemplate.includes("onload=\"window.dispatchEvent(new Event('flowtrack:pusher-ready'))\""),
  dynamicAsset.includes("'flowtrack:pusher-ready'"),
];
if (previousFrontendParityMarkers.some((present) => !present)) {
  console.error('Previous-project login or non-blocking realtime frontend behavior is incomplete.');
  process.exit(1);
}
if (dynamicAsset.includes("api('/login'") || dynamicAsset.includes("loginForm?.addEventListener('submit'") || dynamicAsset.includes('loginPasswordToggle')) {
  console.error('Login credentials must be submitted natively and must not be processed by FlowTrack JavaScript.');
  process.exit(1);
}
const assetPartial = readFileSync('resources/views/flowtrack/partials/assets.blade.php', 'utf8');
const pusherTagPosition = assetPartial.indexOf('https://js.pusher.com/8.4.0/pusher.min.js');
const loaderTagPosition = assetPartial.indexOf("asset('flowtrack-dynamic.js')");
if (pusherTagPosition < loaderTagPosition) {
  console.error('Pusher must remain non-blocking and load after the FlowTrack route loader.');
  process.exit(1);
}
const sharedFormStyleMarkers = [
  'Application form controls restored exactly from Archive 5.',
  '.field{display:flex;flex-direction:column;gap:7px;margin-bottom:16px}',
  '.field label{font-weight:600;font-size:13px}',
  '.field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:10px',
  '.field input:focus,.field select:focus,.field textarea:focus{border-color:#8fb1ff',
  '.field input[readonly]{background:#f7f9fc;color:#59677a;cursor:not-allowed}',
  '.task-pack-items-editor{display:flex;flex-direction:column;gap:12px}',
  '.task-pack-editor-item{padding:14px}',
];
const missingSharedFormStyles = sharedFormStyleMarkers.filter((marker) => !flowtrackCss.includes(marker));
if (missingSharedFormStyles.length) {
  console.error(`Shared form design regression detected: ${missingSharedFormStyles.join(', ')}`);
  process.exit(1);
}

const formRenderMarkers = [
  'client-form-stack',
  'Client code',
  'Create New Client',
  'renderWorkflowSetup = function ()',
  'renderMasterData',
  'task-pack-items-editor',
  'standalone-record-form',
];
const missingFormRenderMarkers = formRenderMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingFormRenderMarkers.length) {
  console.error(`Expected application form renderer is incomplete: ${missingFormRenderMarkers.join(', ')}`);
  process.exit(1);
}


const routingSafetyMarkers = [
  'function flowtrackNormalizePageName(page)',
  'if (!requirement) return false;',
  'await window.loadBootstrap();',
  "document.getElementById(`page-${pageName}`)",
];
const routingAssets = `${dynamicLoader}\n${dynamicAsset}`;
const missingRoutingSafetyMarkers = routingSafetyMarkers.filter((marker) => !routingAssets.includes(marker));
if (missingRoutingSafetyMarkers.length) {
  console.error(`Routing/startup safety regression detected: ${missingRoutingSafetyMarkers.join(', ')}`);
  process.exit(1);
}
if (routingAssets.includes('.then(showApplication)')) {
  console.error('Routing/startup safety regression detected: bootstrap payload must not be passed directly to showApplication().');
  process.exit(1);
}
if (!prototypeFallback.includes("document.getElementById('page-'+page)")) {
  console.error('Routing/startup safety regression detected: base navigate() must use a validated page element instead of a dynamic CSS selector.');
  process.exit(1);
}

const jobAssigneeViewModeMarkers = [
  'data-job-v4-edit-assignee',
  'job-v4-assignee-cell',
  "document.createElement('select')",
  'job-v4-assignee-editor',
  "cell.classList.add('is-editing')",
  'Only Admin/Super Admin',
];
const missingJobAssigneeViewModeMarkers = jobAssigneeViewModeMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingJobAssigneeViewModeMarkers.length) {
  console.error(`Job Details assignee view-mode regression detected: ${missingJobAssigneeViewModeMarkers.join(', ')}`);
  process.exit(1);
}
const jobAssigneeInitialRowTemplate = dynamicAsset.match(/return `<tr class="job-v4-task-row"[\s\S]*?<\/tr>`;/)?.[0] || '';
if (jobAssigneeInitialRowTemplate.includes('data-job-v4-task-assignee')) {
  console.error('Job Details assignee view-mode regression detected: assignee select must not exist in initial row markup.');
  process.exit(1);
}


const mobileBoardMarkers = [
  'ops-mobile-column-head',
  "grid-template-columns:repeat(2,minmax(0,1fr))!important",
  '#page-board .ops-board-page.ops-mode-tasks .ops-status-grid{display:none!important}',
  '#page-board .ops-board-page.ops-mode-jobs .ops-phase-kanban',
  "select.setAttribute('data-native-select', '')",
];
const missingMobileBoardMarkers = mobileBoardMarkers.filter((marker) => !frontendSource.includes(marker));
if (missingMobileBoardMarkers.length) {
  console.error(`Board mobile / assignee editor regression detected: ${missingMobileBoardMarkers.join(', ')}`);
  process.exit(1);
}
if (!dynamicAsset.includes('data-column-label="${html(column.label)}"')) {
  console.error('Board mobile regression detected: task status cells must expose their mobile status label.');
  process.exit(1);
}


const mobileBoardScrollMarkers = [
  'overscroll-behavior-y:auto!important',
  'touch-action:pan-y!important',
  'padding-bottom:calc(94px + env(safe-area-inset-bottom, 0px))!important',
  '#page-board .ops-board-page.ops-mode-tasks .ops-board-canvas,',
];
const missingMobileBoardScrollMarkers = mobileBoardScrollMarkers.filter((marker) => !frontendSource.includes(marker));
if (missingMobileBoardScrollMarkers.length) {
  console.error(`Mobile Board vertical-scroll regression detected: ${missingMobileBoardScrollMarkers.join(', ')}`);
  process.exit(1);
}

const realtimeMarkers = [
  'workspaceDataVersion',
  'workspace_version',
  'refreshWorkspaceDataSilently',
  'scheduleWorkspaceDataRefresh',
  'pollIntervalMs',
  'flowtrackEnsurePageData(previousPage, { force: true })',
];
const missingRealtimeMarkers = realtimeMarkers.filter((marker) => !dynamicAsset.includes(marker) && !bladeTemplate.includes(marker));
if (missingRealtimeMarkers.length) {
  console.error(`Realtime AJAX synchronization regression detected: ${missingRealtimeMarkers.join(', ')}`);
  process.exit(1);
}

const requestResilienceMarkers = [
  'activeMutationRequests',
  'body instanceof FormData ? 120000',
  'Your form is still open; check your connection and try again.',
  'Your changes were not cleared; reconnect and try again.',
  'FlowTrack needs a database update.',
];
const missingRequestResilienceMarkers = requestResilienceMarkers.filter((marker) => !dynamicAsset.includes(marker));
if (missingRequestResilienceMarkers.length) {
  console.error(`Request resilience regression detected: ${missingRequestResilienceMarkers.join(', ')}`);
  process.exit(1);
}

const lazyDataMarkers = [
  "'/context/{page}'",
  'flowtrackEnsurePageData',
  'flowtrackLoadInitialSegment',
  'contextBase',
  'clientOptions',
  'X-XSRF-TOKEN',
];
const lazyDataSource = `${readFileSync('routes/web.php', 'utf8')}\n${dynamicAsset}`;
const missingLazyDataMarkers = lazyDataMarkers.filter((marker) => !lazyDataSource.includes(marker));
if (missingLazyDataMarkers.length) {
  console.error(`Lazy data / secure request regression detected: ${missingLazyDataMarkers.join(', ')}`);
  process.exit(1);
}
if (lazyDataSource.includes('/flowtrack/csrf-token') || lazyDataSource.includes('X-CSRF-TOKEN') || lazyDataSource.includes('FLOWTRACK_CSRF_AUTO_RECOVERY')) {
  console.error('Legacy custom CSRF recovery must not be reintroduced.');
  process.exit(1);
}

const modularFrontendMarkers = [
  bladeEntry.includes("@include('flowtrack.auth.login')"),
  bladeEntry.includes("@include('flowtrack.layouts.workspace')"),
  bladeEntry.includes("@vite('resources/css/flowtrack/app.css')"),
  canonicalScreens.every((screen) => livewireScreen.includes(`id="page-${screen}"`)),
  flowtrackCssFiles.length >= 30,
  !bladeTemplate.includes('<style'),
  !bladeTemplate.includes('style="'),
  !readFileSync('resources/js/flowtrack/flowtrack-dynamic-source.js', 'utf8').includes("document.createElement('style')"),
  !readFileSync('resources/js/flowtrack/flowtrack-dynamic-source.js', 'utf8').includes('document.createElement("style")'),
];
if (modularFrontendMarkers.some((present) => !present)) {
  console.error('Modular Blade, external CSS, or Vite frontend structure is incomplete.');
  process.exit(1);
}

console.log('FlowTrack modular Livewire Blade views, Vite CSS, lazy JavaScript, design parity, routing safety, and realtime synchronization verified.');
