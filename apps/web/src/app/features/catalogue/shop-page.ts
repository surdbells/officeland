import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { EMPTY_LISTING, readFilters } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { Icon } from '../../shared/icon';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';

/**
 * The shop page — the whole catalogue behind one set of filters.
 *
 * ── Why it exists when every category already has a page ─────────────────
 * Category pages answer "show me chairs". This answers the questions that do
 * not start from a category: everything from one brand, everything with a
 * given attribute, everything added recently. A buyer working from a
 * requisition list rather than a browse path has no category to start from,
 * and the reference site has no equivalent at all.
 *
 * ── Filters on the left ──────────────────────────────────────────────────
 * The same side as the category pages. A filter rail that moves depending on
 * which listing you are looking at costs the visitor a moment of hunting on
 * every page, and consistency across the site is worth more than any argument
 * for one side over the other.
 *
 * The DOM order is still grid first, filters second, so that on a phone —
 * where the two columns stack — the products are what appears under the
 * heading rather than a long rail of filters. CSS "order" puts the rail on
 * the left at desktop widths without moving it in the markup.
 *
 * ── Noindex, and not reluctantly ─────────────────────────────────────────
 * An unfiltered listing of the whole catalogue competes directly with the
 * category pages that carry the intro copy, and every filter combination is
 * another URL with the same products in a different order. That is the
 * textbook infinite crawl space (SEO_SPEC §10.3). This page is for buyers,
 * not for crawlers, and the canonical route into the catalogue stays the
 * taxonomy.
 *
 * ── Prerendered as a shell ───────────────────────────────────────────────
 * Under D-009 the URL still has to resolve to a file on a static host, so the
 * page is prerendered; the listing arrives client-side because it depends on
 * query parameters a static file cannot vary on. Same arrangement as /search.
 */
@Component({
  selector: 'ol-shop-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, FacetRail, Icon, Pagination, ProductCard],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <nav aria-label="Breadcrumb" class="text-sm text-ink-600">
        <ol class="flex flex-wrap items-center gap-1.5">
          <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
          <li aria-hidden="true">/</li>
          <li aria-current="page" class="text-ink-900">Shop</li>
        </ol>
      </nav>

      <h1 class="mt-4 font-heading text-3xl font-bold">Shop the catalogue</h1>
      <p class="mt-3 max-w-3xl text-ink-600">
        Everything we list, filterable by brand and specification. If you are
        working from a requisition list, send it to us instead &mdash;
        <a routerLink="/quote/" class="text-brand-600 hover:underline">we quote from a schedule</a>
        and we supply far more than appears here.
      </p>

      <div class="mt-8 grid gap-8 lg:grid-cols-[18rem_1fr]">
        <!--
          Grid first in the DOM, left-hand column at desktop via CSS "order".
          Stacked on a phone, the products are what follows the heading.
        -->
        <section aria-labelledby="products-heading" class="lg:order-2">
          <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink-100 pb-3">
            <h2 id="products-heading" class="font-heading text-lg font-semibold">
              Products
              <span class="ml-1 font-normal text-ink-600">({{ listing().meta.total }})</span>
            </h2>
            <p class="text-sm text-ink-600">{{ rangeLabel() }}</p>
          </div>

          @if (listing().data.length) {
            <ul class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
              @for (product of listing().data; track product.uuid) {
                <li class="h-full">
                  <ol-product-card [product]="product" [headingLevel]="3" />
                </li>
              }
            </ul>

            <ol-pagination
              [page]="listing().meta.page"
              [lastPage]="listing().meta.last_page"
              basePath="/shop/"
              [preserved]="paginationParams()"
            />
          } @else {
            <p class="mt-6 text-ink-600">
              @if (hasFilters()) {
                No products match those filters.
                <a routerLink="/shop/" class="text-brand-600 hover:underline">Clear them</a>
                to see the whole catalogue.
              } @else {
                Nothing is listed yet.
                <a routerLink="/quote/" class="text-brand-600 hover:underline">Ask us for a quote</a>
                &mdash; we supply far more than we list.
              }
            </p>
          }
        </section>

        <!--
          The rail is a disclosure on small screens and always-open above lg.
          A filter rail that cannot be collapsed on a phone pushes the products
          off the first two screens, which is the same as not having them.
        -->
        <div class="lg:order-1">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-xl border border-ink-200 px-4 py-3 text-sm font-semibold lg:hidden"
            [attr.aria-expanded]="filtersOpen()"
            aria-controls="shop-filters"
            (click)="filtersOpen.set(!filtersOpen())"
          >
            <span class="flex items-center gap-2">
              <ol-icon name="funnel" [size]="18" class="text-brand-600" />
              Filters
              @if (filterCount() > 0) {
                <span class="rounded-full bg-brand-600 px-2 text-xs text-white">{{ filterCount() }}</span>
              }
            </span>
            <ol-icon [name]="filtersOpen() ? 'chevron-down' : 'chevron-right'" [size]="16" />
          </button>

          <div
            id="shop-filters"
            class="mt-4 lg:mt-0 lg:block"
            [class.hidden]="!filtersOpen()"
          >
            <ol-facet-rail
              [facets]="listing().facets"
              [selected]="selectedFilters()"
              basePath="/shop/"
              [preserved]="preservedParams()"
            />
          </div>
        </div>
      </div>
    </div>
  `,
})
export class ShopPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  protected readonly filtersOpen = signal(false);

  private readonly queryParams = toSignal(this.route.queryParamMap, {
    initialValue: null,
  });

  protected readonly selectedFilters = computed<Record<string, readonly string[]>>(() => {
    const params = this.queryParams();

    return params ? readFilters(params) : {};
  });

  protected readonly filterCount = computed(() =>
    Object.values(this.selectedFilters()).reduce((total, values) => total + values.length, 0),
  );

  protected readonly hasFilters = computed(() => this.filterCount() > 0);

  protected readonly listing = toSignal(
    this.route.queryParamMap.pipe(
      switchMap((params) =>
        this.api
          .catalogue({
            page: Number(params.get('page') ?? 1) || 1,
            sort: params.get('sort') ?? undefined,
            filters: readFilters(params),
          })
          .pipe(catchError(() => of(EMPTY_LISTING))),
      ),
    ),
    { initialValue: EMPTY_LISTING },
  );

  protected readonly rangeLabel = computed(() => {
    const meta = this.listing().meta;

    if (meta.total === 0) {
      return '';
    }

    const first = (meta.page - 1) * meta.per_page + 1;
    const last = Math.min(meta.page * meta.per_page, meta.total);

    return `Showing ${first}–${last} of ${meta.total}`;
  });

  /** Carried across facet changes; page deliberately is not. */
  protected readonly preservedParams = computed<Record<string, string>>(() => {
    const sort = this.queryParams()?.get('sort');

    return sort ? { sort } : ({} as Record<string, string>);
  });

  protected readonly paginationParams = computed<Record<string, string>>(() => {
    const params: Record<string, string> = { ...this.preservedParams() };

    for (const [code, values] of Object.entries(this.selectedFilters())) {
      if (values.length > 0) {
        params[code] = values.join(',');
      }
    }

    return params;
  });

  constructor() {
    effect(() => {
      this.seo.apply({
        title: ['Shop the Catalogue — Filter by Brand and Specification', 'Shop the catalogue', 'Shop'],
        description:
          'Filter the OfficeLand catalogue by brand and specification. Quotes answered within one business day.',
        path: '/shop/',
        // See the class comment: this competes with the category pages and
        // every filter combination is another URL of the same products.
        indexable: false,
        canonicalOverride: null,
      });
    });
  }
}
