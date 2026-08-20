#!/usr/bin/env node
/**
 * Raw-HTML verification gate — SEO_SPEC.md section 10.1.
 *
 * "For a sample of >= 20 URLs spanning every template, fetch the served HTML
 *  WITH JAVASCRIPT DISABLED and assert the presence of: <title>, meta
 *  description, canonical, exactly one <h1>, full product/category body copy,
 *  breadcrumbs, internal links and valid JSON-LD."
 *
 * Under D-009 the served HTML is a static file, so "with JavaScript disabled"
 * means reading the file off disk. No browser, no renderer, no chance of a
 * client-side script papering over a hole in the prerender. The section is
 * explicit that the gate must still run even though it reduces to this,
 * "because a regression in the render pipeline is otherwise silent" — and in
 * Phase 7 it caught exactly that twice: every catalogue page shipped with the
 * placeholder <title>Web</title>, and 64 of 136 pages rendered "not found"
 * because a database bug only appeared under concurrent rendering.
 *
 * Usage:  node tools/raw-html-gate.mjs [dist/web/browser]
 */

import { readFile, readdir, stat } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';
import process from 'node:process';

const ROOT = process.argv[2] ?? 'dist/web/browser';
const ORIGIN = 'https://officelandng.com';
const MIN_SAMPLE = 20;

/** Section 14.3 budgets, duplicated here on purpose: a gate that imports its
 *  own limits from the code under test cannot catch a change to those limits. */
const TITLE_BUDGET = 60;
const DESCRIPTION_BUDGET = 155;

/** Section 14.7 content floor, in characters of visible body copy. */
const BODY_COPY_FLOOR = 250;

const failures = [];
const notes = [];

function fail(url, message) {
  failures.push(`${url}\n    ${message}`);
}

// ── tiny HTML helpers ──────────────────────────────────────────────────────
// A parser would be more correct and would also hide exactly the class of bug
// this gate exists to find: a real crawler reads bytes, so these read bytes.

const between = (html, open, close) => {
  const start = html.indexOf(open);
  if (start === -1) return null;
  const end = html.indexOf(close, start + open.length);
  return end === -1 ? null : html.slice(start + open.length, end);
};

const countTags = (html, tag) => (html.match(new RegExp(`<${tag}[\\s>]`, 'gi')) ?? []).length;

const attr = (html, selectorRe, name) => {
  const tag = html.match(selectorRe)?.[0];
  if (!tag) return null;
  return tag.match(new RegExp(`${name}="([^"]*)"`, 'i'))?.[1] ?? null;
};

const decode = (value) =>
  value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/&rsquo;/g, '’');

/** Visible text of <main>, which is where body copy has to be. */
const mainText = (html) => {
  const main = between(html, '<main', '</main>');
  if (main === null) return '';

  return decode(
    main
      .replace(/<script[\s\S]*?<\/script>/gi, ' ')
      .replace(/<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/<[^>]+>/g, ' '),
  )
    .replace(/\s+/g, ' ')
    .trim();
};

const jsonLdNodes = (html) => {
  const nodes = [];
  const re = /<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi;

  let match;
  while ((match = re.exec(html)) !== null) {
    nodes.push(decode(match[1]));
  }

  return nodes;
};

// ── discovery ──────────────────────────────────────────────────────────────

async function findPages(dir) {
  const found = [];

  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);

    if (entry.isDirectory()) {
      found.push(...(await findPages(full)));
    } else if (entry.name === 'index.html') {
      found.push(full);
    }
  }

  return found;
}

/** '/c/office-furniture/index.html' -> '/c/office-furniture/' */
const toUrl = (file) => {
  const rel = relative(ROOT, file).split(sep).join('/');
  const path = rel.replace(/index\.html$/, '');
  return `/${path}`;
};

const templateOf = (url) => {
  if (url === '/') return 'home';
  if (url === '/c/') return 'category-index';
  if (url.startsWith('/p/')) return 'product';
  if (url.startsWith('/c/')) return 'category';
  return 'other';
};

// ── the assertions ─────────────────────────────────────────────────────────

function checkPage(url, template, html) {
  const isError = template === 'other' && /not found/i.test(html);

  // 1. <title>
  const title = between(html, '<title>', '</title>');
  if (!title) {
    fail(url, 'no <title>');
  } else {
    const text = decode(title);

    if (text === 'Web' || text.trim() === '') {
      fail(url, `placeholder title: "${text}" — SeoService did not run for this route`);
    }
    if (text.length > TITLE_BUDGET) {
      fail(url, `title is ${text.length} chars, over the ${TITLE_BUDGET} budget: "${text}"`);
    }
  }

  // 2. canonical, absolute and self-referencing
  const canonical = attr(html, /<link[^>]*rel="canonical"[^>]*>/i, 'href');
  if (!canonical) {
    fail(url, 'no canonical link');
  } else {
    if (!canonical.startsWith(ORIGIN)) {
      fail(url, `canonical is not absolute against ${ORIGIN}: ${canonical}`);
    }
    if (!isError && canonical !== `${ORIGIN}${url}`) {
      fail(url, `canonical does not self-reference: ${canonical} !== ${ORIGIN}${url}`);
    }
  }

  // 3. robots
  const robots = attr(html, /<meta[^>]*name="robots"[^>]*>/i, 'content');
  if (!robots) {
    fail(url, 'no robots meta');
  }

  // 4. exactly one h1
  const h1s = countTags(html, 'h1');
  if (h1s !== 1) {
    fail(url, `expected exactly one <h1>, found ${h1s}`);
  }

  // 5. meta description on every indexable page
  const description = attr(html, /<meta[^>]*name="description"[^>]*>/i, 'content');
  const indexable = robots !== null && !robots.includes('noindex');

  if (indexable && !description) {
    fail(url, 'indexable page has no meta description');
  }
  if (description && decode(description).length > DESCRIPTION_BUDGET) {
    fail(url, `meta description is over the ${DESCRIPTION_BUDGET} budget`);
  }

  // 6. body copy actually present in the HTML, not fetched by script
  const body = mainText(html);

  if (template === 'product' || template === 'category') {
    if (/not found/i.test(body)) {
      fail(url, 'rendered the not-found branch: the render could not reach the API');
    }
    if (template === 'product' && body.length < BODY_COPY_FLOOR) {
      fail(url, `only ${body.length} chars of body copy, under the ${BODY_COPY_FLOOR} floor`);
    }
  }

  // 7. breadcrumbs on every non-home page
  if (template !== 'home' && !isError) {
    if (!/aria-label="Breadcrumb"/i.test(html)) {
      fail(url, 'no breadcrumb nav');
    }
  }

  // 8. internal links, and every one of them slash-terminated
  const hrefs = [...html.matchAll(/href="(\/[^"#?]*)"/g)].map((m) => m[1]);

  if (hrefs.length === 0) {
    fail(url, 'no internal links: this page is a crawl dead end');
  }

  const slashless = [...new Set(hrefs)].filter(
    (href) => !href.endsWith('/') && !href.split('/').pop().includes('.'),
  );

  if (slashless.length > 0) {
    fail(
      url,
      `${slashless.length} internal link(s) missing the trailing slash, each one a ` +
        `redirect hop: ${slashless.slice(0, 3).join(', ')}`,
    );
  }

  // 9. JSON-LD: present, parseable, and carrying the required types
  const nodes = jsonLdNodes(html);

  if (nodes.length === 0) {
    fail(url, 'no JSON-LD');
    return;
  }

  const types = [];

  for (const raw of nodes) {
    try {
      const parsed = JSON.parse(raw);
      types.push(parsed['@type']);

      if (!parsed['@context']) {
        fail(url, `JSON-LD node ${parsed['@type']} has no @context`);
      }
    } catch (error) {
      fail(url, `invalid JSON-LD: ${error.message}`);
    }
  }

  // Section 14.4: Organization and WebSite are site-wide.
  for (const required of ['Organization', 'WebSite']) {
    if (!types.includes(required)) {
      fail(url, `missing site-wide ${required} JSON-LD`);
    }
  }

  if (template !== 'home' && !isError && !types.includes('BreadcrumbList')) {
    fail(url, 'missing BreadcrumbList JSON-LD');
  }

  if (template === 'product') {
    if (!types.includes('Product')) {
      fail(url, 'missing Product JSON-LD');
    }

    // D-002: no prices anywhere, and no invented offer to earn a rich result.
    for (const raw of nodes) {
      if (raw.includes('"offers"') || raw.includes('"price"')) {
        fail(url, 'Product JSON-LD carries an offer or a price, which D-002 forbids');
      }
    }
  }
}

// ── run ────────────────────────────────────────────────────────────────────

async function main() {
  try {
    await stat(ROOT);
  } catch {
    console.error(`raw-html gate: no build output at ${ROOT}. Run \`ng build\` first.`);
    process.exit(1);
  }

  const files = await findPages(ROOT);

  if (files.length === 0) {
    console.error(`raw-html gate: no index.html anywhere under ${ROOT}.`);
    process.exit(1);
  }

  const byTemplate = new Map();

  for (const file of files) {
    const url = toUrl(file);
    const template = templateOf(url);

    if (!byTemplate.has(template)) byTemplate.set(template, []);
    byTemplate.get(template).push({ file, url, template });
  }

  /*
   * Section 10.1 wants a sample spanning EVERY template, so it is built per
   * template rather than by taking the first 20 of a sorted list — which
   * would have been 20 category pages and nothing else.
   *
   * Then it is topped up. Two templates have exactly one page each (home,
   * /c/), so an even per-template split lands well under 20; the shortfall is
   * made up from whichever templates have pages to spare.
   */
  const taken = new Map();
  const perTemplate = Math.max(5, Math.ceil(MIN_SAMPLE / byTemplate.size));

  for (const [template, pages] of byTemplate) {
    taken.set(template, Math.min(perTemplate, pages.length));
  }

  let total = [...taken.values()].reduce((sum, n) => sum + n, 0);

  while (total < MIN_SAMPLE) {
    const growable = [...byTemplate].filter(([t, pages]) => taken.get(t) < pages.length);
    if (growable.length === 0) break;

    for (const [template] of growable) {
      if (total >= MIN_SAMPLE) break;
      taken.set(template, taken.get(template) + 1);
      total += 1;
    }
  }

  const sample = [];

  for (const [template, pages] of byTemplate) {
    const take = taken.get(template);
    sample.push(...pages.slice(0, take));
    notes.push(`${template}: ${take} of ${pages.length}`);
  }

  for (const required of ['home', 'product', 'category']) {
    if (!byTemplate.has(required)) {
      failures.push(`no ${required} pages were rendered at all`);
    }
  }

  if (sample.length < MIN_SAMPLE) {
    failures.push(
      `sample is ${sample.length} URLs, below the ${MIN_SAMPLE} that section 10.1 requires`,
    );
  }

  for (const { file, url, template } of sample) {
    checkPage(url, template, await readFile(file, 'utf8'));
  }

  console.log('Raw-HTML gate — SEO_SPEC.md section 10.1');
  console.log(`  output:   ${ROOT}`);
  console.log(`  rendered: ${files.length} pages`);
  console.log(`  sampled:  ${sample.length} (${notes.join(', ')})`);
  console.log('');

  if (failures.length > 0) {
    console.error(`FAILED — ${failures.length} problem(s):\n`);
    for (const failure of failures) console.error(`  ${failure}\n`);
    process.exit(1);
  }

  console.log('PASSED');
}

await main();
