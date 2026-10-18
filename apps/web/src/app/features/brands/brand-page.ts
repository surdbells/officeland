import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, startWith, switchMap } from 'rxjs';
import { CatalogueApi, type Brand } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';
import { EMPTY_LISTING, readFilters, splitPagedPath } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';
import { Skeleton } from '../../shared/skeleton';
import { ProductCardSkeleton } from '../../shared/product-card-skeleton';

/**
 * A brand hub at /brands/{slug}/.
 *
 * This is the surface that replaces the legacy `/product-category/hp/`
 * pattern, where brands were modelled as taxonomy terms (defect 4E). It uses
 * the same card, facet rail and pagination as the PLP, because a brand hub IS
 * a listing - only the filter differs.
 */
@Component({
  selector: 'ol-brand-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductCard, FacetRail, Pagination, Skeleton, ProductCardSkeleton],
  template: `
    @if (loading()) {
      <!-- Loading. Holds the hub's shape until the brand and its products
           resolve, instead of a blank screen. A crawler gets the resolved
           page and never this branch. -->
      <div
        class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8"
        aria-busy="true"
        aria-label="Loading brand"
      >
        <ol-skeleton shape="h-3 w-64 max-w-full rounded bg-ink-100" />
        <ol-skeleton shape="mt-5 h-9 w-72 max-w-full rounded bg-ink-100" />
        <ol-skeleton shape="mt-3 h-4 w-full max-w-2xl rounded bg-ink-100" />
        <ul class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          @for (card of skeletonCards; track card) {
            <li class="h-full"><ol-product-card-skeleton /></li>
          }
        </ul>
      </div>
    } @else if (brand(); as b) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-ink-600">
            <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
            <li aria-hidden="true">/</li>
            <li><a routerLink="/brands/" class="hover:text-brand-600">Brands</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-ink-900">{{ b.name }}</li>
          </ol>
        </nav>

        <h1 class="mt-4 font-heading text-3xl font-bold">{{ b.name }}</h1>

        @if (b.description_html) {
          <div class="mt-4 max-w-3xl text-ink-600" [innerHTML]="b.description_html"></div>
        } @else {
          <p class="mt-4 max-w-3xl text-ink-600">
            {{ b.name }} products supplied and supported by OfficeLand across Nigeria. Request a
            quote for volume pricing, delivery and installation.
          </p>
        }

        <!-- Facets above the grid; see category-page. -->
        <div class="mt-10">
          <ol-facet-rail
            [facets]="listing().facets"
            [selected]="selectedFilters()"
            [basePath]="b.url"
          />

          <section aria-labelledby="products-heading" class="mt-8">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h2 id="products-heading" class="font-heading text-lg font-semibold">
                {{ b.name }} products
                <span class="ml-1 font-normal text-ink-600">({{ listing().meta.total }})</span>
              </h2>
            </div>

            @if (listing().data.length) {
              <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @for (product of listing().data; track product.uuid) {
                  <li class="h-full"><ol-product-card [product]="product" [headingLevel]="3" /></li>
                }
              </ul>

              <ol-pagination
                [page]="listing().meta.page"
                [lastPage]="listing().meta.last_page"
                [basePath]="b.url"
                [preserved]="paginationParams()"
              />
            } @else {
              <p class="mt-6 text-ink-600">
                Nothing from {{ b.name }} is listed yet.
                <a routerLink="/quote/" class="text-brand-600 hover:underline">Ask us for a quote</a>
                - we source far more than we list.
              </p>
            }
          </section>
        </div>
      </div>
    } @else if (failed()) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-20">
        <h1 class="font-heading text-2xl font-bold">Brand not found</h1>
        <p class="mt-2 text-ink-600">
          <a routerLink="/brands/" class="text-brand-600 hover:underline">See every brand we supply</a>
        </p>
      </div>
    }
  `,
})
export class BrandPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  private readonly segments = toSignal(
    this.route.url.pipe(map((parts) => parts.map((s) => s.path))),
    { initialValue: [] as string[] },
  );

  protected readonly page = computed(() => splitPagedPath(this.segments(), 'brands').page);

  private readonly queryParams = toSignal(this.route.queryParamMap, { initialValue: null });

  protected readonly selectedFilters = computed<Record<string, readonly string[]>>(() => {
    const params = this.queryParams();
    return params ? readFilters(params) : {};
  });

  private readonly result = toSignal(
    this.route.url.pipe(
      map((segments) => splitPagedPath(segments.map((s) => s.path), 'brands')),
      switchMap(({ path, page }) =>
        combineLatest([
          this.api.brand(path).pipe(
            map((brand) => ({ brand, failed: false })),
            catchError(() => of({ brand: null as Brand | null, failed: true })),
          ),
          this.route.queryParamMap.pipe(
            switchMap((params) =>
              this.api
                .brandProducts(path, { page, filters: readFilters(params) })
                // A failed listing degrades the grid, not the whole hub: the
                // brand copy and the breadcrumbs are what a crawler needs.
                .pipe(catchError(() => of(EMPTY_LISTING))),
            ),
          ),
        ]).pipe(
          map(([brand, listing]) => ({ ...brand, listing, loading: false })),
          // Re-enter loading on each navigation, so moving between brand hubs
          // shows the skeleton rather than the previous brand's content.
          startWith({
            brand: null as Brand | null,
            failed: false,
            listing: EMPTY_LISTING,
            loading: true,
          }),
        ),
      ),
    ),
    {
      initialValue: {
        brand: null as Brand | null,
        failed: false,
        listing: EMPTY_LISTING,
        loading: true,
      },
    },
  );

  protected readonly brand = computed(() => this.result().brand);
  protected readonly failed = computed(() => this.result().failed);
  protected readonly listing = computed(() => this.result().listing);
  protected readonly loading = computed(() => this.result().loading);
  protected readonly skeletonCards = [0, 1, 2, 3, 4, 5, 6, 7] as const;

  protected readonly paginationParams = computed<Record<string, string>>(() => {
    const params: Record<string, string> = {};

    for (const [code, values] of Object.entries(this.selectedFilters())) {
      if (values.length > 0) params[`filters[${code}]`] = values.join(',');
    }

    return params;
  });

  constructor() {
    effect(() => this.applySeo());
  }

  private applySeo(): void {
    const brand = this.brand();
    if (!brand) return;

    const page = this.listing().meta.page;
    const paged = page > 1;
    const filtered = Object.keys(this.selectedFilters()).length > 0;

    this.seo.apply({
      title: paged
        ? [`${brand.name} products - Page ${page}`, `${brand.name} - Page ${page}`]
        : [
            `${brand.name} Products in Nigeria - Bulk Supply`,
            `${brand.name} Products in Nigeria`,
            `${brand.name} Products`,
          ],
      description:
        brand.seo?.meta_description ??
        `${brand.name} office and IT products supplied across Nigeria. Request a quote for volume pricing, delivery and installation.`,
      path: paged ? `${brand.url}page/${page}/` : brand.url,
      // Filtered views are near-duplicates of the hub; page 2+ is its own
      // page and self-canonical (see CategoryPage for the full reasoning).
      indexable: filtered ? false : brand.seo?.is_indexable !== false,
    });

    const nodes: Array<Record<string, unknown>> = [
      breadcrumbSchema([
        { name: 'Home', url: '/' },
        { name: 'Brands', url: '/brands/' },
        { name: brand.name, url: brand.url },
      ]),
    ];

    if (this.listing().data.length > 0) {
      nodes.push(
        itemListSchema(this.listing().data.map((p) => ({ name: p.name, url: p.url }))),
      );
    }

    this.seo.setStructuredData(nodes);
  }
}
