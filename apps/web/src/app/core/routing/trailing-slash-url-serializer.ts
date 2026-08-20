import { DefaultUrlSerializer, UrlTree } from '@angular/router';

/**
 * Keeps the trailing slash on every internal URL (SEO_SPEC.md section 2).
 *
 * ── Why this is not cosmetic ─────────────────────────────────────────────
 * Angular's DefaultUrlSerializer drops trailing slashes, so
 *
 *     routerLink="/c/office-furniture/"   renders   href="/c/office-furniture"
 *
 * Under D-009 the page is a file at /c/office-furniture/index.html. Caddy
 * serves the slashless form by issuing a 301 to the canonical one, so every
 * internal link on the site becomes a redirect hop: a crawler following the
 * megamenu across 15,000 products pays for 15,000 avoidable redirects, and the
 * redirect gate's "zero chains" rule is violated by the site's own navigation.
 *
 * It also splits the URL space against the canonicals SeoService emits, which
 * always carry the slash.
 *
 * ── What is deliberately NOT given a slash ───────────────────────────────
 *   - the root, which already is one
 *   - anything whose last segment contains a dot, so /favicon.ico and
 *     /sitemap.xml are left alone
 *
 * Query strings and fragments are preserved: the slash belongs to the path.
 */
export class TrailingSlashUrlSerializer extends DefaultUrlSerializer {
  override serialize(tree: UrlTree): string {
    const url = super.serialize(tree);

    // Split off ?query and #fragment; the slash is a property of the path.
    const queryAt = url.indexOf('?');
    const hashAt = url.indexOf('#');

    let cut = url.length;
    if (queryAt !== -1) cut = Math.min(cut, queryAt);
    if (hashAt !== -1) cut = Math.min(cut, hashAt);

    const path = url.slice(0, cut);
    const rest = url.slice(cut);

    if (path === '' || path.endsWith('/')) {
      return url;
    }

    const lastSegment = path.slice(path.lastIndexOf('/') + 1);

    // A dot means a file, and a file must not gain a directory slash.
    if (lastSegment.includes('.')) {
      return url;
    }

    return `${path}/${rest}`;
  }
}
