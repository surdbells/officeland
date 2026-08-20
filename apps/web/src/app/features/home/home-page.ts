import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { SeoService } from '../../core/seo/seo.service';

/**
 * Homepage (section 23.4).
 *
 * Phase 7 establishes the structure and the SEO contract. Merchandising rails
 * — category tiles, solution sets, brand rail, guides row — arrive in Phase 8
 * with the components that populate them.
 *
 * What is already load-bearing: the title replaces a 389-character legacy one
 * (defect 4A), and the USP strip is the section 23.4 item 2 pattern, which
 * does more conversion work than anything else on the page for a B2B visitor
 * who has never heard of the company.
 */
@Component({
  selector: 'ol-home-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <section class="bg-brand-50">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-16">
        <h1 class="font-heading text-3xl font-bold text-ink-900 sm:text-5xl">
          Nigeria&rsquo;s office supply partner
        </h1>
        <p class="mt-4 max-w-2xl text-lg text-ink-600">
          Furniture, IT and consumables for businesses, schools and government.
          Two Victoria Island showrooms, nationwide delivery and installation.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a routerLink="/c/" class="rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white hover:bg-brand-700">
            Browse the catalogue
          </a>
          <a routerLink="/quote/" class="rounded-lg border border-brand-600 px-6 py-3 font-semibold text-brand-600 hover:bg-white">
            Request a quote
          </a>
        </div>
      </div>
    </section>

    <!-- Section 23.4 item 2. Trust signals, not discounts. -->
    <section aria-label="Why OfficeLand" class="border-b border-ink-100">
      <ul class="mx-auto grid max-w-(--container-page) gap-6 px-(--spacing-gutter) py-8 sm:grid-cols-2 lg:grid-cols-4">
        <li>
          <h2 class="font-heading text-base font-semibold">Two Victoria Island showrooms</h2>
          <p class="mt-1 text-sm text-ink-600">See the furniture before you commit.</p>
        </li>
        <li>
          <h2 class="font-heading text-base font-semibold">4.4&#9733; from 138 Google reviews</h2>
          <p class="mt-1 text-sm text-ink-600">Rated by the businesses we supply.</p>
        </li>
        <li>
          <h2 class="font-heading text-base font-semibold">Quotes answered within 1 business day</h2>
          <p class="mt-1 text-sm text-ink-600">Send a requisition list and we price it.</p>
        </li>
        <li>
          <h2 class="font-heading text-base font-semibold">Nationwide delivery &amp; installation</h2>
          <p class="mt-1 text-sm text-ink-600">Lead times quoted per order.</p>
        </li>
      </ul>
    </section>
  `,
})
export class HomePage {
  private readonly seo = inject(SeoService);

  constructor() {
    /*
     * The legacy homepage title is the company name followed by a ~330
     * character marketing paragraph, so every SERP snippet it produces is
     * truncated garbage (defect 4A). This is the section 14.3 template, within
     * the 60-character budget the service enforces.
     */
    this.seo.apply({
      // 36 characters, so the full template fits the 60-char budget with
      // the site name intact. The longer "Office Supplies, IT & Furniture in
      // Lagos" overflowed by one character and the budget dropped "Lagos" --
      // the single most valuable word in the title for local intent.
      title: 'Office Supplies & Furniture in Lagos',
      description:
        'Office furniture, IT products and consumables for businesses, schools and government across Nigeria. Two Lagos showrooms. Quotes in one business day.',
      path: '/',
      type: 'website',
      indexable: true,
    });
  }
}
