import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
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
import { Icon } from '../../shared/icon';
import { Skeleton } from '../../shared/skeleton';
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
        <ol-skeleton shape="h-3 w-72 max-w-full rounded bg-ink-100" />
        <ol-skeleton shape="mt-5 h-9 w-2/3 rounded bg-ink-100" />
        <ol-skeleton shape="mt-3 h-4 w-48 rounded bg-ink-100" />
        <div class="mt-6 grid gap-8 lg:grid-cols-2">
          <ol-skeleton shape="aspect-square w-full rounded-2xl bg-ink-100" />
          <div class="space-y-4">
            <ol-skeleton shape="h-6 w-40 rounded bg-ink-100" />
            <ol-skeleton shape="h-4 w-full rounded bg-ink-100" />
            <ol-skeleton shape="h-4 w-5/6 rounded bg-ink-100" />
            <ol-skeleton shape="h-4 w-3/4 rounded bg-ink-100" />
            <ol-skeleton shape="h-12 w-full rounded-full bg-ink-100" />
            <ol-skeleton shape="h-24 w-full rounded-xl bg-ink-100" />
          </div>
        </div>
      </div>
    } @else if (product(); as p) {
      <article class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-ink-600">
            <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
            @for (crumb of p.breadcrumb ?? []; track crumb.url) {
              <li aria-hidden="true">/</li>
              <li><a [routerLink]="crumb.url" class="hover:text-brand-600">{{ crumb.name }}</a></li>
            }
          </ol>
        </nav>

        <!-- Exactly one h1 per page (section 14.8). The display face (inherited
             from the base layer, no font-heading override) matches every other
             page title. -->
        <h1 class="mt-4 text-2xl font-bold leading-tight sm:text-4xl">{{ p.name }}</h1>

        <!--
          Brand and model, separated.

          These were two adjacent @if blocks with only source whitespace
          between them, and Angular collapses that - so the page read
          "OfficeLandModel EX900". A flex row with a gap makes the separation
          structural rather than dependent on a space surviving compilation.
        -->
        <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-600">
          @if (p.brand) {
            <a [routerLink]="p.brand.url" class="font-medium text-brand-600 hover:underline">{{ p.brand.name }}</a>
          }
          @if (p.brand && p.mpn) {
            <span aria-hidden="true" class="text-ink-300">&middot;</span>
          }
          @if (p.mpn) {
            <span>Model {{ p.mpn }}</span>
          }
          @if (p.sku) {
            <span aria-hidden="true" class="text-ink-300">&middot;</span>
            <span>SKU {{ p.sku }}</span>
          }
        </p>

        <div class="mt-6 grid gap-8 lg:grid-cols-2">
          <div class="rounded-2xl bg-[#f6f3ee] p-4 sm:p-6">
            <ol-product-gallery [images]="p.images ?? []" />
          </div>

          <!--
            The buy box.

            Sticky above lg, because the gallery is tall and a buyer
            comparing images against specifications should never have to
            scroll back up to act. "top" clears the sticky site header.

            Everything a B2B buyer needs before raising a requisition, in the
            order they need it: what it is, whether you can get it, when, in
            what quantity, and who they are dealing with.
          -->
          <div class="lg:sticky lg:top-28 lg:self-start">
            <div class="rounded-2xl border border-ink-100 p-6 shadow-xs">
              <p class="flex items-center gap-2 text-sm font-medium">
                <span
                  class="inline-block h-2 w-2 rounded-full"
                  [class]="inStock() ? 'bg-emerald-500' : 'bg-amber-500'"
                  aria-hidden="true"
                ></span>
                {{ availabilityLabel() }}
              </p>

              <!-- D-002: no prices anywhere. pricing_mode drives this from data,
                   so enabling posture C later is a data change, not a code one. -->
              @if (p.pricing_mode === 'quote_only') {
                <p class="mt-4 font-heading text-xl font-semibold">Request a quote for pricing</p>
                <p class="mt-1 text-sm text-ink-600">
                  We respond within one business day. Volume pricing available.
                </p>
              }

              <!--
                Quantity, because this is a trade counter rather than a shop.

                A facilities manager ordering forty chairs should say forty
                here rather than in a free-text note, and min_order_qty and
                qty_step are already on the product for exactly this. Stepped
                to the product's own multiple so an invalid quantity cannot
                be entered rather than being rejected later.
              -->
              <div class="mt-6 flex flex-wrap items-end gap-3">
                <div>
                  <label for="quote-qty" class="block text-sm font-medium">Quantity</label>
                  <input
                    id="quote-qty"
                    type="number"
                    class="mt-1 w-24 rounded-lg border border-ink-200 px-3 py-2.5"
                    [min]="p.min_order_qty ?? 1"
                    [step]="p.qty_step ?? 1"
                    [value]="p.min_order_qty ?? 1"
                    #qty
                  />
                </div>

                <button
                  type="button"
                  class="flex-1 rounded-full bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700"
                >
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
                class="mt-3 flex items-center justify-center gap-2 rounded-full border border-ink-200 px-6 py-3 font-semibold transition-colors hover:border-brand-600 hover:text-brand-600"
              >
                <ol-icon name="message-circle" [size]="18" class="text-brand-600" />
                Ask on WhatsApp
              </a>

              <!--
                The assurances a procurement officer checks before raising an
                order, on the page rather than three clicks away. Every line
                is a fact this business can stand behind; none of it is a
                claim invented for the layout.
              -->
              <ul class="mt-6 space-y-3 border-t border-ink-100 pt-5 text-sm text-ink-600">
                <li class="flex items-start gap-2.5">
                  <ol-icon name="truck" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                  <span>
                    Nationwide delivery, installed in Lagos.
                    <a routerLink="/delivery/" class="text-brand-600 hover:underline">Lead times</a>
                  </span>
                </li>
                <li class="flex items-start gap-2.5">
                  <ol-icon name="shield-check" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                  <span>
                    Manufacturer warranty, claims handled by us.
                    <a routerLink="/warranty/" class="text-brand-600 hover:underline">How it works</a>
                  </span>
                </li>
                <li class="flex items-start gap-2.5">
                  <ol-icon name="badge-check" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                  <span>Authorised reseller, certified by the Standards Organisation of Nigeria.</span>
                </li>
                <li class="flex items-start gap-2.5">
                  <ol-icon name="file-text" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                  <span>
                    Buying in volume?
                    <a routerLink="/bulk-supply/" class="text-brand-600 hover:underline">Send the schedule</a>
                    and we price the whole list.
                  </span>
                </li>
              </ul>
            </div>

            <!--
              Key specifications, above the fold and beside the gallery.

              A spec accordion further down the page is where somebody
              confirms a detail; this is where they decide whether to keep
              reading. Four is the limit on purpose — a summary that lists
              everything is the table it was meant to summarise.
            -->
            @if (keySpecs().length) {
              <!--
                The heading sits OUTSIDE the <dl>.

                A <dl> may only directly contain dt/dd groups wrapped in
                div, plus script and template. A <p> in there is invalid,
                and axe rates it SERIOUS because assistive technology walks
                a definition list by its term/description pairing — a stray
                child breaks that traversal rather than just looking odd.
                Caught by the gate on 1,200 product pages.
              -->
              <div class="mt-6 rounded-2xl border border-ink-100 p-6 text-sm">
                <p class="font-heading font-semibold text-ink-900">At a glance</p>

                <dl>
                  @for (spec of keySpecs(); track spec.label) {
                    <div class="mt-3 flex justify-between gap-4 border-t border-ink-50 pt-3">
                      <dt class="text-ink-600">{{ spec.label }}</dt>
                      <dd class="text-right font-medium text-ink-900">{{ spec.value }}</dd>
                    </div>
                  }
                </dl>
              </div>
            }
          </div>
        </div>

        @if (p.description_html) {
          <section class="mt-12">
            <h2 class="font-heading text-xl font-semibold">Overview</h2>
            <div class="ol-prose mt-3 max-w-3xl text-ink-600" [innerHTML]="p.description_html"></div>
          </section>
        }

        <!-- Grouped specifications (section 23.3). Always in the HTML. -->
        @if (p.specifications?.length) {
          <section class="mt-12">
            <h2 class="font-heading text-xl font-semibold">Specifications</h2>
            <ol-spec-accordion [groups]="p.specifications ?? []" />
          </section>
        }

        @if (p.warranty_text) {
          <section class="mt-10">
            <h2 class="font-heading text-xl font-semibold">Warranty</h2>
            <p class="mt-2 max-w-3xl text-ink-600">{{ p.warranty_text }}</p>
          </section>
        }

      </article>

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
})
export class ProductPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

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
      .slice(0, 4)
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
        return p?.lead_time_days
          ? `Available on ${p.lead_time_days} day lead time`
          : 'Available on lead time';
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
