import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, distinctUntilChanged, map, of, switchMap } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { EMPTY_LISTING, filterParams, readFilters } from '../../shared/listing-state';
import { Icon } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import type { ShopFilters } from '../../core/api/catalogue-api';
import { ShopSidebar, type ShopState } from './shop-sidebar';
import { FacetRail } from '../../shared/facet-rail';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';

const EMPTY_FILTERS: ShopFilters = { brands: [], availability: [] };

/**
 * The shop page - the whole catalogue behind one set of filters.
 *
 * ── Why it exists when every category already has a page ─────────────────
 * Category pages answer "show me chairs". This answers the questions that do
 * not start from a category: everything from one brand, everything with a
 * given attribute, everything added recently. A buyer working from a
 * requisition list rather than a browse path has no category to start from,
 * and the reference site has no equivalent at all.
 *
 * ── A left rail here, a filter bar everywhere else ───────────────────────
 * This is the only listing with a vertical rail. Category, brand and search
 * pages put their facets in a horizontal bar above the grid instead.
 *
 * The split follows how the pages are used. A category page arrives with the
 * scope already narrowed - you are inside "Seating" - so filtering is a
 * secondary act, and a bar above the grid keeps the products at full width
 * where they belong. This page starts from the entire catalogue, so filtering
 * IS the page: there are more groups, more values, and more traffic through
 * them. A rail holds that without collapsing anything, and it stays visible
 * while the grid scrolls.
 *
 * The DOM order is grid first, filters second, so that on a phone - where the
 * two columns stack - the products are what appears under the heading rather
 * than a long rail of filters. CSS "order" puts the rail on the left at
 * desktop widths without moving it in the markup.
 *
 * ── The department rail on top ───────────────────────────────────────────
 * Above the grid sits a rail of department tiles (their card artwork, from
 * the navigation payload every page already carries) and, once a department
 * is chosen, a row of its subcategories. It is a real filter, not a set of
 * exits: it sets ?category=<path> and the listing switches to that category's
 * products and facets while the buyer stays on this page. Choosing a category
 * drops the attribute filters, because facets differ per category and a
 * filter that no longer exists would silently match nothing.
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
  imports: [RouterLink, FacetRail, Pagination, ProductCard, Icon, ResponsiveImage, ShopSidebar],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-6 sm:py-8">
      <!-- Page header: a quiet breadcrumb, a title that steps down a size on a
           phone, and an intro that reads a notch smaller there so the products
           start higher. The same treatment belongs on every listing page. -->
      <nav aria-label="Breadcrumb" class="text-xs text-ink-500">
        <ol class="flex flex-wrap items-center gap-1.5">
          <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
          <li aria-hidden="true" class="text-ink-300">/</li>
          <li aria-current="page" class="font-medium text-ink-700">Shop</li>
        </ol>
      </nav>

      <h1 class="mt-2.5 font-heading text-2xl font-bold tracking-tight sm:text-3xl">Shop the catalogue</h1>
      <p class="mt-2 max-w-3xl text-sm leading-relaxed text-ink-600 sm:text-base">
        Everything we list, filterable by brand and specification. If you are
        working from a requisition list, send it to us instead -
        <a routerLink="/quote/" class="text-brand-600 hover:underline">we quote from a schedule</a>
        and we supply far more than appears here.
      </p>

      @if (departments().length) {
        <nav aria-label="Filter by department" class="mt-6 rounded-3xl border border-ink-100 bg-ink-50 p-4 sm:mt-8 sm:p-5">
          <div class="flex items-baseline justify-between gap-3">
            <p class="ol-eyebrow">Shop by department</p>
            @if (activeDepartment(); as active) {
              <a [routerLink]="active.url" class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-sm font-semibold text-brand-600 hover:underline">
                {{ active.name }} page
                <ol-icon name="arrow-right" [size]="14" />
              </a>
            }
          </div>

          <ul class="ol-shop-rail mt-3 flex gap-3 overflow-x-auto pb-1 sm:gap-4">
            <li class="w-24 shrink-0 sm:w-28">
              <a
                routerLink="/shop/"
                [queryParams]="preservedSort()"
                [attr.aria-current]="activeDepartment() ? null : 'true'"
                [class]="tileClass(!activeDepartment())"
              >
                <span class="flex aspect-square w-full items-center justify-center rounded-xl bg-brand-600 text-white">
                  <ol-icon name="layers" [size]="28" />
                </span>
                <span class="mt-2 block text-xs font-semibold leading-tight sm:text-sm">All products</span>
              </a>
            </li>

            @for (department of departments(); track department.slug) {
              <li class="w-24 shrink-0 sm:w-28">
                <a
                  routerLink="/shop/"
                  [queryParams]="categoryParams(department.url)"
                  [attr.aria-current]="activeDepartment()?.slug === department.slug ? 'true' : null"
                  [class]="tileClass(activeDepartment()?.slug === department.slug)"
                >
                  <span class="flex aspect-square w-full items-center justify-center overflow-hidden rounded-xl bg-[#f6f3ee] text-brand-600">
                    @if (department.card ?? department.hero; as image) {
                      <ol-image
                        class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                        [image]="image"
                        sizes="112px"
                        imgClass="h-full w-full object-cover"
                      />
                    } @else {
                      <ol-icon name="layers" [size]="26" />
                    }
                  </span>
                  <span class="mt-2 block text-xs font-semibold leading-tight sm:text-sm">{{ department.name }}</span>
                </a>
              </li>
            }
          </ul>

          <!-- The chosen department's subcategories. -->
          @if (activeDepartment(); as active) {
            @if (active.children.length) {
              <ul class="mt-4 flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                <li>
                  <a routerLink="/shop/" [queryParams]="categoryParams(active.url)" [class]="pillClass(activeCategory() === pathOf(active.url))">
                    All {{ active.name }}
                  </a>
                </li>
                @for (child of active.children; track child.slug) {
                  <li>
                    <a routerLink="/shop/" [queryParams]="categoryParams(child.url)" [class]="pillClass(activeCategory() === pathOf(child.url))">
                      {{ child.name }}
                    </a>
                  </li>
                }
              </ul>
            }
          }
        </nav>
      }

      <div class="mt-6 grid gap-6 sm:mt-8 lg:grid-cols-[18rem_1fr] lg:gap-8">
        <!--
          The sidebar: sort, category, availability and brand (which every
          product has), then the category's specification facets where it has
          any. Desktop: always open in the left column, and it stays in view as
          the grid scrolls. Phone: collapsed behind the "Filters" button so the
          products lead, opened in place - the same links, no second UI.
        -->
        <!-- Phone: the toggle comes first, so the panel opens beneath it. -->
        <button
          type="button"
          class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-ink-200 bg-white px-5 py-2.5 text-sm font-semibold text-ink-900 lg:hidden"
          aria-controls="shop-filters"
          [attr.aria-expanded]="filtersOpen()"
          (click)="filtersOpen.set(!filtersOpen())"
        >
          <ol-icon name="sliders-horizontal" [size]="16" />
          {{ filtersOpen() ? 'Hide filters' : 'Filters & sort' }}
          @if (appliedCount() > 0) {
            <span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white">{{ appliedCount() }}</span>
          }
        </button>

        <aside
          id="shop-filters"
          aria-label="Filters"
          [class]="(filtersOpen() ? 'block' : 'hidden') + ' lg:order-1 lg:block'"
        >
          <div class="space-y-4 lg:sticky lg:top-28 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto lg:pr-1">
            <ol-shop-sidebar [departments]="departments()" [filters]="sidebarFilters()" [state]="state()" />

            @if (listing().facets.length) {
              <ol-facet-rail
                [facets]="listing().facets"
                [selected]="selectedFilters()"
                basePath="/shop/"
                [preserved]="preservedParams()"
                layout="rail"
              />
            }

            <div class="rounded-2xl bg-ink-900 p-5 text-white">
              <h3 class="font-heading text-base font-semibold text-white">Buying in bulk?</h3>
              <p class="mt-1 text-sm text-white/80">Send the list. We price every line within one business day.</p>
              <a routerLink="/quote/" class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-accent-500 px-4 py-2 text-sm font-semibold text-ink-900 transition-colors hover:bg-accent-400">
                Request a quote
                <ol-icon name="arrow-right" [size]="14" />
              </a>
            </div>
          </div>
        </aside>

        <section aria-labelledby="products-heading" class="lg:order-2">

          <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink-100 pb-3">
            <h2 id="products-heading" class="font-heading text-lg font-semibold">
              {{ activeLabel() ?? 'Products' }}
              <span class="ml-1 font-normal text-ink-600">({{ listing().meta.total }})</span>
            </h2>
            <p class="text-sm text-ink-600">{{ rangeLabel() }}</p>
          </div>

          @if (listing().data.length) {
            <ul class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
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
              mode="query"
            />
          } @else {
            <p class="mt-6 text-ink-600">
              @if (hasFilters()) {
                No products match those filters.
                <a routerLink="/shop/" [queryParams]="preservedSort()" class="text-brand-600 hover:underline">Clear them</a>
                to see the whole catalogue.
              } @else {
                Nothing is listed yet.
                <a routerLink="/quote/" class="text-brand-600 hover:underline">Ask us for a quote</a>
                - we supply far more than we list.
              }
            </p>
          }
        </section>
      </div>
    </div>
  `,
  styles: `
    /* The scroll stays, the bar goes - same as every other rail on the site. */
    .ol-shop-rail {
      scrollbar-width: none;
    }

    .ol-shop-rail::-webkit-scrollbar {
      display: none;
    }
  `,
})
export class ShopPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  private readonly queryParams = toSignal(this.route.queryParamMap, {
    initialValue: null,
  });

  /** Active sort, for the sheet's tick; mirrors the API default of 'newest'. */
  protected readonly currentSort = computed(() => this.queryParams()?.get('sort') ?? 'newest');

  protected readonly selectedFilters = computed<Record<string, readonly string[]>>(() => {
    const params = this.queryParams();

    return params ? readFilters(params) : {};
  });

  protected readonly filterCount = computed(() =>
    Object.values(this.selectedFilters()).reduce((total, values) => total + values.length, 0),
  );

  /** Phone only: whether the sidebar is opened in place. */
  protected readonly filtersOpen = signal(false);

  protected readonly activeBrand = computed<string | null>(() => {
    const raw = (this.queryParams()?.get('brand') ?? '').trim();

    return /^[a-z0-9-]+$/.test(raw) ? raw : null;
  });

  protected readonly activeAvailability = computed<readonly string[]>(() =>
    (this.queryParams()?.get('availability') ?? '').split(',').filter((value) => /^[a-z_]+$/.test(value)),
  );

  /** Everything applied, for the phone button's badge and the empty-state wording. */
  protected readonly appliedCount = computed(
    () =>
      this.filterCount() +
      (this.activeCategory() ? 1 : 0) +
      (this.activeBrand() ? 1 : 0) +
      this.activeAvailability().length,
  );

  protected readonly hasFilters = computed(() => this.appliedCount() > 0);

  /** The URL state, parsed once, for the sidebar. */
  protected readonly state = computed<ShopState>(() => ({
    category: this.activeCategory(),
    brand: this.activeBrand(),
    availability: this.activeAvailability(),
    sort: this.currentSort(),
    filterParams: filterParams(this.selectedFilters()),
  }));

  /**
   * Brand and availability choices with counts, re-read whenever the scope
   * changes. Keeps the previous lists while the next ones load, so the sidebar
   * does not blink empty between two filters.
   */
  protected readonly sidebarFilters = toSignal(
    this.route.queryParamMap.pipe(
      map((params) => ({
        category: this.activeCategoryOf(params.get('category')),
        brand: /^[a-z0-9-]+$/.test(params.get('brand') ?? '') ? params.get('brand') : null,
        availability: (params.get('availability') ?? '').split(',').filter((value) => /^[a-z_]+$/.test(value)),
      })),
      distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
      switchMap((scope) => this.api.shopFilters(scope).pipe(catchError(() => of(EMPTY_FILTERS)))),
    ),
    { initialValue: EMPTY_FILTERS },
  );

  /** Departments with their card artwork, from the navigation payload. */
  private readonly navigation = toSignal(this.api.navigation().pipe(catchError(() => of(null))));

  protected readonly departments = computed(() => this.navigation()?.departments ?? []);

  /**
   * The chosen category as a bare path ("it-products/computers"), or null.
   * Only lowercase slug paths are accepted, so the value can never be anything
   * but a category path when it reaches the API URL.
   */
  protected readonly activeCategory = computed<string | null>(() =>
    this.activeCategoryOf(this.queryParams()?.get('category') ?? null),
  );

  protected readonly activeDepartment = computed(() => {
    const slug = this.activeCategory()?.split('/')[0];

    return slug ? (this.departments().find((d) => d.slug === slug) ?? null) : null;
  });

  /** "IT Products" or "IT Products · Computers" for the grid heading. */
  protected readonly activeLabel = computed<string | null>(() => {
    const department = this.activeDepartment();
    if (!department) return null;

    const child = department.children.find((c) => this.pathOf(c.url) === this.activeCategory());

    return child ? `${department.name} · ${child.name}` : department.name;
  });

  protected readonly listing = toSignal(
    this.route.queryParamMap.pipe(
      switchMap((params) => {
        const brand = params.get('brand') ?? '';
        const listingParams = {
          page: Number(params.get('page') ?? 1) || 1,
          sort: params.get('sort') ?? undefined,
          filters: readFilters(params),
          brand: /^[a-z0-9-]+$/.test(brand) ? brand : undefined,
          availability: (params.get('availability') ?? '').split(',').filter((value) => /^[a-z_]+$/.test(value)),
        };
        const category = this.activeCategoryOf(params.get('category'));

        return (
          category
            ? this.api.categoryProducts(`/${category}/`, listingParams)
            : this.api.catalogue(listingParams)
        ).pipe(catchError(() => of(EMPTY_LISTING)));
      }),
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

  /** The sort alone: what survives a change of category. */
  protected readonly preservedSort = computed<Record<string, string>>(() => {
    const sort = this.queryParams()?.get('sort');

    return sort ? { sort } : ({} as Record<string, string>);
  });

  /** Carried across specification-facet changes and pages: sort, category, brand, availability. */
  protected readonly preservedParams = computed<Record<string, string>>(() => {
    const params: Record<string, string> = { ...this.preservedSort() };
    const category = this.activeCategory();
    const brand = this.activeBrand();
    const availability = this.activeAvailability();

    if (category) params['category'] = category;
    if (brand) params['brand'] = brand;
    if (availability.length) params['availability'] = availability.join(',');

    return params;
  });

  protected readonly paginationParams = computed<Record<string, string>>(() => ({
    ...this.preservedParams(),
    ...filterParams(this.selectedFilters()),
  }));

  /** "/c/it-products/computers/" -> "it-products/computers". */
  protected pathOf(url: string): string {
    return url.replace(/^\/c\//, '').replace(/\/+$/, '');
  }

  /** Query params that select a category: the sort survives, filters and page do not. */
  protected categoryParams(url: string): Record<string, string> {
    return { ...this.preservedSort(), category: this.pathOf(url) };
  }

  protected tileClass(active: boolean): string {
    return (
      'group block rounded-2xl border bg-white p-2 text-center transition-colors ' +
      (active
        ? 'border-brand-600 text-brand-700 shadow-sm ring-2 ring-brand-600/25'
        : 'border-ink-100 text-ink-800 hover:border-brand-600 hover:text-brand-700')
    );
  }

  protected pillClass(active: boolean): string {
    return (
      'inline-block rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors ' +
      (active
        ? 'border-brand-600 bg-brand-600 text-white'
        : 'border-ink-100 bg-white text-ink-600 hover:border-brand-600 hover:text-brand-700')
    );
  }

  private activeCategoryOf(raw: string | null): string | null {
    const path = (raw ?? '').replace(/^\/+|\/+$/g, '');

    return /^[a-z0-9-]+(\/[a-z0-9-]+){0,2}$/.test(path) ? path : null;
  }

  constructor() {
    effect(() => {
      this.seo.apply({
        title: ['Shop the Catalogue - Filter by Brand and Specification', 'Shop the catalogue', 'Shop'],
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
