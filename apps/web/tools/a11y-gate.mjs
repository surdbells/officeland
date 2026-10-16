#!/usr/bin/env node
/**
 * WCAG 2.2 AA gate over the prerendered HTML (Phase 13c).
 *
 * Runs axe-core against every prerendered page under jsdom, so accessibility
 * regressions fail the build rather than being found by a user.
 *
 * ── What this catches, and what it structurally cannot ───────────────────
 * jsdom parses and builds a DOM but does not lay out or paint. So the rules
 * that reason about STRUCTURE all work — heading order, landmarks, alt text,
 * accessible names, form labels, ARIA validity, duplicate ids — and the rules
 * that need rendering do not:
 *
 *   colour-contrast   needs computed colours against painted backgrounds
 *   target-size       needs geometry
 *
 * Those two are disabled explicitly rather than left to fail silently as
 * "incomplete", because a gate that reports a rule it never actually ran is
 * worse than one that admits the gap. **They are verified in a browser
 * instead** — see docs/ACCESSIBILITY.md. Contrast in particular found six real
 * failures that no amount of static analysis would have produced.
 *
 * ── Why every page and not a sample ──────────────────────────────────────
 * Page types differ in what they render, and the cheap failure — one template
 * missing a label — appears on exactly one type. At 15,000 products this reads
 * every prerendered file, which is fine: it is parsing, not network.
 *
 *   node tools/a11y-gate.mjs           # every page
 *   node tools/a11y-gate.mjs --sample  # one per page type, for a fast local loop
 */

import { readdir, readFile } from 'node:fs/promises';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import axeCore from 'axe-core';

// Reads the SSR snapshots written by render-snapshots.mjs (Dynamic Rendering:
// there is no prerendered tree). Overridable so the gate can run against any
// directory of rendered HTML.
const rootArg = process.argv.slice(2).find((a) => !a.startsWith('--'));
const DIST = rootArg
  ? resolve(process.cwd(), rootArg) + sep
  : fileURLToPath(new URL('../dist/web/snapshots/', import.meta.url));
const SAMPLE = process.argv.includes('--sample');

/*
 * Disabled because jsdom cannot run them, NOT because they do not matter.
 * Both are covered by browser testing; see the module comment.
 */
const CANNOT_RUN_HEADLESS = ['color-contrast', 'target-size'];

async function* htmlFiles(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) yield* htmlFiles(path);
    else if (entry.name.endsWith('.html') && !entry.name.endsWith('.csr.html')) yield path;
  }
}

/** Groups by page type so --sample covers each template once. */
function pageType(route) {
  if (route === 'index.html') return 'home';
  if (route.startsWith('p/')) return 'product';
  if (route.startsWith('c/')) return route === 'c/index.html' ? 'category-index' : 'category';
  if (route.startsWith('brands/')) return route === 'brands/index.html' ? 'brand-index' : 'brand';
  if (route.startsWith('quote')) return 'quote';
  if (route.startsWith('search')) return 'search';
  if (route.startsWith('404')) return 'error';

  return 'other';
}

const files = [];

for await (const file of htmlFiles(DIST)) files.push(relative(DIST, file).replaceAll('\\', '/'));

if (files.length === 0) {
  console.error('No prerendered HTML found. Run `ng build` first.');
  process.exit(1);
}

const byType = new Map();

for (const file of files) {
  const type = pageType(file);

  if (!byType.has(type)) byType.set(type, []);
  byType.get(type).push(file);
}

const targets = SAMPLE ? [...byType.values()].map((group) => group[0]) : files;

const violations = new Map();
let scanned = 0;

for (const file of targets) {
  const html = await readFile(join(DIST, file), 'utf8');

  /*
   * `outside-only` is the exact setting this needs: it provides `window.eval`
   * so axe can be injected, while the page's OWN <script> tags stay inert.
   *
   * That distinction is the whole point. The prerendered HTML is what a
   * crawler and a screen reader meet before hydration, and it has to be
   * accessible in that state on its own. Running Angular here would test the
   * hydrated app instead — a different question, and one that hides a
   * server-rendered document that is inaccessible until JavaScript repairs it.
   */
  const dom = new JSDOM(html, { pretendToBeVisual: true, runScripts: 'outside-only' });

  const { window } = dom;

  window.eval(axeCore.source);

  const results = await window.axe.run(window.document, {
    resultTypes: ['violations'],
    rules: Object.fromEntries(CANNOT_RUN_HEADLESS.map((id) => [id, { enabled: false }])),
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] },
  });

  for (const violation of results.violations) {
    if (!violations.has(violation.id)) {
      violations.set(violation.id, {
        impact: violation.impact,
        help: violation.help,
        pages: new Set(),
        example: violation.nodes[0]?.html?.slice(0, 120) ?? '',
      });
    }

    violations.get(violation.id).pages.add(file);
  }

  dom.window.close();
  scanned++;
}

console.log(`axe-core ${axeCore.version} — ${scanned} page(s), ${byType.size} page type(s)`);
console.log(`not runnable under jsdom, verified in-browser instead: ${CANNOT_RUN_HEADLESS.join(', ')}\n`);

if (violations.size === 0) {
  console.log('PASSED — no WCAG 2.2 AA violations.');
  process.exit(0);
}

/*
 * Grouped by rule rather than listed per page: one missing label on a shared
 * component produces a violation on every page that uses it, and a list of
 * 15,000 identical findings is a list nobody reads to the end of.
 */
for (const [id, detail] of [...violations].sort((a, b) => b[1].pages.size - a[1].pages.size)) {
  console.error(`${(detail.impact ?? 'unknown').toUpperCase()}  ${id} — ${detail.help}`);
  console.error(`  ${detail.pages.size} page(s), e.g. ${[...detail.pages][0]}`);
  console.error(`  ${detail.example}\n`);
}

console.error(`FAILED — ${violations.size} distinct rule(s) violated.`);
process.exit(1);
