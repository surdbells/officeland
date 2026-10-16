import {
  ApplicationConfig,
  provideBrowserGlobalErrorListeners,
  provideZonelessChangeDetection,
} from '@angular/core';
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
     * Client-side rendering (CSR): the app boots and renders in the browser,
     * so there is no server-rendered HTML to hydrate and no TransferState to
     * seed. Crawlers and social scrapers are served fully-rendered snapshots
     * by the Dynamic Rendering service (infra/renderer), which runs this same
     * app in headless Chrome and captures the DOM once SeoService has written
     * the head and the route's data has loaded.
     */
    provideHttpClient(withFetch()),
  ],
};
