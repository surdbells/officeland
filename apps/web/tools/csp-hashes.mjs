#!/usr/bin/env node
/**
 * Derives the storefront's Content-Security-Policy script hashes from the
 * prerendered output (Phase 13a).
 *
 * ── Why this exists rather than Angular's auto-CSP ───────────────────────
 * Angular 22 ships `security.autoCsp`, which would do exactly this — but it
 * refuses to run alongside SSR: *"Cannot set both SSR and auto-CSP at the same
 * time."* D-009 prerenders through the SSR builder, so auto-CSP is unavailable
 * to this project and the hashes have to come from somewhere else.
 *
 * ── Why hashes and not a nonce ───────────────────────────────────────────
 * A nonce must be unique per response and injected by whatever serves the page.
 * The storefront is static files behind Caddy with no application in the path
 * (D-009), so there is nothing to generate one. Hashes are the only option that
 * survives static hosting, and they are strictly better than `unsafe-inline`.
 *
 * ── What is deliberately NOT hashed ──────────────────────────────────────
 * `<script type="application/ld+json">` and `<script type="application/json">`
 * hold data, not code. Browsers never execute them, so `script-src` does not
 * apply — hashing them would add churn to the policy for no security benefit,
 * and the JSON-LD changes on every page.
 *
 * ── The consistency check is the point ───────────────────────────────────
 * A CSP is a promise made in a Caddy header about bytes in thousands of files.
 * If one page carries an inline script the others do not, a policy derived from
 * a sample would break that page — and it would break it in the way CSP
 * failures always break things: silently, for users, with a console message
 * nobody sees. So every page is read, and disagreement is a hard failure.
 *
 * Usage:
 *   node tools/csp-hashes.mjs            # print the policy
 *   node tools/csp-hashes.mjs --check    # fail if it differs from the committed one
 */

import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

// fileURLToPath rather than .pathname: on Windows the latter yields
// "/C:/...", which join() then turns into "C:C:...".
const DIST = fileURLToPath(new URL('../dist/web/browser/', import.meta.url));
const SERVER = fileURLToPath(new URL('../dist/web/server/server.mjs', import.meta.url));
const COMMITTED = fileURLToPath(new URL('../../../infra/caddy/csp.caddy', import.meta.url));

/** Script types the browser executes. Everything else is inert data. */
const EXECUTABLE = new Set(['', 'text/javascript', 'application/javascript', 'module']);

const SCRIPT = /<script([^>]*)>([\s\S]*?)<\/script>/gi;

function attribute(attributes, name) {
  const match = attributes.match(new RegExp(`${name}\\s*=\\s*["']([^"']*)["']`, 'i'));

  return match ? match[1].trim().toLowerCase() : '';
}

/**
 * Inline event handlers: `<link onload="this.media='all'">` and friends.
 *
 * ── Why this is a separate pass, and why it was missed ───────────────────
 * The first version of this tool scanned `<script>` blocks only. That policy
 * shipped and a real browser immediately rejected Angular's deferred-stylesheet
 * promoter, leaving the full stylesheet pinned at `media="print"` — 44 rules
 * that never applied. The page still LOOKED right, because Angular inlines the
 * critical CSS, which is exactly why hashing bytes could not catch it and only
 * loading the page could.
 *
 * Handlers need `'unsafe-hashes'` in addition to their hash. The keyword reads
 * alarming and is narrower than it sounds: it does not permit arbitrary inline
 * script, only allows the hashes already listed to match handler attributes.
 * The alternative — `'unsafe-inline'` — would permit any injected script at all.
 */
const EVENT_HANDLER = /\s(on[a-z]+)\s*=\s*"([^"]*)"/gi;

/** @returns {{hashes: Set<string>, handlers: Set<string>}} for one document */
function hashesIn(html) {
  const hashes = new Set();
  const handlers = new Set();

  for (const [, attributes, body] of html.matchAll(SCRIPT)) {
    // An external script is covered by 'self', not by a hash.
    if (/\ssrc\s*=/i.test(attributes)) continue;

    if (!EXECUTABLE.has(attribute(attributes, 'type'))) continue;

    // Hashed exactly as the browser does: the raw text between the tags, with
    // no trimming. One stripped newline is a policy that blocks the page.
    hashes.add(`'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`);
  }

  /*
   * Handlers are hashed on the attribute VALUE alone — the code the browser
   * would run — with no surrounding quotes or attribute name.
   */
  for (const [, , code] of html.matchAll(EVENT_HANDLER)) {
    const hash = `'sha256-${createHash('sha256').update(code, 'utf8').digest('base64')}'`;

    hashes.add(hash);
    handlers.add(hash);
  }

  return { hashes, handlers };
}

async function* htmlFiles(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) yield* htmlFiles(path);
    // The CSR shell (index.csr.html) is the only build-time HTML on disk now
    // that nothing is prerendered. It is NOT the whole story: humans and bots
    // are served the on-demand SSR output (render:4000), and with hydration on
    // that output carries inline scripts the shell does not - Angular's
    // event-replay contract and jsaction bootstrap. Those are rendered, not
    // written to disk, so collectSsrHashes below renders them to hash them.
    else if (entry.name.endsWith('.html')) yield path;
  }
}

/**
 * Render a few routes through the built SSR engine and return their inline-
 * script hashes.
 *
 * ── Why this is necessary ────────────────────────────────────────────────
 * The policy is a promise about every byte a browser will execute. Under
 * hydration the bytes a human runs come from the SSR engine, not the shell on
 * disk, and they include scripts the shell has never had (the event-replay
 * contract). Hashing only the disk shell would ship a policy that blocks every
 * server-rendered page - the exact silent failure this tool exists to prevent.
 *
 * The hydration bootstrap is data-independent: it registers a fixed set of
 * event types, so its hash is identical on every route (verified: the homepage
 * and the quote form carry the same two). The API is therefore not needed - the
 * origin is pointed at a closed port so every catalogue call fails fast and the
 * render completes in a second with the scripts present. A couple of routes are
 * rendered rather than one so a route that ever inlines something extra is seen.
 */
async function collectSsrHashes() {
  const port = 41000 + Math.floor(Math.random() * 2000);
  const server = spawn(process.execPath, [SERVER], {
    env: {
      ...process.env,
      PORT: String(port),
      // A closed port: every /api call fails immediately (ECONNREFUSED), so the
      // render does not wait on a real API to emit the hydration scripts.
      PRERENDER_API_ORIGIN: 'http://127.0.0.1:1',
    },
    stdio: 'ignore',
  });

  try {
    // Wait for the engine to accept connections.
    const base = `http://127.0.0.1:${port}`;
    for (let attempt = 0; attempt < 40; attempt++) {
      try {
        await fetch(base + '/', { signal: AbortSignal.timeout(2000) });
        break;
      } catch {
        if (attempt === 39) throw new Error('SSR engine did not start for CSP hashing.');
        await sleep(250);
      }
    }

    const merged = { hashes: new Set(), handlers: new Set() };
    for (const route of ['/', '/quote/request/', '/a-path-that-404s/']) {
      const html = await fetch(base + route, { signal: AbortSignal.timeout(20000) }).then((r) => r.text());
      const { hashes, handlers } = hashesIn(html);
      for (const h of hashes) merged.hashes.add(h);
      for (const h of handlers) merged.handlers.add(h);
    }

    return merged;
  } finally {
    server.kill();
  }
}

const perFile = new Map();

for await (const file of htmlFiles(DIST)) {
  perFile.set(relative(DIST, file), hashesIn(await readFile(file, 'utf8')));
}

if (perFile.size === 0) {
  console.error('No built HTML found (expected dist/web/browser/index.csr.html). Run `ng build` first.');
  process.exit(1);
}

// The SSR output humans and bots actually receive, hashed from live renders.
perFile.set('ssr:rendered', await collectSsrHashes());

/*
 * The UNION across every page, not one page's set.
 *
 * Measured rather than assumed: Angular's hydration bootstrap embeds the
 * event-replay contract for the events a page actually binds, so pages differ
 * — a home page with a search box does not carry the same script as a 404.
 * The union is only a sensible policy if the distinct count stays small, which
 * is why the number is printed and gated below rather than taken on trust.
 */
const union = new Set();
const handlerHashes = new Set();

for (const { hashes, handlers } of perFile.values()) {
  for (const hash of hashes) union.add(hash);
  for (const hash of handlers) handlerHashes.add(hash);
}

/*
 * A policy that grew to hundreds of hashes would be both unmanageable and a
 * sign that something page-specific is being inlined — at which point hashes
 * are the wrong mechanism and the honest answer is to stop inlining.
 */
const CEILING = 24;

if (union.size > CEILING) {
  console.error(
    `${union.size} distinct inline scripts across ${perFile.size} pages, above the ${CEILING} ceiling.\n\n` +
      'Something page-specific is being inlined. Hashes are the wrong tool for that:\n' +
      'either stop inlining it, or move it to an external file covered by \'self\'.',
  );
  process.exit(1);
}

/*
 * `'unsafe-hashes'` only when a handler actually needs it, so the keyword
 * disappears from the policy the day Angular stops emitting one rather than
 * lingering as a permission nobody remembers granting.
 */
const unsafeHashes = handlerHashes.size > 0 ? " 'unsafe-hashes'" : '';

const scriptSrc = `'self'${unsafeHashes} ${[...union].sort().join(' ')}`.trim();

/*
 * The full policy, emitted as a Caddy snippet so the header and the hashes it
 * depends on are generated together. Two files that must agree is one file too
 * many when disagreement means a blank page.
 *
 * ── Verified against the build, not assumed ──────────────────────────────
 * The storefront loads no external stylesheet, no web font and no CDN script —
 * checked against the built output — so `default-src 'self'` costs nothing.
 *
 * ── style-src keeps 'unsafe-inline', and script-src does not ─────────────
 * Angular inlines critical CSS and sets style attributes during hydration.
 * Hashes do not cover style attributes (that needs 'unsafe-hashes'), so the
 * honest options are 'unsafe-inline' or a broken page. The asymmetry is
 * deliberate: inline STYLE is a defacement risk, inline SCRIPT is code
 * execution, and the whole value of a CSP is in the second one.
 *
 * ── frame-ancestors is why this is a header and not a meta tag ───────────
 * Browsers ignore frame-ancestors in <meta>, which is exactly the directive
 * that stops the admin panel and the quote form being framed for clickjacking.
 */
const policy = [
  "default-src 'self'",
  `script-src ${scriptSrc}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data:",
  "font-src 'self'",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ');

const snippet = `# GENERATED by apps/web/tools/csp-hashes.mjs — do not hand-edit.
#
# The hashes below are of the inline scripts Angular emits into every
# prerendered page. They change whenever the Angular build changes what it
# inlines, and a stale hash serves a policy that blanks the site — so CI runs
# \`node tools/csp-hashes.mjs --check\` and fails if this file has drifted.
#
# Regenerate with:  node tools/csp-hashes.mjs --write
header Content-Security-Policy "${policy}"
`;

if (process.argv.includes('--write')) {
  await writeFile(COMMITTED, snippet, 'utf8');
  console.log(`Wrote ${COMMITTED}\n\n${policy}`);
  process.exit(0);
}

if (process.argv.includes('--check')) {
  let committed = '';

  try {
    committed = await readFile(COMMITTED, 'utf8');
  } catch {
    console.error(`No committed policy at ${COMMITTED}. Run with --write and commit the result.`);
    process.exit(1);
  }

  if (committed.trim() !== snippet.trim()) {
    console.error(
      'The prerendered inline scripts no longer match the committed CSP.\n\n' +
        `  built: ${policy}\n\n` +
        'Caddy would serve a policy that blocks every page. Regenerate with --write.',
    );
    process.exit(1);
  }

  console.log(
    `CSP verified: ${union.size} inline script hash(es) across ${perFile.size} prerendered page(s).`,
  );
  process.exit(0);
}

console.log(policy);
