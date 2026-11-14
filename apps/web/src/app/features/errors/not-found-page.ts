import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Meta } from '@angular/platform-browser';
import { RouterLink } from '@angular/router';
import { SeoService } from '../../core/seo/seo.service';
import { Icon } from '../../shared/icon';
import { DepartmentTiles } from '../../shared/department-tiles';

/**
 * 404 with a search box and top categories (section 14.8).
 *
 * Never a soft-404: this page is noindex, and the server returns a real 404
 * status. Returning 200 with "not found" content is called out explicitly as
 * forbidden.
 *
 * ── How the 404 status survives Dynamic Rendering ────────────────────────
 * A crawler gets this page from the SSR render worker, not from a prerendered
 * file, so the status can no longer come from Caddy serving `404/index.html`.
 * Instead this page stamps a marker meta - `ol-render-status: 404` - into the
 * head, and the render worker (server.ts) reads it and sets the HTTP status to
 * 404 before returning. `noindex` alone could not carry the signal: search,
 * quote and demo pages are noindex too, and only this one is a not-found.
 */
@Component({
  selector: 'ol-not-found-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, DepartmentTiles],
  template: `
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      <span aria-hidden="true" class="ol-404-glow absolute inset-0 -z-10"></span>
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14 sm:py-20">
        <p class="font-heading text-7xl font-bold leading-none text-white/10 sm:text-9xl" aria-hidden="true">404</p>
        <h1 class="-mt-6 font-heading text-3xl font-bold tracking-tight text-white sm:-mt-10 sm:text-display">We couldn&rsquo;t find that page</h1>
        <p class="mt-3 max-w-2xl text-white/80 sm:text-lg">
          It may have moved, or the address may be slightly off. Search for it, or start from a department below.
        </p>

        <form action="/search" method="get" role="search" aria-label="Search from the not-found page" class="mt-7 max-w-2xl">
          <label for="notfound-search" class="sr-only">Search products</label>
          <div class="flex items-stretch overflow-hidden rounded-full bg-white shadow-lg">
            <span class="flex items-center pl-5 text-ink-400"><ol-icon name="search" [size]="20" /></span>
            <input
              id="notfound-search"
              name="q"
              type="search"
              placeholder="Search by product, brand or model code"
              class="min-w-0 flex-1 border-0 bg-transparent px-4 py-4 text-ink-900 placeholder:text-ink-400 focus:outline-none"
            />
            <button type="submit" class="m-1.5 cursor-pointer rounded-full bg-brand-600 px-6 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
          </div>
        </form>

        <div class="mt-6 flex flex-wrap gap-3">
          <a routerLink="/" class="inline-flex items-center gap-2 rounded-full border border-white/50 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-white/10">
            <ol-icon name="home" [size]="16" /> Home
          </a>
          <a routerLink="/quote/" class="inline-flex items-center gap-2 rounded-full border border-white/50 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-white/10">
            <ol-icon name="file-text" [size]="16" /> Request a quote
          </a>
        </div>
      </div>
    </section>

    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10 sm:py-14">
      <h2 class="font-heading text-xl font-semibold text-ink-900">Start from a department</h2>
      <div class="mt-4"><ol-department-tiles /></div>
    </div>
  `,
  styles: `
    .ol-404-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }
  `,
})
export class NotFoundPage {
  private readonly seo = inject(SeoService);
  private readonly meta = inject(Meta);

  constructor() {
    this.seo.apply({
      title: 'Page not found',
      path: '/404/',
      indexable: false,
    });

    // The render worker reads this and returns a 404 status (see the class
    // comment). Harmless in a browser - a meta a human never sees - and the
    // signal a bot's response needs.
    this.meta.updateTag({ name: 'ol-render-status', content: '404' });
  }
}
