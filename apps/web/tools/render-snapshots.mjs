/*
 * Render the storefront's snapshots for the gate suite.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 * The storefront is a client-rendered SPA (Dynamic Rendering, D-009 revised).
 * There is no longer a directory of prerendered HTML for the gates to read -
 * a crawler and the raw-HTML, crawl-depth, link and a11y gates all need the
 * SERVER-rendered output that a bot receives. This produces exactly that: it
 * walks the site the way a crawler does, over the real edge with a crawler
 * user-agent, so every file it writes is the bytes Googlebot is actually
 * served - the UA routing, the renderer and the SSR are all exercised, not
 * mocked.
 *
 * The output mirrors the old prerendered tree (`<path>/index.html`), so the
 * gates that read a directory of files run against it unchanged.
 *
 * ── The crawl ────────────────────────────────────────────────────────────
 * Breadth-first from `/`, following only same-origin `<a href>` with a clean
 * path - query strings (facets, pagination, search) are noindex and were
 * never files, so they are skipped, exactly as the prerender skipped them.
 * Depth is unbounded by default because the crawl-depth gate's whole job is to
 * prove the graph is shallow; `--max-pages` is a safety valve, not a sample.
 *
 * Usage:
 *   node tools/render-snapshots.mjs [--base=http://localhost:8095] \
 *     [--out=dist/web/snapshots] [--max-pages=0] [--concurrency=8]
 */
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = new Map(
  process.argv.slice(2).map((a) => {
    const [k, v] = a.replace(/^--/, '').split('=');
    return [k, v ?? true];
  }),
);

const BASE = (args.get('base') ?? process.env['GATE_BASE'] ?? 'http://localhost:8095').replace(/\/$/, '');
const here = dirname(fileURLToPath(import.meta.url));
const OUT = join(here, '..', args.get('out') ?? 'dist/web/snapshots');
const MAX_PAGES = Number(args.get('max-pages') ?? 0) || Infinity;
const CONCURRENCY = Number(args.get('concurrency') ?? 8);

// A crawler user-agent, so the edge routes us through Dynamic Rendering to the
// renderer rather than handing back the empty CSR shell.
const UA =
  'Mozilla/5.0 (compatible; OfficeLandGateBot/1.0; +https://officelandng.com/) Googlebot/2.1';

/*
 * Routes the crawl cannot discover by following <a href>, because they are
 * reached through a form or a button - search, the quote flow - or are simply
 * the roots the rest hangs off. This is the same set the prerender listed
 * explicitly; seeding them here means the snapshot tree covers every page type
 * the gates expect, not only the anchor-linked ones. Overlap with crawled
 * links is harmless: the frontier dedupes.
 */
const SEED = [
  '/',
  '/c/',
  '/shop/',
  '/search/',
  '/brands/',
  '/quote/',
  '/quote/request/',
  '/quote/track/',
  '/about/',
  '/contact/',
  '/locations/',
  '/delivery/',
  '/returns/',
  '/warranty/',
  '/bulk-supply/',
  '/guides/',
  '/privacy/',
  '/terms/',
  '/cookies/',
];

/** A path that maps to a file, the way the prerender named them. */
function toFile(pathname) {
  const clean = pathname.replace(/^\/+|\/+$/g, '');
  return clean === '' ? 'index.html' : join(clean, 'index.html');
}

/** Same-origin, clean-path links only - what a prerendered file could exist for. */
function extractLinks(html, fromPath) {
  const links = new Set();
  const re = /<a\b[^>]*\bhref\s*=\s*("([^"]*)"|'([^']*)')/gi;
  let m;

  while ((m = re.exec(html)) !== null) {
    const raw = (m[2] ?? m[3] ?? '').trim();
    if (raw === '' || raw.startsWith('#')) continue;
    if (/^(https?:|mailto:|tel:|javascript:)/i.test(raw) && !raw.startsWith(BASE)) continue;

    let url;
    try {
      url = new URL(raw, BASE + fromPath);
    } catch {
      continue;
    }

    if (url.origin !== new URL(BASE).origin) continue;
    // Query and hash routes are noindex and were never prerendered files.
    if (url.search !== '') continue;

    links.add(url.pathname);
  }

  return links;
}

async function fetchPage(pathname) {
  const res = await fetch(BASE + pathname, {
    headers: { 'User-Agent': UA, Accept: 'text/html' },
    redirect: 'manual',
  });

  // Redirects and non-HTML are edges of the graph, not pages to store.
  const type = res.headers.get('content-type') ?? '';
  if (res.status >= 300 || !type.includes('text/html')) {
    return { status: res.status, html: null };
  }

  return { status: res.status, html: await res.text() };
}

async function main() {
  await rm(OUT, { recursive: true, force: true });
  await mkdir(OUT, { recursive: true });

  const seen = new Set(SEED);
  let frontier = [...SEED];
  let rendered = 0;
  let failed = 0;

  const started = Date.now();

  while (frontier.length > 0 && rendered < MAX_PAGES) {
    const batch = frontier.slice(0, CONCURRENCY);
    frontier = frontier.slice(CONCURRENCY);

    const results = await Promise.all(
      batch.map(async (pathname) => {
        try {
          const { status, html } = await fetchPage(pathname);
          return { pathname, status, html };
        } catch (error) {
          return { pathname, status: 0, html: null, error };
        }
      }),
    );

    for (const { pathname, status, html } of results) {
      if (html === null) {
        // A miss on a link IS a finding, but it is the link gate's finding to
        // report against the referring page - here it is simply not a file.
        if (status === 0 || status >= 400) failed++;
        continue;
      }

      const file = join(OUT, toFile(pathname));
      await mkdir(dirname(file), { recursive: true });
      await writeFile(file, html, 'utf8');
      rendered++;

      if (rendered % 50 === 0) {
        process.stdout.write(`  rendered ${rendered}, queue ${frontier.length}\n`);
      }

      for (const next of extractLinks(html, pathname)) {
        if (!seen.has(next)) {
          seen.add(next);
          frontier.push(next);
        }
      }
    }
  }

  const seconds = ((Date.now() - started) / 1000).toFixed(1);
  process.stdout.write(
    `\nSnapshots: ${rendered} page(s) rendered to ${args.get('out') ?? 'dist/web/snapshots'} ` +
      `in ${seconds}s${failed ? `, ${failed} link target(s) did not resolve` : ''}.\n`,
  );

  if (rendered === 0) {
    process.stderr.write(
      `\nNothing rendered from ${BASE}. Is the stack up (docker compose up) and the ` +
        `renderer serving? A crawler UA must reach the SSR path, not the CSR shell.\n`,
    );
    process.exit(1);
  }
}

main().catch((error) => {
  process.stderr.write(`render-snapshots failed: ${error?.stack ?? error}\n`);
  process.exit(1);
});
