import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, switchMap } from 'rxjs';
import {
  CatalogueApi,
  type Category,
  type ProductCard as ProductCardData,
} from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';
import { EMPTY_LISTING, filterParams, readFilters, splitPagedPath } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';

/**
 * Category page — breadcrumbs, child anchors, facet rail, product grid.
 *
 * ── D-011, and why the child list is not decoration ──────────────────────
 * Section 14.5 requires every product within 3 clicks of the homepage.
 * Section 7.1 caps categories at depth 3. Section 23.1 rule 2 loads level-3
 * menu links on intent only. Together those make products 4 clicks minimum
 * and the gate unpassable.
 *
 * Rendering every child category here as a real <a href> gives
 * home → department (1) → leaf (2) → product (3). At 15,000 products across
 * 86 leaves that is the difference between a crawlable catalogue and a
 * quarter of it stranded.
 * ─────────────────────────────────────────────────────────────────────────
 */
@Component({
  selector: 'ol-category-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductCard, FacetRail, Pagination],
  template: `
    @if (category(); as cat) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
        <!-- Breadcrumbs on every non-home page (section 23.5), matching the
             BreadcrumbList markup emitted below. -->
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-ink-600">
            <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
            @for (crumb of cat.breadcrumb ?? []; track crumb.url) {
              <li aria-hidden="true">/</li>
              <li><a [routerLink]="crumb.url" class="hover:text-brand-600">{{ crumb.name }}</a></li>
            }
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-ink-900">{{ cat.name }}</li>
          </ol>
        </nav>

        <h1 class="mt-4 font-heading text-3xl font-bold">{{ cat.name }}</h1>

        @if (cat.intro_copy) {
          <div class="mt-4 max-w-3xl text-ink-600">{{ cat.intro_copy }}</div>
        }

        <!-- D-011. Real anchors, always in the HTML. -->
        @if (cat.children?.length) {
          <nav aria-label="Subcategories" class="mt-8">
            <h2 class="font-heading text-sm font-semibold uppercase tracking-wide">Browse by category</h2>
            <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              @for (child of cat.children; track child.slug) {
                <li>
                  <a [routerLink]="child.url" class="block rounded-lg border border-ink-100 px-4 py-3 hover:border-brand-600">
                    <span class="font-medium">{{ child.name }}</span>
                    @if (child.product_count) {
                      <span class="ml-1 text-sm text-ink-400">({{ child.product_count }})</span>
                    }
                  </a>
                </li>
              }
            </ul>
          </nav>
        }

        <div class="mt-10 grid gap-8 lg:grid-cols-[16rem_1fr]">
          <ol-facet-rail
            [facets]="listing().facets"
            [selected]="selectedFilters()"
            [basePath]="cat.url"
            [preserved]="preservedParams()"
          />

          <section aria-labelledby="products-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h2 id="products-heading" class="font-heading text-lg font-semibold">
                Products
                <span class="ml-1 font-normal text-ink-600">({{ listing().meta.total }})</span>
              </h2>
              <p class="text-sm text-ink-600">{{ rangeLabel() }}</p>
            </div>

            @if (listing().data.length) {
              <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @for (product of listing().data; track product.uuid) {
                  <li class="h-full">
                    <ol-product-card [product]="product" [headingLevel]="3" />
                  </li>
                }
              </ul>

              <ol-pagination
                [page]="listing().meta.page"
                [lastPage]="listing().meta.last_page"
                [basePath]="cat.url"
                [preserved]="paginationParams()"
              />
            } @else {
              <p class="mt-6 text-ink-600">
                @if (hasFilters()) {
                  No products match those filters.
                  <a [routerLink]="cat.url" class="text-brand-600 hover:underline">Clear them</a>
                  to see everything in {{ cat.name }}.
                } @else {
                  Nothing is listed here yet.
                  <a routerLink="/quote/" class="text-brand-600 hover:underline">Ask us for a quote</a>
                  &mdash; we supply far more than we list.
                }
              </p>
            }
          </section>
        </div>
      </div>
    } @else if (failed()) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-20">
        <h1 class="font-heading text-2xl font-bold">Category not found</h1>
        <p class="mt-2 text-ink-600">
          <a routerLink="/c/" class="text-brand-600 hover:underline">Browse all departments</a>
        </p>
      </div>
    }
  `,
})
export class CategoryPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  private readonly queryParams = toSignal(this.route.queryParamMap, { initialValue: null });

  private readonly segments = toSignal(
    this.route.url.pipe(map((parts) => parts.map((s) => s.path))),
    { initialValue: [] as string[] },
  );

  protected readonly page = computed(() => splitPagedPath(this.segments(), 'c').page);

  protected readonly sort = computed(() => this.queryParams()?.get('sort') ?? null);

  protected readonly selectedFilters = computed<Record<string, readonly string[]>>(() => {
    const params = this.queryParams();

    return params ? readFilters(params) : {};
  });

  protected readonly hasFilters = computed(
    () => Object.keys(this.selectedFilters()).length > 0,
  );

  /*
   * ActivatedRoute.url for a 'c/**' route yields every segment the route
   * consumed, INCLUDING the literal 'c'. The API keys categories on the
   * materialized path, which has no /c/ prefix (D-008), so passing this
   * through unmodified requests /api/v1/categories/c/office-furniture and
   * 404s every category page on the site.
   */
  private readonly result = toSignal(
    this.route.url.pipe(
      map((segments) => splitPagedPath(segments.map((s) => s.path), 'c')),
      switchMap(({ path, page }) =>
        combineLatest([
          this.api.category(path).pipe(
            map((category) => ({ category, failed: false })),
            catchError(() => of({ category: null as Category | null, failed: true })),
          ),
          this.route.queryParamMap.pipe(
            switchMap((params) =>
              this.api
                .categoryProducts(path, {
                  page,
                  sort: params.get('sort') ?? undefined,
                  filters: readFilters(params),
                })
                // A failed listing must not take the category page down: the
                // intro copy, the child anchors and the breadcrumbs are the
                // parts a crawler needs, and they do not depend on the grid.
                .pipe(catchError(() => of(EMPTY_LISTING))),
            ),
          ),
        ]).pipe(map(([category, listing]) => ({ ...category, listing }))),
      ),
    ),
    { initialValue: { category: null as Category | null, failed: false, listing: EMPTY_LISTING } },
  );

  protected readonly category = computed(() => this.result().category);
  protected readonly failed = computed(() => this.result().failed);
  protected readonly listing = computed(() => this.result().listing);

  protected readonly rangeLabel = computed(() => {
    const meta = this.listing().meta;
    if (meta.total === 0) return '';

    const first = (meta.page - 1) * meta.per_page + 1;
    const last = Math.min(meta.page * meta.per_page, meta.total);

    return `Showing ${first}–${last} of ${meta.total}`;
  });

  /** Carried across facet changes; page is deliberately not. */
  protected readonly preservedParams = computed<Record<string, string>>(() => {
    const sort = this.sort();
    const params: Record<string, string> = {};

    if (sort) params['sort'] = sort;

    return params;
  });

  /** Carried across page changes: filters and sort both survive. */
  protected readonly paginationParams = computed<Record<string, string>>(() => ({
    ...this.preservedParams(),
    ...filterParams(this.selectedFilters()),
  }));

  constructor() {
    /*
     * An effect, not a one-shot call. Metadata follows the data, and the
     * data arrives asynchronously: a microtask or a constructor call runs
     * while the signal is still null, applies nothing, and never runs again.
     * That produced a prerendered catalogue whose every page carried the
     * index.html placeholder title and no canonical.
     *
     * As an effect it also re-runs on client-side navigation between
     * categories, which a one-shot call in a reused component would not.
     *
     * Only this service writes tags (section 15.5).
     */
    effect(() => this.applySeo());
  }

  private applySeo(): void {
    const cat = this.category();
    if (!cat) return;

    const meta = this.listing().meta;
    const page = meta.page;
    const filtered = this.hasFilters();

    const crumbs = [
      { name: 'Home', url: '/' },
      ...(cat.breadcrumb ?? []).map((c) => ({ name: c.name, url: c.url })),
      { name: cat.name, url: cat.url },
    ];

    /*
     * ── Paginated and filtered pages (SEO_SPEC.md section 2) ─────────────
     *
     * A filtered page is noindex, follow. The combinatorial space of facet
     * selections is enormous and every one of them is a near-duplicate of the
     * unfiltered page; `follow` keeps them useful as a discovery surface.
     *
     * Page 2+ IS indexable and self-canonical. Canonicalising it to page 1 is
     * a common mistake: it tells Google the products on page 2 belong to a
     * page that does not list them, and they drop out of the index. The
     * distinct title is what stops page 2 reading as a duplicate.
     */
    const paged = page > 1;

    this.seo.apply({
      /*
       * Section 14.3 category template, in descending preference. "Prices"
       * is retained because it matches query intent even though D-002 shows
       * none, and "Bulk Supply" because the buyer is a business.
       *
       * Longer category names cannot carry the full phrase inside 60
       * characters, so shorter whole phrases are offered rather than letting
       * the budget truncate the first one mid-clause.
       */
      title: paged
        ? [
            `${cat.name} in Nigeria — Page ${page}`,
            `${cat.name} — Page ${page}`,
          ]
        : [
            `${cat.name} in Nigeria — Prices & Bulk Supply`,
            `${cat.name} — Prices & Bulk Supply`,
            `${cat.name} in Nigeria`,
            cat.name,
          ],
      description: cat.seo?.meta_description ?? cat.intro_copy ?? undefined,
      path: paged ? `${cat.url}page/${page}/` : cat.url,
      indexable: filtered ? false : cat.seo?.is_indexable !== false,
      canonicalOverride: cat.seo?.canonical_override ?? null,
    });

    const nodes: Array<Record<string, unknown>> = [breadcrumbSchema(crumbs)];

    // ItemList reflects what this page actually lists, in display order.
    if (this.listing().data.length > 0) {
      nodes.push(
        itemListSchema(
          this.listing().data.map((product: ProductCardData) => ({
            name: product.name,
            url: product.url,
          })),
        ),
      );
    }

    this.seo.setStructuredData(nodes);
  }
}
