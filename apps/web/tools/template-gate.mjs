#!/usr/bin/env node
/**
 * Catches backticks inside Angular inline templates and styles blocks.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 * Inline templates are backtick-delimited template literals. A backtick in
 * the template — almost always someone quoting a CSS property or a class name
 * inside an HTML comment — closes the literal early. TypeScript then reads
 * the rest of the component as code, and the build fails with a page of
 * errors pointing at everything except the backtick:
 *
 *     NG1001: Decorator argument must be literal
 *     TS18004: No value exists in scope for the shorthand property 'order'
 *     TS1005: ',' expected
 *
 * This has broken the build five times across five different files, and each
 * time the errors named an unrelated symbol on an unrelated line. The fix is
 * always the same — use double quotes — and the diagnosis is always the slow
 * part. So: check for it directly, and say which line it is on.
 *
 * ── Why a script and not an ESLint rule ──────────────────────────────────
 * The project has no ESLint config, and adding one to catch a single lexical
 * mistake would be a large dependency for a small rule. This is thirty lines
 * with no dependencies and it runs with the other gates.
 *
 * ── Why ${ is allowed ────────────────────────────────────────────────────
 * Nothing here interpolates into a template, but a bare `$` is legal and
 * common in text. Only the backtick actually terminates the literal.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', 'src');

/** Every .ts file under src/, recursively. */
function sources(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      return sources(path);
    }

    return path.endsWith('.ts') && !path.endsWith('.spec.ts') ? [path] : [];
  });
}


/**
 * Both inline literals a component carries.
 *
 * `styles:` was added after the gate let a backtick through it. The rule is
 * identical — a backtick closes the literal — but the failure reads
 * differently: the TypeScript that follows is parsed as an object literal, so
 * the build complains that "overflow" is not a property of Component and that
 * it cannot find the name "hidden". Nothing in that names CSS, let alone the
 * comment the backticks were in.
 *
 * Checking only the one that had bitten me was the same mistake as every gate
 * this project has had to add: it checked the thing it was named after.
 */
const MARKERS = ['template: `', 'styles: `'];

const problems = [];

for (const file of sources(root)) {
  const text = readFileSync(file, 'utf8');

  for (const marker of MARKERS) {
    const start = text.indexOf(marker);

    if (start === -1) {
      continue;
    }

    const open = start + marker.length;
    const close = text.indexOf('`', open);

    if (close === -1) {
      continue;
    }

    /*
     * The literal is delimited by the FIRST unescaped backtick after the
     * opening one, so anything the author intended as content past that point
     * is already outside it. A healthy literal is followed by `,` or `}`;
     * anything else means it closed somewhere it should not have.
     */
    if (/^\s*(,|\})/.test(text.slice(close + 1, close + 3))) {
      continue;
    }

    const line = text.slice(0, close).split('\n').length;
    const context = text.split('\n')[line - 1].trim();

    problems.push(`${relative(join(here, '..'), file)}:${line}  ${context}`);
  }
}

if (problems.length > 0) {
  console.error(
    `${problems.length} inline literal(s) closed early by a backtick:\n` +
      problems.map((entry) => `  ${entry}`).join('\n') +
      '\n\nA backtick inside an inline template or styles block ends the literal. Use double quotes.',
  );
  process.exit(1);
}

console.log(`Templates verified: no inline template or styles block is closed early by a backtick.`);
