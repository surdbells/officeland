import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of, switchMap } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { EMPTY_LISTING, filterParams, readFilters } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';

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
 * than a path — there is no file to name, and D-032's reason for path
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
  imports: [RouterLink, ProductCard, FacetRail, Pagination],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <h1 class="font-heading text-2xl font-bold">
        @if (term()) {
          Search results for &ldquo;{{ term() }}&rdquo;
        } @else {
          Search
        }
      </h1>

      <form action="/search" method="get" role="search" aria-label="Refine this search" class="mt-4 max-w-xl">
        <label for="results-search" class="sr-only">Search products</label>
        <input
          id="results-search"
          name="q"
          type="search"
          [value]="term()"
          placeholder="Search by product, brand or model code"
          class="w-full rounded-lg border border-ink-200 px-4 py-3 focus:border-brand-600"
        />
      </form>

      @if (term()) {
        <p class="mt-3 text-sm text-ink-600">
          {{ listing().meta.total }} result{{ listing().meta.total === 1 ? '' : 's' }}
        </p>

        @if (listing().meta.used_fallback && listing().data.length) {
          <!-- Trigram fallback ran. Saying so is honest, and it stops the
               user assuming an exact match they did not get. -->
          <p class="mt-1 text-sm text-ink-600">
            Showing close matches &mdash; nothing matched &ldquo;{{ term() }}&rdquo; exactly.
          </p>
        }
      }

      @if (listing().data.length) {
        <!-- Facets above the results; see category-page. -->
        <div class="mt-8">
          <ol-facet-rail
            [facets]="listing().facets"
            [selected]="selectedFilters()"
            [basePath]="'/search/'"
            [preserved]="searchParams()"
          />

          <section aria-labelledby="results-heading" class="mt-8">
            <h2 id="results-heading" class="sr-only">Results</h2>

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
        </div>
      } @else if (term()) {
        <section aria-labelledby="no-results" class="mt-8 max-w-3xl">
          <h2 id="no-results" class="font-heading text-lg font-semibold">
            Nothing matched &ldquo;{{ term() }}&rdquo;
          </h2>

          <ul class="mt-3 list-disc space-y-1 pl-5 text-ink-600">
            <li>Check the model code &mdash; a single character is enough to miss.</li>
            <li>Try the manufacturer name on its own, then narrow with the filters.</li>
            <li>Search for what the product does rather than what it is called.</li>
          </ul>

          <p class="mt-4 text-ink-600">
            We stock far more than we list.
            <a routerLink="/quote/" class="text-brand-600 hover:underline">Send us the list</a>
            and we will price it.
          </p>

          <nav aria-label="Browse departments" class="mt-6">
            <h3 class="font-heading text-sm font-semibold uppercase tracking-wide">Or start here</h3>
            <ul class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              <li><a routerLink="/c/office-furniture/" class="text-brand-600 hover:underline">Office furniture</a></li>
              <li><a routerLink="/c/it-products/" class="text-brand-600 hover:underline">IT products</a></li>
              <li><a routerLink="/c/printers-consumables/" class="text-brand-600 hover:underline">Printers &amp; consumables</a></li>
              <li><a routerLink="/c/office-supplies/" class="text-brand-600 hover:underline">Office supplies</a></li>
              <li><a routerLink="/c/paper-notebooks/" class="text-brand-600 hover:underline">Paper &amp; notebooks</a></li>
              <li><a routerLink="/c/filing-storage/" class="text-brand-600 hover:underline">Filing &amp; storage</a></li>
            </ul>
          </nav>
        </section>
      }
    </div>
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
        if (term === '') return of(EMPTY_LISTING);

        return this.api
          .search(term, {
            page: Number(params.get('page') ?? 1) || 1,
            filters: readFilters(params),
          })
          .pipe(catchError(() => of(EMPTY_LISTING)));
      }),
    ),
    { initialValue: EMPTY_LISTING },
  );

  protected readonly listing = computed(() => this.listingResult());

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
