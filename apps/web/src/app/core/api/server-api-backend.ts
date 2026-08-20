import { HttpBackend, HttpEvent, HttpRequest, FetchBackend } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';

/** Only catalogue calls are redirected; anything else is left alone. */
const API_PREFIX = '/api/';

/**
 * Points API calls at the real API while prerendering (D-009).
 *
 * ── The problem ──────────────────────────────────────────────────────────
 * Application code requests '/api/v1/navigation'. In the browser that is
 * same-origin and correct. During prerendering, platform-server's
 * `relativeUrlsTransformerInterceptorFn` resolves every relative URL against
 * the current document origin — which, in a prerender, is the throwaway HTTP
 * server the Angular builder starts on a random port to invoke server.ts. The
 * request therefore arrives back at the application's own Express handler,
 * which has no /api route:
 *
 *     Error: Unable to handle request: '/api/v1/navigation'.
 *
 * So this is not a missing-base-URL problem. The base URL is present and
 * wrong, and it is applied by a root interceptor that runs before any backend.
 * Matching on the PATH rather than on the absence of an origin is what makes
 * the rewrite survive that.
 *
 * ── Why a backend and not an interceptor ─────────────────────────────────
 * The transfer cache interceptor (section 14.1: hydration must not re-fetch
 * what the render already fetched) computes its key from the request URL as it
 * sees it. It is registered before platform-server's transformer, so on the
 * server it keys on the RELATIVE url, exactly as the browser will. Rewriting
 * anywhere in the interceptor chain risks keying the server's entries on a
 * random localhost port that the browser could never reproduce — every entry
 * would miss and all 15,000 pages would re-fetch on hydration.
 *
 * A backend sits below every interceptor, so the cache keys stay untouched.
 *
 * HTTP_TRANSFER_CACHE_ORIGIN_MAP is the framework's tool for the neighbouring
 * problem, but it maps one absolute origin onto another and cannot map onto a
 * relative path, and the prerender origin is a different random port per run.
 *
 * ── Configuration ────────────────────────────────────────────────────────
 * PRERENDER_API_ORIGIN, the same variable the route manifest reads, so page
 * content and the route list can never come from different environments.
 */
@Injectable()
export class ServerApiBackend implements HttpBackend {
  private readonly delegate = inject(FetchBackend);

  private readonly origin = (
    process.env['PRERENDER_API_ORIGIN'] ?? 'http://localhost:8095'
  ).replace(/\/+$/, '');

  handle(request: HttpRequest<unknown>): Observable<HttpEvent<unknown>> {
    const target = this.redirect(request.url);

    return this.delegate.handle(target === null ? request : request.clone({ url: target }));
  }

  /** Returns the API-origin URL for a catalogue call, or null to pass through. */
  private redirect(url: string): string | null {
    if (url.startsWith(API_PREFIX)) {
      return this.origin + url;
    }

    // Absolute by the time it reaches here, in every real prerender.
    let parsed: URL;

    try {
      parsed = new URL(url);
    } catch {
      // Not a URL this backend can reason about; the delegate will report it
      // far more usefully than a rewrite would.
      return null;
    }

    if (!parsed.pathname.startsWith(API_PREFIX)) {
      return null;
    }

    return this.origin + parsed.pathname + parsed.search;
  }
}
