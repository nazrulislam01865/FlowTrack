import { existsSync, statSync } from 'node:fs';
const required = [
  'public/flowtrack-dynamic.js',
  'public/flowtrack-searchable-dropdown.js',
  'public/flowtrack-searchable-dropdown.css',
  'resources/views/flowtrack-template.blade.php'
];
const missing = required.filter((file) => !existsSync(file) || statSync(file).size === 0);
if (missing.length) {
  console.error(`Missing required FlowTrack assets: ${missing.join(', ')}`);
  process.exit(1);
}
console.log('FlowTrack Blade assets verified.');
