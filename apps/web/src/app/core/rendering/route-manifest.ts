/**
 * The build-time list of every route to prerender (D-009).
 *
 * This runs in Node during `ng build`, never in a browser. It talks to the API
 * directly rather than through CatalogueApi, because CatalogueApi is an
 * injectable that assumes an Angular application context, and
 * getPrerenderParams runs outside one.
 *
 * Failure behaviour is deliberate and asymmetric:
 *
 *   - A build that cannot reach the API renders zero product pages. Shipping
 *     that silently would empty the index, so it THROWS unless explicitly
 *     opted out of.
 *   - Local development and the CI typecheck do not have an API. They set
 *     PRERENDER_ALLOW_EMPTY=1 and get an empty manifest with a warning.
 *
 * The alternative — a checked-in list of slugs — was rejected: 15,000 slugs in
 * git would be stale the day after it was written, and nothing would say so.
 */

export interface RouteManifest {
  /** Materialized category paths without the /c/ prefix, e.g. 'office-furniture/seating'. */
  readonly categories: readonly string[];
  /** Product slugs without the /p/ prefix. */
  readonly products: readonly string[];
  /** Brand hub routes without the /brands/ prefix, including `slug/page/N`. */
  readonly brands: readonly string[];
}

const EMPTY: RouteManifest = { categories: [], products: [], brands: [] };

/** Cached for the lifetime of one build: getPrerenderParams is called per route. */
let cached: Promise<RouteManifest> | null = null;

export function fetchRouteManifest(): Promise<RouteManifest> {
  cached ??= load();
  return cached;
}

/** Test seam: the manifest is process-global state and would leak between cases. */
export function resetRouteManifestCache(): void {
  cached = null;
}

async function load(): Promise<RouteManifest> {
  const origin = process.env['PRERENDER_API_ORIGIN'] ?? 'http://localhost:8095';
  const allowEmpty = process.env['PRERENDER_ALLOW_EMPTY'] === '1';

  try {
    const response = await fetch(`${origin}/api/v1/render/manifest`);

    if (!response.ok) {
      throw new Error(`manifest endpoint returned ${response.status}`);
    }

    const body = (await response.json()) as { data?: Partial<RouteManifest> };

    return {
      categories: normalise(body.data?.categories),
      products: normalise(body.data?.products),
      brands: normalise(body.data?.brands),
    };
  } catch (error) {
    if (allowEmpty) {
      // eslint-disable-next-line no-console
      console.warn(
        `[prerender] no route manifest from ${origin} (${describe(error)}); ` +
          'PRERENDER_ALLOW_EMPTY=1, so only static routes will be rendered.',
      );
      return EMPTY;
    }

    throw new Error(
      `[prerender] could not load the route manifest from ${origin}: ${describe(error)}. ` +
        'A production build without it would publish an empty catalogue. ' +
        'Set PRERENDER_ALLOW_EMPTY=1 to build without one.',
    );
  }
}

/**
 * Strips the leading and trailing slashes the API returns on paths. Angular
 * substitutes the wildcard verbatim, so a leading slash would produce '/c//x/'.
 */
function normalise(values: readonly string[] | undefined): readonly string[] {
  return (values ?? [])
    .map((value) => value.replace(/^\/+|\/+$/g, ''))
    .filter((value) => value !== '');
}

function describe(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}
