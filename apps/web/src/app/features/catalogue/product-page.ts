import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import { CatalogueApi, type Product } from '../../core/api/catalogue-api';
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
  imports: [RouterLink],
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

        <p class="mt-2 text-sm text-ink-600">
          @if (p.brand) {
            <a [routerLink]="p.brand.url" class="text-brand-600 hover:underline">{{ p.brand.name }}</a>
          }
          @if (p.mpn) {
            <span>Model {{ p.mpn }}</span>
          }
        </p>

        <div class="mt-6 grid gap-8 lg:grid-cols-2">
          <div>
            <!-- Gallery: Phase 8, with the derivative pipeline from Phase 4. -->
            <div class="aspect-square rounded-lg bg-ink-50"></div>
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
            <div class="mt-3 max-w-3xl text-ink-600" [innerHTML]="p.description_html"></div>
          </section>
        }

        <!-- Grouped specifications (section 23.3). Always in the HTML. -->
        @if (p.specifications?.length) {
          <section class="mt-12">
            <h2 class="font-heading text-xl font-semibold">Specifications</h2>
            @for (group of p.specifications ?? []; track group.group) {
              <div class="mt-6">
                <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-ink-600">
                  {{ group.group }}
                </h3>
                <table class="mt-2 w-full max-w-3xl text-sm">
                  <tbody>
                    @for (item of group.items ?? []; track $index) {
                      <tr class="border-b border-ink-100">
                        <th scope="row" class="py-2 pr-4 text-left font-medium">{{ item.label }}</th>
                        <td class="py-2 text-ink-600">{{ item.value }}</td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            }
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
