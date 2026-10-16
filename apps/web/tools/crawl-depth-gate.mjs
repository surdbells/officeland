#!/usr/bin/env node
/**
 * Crawl-depth and orphan gate — SEO_SPEC.md §10.4 and §10.5.
 *
 *   §10.5  "Every product reachable within 3 clicks of the homepage."
 *   §10.4  "No indexable page has zero internal inbound links."
 *
 * Walks the PRERENDERED FILES, following only real `<a href>` — no JavaScript,
 * no sitemap, no manifest. That is deliberate: the sitemap and the manifest are
 * both generated from the database and would happily agree with each other
 * while the actual link graph was broken. This measures what a crawler can
 * reach by following links, which is the only thing that counts.
 *
 * D-011 exists because of this gate. Section 14.5 wants products within 3
 * clicks, §7.1 caps categories at depth 3, and §23.1 loads level-3 menu links
 * on intent only — together they put products at 4 clicks unless category pages
 * render their children as real anchors.
 *
 * Usage:  node tools/crawl-depth-gate.mjs [dist/web/browser] [--max-depth=3]
 */

import { readFile, readdir, stat } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';
import process from 'node:process';

const args = process.argv.slice(2);
const ROOT = args.find((a) => !a.startsWith('--')) ?? 'dist/web/snapshots';
const MAX_DEPTH = Number(
  (args.find((a) => a.startsWith('--max-depth=')) ?? '--max-depth=3').split('=')[1],
);

/**
 * Pages that are noindex by design, so §10.4 does not apply to them and they
 * are not counted as orphans.
 */
const NOT_INDEXABLE = new Set(['/search/', '/404/']);

// ── discovery ──────────────────────────────────────────────────────────────

async function findPages(dir) {
  const found = [];

  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);

    if (entry.isDirectory()) found.push(...(await findPages(full)));
    else if (entry.name === 'index.html') found.push(full);
  }

  return found;
}

const toUrl = (file) => `/${relative(ROOT, file).split(sep).join('/').replace(/index\.html$/, '')}`;

/**
 * Internal links, from the rendered markup only.
 *
 * Query strings are stripped and the result normalised to a trailing slash so
 * a facet link and its base page count as the same destination — the facet
 * page is noindex and is not a separate crawl target.
 */
function linksFrom(html) {
  const links = new Set();

  for (const [, href] of html.matchAll(/href="(\/[^"]*)"/g)) {
    const path = href.split('#')[0].split('?')[0];

    if (path === '' || path.includes('.')) continue;

    links.add(path.endsWith('/') ? path : `${path}/`);
  }

  return links;
}

// ── run ────────────────────────────────────────────────────────────────────

async function main() {
  try {
    await stat(ROOT);
  } catch {
    console.error(`crawl-depth gate: no build output at ${ROOT}. Run \`ng build\` first.`);
    process.exit(1);
  }

  const files = await findPages(ROOT);

  if (files.length === 0) {
    console.error(`crawl-depth gate: no pages under ${ROOT}.`);
    process.exit(1);
  }

  const pages = new Map();

  for (const file of files) {
    const url = toUrl(file);
    const html = await readFile(file, 'utf8');

    pages.set(url, {
      links: linksFrom(html),
      indexable: !/name="robots" content="[^"]*noindex/.test(html),
      isProduct: url.startsWith('/p/'),
    });
  }

  // Breadth-first from the homepage, so the first time a page is reached is
  // by its shortest path — which is what "within N clicks" means.
  const depth = new Map([['/', 0]]);
  const inbound = new Map();
  let frontier = ['/'];

  while (frontier.length > 0) {
    const next = [];

    for (const url of frontier) {
      const page = pages.get(url);
      if (!page) continue;

      for (const link of page.links) {
        inbound.set(link, (inbound.get(link) ?? 0) + 1);

        if (!depth.has(link) && pages.has(link)) {
          depth.set(link, depth.get(url) + 1);
          next.push(link);
        }
      }
    }

    frontier = next;
  }

  const products = [...pages].filter(([, page]) => page.isProduct);

  const unreachable = products.filter(([url]) => !depth.has(url));
  const tooDeep = products.filter(([url]) => (depth.get(url) ?? Infinity) > MAX_DEPTH);

  const orphans = [...pages].filter(
    ([url, page]) =>
      url !== '/' && page.indexable && !NOT_INDEXABLE.has(url) && !inbound.has(url),
  );

  // A histogram is worth more than a pass/fail here: it shows how much
  // headroom the link graph has before the next category level costs a click.
  const histogram = new Map();

  for (const [url] of products) {
    const d = depth.get(url);
    const key = d === undefined ? 'unreachable' : String(d);
    histogram.set(key, (histogram.get(key) ?? 0) + 1);
  }

  console.log('Crawl-depth gate — SEO_SPEC.md sections 10.4 and 10.5');
  console.log(`  output:    ${ROOT}`);
  console.log(`  pages:     ${pages.size}`);
  console.log(`  products:  ${products.length}`);
  console.log(`  max depth: ${MAX_DEPTH} clicks from /`);
  console.log('');
  console.log('  products by click depth:');

  for (const key of [...histogram.keys()].sort()) {
    console.log(`    ${key.padStart(11)}: ${histogram.get(key)}`);
  }

  console.log('');

  const failures = [];

  if (unreachable.length > 0) {
    failures.push(
      `${unreachable.length} product(s) cannot be reached from the homepage by following links at all: ` +
        unreachable.slice(0, 5).map(([url]) => url).join(', '),
    );
  }

  if (tooDeep.length > 0) {
    failures.push(
      `${tooDeep.length} product(s) are deeper than ${MAX_DEPTH} clicks: ` +
        tooDeep.slice(0, 5).map(([url]) => `${url} (${depth.get(url)})`).join(', '),
    );
  }

  if (orphans.length > 0) {
    failures.push(
      `${orphans.length} indexable page(s) have no internal inbound link: ` +
        orphans.slice(0, 5).map(([url]) => url).join(', '),
    );
  }

  if (failures.length > 0) {
    console.error(`FAILED — ${failures.length} problem(s):\n`);
    for (const failure of failures) console.error(`  ${failure}\n`);
    process.exit(1);
  }

  console.log('PASSED');
}

await main();
