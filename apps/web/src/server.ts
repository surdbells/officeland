import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import express from 'express';
import { join } from 'node:path';

/*
 * ── On angular.json security.allowedHosts ────────────────────────────────
 * Angular 22 rejects a server-rendered request whose Host header is not on
 * that list. It is SSRF protection, which is exactly why it is NOT a
 * wildcard: this process renders arbitrary routes and would otherwise make a
 * useful confused deputy.
 *
 * Only the internal names the render worker is reached by are listed. The API
 * asks it for one route at a time over the compose network
 * (http://render:4000), Caddy never proxies here, and nothing outside that
 * network can reach it. Adding a public hostname there would be a real change
 * in exposure, not a configuration tidy-up.
 */

const browserDistFolder = join(import.meta.dirname, '../browser');

const app = express();
const angularApp = new AngularNodeAppEngine();

/**
 * Example Express Rest API endpoints can be defined here.
 * Uncomment and define endpoints as necessary.
 *
 * Example:
 * ```ts
 * app.get('/api/{*splat}', (req, res) => {
 *   // Handle API request
 * });
 * ```
 */

/**
 * Serve static files from /browser
 */
app.use(
  express.static(browserDistFolder, {
    maxAge: '1y',
    index: false,
    redirect: false,
  }),
);

/**
 * Handle all other requests by rendering the Angular application.
 *
 * ── The 404 status is recovered from the rendered head ───────────────────
 * A crawler reaches an unknown URL through here, and the Angular router
 * resolves it to the not-found page. That page stamps `ol-render-status: 404`
 * into its head; without acting on it the worker would return the not-found
 * body under a 200, which is the soft-404 SEO_SPEC section 8 forbids. So the
 * rendered HTML is read once, the marker is checked, and the status is
 * corrected to 404 before the response is written. The body is passed through
 * unchanged - the marker is an inert meta tag - so nothing else is affected.
 */
app.use(async (req, res, next) => {
  try {
    const response = await angularApp.handle(req);

    if (!response) {
      next();
      return;
    }

    const html = await response.text();
    // The marker name appears on the not-found page and nowhere else, so its
    // mere presence is the signal - no dependence on attribute order.
    const status = html.includes('ol-render-status') ? 404 : response.status;

    writeResponseToNodeResponse(
      new Response(html, {
        status,
        statusText: response.statusText,
        headers: response.headers,
      }),
      res,
    );
  } catch (error) {
    next(error);
  }
});

/**
 * Start the server if this module is the main entry point, or it is ran via PM2.
 * The server listens on the port defined by the `PORT` environment variable, or defaults to 4000.
 */
if (isMainModule(import.meta.url) || process.env['pm_id']) {
  const port = process.env['PORT'] || 4000;
  app.listen(port, (error) => {
    if (error) {
      throw error;
    }

    console.log(`Node Express server listening on http://localhost:${port}`);
  });
}

/**
 * Request handler used by the Angular CLI (for dev-server and during build) or Firebase Cloud Functions.
 */
export const reqHandler = createNodeRequestHandler(app);
