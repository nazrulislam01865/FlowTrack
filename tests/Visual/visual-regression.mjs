#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const root = path.resolve(import.meta.dirname, '../..');
const mode = process.argv[2] ?? 'test';
if (!['update', 'test'].includes(mode)) {
  console.error('Usage: node tests/Visual/visual-regression.mjs [update|test]');
  process.exit(2);
}

const config = JSON.parse(fs.readFileSync(path.join(root, 'quality/visual-scenarios.json'), 'utf8'));
const baseUrl = (process.env.VISUAL_BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const email = process.env.VISUAL_EMAIL ?? '';
const password = process.env.VISUAL_PASSWORD ?? '';
const maxDiffRatio = Number(process.env.VISUAL_MAX_DIFF_RATIO ?? '0.005');
const selected = new Set((process.env.VISUAL_SCENARIOS ?? '').split(',').map((value) => value.trim()).filter(Boolean));
const executablePath = process.env.VISUAL_CHROMIUM_PATH || undefined;

const baselineDir = path.join(root, 'tests/Visual/baselines');
const actualDir = path.join(root, 'tests/Visual/actual');
const diffDir = path.join(root, 'tests/Visual/diffs');
for (const directory of [baselineDir, actualDir, diffDir]) fs.mkdirSync(directory, { recursive: true });

const browser = await chromium.launch({ headless: true, executablePath });
const context = await browser.newContext({
  locale: process.env.VISUAL_LOCALE ?? 'en-US',
  timezoneId: process.env.VISUAL_TIMEZONE ?? 'Asia/Dhaka',
  reducedMotion: 'reduce',
});
const page = await context.newPage();

async function stabilizePage() {
  await page.addStyleTag({ content: `
    *, *::before, *::after {
      animation-duration: 0s !important;
      animation-delay: 0s !important;
      transition-duration: 0s !important;
      caret-color: transparent !important;
    }
  ` }).catch(() => {});
  await page.evaluate(async () => {
    if (document.fonts?.ready) await document.fonts.ready;
    window.scrollTo(0, 0);
  }).catch(() => {});
  await page.waitForTimeout(Number(process.env.VISUAL_SETTLE_MS ?? '800'));
}

async function ensureAuthenticated() {
  const response = await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded' });
  if (response && response.status() >= 500) {
    throw new Error(`Dashboard returned HTTP ${response.status()} before visual capture.`);
  }

  if (!page.url().includes('/login')) return;
  if (!email || !password) {
    throw new Error('Authenticated visual scenarios require VISUAL_EMAIL and VISUAL_PASSWORD.');
  }

  await page.locator('input[type="email"], input[name="email"]').first().fill(email);
  await page.locator('input[type="password"], input[name="password"]').first().fill(password);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('domcontentloaded').catch(() => {});

  if (page.url().includes('/login')) {
    throw new Error('Visual baseline login did not leave /login. Check credentials and seed data.');
  }
}

function comparePng(expectedPath, actualPath, diffPath) {
  const expected = PNG.sync.read(fs.readFileSync(expectedPath));
  const actual = PNG.sync.read(fs.readFileSync(actualPath));
  if (expected.width !== actual.width || expected.height !== actual.height) {
    return { ratio: 1, changed: expected.width * expected.height, sizeMismatch: true };
  }
  const diff = new PNG({ width: expected.width, height: expected.height });
  const changed = pixelmatch(expected.data, actual.data, diff.data, expected.width, expected.height, {
    threshold: 0.1,
    includeAA: false,
  });
  fs.writeFileSync(diffPath, PNG.sync.write(diff));
  return { ratio: changed / (expected.width * expected.height), changed, sizeMismatch: false };
}

let failures = 0;
try {
  await ensureAuthenticated();

  for (const scenario of config.scenarios) {
    if (selected.size > 0 && !selected.has(scenario.name)) continue;

    for (const [viewportName, viewport] of Object.entries(config.viewports)) {
      await page.setViewportSize(viewport);
      const response = await page.goto(`${baseUrl}${scenario.path}`, { waitUntil: 'domcontentloaded' });
      const status = response?.status() ?? 0;
      if (status >= 400) {
        console.error(`FAIL ${scenario.name}/${viewportName}: HTTP ${status}`);
        failures++;
        continue;
      }
      if (page.url().includes('/login')) {
        console.error(`FAIL ${scenario.name}/${viewportName}: redirected to login`);
        failures++;
        continue;
      }

      await stabilizePage();
      const filename = `${scenario.name}-${viewportName}.png`;
      const baselinePath = path.join(baselineDir, filename);
      const actualPath = path.join(actualDir, filename);
      const diffPath = path.join(diffDir, filename);
      await page.screenshot({ path: mode === 'update' ? baselinePath : actualPath, fullPage: true, animations: 'disabled' });

      if (mode === 'update') {
        console.log(`UPDATED ${filename}`);
        continue;
      }

      if (!fs.existsSync(baselinePath)) {
        console.error(`FAIL ${filename}: baseline missing (run npm run visual:update and approve it)`);
        failures++;
        continue;
      }

      const result = comparePng(baselinePath, actualPath, diffPath);
      if (result.ratio > maxDiffRatio) {
        console.error(`FAIL ${filename}: ${(result.ratio * 100).toFixed(3)}% pixels changed`);
        failures++;
      } else {
        if (fs.existsSync(diffPath)) fs.unlinkSync(diffPath);
        console.log(`PASS ${filename}: ${(result.ratio * 100).toFixed(3)}% pixels changed`);
      }
    }
  }
} finally {
  await browser.close();
}

if (failures > 0) {
  console.error(`Visual regression failed with ${failures} scenario(s).`);
  process.exit(1);
}
console.log(mode === 'update' ? 'Visual baselines captured. Review before approval.' : 'Visual regression passed.');
