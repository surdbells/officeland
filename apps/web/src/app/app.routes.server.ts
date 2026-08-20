import { RenderMode, ServerRoute } from '@angular/ssr';
import { fetchRouteManifest } from './core/rendering/route-manifest';

/**
 * Prerender configuration (D-009).
 *
 * Every storefront route is rendered to a static HTML file at build time and
 * served by Caddy. There is no Node process in production.
 *
 * This is only viable because of D-002: with no prices and no stock levels
 * there is nothing on a catalogue page that goes stale between publishes. The
 * moment a price appears on a page, this decision has to be revisited.
 *
 * The parameter lists come from the API, not from a checked-in file, because a
 * checked-in list of 15,000 slugs would be stale the day it was written. Phase
 * 8 adds the incremental half: a publish enqueues only the affected routes
 * instead of re-rendering all of them.
 */
export const serverRoutes: ServerRoute[] = [
  {
    path: '',
    renderMode: RenderMode.Prerender,
  },
  {
    path: 'c',
    renderMode: RenderMode.Prerender,
  },
  {
    // The wildcard carries the whole materialized path, so one entry covers
    // all three levels of the taxonomy.
    path: 'c/**',
    renderMode: RenderMode.Prerender,
    getPrerenderParams: async () => {
      const manifest = await fetchRouteManifest();
      return manifest.categories.map((path) => ({ '**': path }));
    },
  },
  {
    path: 'p/:slug',
    renderMode: RenderMode.Prerender,
    getPrerenderParams: async () => {
      const manifest = await fetchRouteManifest();
      return manifest.products.map((slug) => ({ slug }));
    },
  },
  {
    /*
     * The 404 template is prerendered to a single file that Caddy serves with
     * a 404 status. Rendering it per-URL would mean prerendering the infinite
     * set of URLs that do not exist.
     */
    path: '**',
    renderMode: RenderMode.Prerender,
  },
];
