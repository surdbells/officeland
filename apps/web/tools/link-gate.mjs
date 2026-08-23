#!/usr/bin/env node
/**
 * Every internal link must go somewhere.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 * The site shipped with twelve dead links — /about/, /contact/, /privacy/,
 * /terms/ and eight more — in the header utility bar and the footer, which
 * put them on all 194 pages. Fourteen per cent of the site's internal links
 * pointed at nothing.
 *
 * Every gate passed. raw-html found content, crawl-depth found products three
 * clicks deep, axe found no violations, the CSP matched, the icons matched.
 * Not one of them asked whether a link resolved, because each was written to
 * check the thing it was named after.
 *
 * That is the third defect of this exact shape in this project — the redirect
 * layer that never fired, the soft-404 that served the homepage, the CSP that
 * blocked its own stylesheet — and all three produced a green signal from the
 * checks that existed. This closes the fourth.
 *
 * ── What it checks ───────────────────────────────────────────────────────
 * Every `href` in the prerendered output that starts with a single `/`
 * resolves to a file Caddy can serve, or to a redirect the map declares.
 * External links, mailto:, tel:, and fragments are out of scope: this gate is
 * about the site's own routing, and reaching the network would make it slow
 * and flaky for no gain.
 *
 * ── Why it reads the redirect map ────────────────────────────────────────
 * A link to a legacy URL that 301s is not broken, it is just indirect. It is
 * also not something to encourage, so those are reported as warnings — an
 * internal link should point at the destination rather than make every
 * visitor pay a redirect.
 */
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const output = join(here, '..', 'dist', 'web', 'browser');
const redirectMap = join(here, '..', '..', '..', 'docs', 'REDIRECT_MAP.csv');

if (!existsSync(output)) {
  console.error(`No prerendered output at ${output}. Run a build first.`);
  process.exit(1);
}

/** Every .html file in the prerendered output. */
function pages(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);

    return statSync(path).isDirectory()
      ? pages(path)
      : path.endsWith('.html')
        ? [path]
        : [];
  });
}

/**
 * Whether a path resolves to something on disk.
 *
 * Caddy serves `/a/b/` from `a/b/index.html`, and a path with an extension
 * as the file itself. Both forms are accepted here because both are how the
 * site actually answers.
 */
function resolves(path) {
  const clean = path.replace(/[?#].*$/, '');

  return (
    existsSync(join(output, clean, 'index.html')) ||
    existsSync(join(output, clean)) ||
    existsSync(join(output, `${clean.replace(/\/$/, '')}.html`))
  );
}

/** from_path values the redirect map declares, so a 301 is not reported as dead. */
function redirected() {
  if (!existsSync(redirectMap)) {
    return new Set();
  }

  return new Set(
    readFileSync(redirectMap, 'utf8')
      .split('\n')
      .slice(1)
      .map((line) => line.split(',')[0]?.trim())
      .filter(Boolean),
  );
}

const known = redirected();

/** href => the pages that link to it. */
const targets = new Map();

const html = pages(output);

for (const file of html) {
  const text = readFileSync(file, 'utf8');
  const page = file.slice(output.length).replace(/\\/g, '/');

  for (const [, href] of text.matchAll(/href="(\/[^"]*)"/g)) {
    // Protocol-relative //host/path is external.
    if (href.startsWith('//')) {
      continue;
    }

    if (!targets.has(href)) {
      targets.set(href, []);
    }

    const seen = targets.get(href);

    if (seen.length < 3) {
      seen.push(page);
    }
  }
}

const dead = [];
const indirect = [];

for (const [href, sources] of targets) {
  if (resolves(href)) {
    continue;
  }

  (known.has(href) ? indirect : dead).push({ href, sources });
}

console.log('Link gate — every internal href resolves');
console.log(`  pages:  ${html.length}`);
console.log(`  links:  ${targets.size} distinct internal target(s)`);

for (const { href, sources } of indirect) {
  console.warn(`  redirect: ${href} — linked from ${sources[0]}; point at the destination instead.`);
}

if (dead.length > 0) {
  console.error(`\n${dead.length} internal link(s) resolve to nothing:\n`);

  for (const { href, sources } of dead.sort((a, b) => a.href.localeCompare(b.href))) {
    console.error(`  ${href}`);
    console.error(`    linked from ${sources.join(', ')}${sources.length === 3 ? ' and others' : ''}`);
  }

  console.error('\nEither build the page or remove the link.');
  process.exit(1);
}

console.log('\nPASSED');
