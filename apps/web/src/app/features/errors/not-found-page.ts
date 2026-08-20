import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { SeoService } from '../../core/seo/seo.service';

/**
 * 404 with a search box and top categories (section 14.8).
 *
 * Never a soft-404: this page is noindex, and the server returns a real 404
 * status. Returning 200 with "not found" content is called out explicitly as
 * forbidden.
 */
@Component({
  selector: 'ol-not-found-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-20">
      <h1 class="font-heading text-3xl font-bold">We couldn&rsquo;t find that page</h1>
      <p class="mt-3 text-ink-600">
        It may have moved, or the address may be slightly off. Try a search, or start from a department.
      </p>

      <form action="/search" method="get" role="search" class="mt-6 max-w-lg">
        <label for="notfound-search" class="sr-only">Search products</label>
        <input
          id="notfound-search"
          name="q"
          type="search"
          placeholder="Search by product, brand or model code"
          class="w-full rounded-lg border border-ink-200 px-4 py-3"
        />
      </form>

      <nav aria-label="Departments" class="mt-8">
        <h2 class="font-heading text-sm font-semibold uppercase tracking-wide">Departments</h2>
        <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <li><a routerLink="/c/office-furniture/" class="text-brand-600 hover:underline">Office furniture</a></li>
          <li><a routerLink="/c/it-products/" class="text-brand-600 hover:underline">IT products</a></li>
          <li><a routerLink="/c/printers-consumables/" class="text-brand-600 hover:underline">Printers &amp; consumables</a></li>
          <li><a routerLink="/c/office-supplies/" class="text-brand-600 hover:underline">Office supplies</a></li>
          <li><a routerLink="/c/paper-notebooks/" class="text-brand-600 hover:underline">Paper &amp; notebooks</a></li>
          <li><a routerLink="/c/filing-storage/" class="text-brand-600 hover:underline">Filing &amp; storage</a></li>
        </ul>
      </nav>
    </div>
  `,
})
export class NotFoundPage {
  private readonly seo = inject(SeoService);

  constructor() {
    this.seo.apply({
      title: 'Page not found',
      path: '/404',
      indexable: false,
    });
  }
}
