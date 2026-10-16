# Fonts

## Bricolage Grotesque (display face)

- **Files:** `apps/web/public/fonts/bricolage-grotesque-latin.woff2`
- **Use:** page and section headlines (`h1`, `h2`), via `--font-display`.
- **Licence:** SIL Open Font License, Version 1.1.
- **Author:** Mathieu Triay (and contributors). Project:
  <https://github.com/googlefonts/bricolage>.
- **Provenance:** the Latin-subset WOFF2 as served by Google Fonts
  (`fonts.gstatic.com`), self-hosted because the storefront CSP is
  `font-src 'self'` (D-053) and forbids hotlinking. One variable file covers
  weights 500-800; the `unicode-range` in `@font-face` is Google's Basic-Latin
  subset, so only the glyphs the storefront uses are fetched.

The OFL permits bundling and self-hosting. If the full licence text is required
in the repository, add `OFL.txt` from the upstream project beside the font file.

## Montserrat / Inter (heading and body)

Declared in `src/styles.css` as `--font-heading` and `--font-sans`; the licensed
webfont files are still outstanding (Q17), so both currently render in their
system-font fallbacks.
