import {
  ApplicationConfig,
  provideBrowserGlobalErrorListeners,
  provideZonelessChangeDetection,
} from '@angular/core';
import { UrlSerializer, provideRouter, withInMemoryScrolling } from '@angular/router';
import { provideHttpClient, withFetch } from '@angular/common/http';
import {
  provideClientHydration,
  withHttpTransferCacheOptions,
  withEventReplay,
} from '@angular/platform-browser';
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

    provideHttpClient(withFetch()),

    /*
     * Section 14.1: hydration must not re-fetch what the render already
     * fetched, and duplicate hydration requests are a gate failure. The
     * transfer cache serialises GET responses into the HTML.
     *
     * includePostRequests stays false — the quote submission (Phase 9) is a
     * POST, and replaying it from a cache would be a duplicate lead.
     */
    provideClientHydration(
      // Clicks landing before hydration are replayed, not lost. On a
      // prerendered site the gap between paint and hydration is where the
      // "Add to quote" button lives.
      withEventReplay(),
      withHttpTransferCacheOptions({
        includePostRequests: false,
        filter: (req) => req.url.startsWith('/api/'),
      }),
    ),
  ],
};
