import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, startWith, switchMap } from 'rxjs';
import { CatalogueApi, type Listing } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { EMPTY_LISTING, filterParams, readFilters } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';
import { ProductCardSkeleton } from '../../shared/product-card-skeleton';
import { Icon } from '../../shared/icon';
import { DepartmentTiles } from '../../shared/department-tiles';

/**
 * Search results.
 *
 * ── Not prerendered, and noindex ─────────────────────────────────────────
 * The one catalogue surface consumed at request time rather than build time
 * (SEO_SPEC.md §2). Search result pages are `noindex, follow`: the query space
 * is unbounded, every page is a recombination of content that already has its
 * own URL, and an indexed search page competes with the category page it
 * duplicates. `follow` is kept so the product links still pass value.
 *
 * Because it is not prerendered, pagination here is a query parameter rather
 * than a path - there is no file to name, and D-032's reason for path
 * pagination does not apply.
 *
 * ── The zero-result page is a real page ──────────────────────────────────
 * §14.10 treats zero-result terms as a signal, and the API logs them. For the
 * user this must never be a dead end, so it offers the departments and the
 * quote route rather than an apology.
 */
@Component({
  selector: 'ol-search-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductCard, FacetRail, Pagination, ProductCardSkeleton, Icon, DepartmentTiles],
  template: `
    <!-- The search band: the term, the box to change it, and what came back. -->
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      <span aria-hidden="true" class="ol-search-glow absolute inset-0 -z-10"></span>
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pb-8 pt-6 sm:pb-12 sm:pt-8">
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-white/70">
            <li><a routerLink="/" class="hover:text-white">Home</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-white">Search</li>
          </ol>
        </nav>

        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Search the catalogue</p>
        <h1 class="mt-2 font-heading text-2xl font-bold tracking-tight text-white sm:text-4xl">
          @if (term()) {
            Results for &ldquo;{{ term() }}&rdquo;
          } @else {
            What are you looking for?
          }
        </h1>

        <form action="/search" method="get" role="search" aria-label="Refine this search" class="mt-6 max-w-3xl">
          <label for="results-search" class="sr-only">Search products</label>
          <div class="flex items-stretch overflow-hidden rounded-full bg-white shadow-lg ring-1 ring-white/10">
            <span class="flex items-center pl-5 text-ink-400"><ol-icon name="search" [size]="20" /></span>
            <input
              id="results-search"
              name="q"
              type="search"
              [value]="term()"
              placeholder="Search by product, brand or model code"
              class="min-w-0 flex-1 border-0 bg-transparent px-4 py-4 text-base text-ink-900 placeholder:text-ink-400 focus:outline-none"
            />
            <button type="submit" class="m-1.5 cursor-pointer rounded-full bg-brand-600 px-6 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
              Search
            </button>
          </div>
        </form>

        @if (term() && !searching()) {
          <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/75">
            <span><span class="font-semibold text-white">{{ listing().meta.total.toLocaleString('en') }}</span> result{{ listing().meta.total === 1 ? '' : 's' }}</span>
            @if (listing().meta.used_fallback && listing().data.length) {
              <!-- Trigram fallback ran. Saying so stops the buyer assuming an exact match. -->
              <span class="rounded-full bg-amber-400/15 px-3 py-0.5 text-amber-200">Close matches - nothing matched exactly</span>
            }
          </p>
        } @else if (!term()) {
          <div class="mt-5 flex flex-wrap items-center gap-2">
            <span class="text-sm text-white/60">Popular:</span>
            @for (suggestion of popular; track suggestion) {
              <a
                routerLink="/search/"
                [queryParams]="{ q: suggestion }"
                class="rounded-full bg-white/10 px-3.5 py-1.5 text-sm text-white transition-colors hover:bg-accent-400 hover:text-ink-900"
              >{{ suggestion }}</a>
            }
          </div>
        }
      </div>
    </section>

    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8 sm:py-10">
      @if (searching()) {
        <!-- In flight: card skeletons so results do not pop in over a blank gap. -->
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" aria-busy="true" aria-label="Searching">
          @for (card of skeletonCards; track card) {
            <li class="h-full"><ol-product-card-skeleton /></li>
          }
        </ul>
      } @else if (listing().data.length) {
        <!-- Facets above the results; see category-page. -->
        <ol-facet-rail
          [facets]="listing().facets"
          [selected]="selectedFilters()"
          [basePath]="'/search/'"
          [preserved]="searchParams()"
        />

        <section aria-labelledby="results-heading" class="mt-6">
          <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink-100 pb-3">
            <h2 id="results-heading" class="font-heading text-lg font-semibold">
              Products <span class="ml-1 font-normal text-ink-600">({{ listing().meta.total.toLocaleString('en') }})</span>
            </h2>
            <p class="text-sm text-ink-600">{{ rangeLabel() }}</p>
          </div>

          <ul class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @for (product of listing().data; track product.uuid) {
              <li class="h-full"><ol-product-card [product]="product" [headingLevel]="3" /></li>
            }
          </ul>

          <ol-pagination
            [page]="listing().meta.page"
            [lastPage]="listing().meta.last_page"
            [basePath]="'/search/'"
            [preserved]="paginationParams()"
          />
        </section>

        <!-- Not the right thing? The quote route, for the buyer whose item is not listed. -->
        <aside class="relative isolate mt-12 flex flex-wrap items-center justify-between gap-5 overflow-hidden rounded-3xl bg-ink-900 p-7 text-white sm:p-9">
          <span aria-hidden="true" class="ol-search-glow absolute inset-0 -z-10"></span>
          <div class="max-w-xl">
            <h2 class="font-heading text-xl font-semibold text-white">Not quite what you need?</h2>
            <p class="mt-1 text-sm text-white/80">We supply far more than we list. Describe it - or send the whole list - and we will price it.</p>
          </div>
          <a routerLink="/quote/" class="inline-flex items-center gap-2 rounded-full bg-accent-500 px-6 py-3 font-semibold text-ink-900 transition-colors hover:bg-accent-400">
            Request a quote <ol-icon name="arrow-right" [size]="16" />
          </a>
        </aside>
      } @else if (term()) {
        <!-- Zero results: never a dead end (§14.10). -->
        <section aria-labelledby="no-results" class="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
          <div class="rounded-3xl border border-ink-100 bg-white p-7 shadow-xs sm:p-9">
            <span class="inline-flex rounded-2xl bg-brand-50 p-3 text-brand-600"><ol-icon name="search" [size]="26" /></span>
            <h2 id="no-results" class="mt-4 font-heading text-2xl font-semibold text-ink-900">Nothing matched &ldquo;{{ term() }}&rdquo;</h2>
            <ul class="mt-5 space-y-3">
              @for (tip of tips; track tip) {
                <li class="flex gap-3 text-ink-600">
                  <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-600"></span>
                  {{ tip }}
                </li>
              }
            </ul>
          </div>
          <div class="flex flex-col justify-between rounded-3xl bg-brand-600 p-7 text-white shadow-brand sm:p-9">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">We stock far more than we list</p>
              <h2 class="mt-2 font-heading text-2xl font-semibold text-white">Tell us what you need</h2>
              <p class="mt-2 text-white/85">Describe the item or send the whole requisition. We price it within one business day.</p>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
              <a routerLink="/quote/" class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-semibold text-ink-900 transition-colors hover:bg-accent-400">
                Request a quote <ol-icon name="arrow-right" [size]="16" />
              </a>
              <a href="https://wa.me/2347074723165" rel="noopener" class="inline-flex items-center gap-2 rounded-full border border-white/60 px-6 py-3 font-semibold text-white transition-colors hover:bg-white/10">
                <ol-icon name="message-circle" [size]="16" /> WhatsApp
              </a>
            </div>
          </div>
        </section>

        <h2 class="mt-12 font-heading text-xl font-semibold text-ink-900">Or start from a department</h2>
        <div class="mt-4"><ol-department-tiles /></div>
      } @else {
        <h2 class="font-heading text-xl font-semibold text-ink-900">Browse by department</h2>
        <div class="mt-4"><ol-department-tiles /></div>
      }
    </div>
  `,
  styles: `
    .ol-search-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }
  `,
})
export class SearchPage {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  private readonly queryParams = toSignal(this.route.queryParamMap, { initialValue: null });

  protected readonly term = computed(() => this.queryParams()?.get('q')?.trim() ?? '');

  protected readonly selectedFilters = computed<Record<string, readonly string[]>>(() => {
    const params = this.queryParams();

    return params ? readFilters(params) : {};
  });

  private readonly listingResult = toSignal(
    this.route.queryParamMap.pipe(
      switchMap((params) => {
        const term = params.get('q')?.trim() ?? '';

        // An empty search is not a search. The API does not log it either
        // (§14.10 counts zero-result TERMS, and '' is not a term).
        if (term === '') return of({ listing: EMPTY_LISTING, loading: false });

        return this.api
          .search(term, {
            page: Number(params.get('page') ?? 1) || 1,
            filters: readFilters(params),
          })
          .pipe(
            map((listing: Listing) => ({ listing, loading: false })),
            catchError(() => of({ listing: EMPTY_LISTING, loading: false })),
            // Skeleton the grid while the request is in flight, re-entering on
            // each new term or page rather than leaving the previous results up.
            startWith({ listing: EMPTY_LISTING, loading: true }),
          );
      }),
    ),
    { initialValue: { listing: EMPTY_LISTING, loading: false } },
  );

  protected readonly listing = computed(() => this.listingResult().listing);
  protected readonly searching = computed(() => this.listingResult().loading);
  protected readonly skeletonCards = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9] as const;

  /** Suggestions for an empty search box: real, high-volume catalogue terms. */
  protected readonly popular = ['Office chair', 'Toner', 'Laptop', 'UPS', 'A4 paper', 'Filing cabinet'] as const;

  protected readonly tips = [
    'Check the model code - a single character is enough to miss.',
    'Try the manufacturer name on its own, then narrow with the filters.',
    'Search for what the product does rather than what it is called.',
  ] as const;

  protected readonly rangeLabel = computed(() => {
    const meta = this.listing().meta;
    if (meta.total === 0) return '';

    const first = (meta.page - 1) * meta.per_page + 1;
    const last = Math.min(meta.page * meta.per_page, meta.total);

    return `Showing ${first}–${last} of ${meta.total.toLocaleString('en')}`;
  });

  /** The term, carried across facet changes so refining does not clear it. */
  protected readonly searchParams = computed<Record<string, string>>(() => {
    const term = this.term();
    const params: Record<string, string> = {};

    if (term) params['q'] = term;

    return params;
  });

  protected readonly paginationParams = computed<Record<string, string>>(() => ({
    ...this.searchParams(),
    ...filterParams(this.selectedFilters()),
  }));

  constructor() {
    effect(() => {
      const term = this.term();

      this.seo.apply({
        title: term ? `Search: ${term}` : 'Search',
        path: '/search/',
        // Never indexable, whatever the term (SEO_SPEC.md §2).
        indexable: false,
      });

      // No page-level structured data: an ItemList here would describe a
      // page that is explicitly not in the index.
      this.seo.setStructuredData([]);
    });
  }
}
