import {
  ApplicationConfig,
  provideBrowserGlobalErrorListeners,
  provideZonelessChangeDetection,
} from '@angular/core';
import { provideClientHydration } from '@angular/platform-browser';
import { UrlSerializer, provideRouter, withInMemoryScrolling } from '@angular/router';
import { provideHttpClient, withFetch } from '@angular/common/http';
import { routes } from './app.routes';
import { TrailingSlashUrlSerializer } from './core/routing/trailing-slash-url-serializer';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),

    provideRouter(
      routes,
      // Anchor scrolling makes in-page jumps work; position restoration keeps
      // the scroll offset on back, which matters on long category listings.
      withInMemoryScrolling({ anchorScrolling: 'enabled', scrollPositionRestoration: 'enabled' }),
    ),

    /*
     * withFetch() rather than the XHR backend: the prerender runs in Node,
     * where XHR does not exist. Section 15.1's generated client goes through
     * HttpClient precisely so these requests are visible to TransferState.
     */
    // Every internal href keeps its trailing slash (SEO_SPEC.md section 2).
    // Without this the router strips it and each link costs a redirect.
    { provide: UrlSerializer, useClass: TrailingSlashUrlSerializer },

    /*
     * Full SSR with hydration. The server (app.config.server.ts) renders the
     * route to HTML with its data already resolved; the browser now REUSES that
     * DOM instead of tearing it down and rendering again from an empty shell.
     *
     * Before this, the app booted CSR-only: the server HTML was discarded on
     * load and every async section (home, navigation, the per-department
     * showcases, Shop-by-category, Best sellers) re-fetched and re-rendered from
     * a skeleton. That skeleton -> content swap was the 0.72 mobile CLS, and the
     * wait for the bundle to boot and the data to return was the slow LCP.
     *
     * provideClientHydration also enables the HTTP transfer cache by default:
     * the responses the server already fetched are serialized into the page
     * (an inert application/json script) and replayed synchronously on the
     * client, so the signals start populated (content, not undefined) and the
     * skeletons never render on hydration.
     *
     * withEventReplay is deliberately NOT used. It would inject an EXECUTABLE
     * inline event-contract script into the SSR output, and the storefront CSP
     * is hash-locked from the build (tools/csp-hashes.mjs, script-src without
     * 'unsafe-inline') - a per-page inline script rendered on demand by the
     * engine has no hash in that policy and would be blocked. Hydration itself
     * (DOM reuse + the transfer cache, which is what removes the skeleton swap
     * and the CLS) needs no inline script; only early-tap replay does, and that
     * is the piece we forgo to keep the policy a pure hash list.
     */
    provideClientHydration(),

    /*
     * withFetch() rather than the XHR backend: the server renders in Node, where
     * XHR does not exist, and the fetch backend is what the HTTP transfer cache
     * above hooks into to carry each response into hydration.
     */
    provideHttpClient(withFetch()),
  ],
};
