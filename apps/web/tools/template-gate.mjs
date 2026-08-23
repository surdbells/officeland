#!/usr/bin/env node
/**
 * Catches backticks inside Angular inline templates.
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

const problems = [];

for (const file of sources(root)) {
  const text = readFileSync(file, 'utf8');
  const start = text.indexOf('template: `');

  if (start === -1) {
    continue;
  }

  const open = start + 'template: `'.length;
  const close = text.indexOf('`', open);

  if (close === -1) {
    continue;
  }

  /*
   * The literal is delimited by the FIRST unescaped backtick after the
   * opening one, so anything the author intended as template content past
   * that point is already outside it. Report the closing backtick's line
   * unless the text that follows looks like a properly terminated template
   * (`,` or `\n}` next), which is the healthy case.
   */
  const after = text.slice(close + 1, close + 3);

  if (/^\s*(,|\})/.test(after)) {
    continue;
  }

  const line = text.slice(0, close).split('\n').length;
  const context = text.split('\n')[line - 1].trim();

  problems.push(`${relative(join(here, '..'), file)}:${line}  ${context}`);
}

if (problems.length > 0) {
  console.error(
    `${problems.length} inline template(s) closed early by a backtick:\n` +
      problems.map((entry) => `  ${entry}`).join('\n') +
      '\n\nA backtick inside an inline template ends the literal. Use double quotes.',
  );
  process.exit(1);
}

console.log('Templates verified: no inline template is closed early by a backtick.');
