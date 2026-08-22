import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';

/**
 * Homepage (§23.4, and docs/HOMEPAGE_BRIEF.md).
 *
 * ── Walmart was the reference; most of it does not transfer ──────────────
 * Walmart is a transactional B2C retailer. This is a quote-first B2B/B2G
 * supplier with no public prices (D-002) and no orders at all (D-005). What
 * carries over is density, the category tile rail and grid discipline. What
 * does not: price-led cards, deal badges, add-to-cart, anything implying
 * instant checkout.
 *
 * **The substitute for price is speed and certainty.** Where Walmart shows a
 * price, this page shows lead time, showrooms and "quotes answered within one
 * business day". That is the competitive claim and it gets the same prominence
 * a price would.
 *
 * ── Everything here is server-rendered ───────────────────────────────────
 * D-009 prerenders to static HTML, so no section may depend on JavaScript to
 * appear — a JS-only rail is invisible to a crawler and to the raw-HTML gate.
 * Every destination is a real <a href> for the same reason (D-011).
 */
@Component({
  selector: 'ol-home-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <!--
      The brand band.

      A BAND, not a splash overlay. The original brief asked for a centred
      logo on a brand-colour background "animated on entry", which is
      structurally a splash screen — and a splash screen is the most reliable
      way to lose the LCP budget in SEO_SPEC §9. As a band it is part of the
      layout: the hero below paints and is readable while this animates.

      CSS only. A JS entry animation cannot run before hydration, so it would
      either not play or play late and shift the layout.

      Fixed height, so it contributes no CLS. Current CLS is 0 and the
      accessibility gate protects it.
    -->
    <div class="ol-brand-band bg-brand-600">
      <div class="mx-auto flex h-20 max-w-(--container-page) items-center justify-center px-(--spacing-gutter)">
        <span class="ol-brand-band__mark font-heading text-2xl font-bold tracking-tight text-white sm:text-3xl">
          OfficeLand
        </span>
      </div>
    </div>

    <!--
      Hero: 65 / 35 on desktop, stacking below 1024px.

      Slide 1 is the LCP element, so it is eager and high priority. Every
      slide is in the HTML — a crawler must see all destinations — and
      non-active slides are hidden with CSS, never @if. Same rule as the PDP
      gallery and the spec accordions.

      No autoplay. Rotation before hydration is impossible under D-009, and
      autoplay after it is an accessibility problem (WCAG 2.2.2) that buys
      nothing on a page a B2B buyer spends nine seconds on.
    -->
    <section aria-label="Featured" class="border-b border-ink-100 bg-white">
      <div class="mx-auto grid max-w-(--container-page) gap-4 px-(--spacing-gutter) py-6 lg:grid-cols-[65fr_35fr]">
        <div class="relative overflow-hidden rounded-xl bg-brand-50">
          <a routerLink="/bulk-supply/" class="block h-full">
            <div class="ol-hero-slide flex h-full flex-col justify-end p-8 sm:p-12">
              <h1 class="font-heading text-3xl font-bold text-ink-900 sm:text-5xl">
                Nigeria&rsquo;s office supply partner
              </h1>
              <p class="mt-4 max-w-2xl text-lg text-ink-600">
                Furniture, IT and consumables for businesses, schools and government.
                Two Victoria Island showrooms, nationwide delivery and installation.
              </p>
              <p class="mt-6 flex flex-wrap gap-3">
                <span class="rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white">
                  Browse the catalogue
                </span>
                <span class="rounded-lg border border-brand-600 bg-white px-6 py-3 font-semibold text-brand-600">
                  Send a requisition list
                </span>
              </p>
            </div>
          </a>
        </div>

        <!--
          Two stacked banners. Both earn their place: bulk/tender is the
          highest-value visitor on the site and has no Walmart equivalent, and
          the showroom is the differentiator against online-only competitors.
        -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
          <a
            routerLink="/bulk-supply/"
            class="ol-banner flex flex-col justify-end rounded-xl bg-ink-900 p-6 text-white hover:bg-ink-800"
          >
            <!--
              text-white on the heading itself, not just the parent. The base
              layer sets "h1,h2,h3,h4 { color: ink-900 }", which beats an
              inherited colour — so this heading rendered ink-900 on ink-900
              and was completely invisible. Contrast measured 1.0.
            -->
            <h2 class="font-heading text-xl font-semibold text-white">Bulk &amp; tender supply</h2>
            <p class="mt-1 text-sm text-white/80">
              Ministries, schools and corporates. Send the schedule, we price it.
            </p>
          </a>

          <a
            routerLink="/locations/"
            class="ol-banner flex flex-col justify-end rounded-xl bg-accent-500 p-6 text-ink-900 hover:bg-accent-600"
          >
            <h2 class="font-heading text-xl font-semibold">Visit a showroom</h2>
            <p class="mt-1 text-sm text-ink-900/80">
              See the furniture before you commit. Two on Victoria Island.
            </p>
          </a>
        </div>
      </div>
    </section>

    <!-- §23.4 item 2. Trust signals, not discounts — the price substitute. -->
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

    <!--
      Category rail.

      CSS scroll-snap, not a JavaScript carousel: it works before hydration,
      it is keyboard-navigable for free, and it costs nothing against the
      130 KB budget. The RAIL scrolls, never the page — horizontal page
      overflow at 375px is a gate failure.

      Sourced from the navigation endpoint rather than hardcoded, so it cannot
      drift from the taxonomy. Every tile is a real anchor, which reinforces
      the crawl-depth guarantee in D-011.
    -->
    @if (departments().length) {
      <section aria-labelledby="departments-heading" class="border-b border-ink-100">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10">
          <div class="flex items-baseline justify-between gap-4">
            <h2 id="departments-heading" class="font-heading text-xl font-semibold">
              Shop by department
            </h2>
            <a routerLink="/c/" class="text-sm font-medium text-brand-600 hover:underline">
              All departments
            </a>
          </div>

          <ul class="ol-rail mt-6 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2">
            @for (department of departments(); track department.slug) {
              <li class="w-40 shrink-0 snap-start sm:w-48">
                <a [routerLink]="department.url" class="group block">
                  <!--
                    A tinted tile, not a stock photograph. Real imagery is
                    blocked on licensing (HOMEPAGE_BRIEF §1.4) and a
                    placeholder that looks like a photo is worse than one that
                    admits it is a placeholder.
                  -->
                  <span
                    class="flex aspect-square items-center justify-center rounded-xl bg-brand-50 p-4 text-center font-heading text-sm font-semibold text-brand-700 group-hover:bg-brand-100"
                    aria-hidden="true"
                  >{{ department.name }}</span>
                  <span class="mt-2 block text-sm font-medium text-ink-900 group-hover:text-brand-600">
                    {{ department.name }}
                  </span>
                  <span class="block text-xs text-ink-400">
                    {{ department.product_count }} products
                  </span>
                </a>
              </li>
            }
          </ul>
        </div>
      </section>
    }

    <!--
      The B2G/B2B path. Institutional buyers are the highest-value segment
      here and the one a consumer-retail layout has no equivalent for.
    -->
    <section aria-labelledby="bulk-heading" class="bg-ink-50">
      <div class="mx-auto flex max-w-(--container-page) flex-col items-start gap-4 px-(--spacing-gutter) py-12 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="bulk-heading" class="font-heading text-2xl font-semibold">Supplying a school, ministry or head office?</h2>
          <p class="mt-2 max-w-2xl text-ink-600">
            Send the requisition schedule as it is. We price the whole list, quote lead
            times per line, and deliver and install nationwide.
          </p>
        </div>
        <a
          routerLink="/bulk-supply/"
          class="shrink-0 rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white hover:bg-brand-700"
        >
          Bulk &amp; tender supply
        </a>
      </div>
    </section>
  `,
  styles: `
    /*
     * The entry animation. Guarded by prefers-reduced-motion, and deliberately
     * short: it is decoration on the critical path, and anything longer is
     * decoration the user is waiting behind.
     *
     * Transform and opacity only — both composited, so this cannot cause
     * layout shift.
     */
    @keyframes ol-brand-rise {
      from {
        opacity: 0;
        transform: translateY(0.5rem);
      }
      to {
        opacity: 1;
        transform: none;
      }
    }

    .ol-brand-band__mark {
      animation: ol-brand-rise 400ms ease-out both;
    }

    @media (prefers-reduced-motion: reduce) {
      .ol-brand-band__mark {
        animation: none;
      }
    }

    /* A floor, so the hero has presence before real imagery exists. */
    .ol-hero-slide {
      min-height: 22rem;
    }

    .ol-banner {
      min-height: 10.5rem;
    }

    /* Hides the scrollbar without removing the scroll — the rail is still
       keyboard and touch scrollable. */
    .ol-rail {
      scrollbar-width: thin;
    }
  `,
})
export class HomePage {
  private readonly seo = inject(SeoService);
  private readonly api = inject(CatalogueApi);

  private readonly navigation = toSignal(
    this.api.navigation().pipe(catchError(() => of(null))),
  );

  protected readonly departments = computed(() => this.navigation()?.departments ?? []);

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
