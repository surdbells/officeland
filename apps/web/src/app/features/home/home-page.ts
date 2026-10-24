import {
  DestroyRef,
  PLATFORM_ID,
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { RouterLink } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, forkJoin, map, of, switchMap } from 'rxjs';
import { CatalogueApi, type Image, type ProductCard as ProductCardData } from '../../core/api/catalogue-api';
import { ProductCard } from '../../shared/product-card';
import { Icon, type IconName } from '../../shared/icon';
import { SeoService } from '../../core/seo/seo.service';
import { EMPTY_LISTING } from '../../shared/listing-state';
import { DepartmentShowcase, type ShowcaseStyle } from './department-showcase';
import { Reveal } from '../../shared/reveal';
import { CountUp } from '../../shared/count-up';
import { ResponsiveImage } from '../../shared/responsive-image';
import { Skeleton } from '../../shared/skeleton';
import { ProductCardSkeleton } from '../../shared/product-card-skeleton';

/** One homepage section: a department, its products, and the shape it takes. */
interface ShowcaseSection {
  readonly name: string;
  readonly slug: string;
  readonly url: string;
  readonly products: readonly ProductCardData[];
  readonly style: ShowcaseStyle;
}

/**
 * Cycled across the departments so no two adjacent sections look alike.
 *
 * Three is enough to break the rhythm without the page feeling like a
 * catalogue of layouts.
 */
const SHOWCASE_STYLES: readonly ShowcaseStyle[] = ['grid', 'feature', 'rail'];

/** One tile in the "Shop by category" grid: a popular category with a real photo and a live count. */
interface MajorCategory {
  readonly name: string;
  readonly url: string;
  readonly image: Image | null;
  readonly count: number;
}

/**
 * One hero slide, resolved for the template.
 *
 * Two sources feed this: admin-curated hero slides (each carries its own copy),
 * and - when none are curated - the departments that have a hero photograph,
 * behind the default copy below. The template reads the same shape either way,
 * so it does not care which source won.
 */
interface HeroSlideView {
  readonly key: string;
  readonly image: Image | null;
  readonly headline: string;
  readonly subheadline: string | null;
  readonly ctaLabel: string | null;
  readonly ctaUrl: string | null;
  readonly secondaryCtaLabel: string | null;
  readonly secondaryCtaUrl: string | null;
  /** The department chip shown on fallback slides; null on curated slides. */
  readonly caption: string | null;
  readonly captionUrl: string | null;
}

/**
 * The copy the hero has always shown, now the fallback used when no slide is
 * curated in admin. Kept here so the department-derived rotation and the empty
 * state read identically to the page before the CMS existed.
 */
const DEFAULT_HERO = {
  headline: 'Nigeria’s office supply partner',
  subheadline:
    'Furniture, IT and consumables for businesses, schools and government. Two Victoria Island showrooms, nationwide delivery and installation.',
  ctaLabel: 'Browse the catalogue',
  ctaUrl: '/shop/',
  secondaryCtaLabel: 'Send a requisition list',
  secondaryCtaUrl: '/quote/request/',
} as const;

/**
 * The "major categories" the legacy WordPress storefront surfaced to shoppers
 * (officelandng.com) - the granular, high-intent entry points a buyer actually
 * types, rather than the nine abstract departments. Every one already exists in
 * the taxonomy; it is nested a level or two down, so the department rail never
 * shows it. This promotes them to a first-class grid the way the old site did.
 *
 * `path` is the subtree the listing endpoint resolves; `url` is the storefront
 * route. A tile with no products is dropped at render (filtered on the live
 * count), so an entry that a mid-import catalogue cannot yet fill simply does
 * not appear rather than showing an empty card.
 */
const MAJOR_CATEGORY_SOURCES: readonly {
  name: string;
  path: string;
  url: string;
  /**
   * Lowercase keywords that a representative product's name contains. The tile
   * takes the image of the first product whose name matches, so a scooter that
   * was mis-filed into Printers, or an outlier that happens to be the newest
   * product in Laptops, does not become the face of the category. Falls back to
   * the first product with any image when nothing matches.
   */
  match: readonly string[];

  /**
   * Tried before `match`, for the two categories whose first name-matching
   * product carries a wrong photo in the legacy source data (a laptop tagged
   * with an earbuds shot, a printer tagged with a scooter). These land the tile
   * on a verified-clean product instead - "dell inspiron" skips the mis-shot
   * "Laptop Yoga 500", and the British-spelled "colour laserjet" skips the
   * American-spelled "Full Color Laserjet" scooter. Omitted where the first
   * match is already clean.
   */
  prefer?: readonly string[];
}[] = [
  { name: 'Laptops', path: '/it-products/computers/laptops/', url: '/c/it-products/computers/laptops/', match: ['laptop', 'notebook'], prefer: ['dell inspiron', 'ideapad', 'thinkpad', 'elitebook'] },
  { name: 'Monitors', path: '/it-products/monitors-displays/monitors/', url: '/c/it-products/monitors-displays/monitors/', match: ['monitor', 'display'] },
  { name: 'Printers', path: '/printers-consumables/printers/', url: '/c/printers-consumables/printers/', match: ['printer', 'laserjet', 'deskjet'], prefer: ['colour laserjet', 'officejet'] },
  { name: 'Ink & Toner', path: '/printers-consumables/ink-toner/', url: '/c/printers-consumables/ink-toner/', match: ['toner', 'ink', 'cartridge'] },
  { name: 'Office Chairs', path: '/office-furniture/seating/', url: '/c/office-furniture/seating/', match: ['chair'] },
  { name: 'Office Tables', path: '/office-furniture/desks-tables/', url: '/c/office-furniture/desks-tables/', match: ['desk', 'table', 'workstation'] },
  { name: 'Shredders', path: '/office-supplies/office-machines/shredders/', url: '/c/office-supplies/office-machines/shredders/', match: ['shred'] },
  { name: 'Computer Accessories', path: '/it-products/computer-accessories/', url: '/c/it-products/computer-accessories/', match: ['mouse', 'keyboard', 'bag', 'headset', 'webcam'] },
  { name: 'Flash & Memory', path: '/it-products/storage-memory/', url: '/c/it-products/storage-memory/', match: ['flash', 'drive', 'card', 'ssd', 'memory', 'usb'] },
  { name: 'Filing & Storage', path: '/filing-storage/', url: '/c/filing-storage/', match: ['file', 'folder', 'binder', 'box'] },
  { name: 'Paper & Notebooks', path: '/paper-notebooks/', url: '/c/paper-notebooks/', match: ['paper', 'notebook', 'ream', 'pad'] },
  { name: 'Writing', path: '/office-supplies/writing/', url: '/c/office-supplies/writing/', match: ['pen', 'pencil', 'marker', 'highlighter'] },
  { name: 'School Supplies', path: '/school-supplies/', url: '/c/school-supplies/', match: ['crayon', 'colour', 'color', 'math', 'ruler', 'pencil'] },
  { name: 'Networking', path: '/it-products/networking/', url: '/c/it-products/networking/', match: ['router', 'switch', 'network', 'access point', 'wifi'] },
];

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
  imports: [
    RouterLink,
    Icon,
    DepartmentShowcase,
    Reveal,
    CountUp,
    ResponsiveImage,
    ProductCard,
    Skeleton,
    ProductCardSkeleton,
  ],
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
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-6">
        <!--
          The hero is a photographic stage, not a tinted panel.

          Every department carries a real hero photograph, so the stage cycles
          through them behind the headline rather than sitting on a gradient -
          the single thing that most separated this page from a considered one.
          All slides are in the DOM and toggled with a class (never @if), so a
          crawler and a no-JS visitor get the first one and its caption, and the
          rotation is a browser-only enhancement that stops under reduced
          motion. The scrim is what keeps white type legible over any photo.
        -->
        <!-- Hero split: 80% slides on the left, 20% two vertical banners on the right. -->
        <div class="grid gap-4 lg:grid-cols-[4fr_1fr]">
        <div
          class="ol-herostage group relative isolate overflow-hidden rounded-3xl shadow-xl"
          aria-roledescription="carousel"
          aria-label="Featured departments"
        >
          @for (slide of heroSlides(); track slide.key; let i = $index) {
            <div
              class="ol-heroslide absolute inset-0"
              [class.is-active]="heroIndex() === i"
              [attr.aria-hidden]="heroIndex() !== i"
            >
              @if (slide.image) {
                <ol-image
                  [image]="slide.image"
                  [priority]="i === 0"
                  sizes="(min-width: 1600px) 1536px, 100vw"
                  imgClass="h-full w-full object-cover"
                />
              }
            </div>
          }

          <span aria-hidden="true" class="ol-heroscrim absolute inset-0"></span>

          <div class="relative flex min-h-[26rem] flex-col justify-end gap-5 p-8 sm:min-h-[34rem] sm:p-14">
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-white backdrop-blur">
              <span class="h-1.5 w-1.5 rounded-full bg-accent-400"></span>
              Business, school &amp; government supply
            </span>

            @if (currentHero(); as hero) {
              <h1 class="max-w-3xl text-display font-extrabold leading-[1.02] text-white drop-shadow-sm sm:text-display-xl">
                {{ hero.headline }}
              </h1>

              @if (hero.subheadline) {
                <p class="max-w-xl text-lg text-white/85">{{ hero.subheadline }}</p>
              }

              <div class="flex flex-wrap items-center gap-3">
                @if (hero.ctaUrl && hero.ctaLabel) {
                  <a
                    [routerLink]="hero.ctaUrl"
                    class="rounded-full bg-brand-600 px-7 py-3.5 font-semibold text-white shadow-brand transition-transform hover:-translate-y-0.5 hover:bg-brand-700"
                  >
                    {{ hero.ctaLabel }}
                  </a>
                }
                @if (hero.secondaryCtaUrl && hero.secondaryCtaLabel) {
                  <a
                    [routerLink]="hero.secondaryCtaUrl"
                    class="rounded-full border border-white/70 bg-white/10 px-7 py-3.5 font-semibold text-white backdrop-blur transition-colors hover:bg-white/20"
                  >
                    {{ hero.secondaryCtaLabel }}
                  </a>
                }
              </div>
            }

            <!--
              The caption names the department on show and links to it, so the
              stage is a way in rather than decoration. It sits above the dots.
            -->
            <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
              @if (currentHero(); as current) {
                @if (current.caption && current.captionUrl) {
                  <a
                    [routerLink]="current.captionUrl"
                    class="inline-flex items-center gap-2 rounded-full bg-white/90 px-4 py-2 text-sm font-semibold text-ink-900 shadow-sm transition-colors hover:bg-white"
                  >
                    {{ current.caption }}
                    <ol-icon name="arrow-right" [size]="16" class="text-brand-600" />
                  </a>
                }
              }

              @if (heroSlides().length > 1) {
                <div class="flex items-center gap-2" role="tablist" aria-label="Choose a featured slide">
                  @for (slide of heroSlides(); track slide.key; let i = $index) {
                    <button
                      type="button"
                      role="tab"
                      class="ol-herodot"
                      [class.is-active]="heroIndex() === i"
                      [attr.aria-selected]="heroIndex() === i"
                      [attr.aria-label]="'Show ' + (slide.caption ?? slide.headline)"
                      (click)="selectHero(i)"
                    ></button>
                  }
                </div>
              }
            </div>
          </div>
        </div>

          <!--
            Right 20%: two vertical banners, PVC on top and office furniture
            below. Each is a real anchor to its department, its hero photograph
            behind a scrim; missing photo falls back to the brand ground.
          -->
          <div class="grid gap-4 lg:grid-rows-2">
            @for (banner of heroBanners(); track banner.url) {
              <a
                [routerLink]="banner.url"
                class="ol-lift group relative isolate flex min-h-[11rem] flex-col justify-end overflow-hidden rounded-2xl bg-brand-700 p-5 text-white shadow-md"
              >
                @if (banner.image; as image) {
                  <span class="absolute inset-0">
                    <ol-image
                      [image]="image"
                      sizes="(min-width: 1024px) 20rem, 100vw"
                      imgClass="h-full w-full object-cover"
                    />
                  </span>
                }
                <span
                  aria-hidden="true"
                  class="absolute inset-0 bg-gradient-to-t from-ink-900/85 via-ink-900/35 to-transparent"
                ></span>
                <div class="relative">
                  <p class="text-xs font-semibold uppercase tracking-[0.1em] text-white/80">
                    {{ banner.eyebrow }}
                  </p>
                  <h2 class="mt-0.5 font-heading text-lg font-semibold text-white">{{ banner.name }}</h2>
                  <span class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-white">
                    {{ banner.tagline }}
                    <ol-icon name="arrow-right" [size]="15" class="transition-transform group-hover:translate-x-0.5" />
                  </span>
                </div>
              </a>
            }
          </div>
        </div>

        <!--
          Two banners under the stage. Bulk/tender is the highest-value visitor
          on the site, and the showroom is the differentiator against
          online-only competitors - both earn a place in the first screen.
        -->
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <a
            routerLink="/bulk-supply/"
            class="ol-banner ol-lift ol-sheen flex flex-col justify-end rounded-2xl bg-ink-900 p-6 text-white shadow-md hover:bg-ink-800"
          >
            <h2 class="font-heading text-xl font-semibold text-white">Bulk &amp; tender supply</h2>
            <p class="mt-1 text-sm text-white/80">
              Ministries, schools and corporates. Send the schedule, we price it.
            </p>
          </a>

          <a
            routerLink="/locations/"
            class="ol-banner ol-lift flex flex-col justify-end rounded-2xl bg-accent-500 p-6 text-ink-900 shadow-md hover:bg-accent-600"
          >
            <h2 class="font-heading text-xl font-semibold">Visit a showroom</h2>
            <p class="mt-1 text-sm text-ink-900/80">
              See the furniture before you commit. Two on Victoria Island.
            </p>
          </a>
        </div>
      </div>
    </section>

    <!--
      PVC use cases, directly below the hero.

      The division sells on where it goes - kitchens, wardrobes, doors, fit-outs
      - more than on a catalogue listing, so this band opens the page with the
      jobs it does and routes each to its subcategory. It sits above the
      material-properties band (why PVC) later on: this is the "what for", that
      is the "why".
    -->
    <section aria-labelledby="pvc-use-cases" class="border-b border-ink-100 bg-ink-50">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14">
        <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="ol-eyebrow">OfficeLand PVC &middot; Doors &amp; Interiors</p>
            <h2 id="pvc-use-cases" class="mt-1 text-2xl font-bold sm:text-3xl">Built for every room</h2>
            <p class="mt-2 max-w-2xl text-ink-600">
              Waterproof, termite-proof PVC, cut and installed to your site measure across Lagos.
            </p>
          </div>
          <a
            routerLink="/c/pvc-doors-interiors/"
            class="inline-flex items-center gap-1.5 rounded-full border border-brand-600 px-5 py-2.5 text-sm font-semibold text-brand-600 transition-colors hover:bg-brand-600 hover:text-white"
          >
            All PVC &amp; interiors
            <ol-icon name="arrow-right" [size]="16" />
          </a>
        </div>

        <ul class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          @for (useCase of pvcUseCases; track useCase.url; let i = $index) {
            <li olReveal [olRevealDelay]="i * 70">
              <a
                [routerLink]="useCase.url"
                class="ol-lift group flex h-full flex-col rounded-2xl border border-ink-100 bg-white p-5 shadow-xs hover:border-brand-600"
              >
                <span class="inline-flex w-fit rounded-xl bg-brand-50 p-3 text-brand-600 transition-colors group-hover:bg-brand-600 group-hover:text-white">
                  <ol-icon [name]="useCase.icon" [size]="24" />
                </span>
                <h3 class="mt-4 font-heading text-base font-semibold text-ink-900">{{ useCase.name }}</h3>
                <p class="mt-1 text-sm text-ink-600">{{ useCase.detail }}</p>
                <span class="mt-auto pt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-600">
                  Explore
                  <ol-icon name="arrow-right" [size]="14" class="transition-transform group-hover:translate-x-0.5" />
                </span>
              </a>
            </li>
          }
        </ul>
      </div>
    </section>

    <!--
      Promise ticker: a cinematic ink band directly under the hero.

      An endless marquee of the claims that stand in for a price on a
      quote-first site. Every claim is one already made elsewhere on the page,
      so nothing here is a new promise. The second group is aria-hidden, so it
      is a screen reader's single reading, and the whole band holds still under
      reduced motion.
    -->
    <div class="ol-ticker border-y border-white/10 bg-ink-900 py-3.5 text-white" aria-label="Why OfficeLand">
      <div class="ol-ticker__track">
        @for (group of [0, 1]; track group) {
          <ul class="flex shrink-0 items-center gap-x-10" [attr.aria-hidden]="group === 1">
            @for (promise of promises; track promise.text) {
              <li class="flex items-center gap-2.5 whitespace-nowrap pr-10 text-sm font-medium text-white/90">
                <ol-icon [name]="promise.icon" [size]="18" class="text-accent-400" />
                {{ promise.text }}
              </li>
            }
          </ul>
        }
      </div>
    </div>

    <!--
      §23.4 item 2. Trust signals, not discounts - the price substitute.

      Two of the four lead with a figure. Those figures count up when the row
      scrolls into view (CountUp), which draws the eye to the numbers that
      carry the argument. The final value is authored in the HTML, so a
      crawler and a no-JS visitor read it whole; the count is enhancement.
    -->
    <section aria-label="Why OfficeLand" class="border-b border-ink-100 bg-gradient-to-b from-white to-ink-50">
      <ul class="mx-auto grid max-w-(--container-page) gap-4 px-(--spacing-gutter) py-10 sm:grid-cols-2 lg:grid-cols-4">
        <li
          olReveal
          class="ol-lift rounded-2xl border border-ink-100 bg-white p-5 shadow-xs"
        >
          <span class="inline-flex rounded-xl bg-brand-50 p-2.5 text-brand-600">
            <ol-icon name="map-pin" [size]="22" />
          </span>
          <p class="mt-3 font-heading text-3xl font-bold text-ink-900"><span olCount>2</span></p>
          <h2 class="mt-1 font-heading text-base font-semibold">Victoria Island showrooms</h2>
          <p class="mt-1 text-sm text-ink-600">See the furniture before you commit.</p>
        </li>
        <li
          olReveal
          [olRevealDelay]="80"
          class="ol-lift rounded-2xl border border-ink-100 bg-white p-5 shadow-xs"
        >
          <span class="inline-flex rounded-xl bg-accent-400/15 p-2.5 text-accent-600">
            <ol-icon name="badge-check" [size]="22" />
          </span>
          <p class="mt-3 font-heading text-3xl font-bold text-ink-900">
            <span olCount>4.4</span><span class="text-accent-500">&#9733;</span>
          </p>
          <h2 class="mt-1 font-heading text-base font-semibold">From <span olCount>138</span> Google reviews</h2>
          <p class="mt-1 text-sm text-ink-600">Rated by the businesses we supply.</p>
        </li>
        <li
          olReveal
          [olRevealDelay]="160"
          class="ol-lift rounded-2xl border border-ink-100 bg-white p-5 shadow-xs"
        >
          <span class="inline-flex rounded-xl bg-brand-50 p-2.5 text-brand-600">
            <ol-icon name="clock" [size]="22" />
          </span>
          <p class="mt-3 font-heading text-3xl font-bold text-ink-900"><span olCount>1</span> day</p>
          <h2 class="mt-1 font-heading text-base font-semibold">Quotes answered</h2>
          <p class="mt-1 text-sm text-ink-600">Send a requisition list and we price it.</p>
        </li>
        <li
          olReveal
          [olRevealDelay]="240"
          class="ol-lift rounded-2xl border border-ink-100 bg-white p-5 shadow-xs"
        >
          <span class="inline-flex rounded-xl bg-brand-50 p-2.5 text-brand-600">
            <ol-icon name="truck" [size]="22" />
          </span>
          <p class="mt-3 font-heading text-3xl font-bold text-ink-900">Nationwide</p>
          <h2 class="mt-1 font-heading text-base font-semibold">Delivery &amp; installation</h2>
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
          <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="ol-eyebrow">Find your department</p>
              <h2 id="departments-heading" class="mt-1 text-2xl font-bold sm:text-3xl">
                Browse by department
              </h2>
            </div>
            <div class="flex items-center gap-3">
              <a routerLink="/c/" class="text-sm font-medium text-brand-600 hover:underline">
                All departments
              </a>
              <!-- Chevron nav for the rail. aria-hidden pointer affordances:
                   the rail scrolls by touch, wheel and tab without them. -->
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  aria-hidden="true"
                  tabindex="-1"
                  class="ol-catnav"
                  (click)="scrollRail(-1)"
                >
                  <ol-icon name="chevron-right" [size]="18" class="rotate-180" />
                </button>
                <button
                  type="button"
                  aria-hidden="true"
                  tabindex="-1"
                  class="ol-catnav"
                  (click)="scrollRail(1)"
                >
                  <ol-icon name="chevron-right" [size]="18" />
                </button>
              </div>
            </div>
          </div>

          <!--
            A rail of category cards: the photo in a light well on top, the name
            and count below. Every card is a real anchor, and the rail scrolls
            by touch, wheel and keyboard - the chevrons above are a pointer
            convenience, not the only way through.
          -->
          <ul
            #rail
            class="ol-rail mt-6 flex snap-x gap-4 overflow-x-auto pb-2"
            (scroll)="onRailScroll()"
          >
            @for (department of departments(); track department.slug; let i = $index) {
              <li class="w-48 shrink-0 snap-start sm:w-52" olReveal [olRevealDelay]="(i % 6) * 50">
                <a
                  [routerLink]="department.url"
                  class="ol-lift group block h-full overflow-hidden rounded-2xl border border-ink-100 bg-[#f6f3ee] p-2.5"
                >
                  <div class="ol-zoom-frame aspect-square w-full overflow-hidden rounded-xl bg-[#f1ece3]">
                    @if (department.hero; as hero) {
                      <ol-image [image]="hero" sizes="13rem" imgClass="h-full w-full object-cover" />
                    } @else {
                      <span class="flex h-full items-center justify-center p-4 text-center font-heading text-sm font-semibold text-brand-700" aria-hidden="true">
                        {{ department.name }}
                      </span>
                    }
                  </div>
                  <div class="px-1.5 pb-1.5 pt-3">
                    <strong class="block font-heading text-base font-bold text-ink-900 group-hover:text-brand-600">
                      {{ department.name }}
                    </strong>
                    @if (department.product_count) {
                      <small class="mt-0.5 block text-sm text-ink-400">{{ department.product_count }} products</small>
                    } @else {
                      <small class="mt-0.5 block text-sm text-ink-400">Shop now</small>
                    }
                  </div>
                </a>
              </li>
            }
          </ul>
        </div>
      </section>
    } @else if (navLoading()) {
      <!-- Department rail, loading. A header and a row of tile skeletons that
           hold the rail's height until the navigation request resolves. -->
      <section aria-busy="true" aria-label="Loading departments" class="border-b border-ink-100">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10">
          <ol-skeleton shape="h-3 w-40 rounded bg-ink-100" />
          <ol-skeleton shape="mt-3 h-7 w-64 rounded bg-ink-100" />
          <ul class="mt-6 flex gap-4 overflow-hidden pb-2">
            @for (placeholder of [0, 1, 2, 3, 4, 5]; track placeholder) {
              <li class="w-48 shrink-0 sm:w-52">
                <div class="rounded-2xl border border-ink-100 bg-[#f6f3ee] p-2.5">
                  <ol-skeleton shape="aspect-square w-full rounded-xl bg-ink-100" />
                  <ol-skeleton shape="mx-1.5 mt-3 h-4 w-3/4 rounded bg-ink-100" />
                  <ol-skeleton shape="mx-1.5 mb-1.5 mt-2 h-3 w-1/2 rounded bg-ink-100" />
                </div>
              </li>
            }
          </ul>
        </div>
      </section>
    }

    <!--
      Trusted brands: the whole roster as logo-and-name cards.

      Every brand OfficeLand carries, ordered by how much live stock it has, so
      a buyer sees their brand at a glance. A missing logo falls back to the
      name's initials rather than a broken frame. Real anchors to each brand
      hub; the rail scrolls by touch, wheel and keyboard.
    -->
    @if (brands().length) {
      <section aria-labelledby="brands-heading" class="border-b border-ink-100 bg-white">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10">
          <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="ol-eyebrow">Authorised &amp; stocked</p>
              <h2 id="brands-heading" class="mt-1 text-2xl font-bold sm:text-3xl">Trusted brands</h2>
            </div>
            <a routerLink="/brands/" class="text-sm font-medium text-brand-600 hover:underline">
              All brands
            </a>
          </div>

          <ul class="ol-rail mt-6 flex snap-x gap-3 overflow-x-auto pb-2">
            @for (brand of brands(); track brand.slug; let i = $index) {
              <li class="w-32 shrink-0 snap-start" olReveal [olRevealDelay]="(i % 8) * 40">
                <a
                  [routerLink]="brand.url"
                  class="ol-lift group flex h-full flex-col items-center gap-3 rounded-2xl border border-ink-100 bg-white p-4 text-center hover:border-brand-600"
                >
                  <span class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl bg-ink-50 p-2">
                    @if (brand.logo; as logo) {
                      <ol-image [image]="logo" sizes="64px" imgClass="h-full w-full object-contain" />
                    } @else {
                      <span class="font-heading text-lg font-bold text-brand-700" aria-hidden="true">
                        {{ brandInitials(brand.name) }}
                      </span>
                    }
                  </span>
                  <span class="font-heading text-sm font-semibold text-ink-900 group-hover:text-brand-600">
                    {{ brand.name }}
                  </span>
                </a>
              </li>
            }
          </ul>
        </div>
      </section>
    }

    <!--
      Shop by category: the legacy site's "major categories".

      The old WordPress storefront put the granular, high-intent categories a
      buyer actually types - Laptops, Printers, Ink & Toner, Office Chairs,
      Shredders - front and centre, not the nine abstract departments. Those
      categories all live in the taxonomy a level or two down, so the
      department rail above never shows them; this promotes them.

      Each tile's photo and count come from a single listing request per
      category (perPage 6, first product carrying an image), run once at build
      and carried into hydration by TransferState like every other request on
      the page. A category with no products is dropped on its live count, so a
      mid-import catalogue thins the grid rather than showing empty cards.
    -->
    @if (majorCategories(); as majorCats) {
      @if (majorCats.length) {
      <section aria-labelledby="major-categories-heading" class="border-b border-ink-100 bg-white">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
          <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="ol-eyebrow">Popular categories</p>
              <h2 id="major-categories-heading" class="mt-1 text-2xl font-bold sm:text-3xl">
                Shop by category
              </h2>
            </div>
            <a
              routerLink="/shop/"
              class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
            >
              Shop all products
              <ol-icon name="arrow-right" [size]="16" />
            </a>
          </div>

          <!--
            A grid, not a rail: these are destinations to scan at a glance, and
            on the first screen a grid reads as a map of the catalogue where a
            rail reads as a filmstrip to page through. Every tile is a real
            anchor, so the crawl surface is the same as the rail's.
          -->
          <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
            @for (cat of majorCats; track cat.url; let i = $index) {
              <li olReveal [olRevealDelay]="(i % 7) * 40">
                <a
                  [routerLink]="cat.url"
                  class="ol-lift group block h-full overflow-hidden rounded-2xl border border-ink-100 bg-[#f6f3ee] p-2.5"
                >
                  <div class="ol-zoom-frame aspect-square w-full overflow-hidden rounded-xl bg-[#f1ece3]">
                    @if (cat.image; as image) {
                      <ol-image
                        [image]="image"
                        sizes="(min-width: 1280px) 11rem, (min-width: 640px) 22vw, 42vw"
                        imgClass="h-full w-full object-cover"
                      />
                    } @else {
                      <span class="flex h-full items-center justify-center p-4 text-center font-heading text-sm font-semibold text-brand-700" aria-hidden="true">
                        {{ cat.name }}
                      </span>
                    }
                  </div>
                  <div class="px-1.5 pb-1.5 pt-3">
                    <strong class="block font-heading text-sm font-bold text-ink-900 group-hover:text-brand-600">
                      {{ cat.name }}
                    </strong>
                    <small class="mt-0.5 block text-sm text-ink-400">{{ cat.count }} products</small>
                  </div>
                </a>
              </li>
            }
          </ul>
        </div>
      </section>
      }
    } @else {
      <!-- "Shop by category", loading. A matching grid of tile skeletons so the
           band holds its height until the per-category requests resolve. -->
      <section aria-busy="true" aria-label="Loading categories" class="border-b border-ink-100 bg-white">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
          <ol-skeleton shape="h-3 w-40 rounded bg-ink-100" />
          <ol-skeleton shape="mt-2 h-7 w-56 rounded bg-ink-100" />
          <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
            @for (placeholder of [0, 1, 2, 3, 4, 5, 6]; track placeholder) {
              <li>
                <div class="rounded-2xl border border-ink-100 bg-[#f6f3ee] p-2.5">
                  <ol-skeleton shape="aspect-square w-full rounded-xl bg-ink-100" />
                  <ol-skeleton shape="mx-1.5 mt-3 h-4 w-3/4 rounded bg-ink-100" />
                  <ol-skeleton shape="mx-1.5 mb-1.5 mt-2 h-3 w-1/2 rounded bg-ink-100" />
                </div>
              </li>
            }
          </ul>
        </div>
      </section>
    }

    <!--
      Promotional banners, carried over from the current site.

      The client's own HP reseller banners, re-encoded and self-hosted. Each is
      a real link to the department it merchandises rather than a dead image.
      They sit as their own band so they read as the storefront's promotions,
      not as catalogue products.
    -->
    <section aria-label="Featured ranges" class="border-b border-ink-100">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          @for (banner of promoBanners; track banner.img; let i = $index) {
            <li olReveal [olRevealDelay]="i * 80">
              <a
                [routerLink]="banner.url"
                class="ol-lift ol-zoom-frame group block overflow-hidden rounded-2xl shadow-sm"
              >
                <img
                  [src]="banner.img"
                  [alt]="banner.alt"
                  width="1280"
                  height="853"
                  loading="lazy"
                  decoding="async"
                  class="aspect-[3/2] w-full object-cover"
                />
              </a>
            </li>
          }
        </ul>
      </div>
    </section>

    <!--
      Best sellers: a dark band so the product photographs carry it.

      A cross-section of real, photographed stock in a horizontal rail, set on
      an ink ground with a radial glow - the one dark merchandising moment in
      the light-dominant page. The cards are the same shared component used
      everywhere; only the ground changes.
    -->
    @if (bestSellers(); as items) {
      @if (items.length) {
      <section aria-labelledby="best-sellers-heading" class="relative isolate overflow-hidden bg-ink-900 text-white">
        <span aria-hidden="true" class="ol-bestsellers-glow absolute"></span>
        <div class="relative mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14">
          <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p class="font-heading text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Our best sellers</p>
              <h2 id="best-sellers-heading" class="mt-2 text-2xl font-bold text-white sm:text-3xl">
                Popular across the catalogue
              </h2>
            </div>
            <a routerLink="/shop/" class="inline-flex items-center gap-1.5 text-sm font-semibold text-white/90 hover:text-white">
              Shop all products
              <ol-icon name="arrow-right" [size]="16" />
            </a>
          </div>

          <ul class="ol-rail mt-8 flex snap-x gap-4 overflow-x-auto pb-2">
            @for (product of items; track product.uuid; let i = $index) {
              <li class="w-56 shrink-0 snap-start sm:w-60" olReveal [olRevealDelay]="(i % 6) * 50">
                <ol-product-card [product]="product" [headingLevel]="3" imageSizes="15rem" />
              </li>
            }
          </ul>
        </div>
      </section>
      }
    } @else {
      <!-- Best sellers, loading. The dark band with a rail of card skeletons;
           the shimmer base is white/10 so it reads on the ink ground. -->
      <section aria-busy="true" aria-label="Loading best sellers" class="relative isolate overflow-hidden bg-ink-900 text-white">
        <span aria-hidden="true" class="ol-bestsellers-glow absolute"></span>
        <div class="relative mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14">
          <ol-skeleton shape="h-3 w-36 rounded bg-white/10" />
          <ol-skeleton shape="mt-2 h-7 w-72 rounded bg-white/10" />
          <ul class="ol-rail mt-8 flex gap-4 overflow-hidden pb-2">
            @for (placeholder of [0, 1, 2, 3, 4, 5]; track placeholder) {
              <li class="w-56 shrink-0 sm:w-60">
                <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                  <ol-skeleton shape="mb-3 aspect-square w-full rounded-md bg-white/10" />
                  <ol-skeleton shape="h-4 w-5/6 rounded bg-white/10" />
                  <ol-skeleton shape="mt-2 h-4 w-2/3 rounded bg-white/10" />
                  <ol-skeleton shape="mt-4 h-10 w-full rounded-lg bg-white/10" />
                </div>
              </li>
            }
          </ul>
        </div>
      </section>
    }

    <!--
      A product section per department, each in one of three shapes.

      This is what turns the homepage from a set of signposts into something
      with the catalogue actually on it. It is also the largest internal
      linking surface the page has: nine departments, up to eight products
      each, all real anchors in the prerendered HTML.

      Sections with no products render nothing rather than an empty heading,
      so a department mid-import is simply absent instead of looking broken.
    -->
    @for (section of sections(); track section.slug) {
      <ol-department-showcase
        [name]="section.name"
        [slug]="section.slug"
        [url]="section.url"
        [products]="section.products"
        [style]="section.style"
      />
    }

    <!--
      PVC Doors & Interiors.

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
    <section aria-labelledby="pvc-heading" class="ol-pvc ol-sheen relative overflow-hidden bg-ink-900 text-white">
      <!--
        Depth on the dark band: a radial brand glow and a fine grid, both
        aria-hidden decoration behind the copy. The .ol-sheen on the section
        sends a slow band of light across it. None of it carries meaning, so
        none of it is in the reading order.
      -->
      <span aria-hidden="true" class="ol-pvc-glow absolute"></span>
      <div class="relative mx-auto max-w-(--container-page) px-(--spacing-gutter) py-16 sm:py-20">
        <div olReveal class="max-w-3xl">
          <p class="font-heading text-sm font-semibold uppercase tracking-[0.2em] text-accent-400">
            OfficeLand PVC - Doors &amp; Interiors
          </p>
          <h2 id="pvc-heading" class="mt-4 font-heading text-display-sm font-bold text-white sm:text-display">
            The beauty you see. The strength you don&rsquo;t.
          </h2>
          <p class="mt-5 text-lg text-white/75">
            PVC boards that build stunning interiors and stand
            strong inside out. Specified for kitchens, wardrobes, furniture and
            full interior fit-outs across Lagos.
          </p>
        </div>

        <!--
          The six properties are the entire sales argument for PVC over MDF or
          plywood in this climate. Termite and moisture resistance is not a
          feature list here - it is why the material exists.
        -->
        <ul class="mt-10 grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3 lg:grid-cols-6">
          @for (property of pvcProperties; track property.name; let i = $index) {
            <li
              olReveal
              [olRevealDelay]="i * 80"
              class="rounded-xl border-t border-white/15 bg-white/[0.02] p-4 transition-colors hover:bg-white/[0.06]"
            >
              <span class="inline-flex rounded-lg bg-accent-400/10 p-2 text-accent-400">
                <ol-icon [name]="property.icon" [size]="22" />
              </span>
              <h3 class="mt-2.5 font-heading text-sm font-semibold text-white">{{ property.name }}</h3>
              <p class="mt-1 text-sm text-white/60">{{ property.detail }}</p>
            </li>
          }
        </ul>

        <div olReveal="fade" class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3">
          <a
            routerLink="/quote/"
            class="rounded-full bg-accent-500 px-7 py-3.5 font-semibold text-ink-900 shadow-lg transition-transform hover:-translate-y-0.5 hover:bg-accent-400"
          >
            Request a PVC quote
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
      <div
        olReveal="fade"
        class="mx-auto flex max-w-(--container-page) flex-col items-start gap-4 px-(--spacing-gutter) py-12 sm:flex-row sm:items-center sm:justify-between"
      >
        <div>
          <p class="ol-eyebrow">Bulk &amp; tender supply</p>
          <h2 id="bulk-heading" class="mt-1 text-2xl font-bold sm:text-3xl">Supplying a school, ministry or head office?</h2>
          <p class="mt-2 max-w-2xl text-ink-600">
            Send the requisition schedule as it is. We price the whole list, quote lead
            times per line, and deliver and install nationwide.
          </p>
        </div>
        <a
          routerLink="/bulk-supply/"
          class="shrink-0 rounded-full bg-brand-600 px-7 py-3.5 font-semibold text-white shadow-brand transition-transform hover:-translate-y-0.5 hover:bg-brand-700"
        >
          Bulk &amp; tender supply
        </a>
      </div>
    </section>
  `,
  styles: `
    /*
     * The hero stage. A brand-tinted ground shows for the instant before the
     * first photograph decodes, so the headline never paints over bare white.
     */
    .ol-herostage {
      background: var(--color-brand-900);
    }

    /*
     * Slides stack and cross-fade. All are in the DOM; only the active one is
     * opaque. Position absolute so they share the stage's box, which the
     * content layer (position relative) then sizes with its min-height.
     */
    .ol-heroslide {
      opacity: 0;
      transition: opacity 0.9s var(--ease-out-soft);
    }

    .ol-heroslide.is-active {
      opacity: 1;
    }

    /*
     * A slow drift on the active photo gives the stage life without a video.
     * Only the active slide animates, so the hidden ones are not burning GPU.
     */
    .ol-heroslide.is-active :is(img) {
      animation: ol-herozoom 12s ease-out forwards;
    }

    @keyframes ol-herozoom {
      from {
        transform: scale(1.08);
      }
      to {
        transform: scale(1);
      }
    }

    /*
     * The scrim: dark at the bottom-left where the copy sits, clear at the top
     * right where the photo should breathe. This is what carries white text to
     * AA over any photograph, measured against the darkest and the lightest of
     * the nine.
     */
    .ol-heroscrim {
      background:
        linear-gradient(to top, rgb(11 11 13 / 0.82) 0%, rgb(11 11 13 / 0.45) 38%, transparent 72%),
        linear-gradient(to right, rgb(11 11 13 / 0.55) 0%, transparent 60%);
    }

    /* The carousel dots. The active one widens into a pill, so the control
       reads at a glance rather than relying on a colour difference alone. */
    .ol-herodot {
      height: 0.5rem;
      width: 0.5rem;
      border-radius: 9999px;
      background: rgb(255 255 255 / 0.5);
      transition:
        width 0.3s var(--ease-out-soft),
        background-color 0.3s var(--ease-out-soft);
    }

    .ol-herodot:hover {
      background: rgb(255 255 255 / 0.8);
    }

    .ol-herodot.is-active {
      width: 1.5rem;
      background: #ffffff;
    }

    @media (prefers-reduced-motion: reduce) {
      .ol-heroslide {
        transition: none;
      }

      .ol-heroslide.is-active :is(img) {
        animation: none;
      }
    }

    /* The PVC band's radial brand glow, low and to one side so the copy above
       it stays the brightest thing in the band. */
    .ol-pvc-glow {
      top: -20%;
      left: 55%;
      height: 140%;
      width: 55%;
      background: radial-gradient(circle at 50% 40%, rgb(5 91 240 / 0.22) 0%, transparent 68%);
      pointer-events: none;
    }

    /* The best-sellers band's glow: a brand light off the left, so the dark
       ground has depth behind the product rail rather than a flat fill. */
    .ol-bestsellers-glow {
      top: -30%;
      left: -10%;
      height: 150%;
      width: 55%;
      background: radial-gradient(circle at 40% 50%, rgb(5 91 240 / 0.2) 0%, transparent 66%);
      pointer-events: none;
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

    /* The category-rail chevrons that sit in the section heading. */
    .ol-catnav {
      display: inline-flex;
      height: 2.25rem;
      width: 2.25rem;
      align-items: center;
      justify-content: center;
      border-radius: 9999px;
      border: 1px solid var(--color-ink-200);
      background: #fff;
      color: var(--color-ink-700);
      transition:
        background-color 0.15s var(--ease-out-soft),
        border-color 0.15s var(--ease-out-soft),
        color 0.15s var(--ease-out-soft);
    }

    .ol-catnav:hover {
      border-color: var(--color-brand-600);
      color: var(--color-brand-600);
      background: var(--color-brand-50);
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

  /**
   * Curated homepage content. `undefined` while the request is in flight, null
   * if it failed - either way the storefront falls back to its derived
   * behaviour, so a missing /home never empties the page. Fetched once at build
   * (D-009) and carried into hydration by TransferState.
   */
  private readonly home = toSignal(
    this.api.home().pipe(catchError(() => of(null))),
  );

  protected readonly departments = computed(() => this.navigation()?.departments ?? []);

  /** Every brand, as logo-and-name cards (from /home, ordered by live stock). */
  protected readonly brands = computed(() => this.home()?.brands ?? []);

  /**
   * The hero's right-column banners: PVC on top, office furniture below.
   *
   * Their photographs come from the two departments' hero images (navigation),
   * but the links and labels are fixed so both banners render even before
   * navigation resolves or if a department has no hero yet.
   */
  protected readonly heroBanners = computed(() => {
    const find = (slug: string) => this.departments().find((d) => d.slug === slug) ?? null;
    const pvc = find('pvc-doors-interiors');
    const furniture = find('office-furniture');

    return [
      {
        eyebrow: 'Doors · wardrobes · panels',
        name: 'PVC doors & interiors',
        tagline: 'Explore the range',
        url: pvc?.url ?? '/c/pvc-doors-interiors/',
        image: pvc?.hero ?? null,
      },
      {
        eyebrow: 'Desks · chairs · storage',
        name: 'Office furniture',
        tagline: 'Shop furniture',
        url: furniture?.url ?? '/c/office-furniture/',
        image: furniture?.hero ?? null,
      },
    ];
  });

  /** The first one or two letters of a brand name, for a missing-logo fallback. */
  protected brandInitials(name: string): string {
    return name.replace(/[^a-z0-9]/gi, '').slice(0, 2).toUpperCase();
  }

  /** The last segment of a '/c/.../' URL, used as a stable track key. */
  private slugFromUrl(url: string): string {
    const parts = url.split('/').filter(Boolean);
    return parts[parts.length - 1] ?? url;
  }

  /**
   * True only in the window before navigation has resolved - undefined, not
   * null (null is the loaded-but-failed state the catchError yields). Drives
   * the department rail's skeleton, which a crawler never reaches because the
   * request resolves before its HTML is serialized.
   */
  protected readonly navLoading = computed(() => this.navigation() === undefined);

  /**
   * The hero stage slides, from whichever source is curated.
   *
   * 1. Admin hero slides, when any are active - each with its own photo and copy.
   * 2. Otherwise the departments that carry a hero photograph, behind the
   *    default copy (exactly the rotation the page has always shown).
   * 3. Otherwise a single copy-only slide, so the stage still renders its scrim
   *    and headline over an empty ground rather than disappearing.
   */
  protected readonly heroSlides = computed<readonly HeroSlideView[]>(() => {
    const curated = this.home()?.hero_slides ?? [];

    if (curated.length > 0) {
      return curated.map((slide, i): HeroSlideView => ({
        key: 'cms-' + i,
        image: slide.image ?? null,
        headline: slide.headline,
        subheadline: slide.subheadline ?? null,
        ctaLabel: slide.cta_label ?? null,
        ctaUrl: slide.cta_url ?? null,
        secondaryCtaLabel: slide.secondary_cta_label ?? null,
        secondaryCtaUrl: slide.secondary_cta_url ?? null,
        caption: null,
        captionUrl: null,
      }));
    }

    const derived = this.departments()
      .filter((d) => d.hero)
      .map((d): HeroSlideView => ({
        key: 'dept-' + d.slug,
        image: d.hero ?? null,
        headline: DEFAULT_HERO.headline,
        subheadline: DEFAULT_HERO.subheadline,
        ctaLabel: DEFAULT_HERO.ctaLabel,
        ctaUrl: DEFAULT_HERO.ctaUrl,
        secondaryCtaLabel: DEFAULT_HERO.secondaryCtaLabel,
        secondaryCtaUrl: DEFAULT_HERO.secondaryCtaUrl,
        caption: d.name,
        captionUrl: d.url,
      }));

    if (derived.length > 0) {
      return derived;
    }

    return [{
      key: 'default',
      image: null,
      headline: DEFAULT_HERO.headline,
      subheadline: DEFAULT_HERO.subheadline,
      ctaLabel: DEFAULT_HERO.ctaLabel,
      ctaUrl: DEFAULT_HERO.ctaUrl,
      secondaryCtaLabel: DEFAULT_HERO.secondaryCtaLabel,
      secondaryCtaUrl: DEFAULT_HERO.secondaryCtaUrl,
      caption: null,
      captionUrl: null,
    }];
  });

  /** The slide currently on show, clamped so an out-of-range index still resolves. */
  protected readonly currentHero = computed<HeroSlideView | null>(() => {
    const slides = this.heroSlides();
    if (slides.length === 0) return null;
    return slides[this.heroIndex()] ?? slides[0];
  });

  /** Which hero slide is showing. Advanced on a timer in the browser only. */
  protected readonly heroIndex = signal(0);
  private heroTimer: ReturnType<typeof setInterval> | null = null;
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  /**
   * Picks a slide and restarts the timer, so a manual choice is not snatched
   * away a moment later by the next tick.
   */
  protected selectHero(index: number): void {
    const count = this.heroSlides().length;
    if (count === 0) return;
    this.heroIndex.set(((index % count) + count) % count);
    this.startHeroTimer();
  }

  private startHeroTimer(): void {
    if (this.heroTimer) clearInterval(this.heroTimer);
    const count = this.heroSlides().length;
    if (count <= 1) return;
    this.heroTimer = setInterval(() => {
      this.heroIndex.update((i) => (i + 1) % this.heroSlides().length);
    }, 6000);
  }

  /**
   * Products for each department's homepage section.
   *
   * ── Nine requests, all at build time ─────────────────────────────────
   * This page is prerendered (D-009), so these run once during the build
   * against a local API and never on a visitor's request. TransferState
   * carries the results into hydration, so the browser does not repeat
   * them — which the duplicate-hydration-request gate would otherwise
   * fail.
   *
   * A dedicated /home/showcase endpoint would be one round trip instead of
   * nine. It would also be a second place that decides what a homepage
   * section contains, and the listing endpoint already answers exactly this
   * question with the same envelope, the same subtree resolution and the
   * same card shape. Nine build-time requests is the cheaper trade.
   *
   * `switchMap` on the navigation signal rather than a fixed list: the
   * departments come from the taxonomy, so adding one gives it a homepage
   * section without touching this file.
   */
  private readonly showcases = toSignal(
    toObservable(this.departments).pipe(
      switchMap((departments) => {
        if (departments.length === 0) {
          return of([] as ShowcaseSection[]);
        }

        return forkJoin(
          departments.map((department, index) =>
            this.api
              .categoryProducts(department.url.replace(/^\/c/, ''), { perPage: 10 })
              .pipe(
                // One department failing must not empty the homepage. It
                // yields no section; the other eight still render.
                catchError(() => of(EMPTY_LISTING)),
                map((listing): ShowcaseSection => ({
                  name: department.name,
                  slug: department.slug,
                  url: department.url,
                  products: listing.data,
                  // Cycled, not random: random re-rolls every build, so the
                  // homepage would change shape for no reason and no
                  // screenshot would be reproducible.
                  style: SHOWCASE_STYLES[index % SHOWCASE_STYLES.length],
                })),
              ),
          ),
        );
      }),
    ),
    { initialValue: [] as ShowcaseSection[] },
  );

  /**
   * A product rail per department.
   *
   * Prefers the server-assembled /home department_rails - one per department
   * with live, image-backed products and the department's square card image -
   * and falls back to the per-department listing requests (showcases) when the
   * curated payload is not yet loaded. Either way a department with no products
   * yields no rail rather than an empty heading.
   */
  protected readonly sections = computed(() => {
    const rails = this.home()?.department_rails ?? [];

    if (rails.length > 0) {
      return rails
        .map((rail, index): ShowcaseSection => ({
          name: rail.name,
          slug: this.slugFromUrl(rail.url),
          url: rail.url,
          products: rail.products,
          style: SHOWCASE_STYLES[index % SHOWCASE_STYLES.length],
        }))
        .filter((section) => section.products.length > 0);
    }

    return this.showcases().filter((section) => section.products.length > 0);
  });

  /**
   * Products for the best-sellers band.
   *
   * A cross-section of the catalogue rather than a single department, pulled
   * from the same listing endpoint every other surface uses. "Best sellers" is
   * the merchandising label the client asked for; the real ranking is theirs to
   * curate - this fills the band with real, photographed stock so the section
   * is never empty, and swaps to a curated set the day one exists.
   */
  // No initialValue: the signal is `undefined` until the request resolves,
  // which is the loading state the band's skeleton renders. Once resolved it is
  // the product list (empty on failure, via catchError), never undefined again.
  private readonly derivedBestSellers = toSignal(
    this.api.catalogue({ perPage: 12 }).pipe(
      catchError(() => of(EMPTY_LISTING)),
      map((listing) => listing.data),
    ),
  );

  /**
   * The best-sellers band, curated when admin has picked products, otherwise
   * the cross-section of the catalogue above. `undefined` only while /home is
   * still loading, which is the skeleton state.
   */
  protected readonly bestSellers = computed<readonly ProductCardData[] | undefined>(() => {
    const home = this.home();
    if (home === undefined) return undefined;

    const picks = home?.product_picks ?? [];
    return picks.length > 0 ? picks : this.derivedBestSellers();
  });

  /**
   * The "Shop by category" grid - the legacy site's major categories.
   *
   * One listing request per category (perPage 6, so a product with an image is
   * almost always in the first page even when the newest few are image-less),
   * taking the first image for the tile and the subtree total for the count.
   * forkJoin so they resolve together; a failing category yields an empty
   * listing rather than collapsing the grid. The whole set is filtered to
   * categories that actually have products, so the grid is self-pruning as the
   * catalogue fills. Runs once at build (D-009) and transfers into hydration.
   */
  private readonly derivedMajorCategories = toSignal(
    forkJoin(
      MAJOR_CATEGORY_SOURCES.map((source) =>
        this.api.categoryProducts(source.path, { perPage: 48 }).pipe(
          catchError(() => of(EMPTY_LISTING)),
          map((listing): MajorCategory => {
            const withImage = listing.data.filter((product) => product.image);
            const nameHas = (keywords: readonly string[]) => (product: ProductCardData) =>
              keywords.some((keyword) => product.name.toLowerCase().includes(keyword));
            // A verified-clean product first (only set where the first name
            // match has a wrong source photo), then any product whose name
            // reads like the category, then whatever has an image at all - so a
            // mis-filed or mis-shot product is not the face of the tile.
            const representative =
              (source.prefer ? withImage.find(nameHas(source.prefer)) : undefined) ??
              withImage.find(nameHas(source.match)) ??
              withImage[0];

            return {
              name: source.name,
              url: source.url,
              image: representative?.image ?? null,
              count: listing.meta.total,
            };
          }),
        ),
      ),
    ).pipe(
      map((categories) => categories.filter((category) => category.count > 0)),
      catchError(() => of([] as MajorCategory[])),
    ),
    // No initialValue: `undefined` is the loading state the grid's skeleton
    // renders; once resolved it is the (possibly empty) category list.
  );

  /**
   * The "Shop by category" grid, curated when admin has chosen tiles, otherwise
   * the derived set above. `undefined` only while /home is still loading, which
   * is the grid's skeleton state.
   */
  protected readonly majorCategories = computed<readonly MajorCategory[] | undefined>(() => {
    const home = this.home();
    if (home === undefined) return undefined;

    const picks = home?.category_picks ?? [];
    if (picks.length === 0) {
      return this.derivedMajorCategories();
    }

    return picks.map((pick): MajorCategory => ({
      name: pick.name,
      url: pick.url,
      image: pick.image ?? null,
      count: pick.product_count,
    }));
  });

  /**
   * PVC's material properties.
   *
   * Hardcoded rather than pulled from attributes because these describe a
   * DIVISION, not a product - there is no PVC product in the catalogue to
   * carry them, and inventing one to hold six marketing claims would put
   * copy into the catalogue where the content floor (D-048) expects
   * specifications.
   *
   * They move into the CMS with the PVC landing page.
   */
  /**
   * The promise ticker's claims. Every one is stated elsewhere on the page or
   * in BUSINESS_FACTS.md - the ticker restates, it does not invent. Icons are
   * all in the registry the icons gate checks.
   */
  /**
   * PVC use cases - the rooms and jobs the division builds for, below the
   * hero. Each links to its subcategory; icons come from the same lookup the
   * megamenu and category chips use, so a use case and its category wear the
   * same glyph.
   */
  protected readonly pvcUseCases = [
    { icon: 'cooking-pot', name: 'Kitchens', detail: 'Cabinetry, islands and worktop units.', url: '/c/pvc-doors-interiors/pvc-kitchens/' },
    { icon: 'shirt', name: 'Wardrobes', detail: 'Fitted and sliding-door wardrobes.', url: '/c/pvc-doors-interiors/pvc-wardrobes/' },
    { icon: 'door-open', name: 'Doors', detail: 'Internal, flush and framed doors.', url: '/c/pvc-doors-interiors/pvc-doors/' },
    { icon: 'panels-top-left', name: 'Wall & ceiling panels', detail: 'Cladding and ceiling panelling.', url: '/c/pvc-doors-interiors/pvc-interiors/' },
    { icon: 'layers', name: 'Boards & sheets', detail: 'The board by the sheet, cut to your list.', url: '/c/pvc-doors-interiors/pvc-boards/' },
  ] as const satisfies readonly { icon: IconName; name: string; detail: string; url: string }[];

  /**
   * The home banners carried over from the current site (officelandng.com),
   * re-encoded to WebP and self-hosted (img-src 'self', D-053). They are the
   * client's own HP reseller banners; each links to the department it sells.
   * Real marketing art, so the alt text describes what the banner actually
   * shows rather than inventing a claim.
   */
  protected readonly promoBanners = [
    {
      img: '/images/banners/banner-printers.webp',
      alt: 'HP LaserJet Pro printers - high performance, professional results',
      url: '/c/printers-consumables/',
    },
    {
      img: '/images/banners/banner-inks-toners.webp',
      alt: 'HP original ink and toner cartridges, engineered to keep you printing',
      url: '/c/printers-consumables/',
    },
    {
      img: '/images/banners/banner-productivity.webp',
      alt: 'HP monitors and workstations - power your productivity',
      url: '/c/it-products/',
    },
  ] as const;

  protected readonly promises = [
    { icon: 'clock', text: 'Quotes answered within 1 business day' },
    { icon: 'truck', text: 'Nationwide delivery & installation' },
    { icon: 'map-pin', text: 'Two Victoria Island showrooms' },
    { icon: 'badge-check', text: '4.4 stars from 138 Google reviews' },
    { icon: 'building-2', text: 'Supplying business, schools & government' },
    { icon: 'file-text', text: 'Send a requisition list, we price it' },
  ] as const satisfies readonly { icon: IconName; text: string }[];

  protected readonly pvcProperties = [
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
     * Start the hero rotation once its slides exist.
     *
     * The slides come from the navigation request, which resolves after the
     * first render - so starting the timer in afterNextRender ran before there
     * was anything to rotate and silently did nothing. An effect starts it the
     * moment the slides arrive instead. Browser only, and never under reduced
     * motion: a carousel that advances on its own is the textbook WCAG 2.2.2
     * offender, and the slides are all in the DOM regardless, so stopping the
     * timer costs only the movement.
     */
    effect(() => {
      const count = this.heroSlides().length;

      if (
        !this.isBrowser ||
        this.heroTimer ||
        count <= 1 ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
      ) {
        return;
      }

      this.startHeroTimer();
      this.destroyRef.onDestroy(() => {
        if (this.heroTimer) clearInterval(this.heroTimer);
      });
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
