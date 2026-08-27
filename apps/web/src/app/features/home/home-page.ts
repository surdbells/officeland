import {
  DestroyRef,
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { Icon, type IconName } from '../../shared/icon';
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
 * appear - a JS-only rail is invisible to a crawler and to the raw-HTML gate.
 * Every destination is a real <a href> for the same reason (D-011).
 */
@Component({
  selector: 'ol-home-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    <!--
      Hero: 65 / 35 on desktop, stacking below 1024px.

      Slide 1 is the LCP element, so it is eager and high priority. Every
      slide is in the HTML - a crawler must see all destinations - and
      non-active slides are hidden with CSS, never @if. Same rule as the PDP
      gallery and the spec accordions.

      No autoplay. Rotation before hydration is impossible under D-009, and
      autoplay after it is an accessibility problem (WCAG 2.2.2) that buys
      nothing on a page a B2B buyer spends nine seconds on.
    -->
    <section aria-label="Featured" class="border-b border-ink-100 bg-white">
      <div class="mx-auto grid max-w-(--container-page) gap-4 px-(--spacing-gutter) py-6 lg:grid-cols-[65fr_35fr]">
        <div class="relative overflow-hidden rounded-2xl bg-brand-50 shadow-sm">
          <a routerLink="/bulk-supply/" class="block h-full">
            <div class="ol-hero-slide flex h-full flex-col justify-end p-8 sm:p-12">
              <h1 class="font-heading text-display-sm font-bold text-ink-900 sm:text-display-lg">
                Nigeria&rsquo;s office supply partner
              </h1>
              <p class="mt-4 max-w-2xl text-lg text-ink-600">
                Furniture, IT and consumables for businesses, schools and government.
                Two Victoria Island showrooms, nationwide delivery and installation.
              </p>
              <p class="mt-6 flex flex-wrap gap-3">
                <span class="rounded-xl bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand">
                  Browse the catalogue
                </span>
                <span class="rounded-xl border border-brand-600 bg-white px-6 py-3 font-semibold text-brand-600 shadow-sm">
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
            class="ol-banner flex flex-col justify-end rounded-2xl bg-ink-900 p-6 text-white shadow-md transition-colors hover:bg-ink-800"
          >
            <!--
              text-white on the heading itself, not just the parent. The base
              layer sets "h1,h2,h3,h4 { color: ink-900 }", which beats an
              inherited colour - so this heading rendered ink-900 on ink-900
              and was completely invisible. Contrast measured 1.0.
            -->
            <h2 class="font-heading text-xl font-semibold text-white">Bulk &amp; tender supply</h2>
            <p class="mt-1 text-sm text-white/80">
              Ministries, schools and corporates. Send the schedule, we price it.
            </p>
          </a>

          <a
            routerLink="/locations/"
            class="ol-banner flex flex-col justify-end rounded-2xl bg-accent-500 p-6 text-ink-900 shadow-md transition-colors hover:bg-accent-600"
          >
            <h2 class="font-heading text-xl font-semibold">Visit a showroom</h2>
            <p class="mt-1 text-sm text-ink-900/80">
              See the furniture before you commit. Two on Victoria Island.
            </p>
          </a>
        </div>
      </div>
    </section>

    <!-- §23.4 item 2. Trust signals, not discounts - the price substitute. -->
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
      130 KB budget. The RAIL scrolls, never the page - horizontal page
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

          <!--
            A carousel, not a scrollbar.

            The rail was a plain overflow-x container, which put a browser
            scrollbar across the bottom of the section. That reads as a bug
            rather than a control - nobody expects a horizontal scrollbar in
            the middle of a page - so the bar is hidden and replaced with
            buttons.

            ── Still a scroll container underneath ──────────────────────────
            The buttons drive "scrollBy"; they do not reimplement scrolling.
            That keeps every native behaviour intact: trackpad swipe, shift +
            wheel, touch drag, and - the one that matters for accessibility -
            tabbing to a link off-screen scrolls it into view on its own.
            Hiding a scrollbar is only safe because none of that depends on it
            being visible.

            ── Why the buttons are progressive enhancement ──────────────────
            Under D-009 this markup is prerendered, so the buttons exist in
            the static HTML but do nothing until hydration. Every department
            is still a real anchor in that HTML and the rail still scrolls by
            touch and keyboard, so the pre-hydration page is usable rather
            than merely present.

            aria-hidden on the buttons: they duplicate scrolling a screen
            reader user gets for free by moving through the links, and
            announcing "previous/next" for a list that reading order already
            covers is noise. They are pointer affordances.
          -->
          <div class="relative mt-6">
            <ul
              #rail
              class="ol-rail flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2"
              (scroll)="onRailScroll()"
            >
              @for (department of departments(); track department.slug) {
                <li class="w-40 shrink-0 snap-start sm:w-48">
                  <a [routerLink]="department.url" class="group block">
                    <!--
                      A tinted tile, not a stock photograph. Real imagery is
                      blocked on licensing (HOMEPAGE_BRIEF §1.4) and a
                      placeholder that looks like a photo is worse than one
                      that admits it is a placeholder.
                    -->
                    <span
                      class="flex aspect-square items-center justify-center rounded-2xl bg-brand-50 transition-colors p-4 text-center font-heading text-sm font-semibold text-brand-700 group-hover:bg-brand-100"
                      aria-hidden="true"
                    >{{ department.name }}</span>
                    <span class="mt-2 block text-sm font-medium text-ink-900 group-hover:text-brand-600">
                      {{ department.name }}
                    </span>
                    <!--
                      The count only when there is one. Every department read
                      "0 products" while the catalogue is being populated,
                      which is both discouraging and misleading - we supply
                      far more than we list, and the tile was saying the
                      opposite.
                    -->
                    @if (department.product_count) {
                      <span class="block text-xs text-ink-400">
                        {{ department.product_count }} products
                      </span>
                    }
                  </a>
                </li>
              }
            </ul>

            @if (canScrollBack()) {
              <button
                type="button"
                aria-hidden="true"
                tabindex="-1"
                class="ol-rail-button left-0 -translate-x-1/2"
                (click)="scrollRail(-1)"
              >
                <ol-icon name="chevron-right" [size]="20" class="rotate-180" />
              </button>
            }

            @if (canScrollOn()) {
              <button
                type="button"
                aria-hidden="true"
                tabindex="-1"
                class="ol-rail-button right-0 translate-x-1/2"
                (click)="scrollRail(1)"
              >
                <ol-icon name="chevron-right" [size]="20" />
              </button>
            }
          </div>
        </div>
      </section>
    }

    <!--
      WPC Doors & Interiors.

      A separate DIVISION, not a product category - kitchens, wardrobes,
      furniture and interiors, sold on material properties rather than on a
      catalogue listing. It gets its own band with its own visual treatment
      for that reason: folding it into the department rail would file a
      fit-out business under "office supplies" and lose it.

      Note it is absent from the Phase 0 taxonomy (124 categories, 7
      departments). That is a real gap, not a styling one - see the handover.

      Scroll-snap again, not a JS carousel: same reasoning as the department
      rail, and it keeps the JS budget for the parts of the site that need it.
    -->
    <section aria-labelledby="wpc-heading" class="bg-ink-900 text-white">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-16 sm:py-20">
        <div class="max-w-3xl">
          <p class="font-heading text-sm font-semibold uppercase tracking-[0.2em] text-accent-400">
            OfficeLand WPC - Doors &amp; Interiors
          </p>
          <h2 id="wpc-heading" class="mt-4 font-heading text-display-sm font-bold text-white sm:text-display">
            The beauty you see. The strength you don&rsquo;t.
          </h2>
          <p class="mt-5 text-lg text-white/75">
            Wood-plastic composite boards that build stunning interiors and stand
            strong inside out. Specified for kitchens, wardrobes, furniture and
            full interior fit-outs across Lagos.
          </p>
        </div>

        <!--
          The six properties are the entire sales argument for WPC over MDF or
          plywood in this climate. Termite and moisture resistance is not a
          feature list here - it is why the material exists.
        -->
        <ul class="mt-10 grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3 lg:grid-cols-6">
          @for (property of wpcProperties; track property.name) {
            <li class="border-t border-white/15 pt-4">
              <span class="inline-flex text-accent-400">
                <ol-icon [name]="property.icon" [size]="22" />
              </span>
              <h3 class="mt-2.5 font-heading text-sm font-semibold text-white">{{ property.name }}</h3>
              <p class="mt-1 text-sm text-white/60">{{ property.detail }}</p>
            </li>
          }
        </ul>

        <div class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3">
          <a
            routerLink="/quote/"
            class="rounded-xl bg-accent-500 px-6 py-3 font-semibold text-ink-900 shadow-lg transition-colors hover:bg-accent-400"
          >
            Request a WPC quote
          </a>
          <p class="text-sm text-white/60">
            Kitchens &middot; Wardrobes &middot; Furniture &middot; Interiors
          </p>
        </div>
      </div>
    </section>

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
          class="shrink-0 rounded-xl bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700"
        >
          Bulk &amp; tender supply
        </a>
      </div>
    </section>
  `,
  styles: `
    /* A floor, so the hero has presence before real imagery exists. */
    .ol-hero-slide {
      min-height: 22rem;
    }

    .ol-banner {
      min-height: 10.5rem;
    }

    /*
     * The scrollbar is hidden, not the scrolling.
     *
     * Safe only because nothing depends on the bar being visible: the buttons
     * drive scrollBy, touch and trackpad gestures are untouched, and tabbing
     * to an off-screen link scrolls it into view natively. Hiding a scrollbar
     * on a container whose only affordance IS the scrollbar would be an
     * accessibility failure; here it removes a control that had been
     * duplicated.
     */
    .ol-rail {
      scrollbar-width: none;
    }

    .ol-rail::-webkit-scrollbar {
      display: none;
    }

    /*
     * The buttons straddle the rail edge rather than sitting inside it, so
     * they never cover a tile. Half of each sits over the gutter, which is
     * why the container is not "overflow: hidden".
     */
    .ol-rail-button {
      position: absolute;
      top: 4.5rem;
      display: none;
      height: 2.75rem;
      width: 2.75rem;
      align-items: center;
      justify-content: center;
      border-radius: 9999px;
      border: 1px solid var(--color-ink-100);
      background: #fff;
      color: var(--color-ink-900);
      box-shadow: var(--shadow-lg, 0 10px 30px rgb(0 0 0 / 0.12));
      transition: background-color 150ms var(--ease-out-soft);
    }

    .ol-rail-button:hover {
      background: var(--color-brand-50);
    }

    /*
     * Pointer devices only. On a touch screen the gesture is the control, a
     * floating button over a scrollable rail is something to hit by accident,
     * and there is no hover state to hint that it is there.
     */
    @media (hover: hover) and (pointer: fine) {
      .ol-rail-button {
        display: flex;
      }
    }
  `,
})
export class HomePage {

  /**
   * The rail element, so the buttons can scroll it.
   *
   * A viewChild rather than a document query: this component is rendered at
   * build time by Domino as well as in a browser, and a document lookup on
   * the server finds a different document or none at all.
   */
  private readonly rail = viewChild<ElementRef<HTMLElement>>('rail');

  /**
   * Where the rail is, remeasured on scroll.
   *
   * Signals rather than reading the element in the template: a template that
   * reads scrollLeft is re-read on every change detection pass and forces a
   * layout each time.
   */
  private readonly scrollLeft = signal(0);
  private readonly scrollWidth = signal(0);
  private readonly clientWidth = signal(0);

  /*
   * Both start false so the buttons are absent from the prerendered HTML.
   * Rendering them into the static page would show two dead controls until
   * hydration, and the left one would be pointing at content that is already
   * on screen.
   */
  protected readonly canScrollBack = computed(() => this.scrollLeft() > 8);

  protected readonly canScrollOn = computed(
    () => this.scrollLeft() + this.clientWidth() < this.scrollWidth() - 8,
  );

  protected onRailScroll(): void {
    const element = this.rail()?.nativeElement;

    if (!element) {
      return;
    }

    this.scrollLeft.set(element.scrollLeft);
    this.scrollWidth.set(element.scrollWidth);
    this.clientWidth.set(element.clientWidth);
  }

  /**
   * Scrolls by roughly a screen, less one tile.
   *
   * The overlap is deliberate: paging by exactly one viewport leaves the
   * reader with no anchor between pages, and a partly visible tile is what
   * tells them the rail moved rather than replaced itself.
   *
   * `scroll-snap` on the list aligns the result to a tile boundary, so this
   * does not need to know the tile width.
   */
  protected scrollRail(direction: 1 | -1): void {
    const element = this.rail()?.nativeElement;

    if (!element) {
      return;
    }

    element.scrollBy({
      left: direction * Math.max(element.clientWidth - 96, 160),
      behavior: 'smooth',
    });
  }
  private readonly seo = inject(SeoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly api = inject(CatalogueApi);

  private readonly navigation = toSignal(
    this.api.navigation().pipe(catchError(() => of(null))),
  );

  protected readonly departments = computed(() => this.navigation()?.departments ?? []);

  /**
   * WPC's material properties.
   *
   * Hardcoded rather than pulled from attributes because these describe a
   * DIVISION, not a product - there is no WPC product in the catalogue to
   * carry them, and inventing one to hold six marketing claims would put
   * copy into the catalogue where the content floor (D-048) expects
   * specifications.
   *
   * They move into the CMS with the WPC landing page.
   */
  protected readonly wpcProperties = [
    { icon: 'droplets', name: '100% waterproof', detail: 'Kitchens and wet areas without swelling.' },
    { icon: 'bug-off', name: 'Termite proof', detail: 'The failure mode that ends timber here.' },
    { icon: 'flame', name: 'Fire retardant', detail: 'Slows ignition and spread.' },
    { icon: 'layers', name: 'High density core', detail: 'Holds a screw and takes a load.' },
    { icon: 'leaf', name: 'Eco friendly', detail: 'No felled hardwood in the board.' },
    { icon: 'sparkles', name: 'Low maintenance', detail: 'Wipes clean; no sealing or refinishing.' },
  ] as const satisfies readonly { icon: IconName; name: string; detail: string }[];

  constructor() {
    /*
     * Measure the rail once it exists, and again when the viewport changes.
     *
     * The buttons were absent on load without this: they are derived from
     * scrollWidth and clientWidth, and those were only read in the scroll
     * handler - so nothing had measured the rail until somebody scrolled it,
     * which is precisely what the buttons exist to let them do.
     *
     * afterNextRender is browser-only by contract, which is what makes it
     * right here. Reading layout during prerender asks Domino for a
     * scrollWidth it does not compute, and the answer would be baked into the
     * static HTML for every viewport.
     */
    afterNextRender(() => {
      this.onRailScroll();

      const remeasure = (): void => this.onRailScroll();

      window.addEventListener('resize', remeasure, { passive: true });
      this.destroyRef.onDestroy(() => window.removeEventListener('resize', remeasure));
    });

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
