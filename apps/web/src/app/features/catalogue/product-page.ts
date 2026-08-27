import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import {
  CatalogueApi,
  type Product,
  type ProductCard as ProductCardData,
} from '../../core/api/catalogue-api';
import { ProductGallery } from '../../shared/product-gallery';
import { SpecAccordion } from '../../shared/spec-accordion';
import { ProductCard } from '../../shared/product-card';
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
 * CONTENT to be present in the HTML whether or not a panel is expanded —
 * collapsed is a CSS and ARIA state, not absent content, because content
 * rendered only on click is content Google may never see.
 */
@Component({
  selector: 'ol-product-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductGallery, SpecAccordion, ProductCard],
  template: `
    @if (product(); as p) {
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

        <!-- Exactly one h1 per page (section 14.8). -->
        <h1 class="mt-4 font-heading text-2xl font-bold sm:text-3xl">{{ p.name }}</h1>

        <!--
          Brand and model, separated.

          These were two adjacent @if blocks with only source whitespace
          between them, and Angular collapses that — so the page read
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
          <div>
            <ol-product-gallery [images]="p.images ?? []" />
          </div>

          <div>
            <p class="text-sm font-medium">{{ availabilityLabel() }}</p>

            <!-- D-002: no prices anywhere. pricing_mode drives this from data,
                 so enabling posture C later is a data change, not a code one. -->
            @if (p.pricing_mode === 'quote_only') {
              <p class="mt-4 font-heading text-xl font-semibold">Request a quote for pricing</p>
              <p class="mt-1 text-sm text-ink-600">
                We respond within one business day. Volume pricing available.
              </p>
            }

            <div class="mt-6 flex flex-wrap gap-3">
              <button type="button" class="rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white hover:bg-brand-700">
                Add to quote
              </button>
              <a href="https://wa.me/2347074723165" rel="noopener"
                 class="rounded-lg border border-ink-200 px-6 py-3 font-semibold hover:border-brand-600">
                Ask on WhatsApp
              </a>
            </div>

            @if (showQuantityRules()) {
              <p class="mt-3 text-sm text-ink-600">
                Minimum order {{ p.min_order_qty }}, sold in multiples of {{ p.qty_step }}.
              </p>
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

        <!--
          Related products, from typed supplier relations rather than
          hand-curation (ICECAT_MAPPING.md F-3). This is the rail that answers
          "which toner fits this printer", which is the single most common
          consumables question in this catalogue.
        -->
        @if (related().length) {
          <section class="mt-12">
            <h2 class="font-heading text-xl font-semibold">Related products</h2>
            <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              @for (item of related(); track item.uuid) {
                <li class="h-full"><ol-product-card [product]="item" [headingLevel]="3" /></li>
              }
            </ul>
          </section>
        }

      </article>
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
          map((product) => ({ product, failed: false })),
          catchError(() => of({ product: null as Product | null, failed: true })),
        ),
      ),
    ),
    { initialValue: { product: null as Product | null, failed: false } },
  );

  protected readonly product = computed(() => this.result().product);
  protected readonly failed = computed(() => this.result().failed);

  /**
   * The related rail, flattened and bounded.
   *
   * The API groups relations by type (accessory, compatible-with,
   * replacement). A single rail is the right surface for now: four typed
   * rails on a page with one accessory each is worse than one rail of four.
   * Bounded at 8 because every card carries an image, and the PDP has an
   * 800 KB page-weight budget (section 14.9).
   */
  protected readonly related = computed(() => {
    const groups = this.product()?.related;

    if (!groups) return [];

    return Object.values(groups as Record<string, ProductCardData[]>)
      .flat()
      .slice(0, 8);
  });

  protected readonly showQuantityRules = computed(() => {
    const p = this.product();
    return !!p && ((p.min_order_qty ?? 1) > 1 || (p.qty_step ?? 1) > 1);
  });

  /**
   * Lead time is the B2B equivalent of the consumer stock check
   * (section 23.2) — the thing buyers actually ask about.
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
