import type { ParamMap } from '@angular/router';
import type { Listing } from '../core/api/catalogue-api';

/**
 * Listing state read from the URL, shared by every listing surface.
 *
 * The category page, the brand hub and the search page all decode the same
 * things from the same URL shapes. Three copies would disagree the first time
 * a filter syntax or a pagination rule changed, and the failure would be
 * silent - a page listing the wrong products still renders perfectly.
 */

/**
 * What a listing shows before its request resolves, or when it fails.
 *
 * A failed listing degrades the grid, not the page: the intro copy, the
 * breadcrumbs and the child anchors are what a crawler needs, and none of them
 * depend on the products.
 */
export const EMPTY_LISTING: Listing = {
  data: [],
  meta: {
    total: 0,
    page: 1,
    per_page: 24,
    last_page: 1,
    has_more: false,
    sort: 'newest',
    duration_ms: 0,
    used_fallback: false,
  },
  facets: [],
};

/**
 * Splits `/c/x/y/page/3` into its resource path and page number.
 *
 * Pagination is a PATH segment, not a query parameter, because Angular
 * prerenders paths and cannot emit a file for a query string (D-032). The page
 * number therefore arrives inside the wildcard the route matched, and has to
 * be separated before the API is asked for a category or brand called "page".
 *
 * The trailing segment must be an integer above 1, so a resource legitimately
 * slugged `page` is not mistaken for pagination, and `page/1` - which should
 * never be linked - does not become a second URL for the first page.
 *
 * @param prefix the literal route segment to drop, e.g. 'c' or 'brands'
 */
export function splitPagedPath(
  segments: readonly string[],
  prefix: string,
): { path: string; page: number } {
  const parts = segments[0] === prefix ? segments.slice(1) : [...segments];

  if (parts.length >= 2 && parts[parts.length - 2] === 'page') {
    const raw = Number(parts[parts.length - 1]);

    if (Number.isInteger(raw) && raw > 1) {
      return { path: parts.slice(0, -2).join('/'), page: raw };
    }
  }

  return { path: parts.join('/'), page: 1 };
}

/**
 * Reads `filters[capacity]=480,960` into `{ capacity: ['480', '960'] }`.
 *
 * Empty values are dropped rather than sent, so a stray `filters[colour]=`
 * from a hand-edited URL does not become a filter that matches nothing.
 */
export function readFilters(params: ParamMap): Record<string, string[]> {
  const filters: Record<string, string[]> = {};

  for (const key of params.keys) {
    const match = /^filters\[(.+)\]$/.exec(key);
    if (!match) continue;

    const values = (params.get(key) ?? '').split(',').filter((value) => value !== '');
    if (values.length > 0) filters[match[1]!] = values;
  }

  return filters;
}

/** Filters flattened back into query parameters, for links that must keep them. */
export function filterParams(
  filters: Readonly<Record<string, readonly string[]>>,
): Record<string, string> {
  const params: Record<string, string> = {};

  for (const [code, values] of Object.entries(filters)) {
    if (values.length > 0) params[`filters[${code}]`] = values.join(',');
  }

  return params;
}
