import { RenderMode, ServerRoute } from '@angular/ssr';

/**
 * Dynamic Rendering configuration.
 *
 * ── The architecture this replaces ───────────────────────────────────────
 * Every route used to be `RenderMode.Prerender`: 1,500+ HTML files written at
 * build time (D-009), which made a full build a ~15-minute job dominated by
 * the 1,190 noindex demo products. That is gone. Nothing is prerendered now,
 * so a build is seconds.
 *
 * ── What serves whom ─────────────────────────────────────────────────────
 * Humans get the client-rendered SPA: Caddy serves the static shell and the
 * browser renders (no SSR, no hydration). Crawlers and social scrapers - the
 * clients that do not run JavaScript - are routed by user-agent at the edge to
 * this same app rendered on demand by the Angular server engine (server.ts,
 * :4000), which produces fully-formed HTML with SeoService's title, meta,
 * canonical and JSON-LD already written into the head. That is Dynamic
 * Rendering: one app, rendered in the browser for people and on the server for
 * bots, from the same components and the same data.
 *
 * `RenderMode.Server` is what makes the engine render a route on request
 * rather than expecting a prerendered file. There are no `getPrerenderParams`
 * because nothing is enumerated ahead of time - any path the router knows is
 * renderable the instant it is asked for.
 */
export const serverRoutes: ServerRoute[] = [
  {
    path: '**',
    renderMode: RenderMode.Server,
  },
];
