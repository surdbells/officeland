import { ChangeDetectionStrategy, Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import {
  CatalogueApi,
  type Product,
  type ProductCard as ProductCardData,
} from '../../core/api/catalogue-api';
import { ProductGallery } from '../../shared/product-gallery';
import { SpecAccordion } from '../../shared/spec-accordion';
import { ProductCard } from '../../shared/product-card';
import { Icon, type IconName } from '../../shared/icon';
import { Skeleton } from '../../shared/skeleton';
import { BasketStore } from '../../core/quotes/basket-store';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, productSchema } from '../../core/seo/structured-data';

/**
 * Product detail page.
 *
 * Phase 7 establishes the SEO contract and the page structure; the gallery,
 * quote basket wiring, delivery checker and related rails arrive in Phase 8.
 *
 * The specification table is here rather than deferred for two reasons.
 * Section 23.3 calls the grouped spec table "the single highest-leverage fix"
 * for the legacy site's unstructured supplier prose. And it requires accordion
 * CONTENT to be present in the HTML whether or not a panel is expanded -
 * collapsed is a CSS and ARIA state, not absent content, because content
 * rendered only on click is content Google may never see.
 */
@Component({
  selector: 'ol-product-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductGallery, SpecAccordion, ProductCard, Icon, Skeleton],
  template: `
    @if (loading()) {
      <!-- Loading. Mirrors the PDP's breadcrumb, title and two-column gallery /
           buy-box so the page holds its shape until the product resolves,
           instead of the blank screen the bare @if produced. A crawler is
           served the resolved product and never this branch. -->
      <div
        class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8"
        aria-busy="true"
        aria-label="Loading product"
      >
        <ol-skeleton shape="h-3 w-72 max-w-full rounded-sm bg-ink-100" />
        <ol-skeleton shape="mt-5 h-9 w-2/3 rounded-sm bg-ink-100" />
        <ol-skeleton shape="mt-3 h-4 w-48 rounded-sm bg-ink-100" />
        <div class="mt-6 grid gap-8 lg:grid-cols-2">
          <ol-skeleton shape="aspect-square w-full rounded-2xl bg-ink-100" />
          <div class="space-y-4">
            <ol-skeleton shape="h-6 w-40 rounded-sm bg-ink-100" />
            <ol-skeleton shape="h-4 w-full rounded-sm bg-ink-100" />
            <ol-skeleton shape="h-4 w-5/6 rounded-sm bg-ink-100" />
            <ol-skeleton shape="h-4 w-3/4 rounded-sm bg-ink-100" />
            <ol-skeleton shape="h-12 w-full rounded-full bg-ink-100" />
            <ol-skeleton shape="h-24 w-full rounded-xl bg-ink-100" />
          </div>
        </div>
      </div>
    } @else if (product(); as p) {
      <article class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pb-24 pt-6 md:pb-10 sm:pt-8">
        <nav aria-label="Breadcrumb" class="text-xs text-ink-500 sm:text-sm">
          <ol class="flex flex-wrap items-center gap-1.5">
            <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
            @for (crumb of p.breadcrumb ?? []; track crumb.url) {
              <li aria-hidden="true" class="text-ink-300">/</li>
              <li><a [routerLink]="crumb.url" class="hover:text-brand-600">{{ crumb.name }}</a></li>
            }
          </ol>
        </nav>

        <div class="mt-5 grid gap-8 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-12">
          <!-- The gallery, on the warm ground every product photo sits on. -->
          <div class="lg:sticky lg:top-28 lg:self-start">
            <div class="rounded-3xl border border-ink-100 bg-white p-4 shadow-xs sm:p-6">
              <ol-product-gallery [images]="p.images ?? []" />
            </div>
          </div>

          <!--
            The decision column: what it is, whether you can get it, what it
            does, and the way to act - in the order a buyer needs them.
          -->
          <div>
            <p class="flex flex-wrap items-center gap-2">
              @if (p.brand) {
                <a
                  [routerLink]="p.brand.url"
                  class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] text-brand-700 transition-colors hover:bg-brand-600 hover:text-white"
                >{{ p.brand.name }}</a>
              }
              @if (p.mpn) {
                <span class="rounded-full border border-ink-100 px-3 py-1 font-mono text-xs text-ink-600">Model {{ p.mpn }}</span>
              }
              @if (p.sku && p.sku !== p.mpn) {
                <span class="rounded-full border border-ink-100 px-3 py-1 font-mono text-xs text-ink-400">SKU {{ p.sku }}</span>
              }
            </p>

            <!-- Exactly one h1 per page (section 14.8). -->
            <h1 class="mt-4 text-2xl font-bold leading-tight tracking-tight sm:text-3xl xl:text-4xl">{{ p.name }}</h1>

            <p
              class="mt-4 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold"
              [class]="inStock() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"
            >
              <span class="inline-block h-2 w-2 rounded-full" [class]="inStock() ? 'bg-emerald-500' : 'bg-amber-500'" aria-hidden="true"></span>
              {{ availabilityLabel() }}
            </p>

            @if (p.short_description) {
              <p class="mt-4 leading-relaxed text-ink-600">{{ p.short_description }}</p>
            }

            <!--
              Key specifications, above the fold beside the gallery: where the
              buyer decides whether to keep reading. Taken from the same grouped
              payload as the full table, so the two can never disagree.
              The heading sits OUTSIDE the <dl> (a <p> inside one is invalid).
            -->
            @if (keySpecs().length) {
              <div class="mt-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">At a glance</p>
                <dl class="mt-3 grid grid-cols-2 gap-2.5">
                  @for (spec of keySpecs(); track spec.label) {
                    <div class="rounded-xl border border-ink-100 bg-ink-50 px-3.5 py-3">
                      <dt class="text-xs text-ink-500">{{ spec.label }}</dt>
                      <dd class="mt-0.5 line-clamp-2 text-sm font-semibold text-ink-900">{{ spec.value }}</dd>
                    </div>
                  }
                </dl>
              </div>
            }

            <!-- The buy box. D-002: no prices; pricing_mode drives this from data. -->
            <div id="pdp-buy" class="mt-6 rounded-3xl border border-ink-100 bg-white p-5 shadow-md sm:p-6">
              @if (p.pricing_mode === 'quote_only') {
                <div class="flex items-start gap-3">
                  <span class="inline-flex shrink-0 rounded-xl bg-brand-50 p-2.5 text-brand-600"><ol-icon name="file-text" [size]="20" /></span>
                  <div>
                    <p class="font-heading text-lg font-semibold text-ink-900">Request a quote for pricing</p>
                    <p class="text-sm text-ink-600">Answered within one business day. Volume pricing available.</p>
                  </div>
                </div>
              }

              <!--
                Quantity, because this is a trade counter rather than a shop:
                a buyer ordering forty chairs says forty here. Stepped to the
                product's own multiple so an invalid quantity cannot be built.
              -->
              <div class="mt-5 flex flex-wrap items-stretch gap-3">
                <div class="flex items-center rounded-full border border-ink-200">
                  <button
                    type="button"
                    class="flex h-12 w-11 cursor-pointer items-center justify-center rounded-l-full text-lg text-ink-600 hover:text-brand-600 disabled:cursor-not-allowed disabled:text-ink-300"
                    aria-label="Decrease quantity"
                    [disabled]="quantity() <= minQty()"
                    (click)="stepQuantity(-1)"
                  >&minus;</button>
                  <label for="quote-qty" class="sr-only">Quantity</label>
                  <input
                    id="quote-qty"
                    type="number"
                    inputmode="numeric"
                    class="h-12 w-14 border-0 bg-transparent text-center font-semibold text-ink-900 [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none"
                    [min]="minQty()"
                    [step]="stepQty()"
                    [value]="quantity()"
                    (change)="setQuantity($any($event.target).valueAsNumber)"
                  />
                  <button
                    type="button"
                    class="flex h-12 w-11 cursor-pointer items-center justify-center rounded-r-full text-lg text-ink-600 hover:text-brand-600"
                    aria-label="Increase quantity"
                    (click)="stepQuantity(1)"
                  >+</button>
                </div>

                <button
                  type="button"
                  class="inline-flex min-w-[12rem] flex-1 cursor-pointer items-center justify-center gap-2 rounded-full bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700"
                  (click)="addToQuote(p.slug, quantity())"
                >
                  <ol-icon name="file-text" [size]="18" />
                  Add to quote
                </button>
              </div>

              @if (showQuantityRules()) {
                <p class="mt-3 text-sm text-ink-600">
                  Minimum order {{ p.min_order_qty }}, sold in multiples of {{ p.qty_step }}.
                </p>
              }

              <a
                href="https://wa.me/2347074723165"
                rel="noopener"
                class="mt-3 flex items-center justify-center gap-2 rounded-full border border-ink-200 px-6 py-3 font-semibold text-ink-900 transition-colors hover:border-emerald-500 hover:text-emerald-700"
              >
                <ol-icon name="message-circle" [size]="18" class="text-emerald-600" />
                Ask on WhatsApp
              </a>

              <!-- The assurances a procurement officer checks before raising an order. -->
              <ul class="mt-5 grid gap-2.5 border-t border-ink-100 pt-5 sm:grid-cols-2">
                @for (assurance of assurances; track assurance.text) {
                  <li class="flex items-start gap-2.5 text-sm text-ink-600">
                    <span class="inline-flex shrink-0 rounded-lg bg-ink-50 p-1.5 text-brand-600"><ol-icon [name]="assurance.icon" [size]="16" /></span>
                    <span>
                      {{ assurance.text }}
                      @if (assurance.url) {
                        <a [routerLink]="assurance.url" class="font-medium text-brand-600 hover:underline">{{ assurance.link }}</a>
                      }
                    </span>
                  </li>
                }
              </ul>
            </div>
          </div>
        </div>

        <!-- Section nav: jump links to the long-form content below. -->
        @if (p.description_html || p.specifications?.length || p.warranty_text) {
          <nav aria-label="Product information" class="sticky top-[4.5rem] z-10 -mx-(--spacing-gutter) mt-12 border-y border-ink-100 bg-white/95 px-(--spacing-gutter) backdrop-blur md:top-24">
            <ul class="flex gap-6 overflow-x-auto text-sm font-semibold">
              @if (p.description_html) {
                <li><a href="#pdp-overview" class="block border-b-2 border-transparent py-3.5 text-ink-600 hover:border-brand-600 hover:text-brand-700">Overview</a></li>
              }
              @if (p.specifications?.length) {
                <li><a href="#pdp-specs" class="block border-b-2 border-transparent py-3.5 text-ink-600 hover:border-brand-600 hover:text-brand-700">Specifications</a></li>
              }
              @if (p.warranty_text) {
                <li><a href="#pdp-warranty" class="block border-b-2 border-transparent py-3.5 text-ink-600 hover:border-brand-600 hover:text-brand-700">Warranty</a></li>
              }
            </ul>
          </nav>
        }

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-12">
          <div class="min-w-0 space-y-12">
            @if (p.description_html) {
              <section id="pdp-overview" class="scroll-mt-40">
                <h2 class="font-heading text-xl font-semibold sm:text-2xl">Overview</h2>
                <div class="ol-prose mt-4 max-w-3xl text-ink-600" [innerHTML]="p.description_html"></div>
              </section>
            }

            <!-- Grouped specifications (section 23.3). Always in the HTML. -->
            @if (p.specifications?.length) {
              <section id="pdp-specs" class="scroll-mt-40">
                <h2 class="font-heading text-xl font-semibold sm:text-2xl">Specifications</h2>
                <div class="mt-4 rounded-2xl border border-ink-100 bg-white p-2 shadow-xs sm:p-4">
                  <ol-spec-accordion [groups]="p.specifications ?? []" />
                </div>
              </section>
            }

            @if (p.warranty_text) {
              <section id="pdp-warranty" class="scroll-mt-40">
                <h2 class="font-heading text-xl font-semibold sm:text-2xl">Warranty</h2>
                <p class="mt-3 max-w-3xl leading-relaxed text-ink-600">{{ p.warranty_text }}</p>
              </section>
            }
          </div>

          <!-- Beside the long-form content: the way to a person, and to a bulk price. -->
          <aside class="space-y-4 lg:sticky lg:top-40 lg:self-start">
            <div class="relative isolate overflow-hidden rounded-3xl bg-ink-900 p-6 text-white">
              <span aria-hidden="true" class="ol-pdp-glow absolute inset-0 -z-10"></span>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Buying more than one?</p>
              <h2 class="mt-2 font-heading text-xl font-semibold text-white">Send the whole list</h2>
              <p class="mt-2 text-sm text-white/80">
                Requisitions, tenders and fit-outs priced line by line, with documentation for procurement.
              </p>
              <a routerLink="/bulk-supply/" class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-accent-500 px-5 py-2.5 text-sm font-semibold text-ink-900 transition-colors hover:bg-accent-400">
                Bulk &amp; tender supply <ol-icon name="arrow-right" [size]="14" />
              </a>
            </div>
            <div class="rounded-3xl border border-ink-100 bg-white p-6 shadow-xs">
              <p class="font-heading text-base font-semibold text-ink-900">See it in person</p>
              <p class="mt-1 text-sm text-ink-600">Two showrooms on Idowu Martins Street, Victoria Island.</p>
              <a routerLink="/locations/" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline">
                Showrooms &amp; directions <ol-icon name="arrow-right" [size]="14" />
              </a>
            </div>
          </aside>
        </div>
      </article>

      <!--
        Phone: the action stays in reach. A bar above the bottom navigation
        with the product's own Add to quote; hidden from md, where the buy box
        sits beside the gallery.
      -->
      <div class="ol-pdp-bar fixed inset-x-0 z-40 flex items-center gap-3 border-t border-ink-100 bg-white/95 px-4 py-2.5 backdrop-blur md:hidden">
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-ink-900">{{ p.name }}</p>
          <p class="text-xs" [class]="inStock() ? 'text-emerald-700' : 'text-amber-700'">{{ availabilityLabel() }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 cursor-pointer rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white"
          (click)="addToQuote(p.slug, quantity())"
        >Add to quote</button>
      </div>

      <!--
        Related products, on a dark band so the product photographs carry it -
        the reference PDP's "you may also like". Typed supplier relations where
        they exist (the toner that fits this printer), else the same-category
        fallback, so the band is filled on every product. Full-bleed, so it
        sits outside the article's reading column.
      -->
      @if (related().length) {
        <section aria-labelledby="related-heading" class="relative isolate mt-14 overflow-hidden bg-ink-900 text-white">
          <span aria-hidden="true" class="ol-related-glow absolute"></span>
          <div class="relative mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14">
            <div class="flex flex-wrap items-end justify-between gap-4">
              <div>
                <p class="font-heading text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">You may also like</p>
                <h2 id="related-heading" class="mt-2 text-2xl font-bold text-white sm:text-3xl">Related products</h2>
              </div>
            </div>
            <ul class="ol-related-rail mt-8 flex snap-x gap-4 overflow-x-auto pb-2">
              @for (item of related(); track item.uuid) {
                <li class="w-56 shrink-0 snap-start sm:w-60">
                  <ol-product-card [product]="item" [headingLevel]="3" imageSizes="15rem" />
                </li>
              }
            </ul>
          </div>
        </section>
      }
    } @else if (failed()) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-20">
        <h1 class="font-heading text-2xl font-bold">Product not found</h1>
        <p class="mt-2 text-ink-600">
          <a routerLink="/c/" class="text-brand-600 hover:underline">Browse the catalogue</a>
        </p>
      </div>
    }
  `,
  styles: `
    .ol-pdp-glow {
      background:
        radial-gradient(28rem 16rem at 100% 0%, rgb(5 91 240 / 0.45), transparent 65%),
        radial-gradient(20rem 12rem at 0% 100%, rgb(245 158 11 / 0.16), transparent 60%);
    }

    /* Sits on top of the fixed bottom navigation (3.25rem + the safe area). */
    .ol-pdp-bar {
      bottom: calc(3.25rem + env(safe-area-inset-bottom, 0px));
    }
  `,
})
export class ProductPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);
  private readonly basket = inject(BasketStore);

  /**
   * Add this product to the quote basket at the chosen quantity.
   *
   * The quantity comes straight off the number input; the server coerces it to
   * the product's min/step (§9b), so this only guards against an empty or junk
   * value before sending. Success raises the shared toast via the basket store.
   */
  /** The quantity the buyer has chosen, reset to the product's minimum on each product. */
  protected readonly quantity = signal(1);

  protected readonly minQty = computed(() => Math.max(1, this.product()?.min_order_qty ?? 1));
  protected readonly stepQty = computed(() => Math.max(1, this.product()?.qty_step ?? 1));

  protected stepQuantity(direction: 1 | -1): void {
    this.setQuantity(this.quantity() + direction * this.stepQty());
  }

  /** Clamp to the minimum and snap to the product's multiple. */
  protected setQuantity(value: number): void {
    const min = this.minQty();
    const step = this.stepQty();
    const raw = Number.isFinite(value) ? Math.floor(value) : min;
    const snapped = Math.max(min, min + Math.round((raw - min) / step) * step);
    this.quantity.set(snapped);
  }

  protected readonly assurances: readonly { icon: IconName; text: string; link?: string; url?: string }[] = [
    { icon: 'truck', text: 'Nationwide delivery, installed in Lagos.', link: 'Lead times', url: '/delivery/' },
    { icon: 'shield-check', text: 'Manufacturer warranty, claims handled by us.', link: 'How it works', url: '/warranty/' },
    { icon: 'badge-check', text: 'Authorised reseller, SON certified.' },
    { icon: 'clock', text: 'Quotes answered within one business day.' },
  ];

  protected addToQuote(slug: string, qty: number): void {
    const quantity = Number.isFinite(qty) && qty > 0 ? Math.floor(qty) : 1;
    this.basket.addProduct(slug, quantity);
  }

  private readonly result = toSignal(
    this.route.paramMap.pipe(
      map((params) => params.get('slug') ?? ''),
      switchMap((slug) =>
        this.api.product(slug).pipe(
          map((product) => ({ product, failed: false, loading: false })),
          catchError(() => of({ product: null as Product | null, failed: true, loading: false })),
          // Re-enter loading on each slug change, so moving between products
          // shows the skeleton rather than the previous product until it lands.
          startWith({ product: null as Product | null, failed: false, loading: true }),
        ),
      ),
    ),
    { initialValue: { product: null as Product | null, failed: false, loading: true } },
  );

  protected readonly product = computed(() => this.result().product);
  protected readonly failed = computed(() => this.result().failed);
  protected readonly loading = computed(() => this.result().loading);

  /**
   * Same-category products, as the fallback related rail.
   *
   * Typed supplier relations (accessory, compatible-with) only exist for the
   * Icecat-matched catalogue. The imported WooCommerce products have none, so
   * "You may also like" would be empty on every one of them. This fills it
   * from the product's own leaf category - real products, no curation - which
   * is exactly the "more like this" a related rail is for. Fetched only when
   * there are no typed relations to show.
   */
  private readonly fallbackRelated = toSignal(
    toObservable(this.product).pipe(
      switchMap((p) => {
        if (!p) return of([] as ProductCardData[]);

        const typed = p.related
          ? Object.values(p.related as Record<string, ProductCardData[]>).flat()
          : [];
        if (typed.length > 0) return of([] as ProductCardData[]);

        const crumb = p.breadcrumb?.[p.breadcrumb.length - 1];
        const path = (crumb?.url ?? '').replace(/^\/c\//, '').replace(/^\/+|\/+$/g, '');
        if (path === '') return of([] as ProductCardData[]);

        return this.api.categoryProducts(path, { perPage: 14 }).pipe(
          map((listing) => listing.data.filter((x) => x.slug !== p.slug).slice(0, 10)),
          catchError(() => of([] as ProductCardData[])),
        );
      }),
    ),
    { initialValue: [] as ProductCardData[] },
  );

  /**
   * The related rail: typed supplier relations where they exist, else the
   * same-category fallback. Bounded because every card carries an image and
   * the PDP has an 800 KB page-weight budget (section 14.9).
   */
  protected readonly related = computed<readonly ProductCardData[]>(() => {
    const groups = this.product()?.related;
    const typed = groups
      ? Object.values(groups as Record<string, ProductCardData[]>).flat().slice(0, 8)
      : [];

    return typed.length > 0 ? typed : this.fallbackRelated();
  });

  protected readonly showQuantityRules = computed(() => {
    const p = this.product();
    return !!p && ((p.min_order_qty ?? 1) > 1 || (p.qty_step ?? 1) > 1);
  });

  /**
   * Whether the dot beside the availability line is green or amber.
   *
   * Only in_stock earns green. "Made to order" and "lead time" are real
   * answers rather than problems, but colouring them as available would
   * tell a buyer with a deadline the opposite of what they need to know.
   */
  protected readonly inStock = computed(() => this.product()?.availability === 'in_stock');

  /**
   * The first few specifications, for the summary beside the gallery.
   *
   * Taken from the same grouped payload the accordion renders, so the
   * summary can never disagree with the table below it — the failure mode
   * of a hand-picked "key specs" list is that it goes stale against the
   * real data and nobody notices.
   *
   * Four, because a summary that lists everything is the table it was
   * meant to summarise.
   */
  protected readonly keySpecs = computed<ReadonlyArray<{ label: string; value: string }>>(() => {
    const groups = this.product()?.specifications ?? [];

    return groups
      .flatMap((group) => group.items ?? [])
      .slice(0, 6)
      .map((row) => ({ label: String(row.label ?? ''), value: String(row.value ?? '') }))
      .filter((row) => row.label !== '' && row.value !== '');
  });

  /**
   * Lead time is the B2B equivalent of the consumer stock check
   * (section 23.2) - the thing buyers actually ask about.
   */
  protected readonly availabilityLabel = computed(() => {
    const p = this.product();

    switch (p?.availability) {
      case 'in_stock':
        return 'In stock';
      case 'limited':
        return 'Limited stock';
      case 'made_to_order':
        return 'Made to order';
      case 'discontinued':
        return 'Discontinued';
      default:
        // Lead time is confirmed per line on the quotation, not stated here.
        return 'Available to order';
    }
  });

  constructor() {
    /*
     * An effect, not a one-shot call. Metadata follows the data, and the
     * data arrives asynchronously: a microtask or a constructor call runs
     * while the signal is still null, applies nothing, and never runs again.
     * That produced a prerendered catalogue whose every page carried the
     * index.html placeholder title and no canonical.
     *
     * As an effect it also re-runs on client-side navigation between
     * products, which a one-shot call in a reused component would not.
     *
     * Only this service writes tags (section 15.5).
     */
    effect(() => this.applySeo());

    // A new product starts at its own minimum quantity.
    effect(() => {
      this.product();
      this.quantity.set(untracked(() => this.minQty()));
    });
  }

  private applySeo(): void {
    const p = this.product();
    if (!p) return;

    this.seo.apply({
      title: p.name,
      titleSuffix: p.brand?.name ?? undefined,
      description: p.seo?.meta_description ?? p.short_description ?? undefined,
      path: p.url,
      type: 'product',
      // Section 14.7: below the content minimum a product is not indexable.
      indexable: p.seo?.is_indexable !== false,
      canonicalOverride: p.seo?.canonical_override ?? null,
    });

    const crumbs = [
      { name: 'Home', url: '/' },
      ...(p.breadcrumb ?? []).map((c) => ({ name: c.name ?? '', url: c.url ?? '' })),
      { name: p.name, url: p.url },
    ];

    this.seo.setStructuredData([
      breadcrumbSchema(crumbs),
      productSchema({
        name: p.name,
        url: p.url,
        description: p.short_description ?? null,
        sku: p.sku ?? null,
        mpn: p.mpn ?? null,
        brand: p.brand?.name ?? null,
        availability: p.availability ?? null,
        pricingMode: p.pricing_mode ?? null,
        specifications: (p.specifications ?? []).flatMap((group) =>
          (group.items ?? []).map((item) => ({
            label: item.label ?? '',
            value: item.value ?? '',
          })),
        ),
      }),
    ]);
  }
}
