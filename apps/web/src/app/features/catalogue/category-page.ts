import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, combineLatest, map, of, switchMap } from 'rxjs';
import {
  CatalogueApi,
  type Category,
  type Image,
  type ProductCard as ProductCardData,
} from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';
import { EMPTY_LISTING, filterParams, readFilters, splitPagedPath } from '../../shared/listing-state';
import { FacetRail } from '../../shared/facet-rail';
import { CategoryChips } from '../../shared/category-chips';
import { ResponsiveImage } from '../../shared/responsive-image';
import { Pagination } from '../../shared/pagination';
import { ProductCard } from '../../shared/product-card';

/**
 * Category page - breadcrumbs, child anchors, facet rail, product grid.
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
  imports: [RouterLink, ProductCard, FacetRail, Pagination, ResponsiveImage, CategoryChips],
  template: `
    @if (category(); as cat) {
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
        <!--
          The category header.

          Every category now carries a photograph - its own, or the nearest
          ancestor's (heroFor) - so the header is a cinematic banner with the
          name over the image rather than a heading beside a thumbnail. The
          scrim carries white type to AA over any of the nine, and the image is
          the LCP element, so it is eager and high-priority. A category with no
          photograph at all (none today) falls back to the plain heading, which
          is simply the banner without its backdrop.

          The breadcrumb lives inside the banner and mirrors the BreadcrumbList
          markup emitted below (section 23.5).
        -->
        @if (heroFor(cat); as hero) {
          <section aria-labelledby="category-title" class="relative isolate overflow-hidden rounded-3xl shadow-lg">
            <div class="absolute inset-0">
              <ol-image
                [image]="hero"
                [priority]="true"
                sizes="(min-width: 1600px) 1536px, 100vw"
                imgClass="h-full w-full object-cover"
              />
            </div>
            <span aria-hidden="true" class="ol-photo-scrim absolute inset-0"></span>

            <div class="relative flex min-h-[15rem] flex-col justify-end gap-3 p-7 sm:min-h-[20rem] sm:p-12">
              <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-sm text-white/75">
                  <li><a routerLink="/" class="hover:text-white">Home</a></li>
                  @for (crumb of cat.breadcrumb ?? []; track crumb.url) {
                    <li aria-hidden="true">/</li>
                    <li><a [routerLink]="crumb.url" class="hover:text-white">{{ crumb.name }}</a></li>
                  }
                  <li aria-hidden="true">/</li>
                  <li aria-current="page" class="text-white">{{ cat.name }}</li>
                </ol>
              </nav>
              <h1 id="category-title" class="max-w-3xl text-4xl font-extrabold leading-tight text-white drop-shadow-sm sm:text-5xl">
                {{ cat.name }}
              </h1>
            </div>
          </section>
        } @else {
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
          <h1 id="category-title" class="mt-4 text-3xl font-bold sm:text-4xl">{{ cat.name }}</h1>
        }

        <!--
          Intro copy is HTML from the CMS, so it is bound rather than
          interpolated. Interpolated it rendered the tags as visible text -
          "<p>Sliding doors run within..." - on every one of the 24 category
          pages that carry copy. It looked fine to the robots check, to the
          raw-HTML gate and to axe; only looking at the page caught it.

          [innerHTML] is safe here in a way it was not for the icons: Domino
          only lacks the setter for SVG elements, and Angular sanitises the
          value, which is what it is for. The copy is authored in the admin
          by staff, not submitted by visitors.
        -->
        @if (cat.intro_copy) {
          <div
            class="ol-prose ol-intro-columns mt-4 text-ink-600"
            [innerHTML]="cat.intro_copy"
          ></div>
        }

        <!--
          The chip row: children where there are children, siblings where
          there are not.

          It sits above the text category block rather than replacing it. The
          block is what carries two levels of anchors and therefore the
          3-click crawl guarantee; these chips carry one level and exist for
          a person scanning the page. Dropping the block to avoid repeating
          the names would trade a crawl guarantee for tidiness.
        -->
        @if (cat.children?.length) {
          <ol-category-chips
            [categories]="cat.children ?? []"
            label="Subcategories"
            class="mt-6 block"
          />
        } @else if (cat.siblings?.length) {
          <ol-category-chips
            [categories]="cat.siblings ?? []"
            label="Related categories"
            class="mt-6 block"
          />
        }

        <!-- D-011. Real anchors, always in the HTML. -->
        @if (cat.children?.length) {
          <!--
            Child anchors, two levels deep (D-011).

            Two, not one, and the reason is arithmetic. A department that
            lists only its subcategories puts each leaf 2 clicks from home and
            each product 4 - because a product is only shallower than its own
            leaf if it happens to fit on page 1 of an ancestor's listing.
            That held while the catalogue was ten products. At real volume,
            224 products sat 4 clicks deep and the crawl-depth gate failed.

            Rendering the grandchildren puts every leaf 2 clicks from home and
            every product on a leaf's first page at 3, which is the guarantee
            SEO_SPEC 10.4 actually asks for.

            It is also the better page: a department that shows what is inside
            it is more useful than one that makes you click a subcategory to
            find out.
          -->
          <!--
            Labelled by its own visible heading rather than an aria-label.

            Both this and the chip row above are nav landmarks about
            categories, and both were named "Subcategories" - two landmarks
            with one accessible name, which axe rates a violation on 120
            pages because landmark navigation then offers the same
            destination twice with no way to tell them apart.

            Pointing at the h2 also ties the landmark to text a sighted user
            can see, which is the better habit: the name cannot drift away
            from the heading because it IS the heading.
          -->
          <nav aria-labelledby="category-index-heading" class="mt-8">
            <h2 id="category-index-heading" class="font-heading text-sm font-semibold uppercase tracking-wide">Browse by category</h2>
            <ul class="mt-3 grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
              @for (child of cat.children; track child.slug) {
                <li class="rounded-xl border border-ink-100 px-4 py-3">
                  <a [routerLink]="child.url" class="block font-medium hover:text-brand-600">
                    {{ child.name }}
                    @if (child.product_count) {
                      <span class="ml-1 text-sm font-normal text-ink-400">({{ child.product_count }})</span>
                    }
                  </a>

                  @if (child.children?.length) {
                    <ul class="mt-2 space-y-1">
                      @for (leaf of child.children; track leaf.slug) {
                        <li>
                          <a
                            [routerLink]="leaf.url"
                            class="text-sm text-ink-600 hover:text-brand-600"
                          >{{ leaf.name }}</a>
                        </li>
                      }
                    </ul>
                  }
                </li>
              }
            </ul>
          </nav>
        }

        <!-- Facets sit ABOVE the grid on every listing except /shop/. The
             scope is already narrowed here, so filtering is a refinement and
             the products keep the full width. -->
        <div class="mt-10">
          <ol-facet-rail
            [facets]="listing().facets"
            [selected]="selectedFilters()"
            [basePath]="cat.url"
            [preserved]="preservedParams()"
          />

          <section aria-labelledby="products-heading" class="mt-8">
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
                  - we supply far more than we list.
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

  /**
   * The image this page shows: its own, or its nearest ancestor's.
   *
   * 161 of 170 categories have no photograph. A hero band with nothing in
   * it looks broken; a subcategory carrying its department's photograph
   * looks like what it is. Category imagery is editorial rather than a
   * depiction of one product, so the inherited one is accurate.
   *
   * The chips deliberately do NOT do this. They read `hero` alone, so five
   * sibling leaves show five distinct icons rather than the same photograph
   * five times.
   */
  protected heroFor(category: Category): Image | null {
    return category.hero ?? category.hero_inherited ?? null;
  }
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
       * An editor-written title wins, and it did not until now.
       *
       * `meta_title` has been a column, an admin field and part of the API
       * payload since Phase 5, and this page composed a template and never
       * looked at it - so the one lever an editor had over a title tag did
       * nothing. Nobody noticed because the template is decent and every
       * title looked fine.
       *
       * It is the FIRST candidate, not the only one: the SEO service still
       * drops to the next entry if a hand-written title overflows the
       * 60-character budget, which is better than truncating somebody's
       * careful phrasing mid-word.
       *
       * Paginated pages ignore it. A title written for /c/x/ describes that
       * category, not page 4 of it, and repeating it across pages is exactly
       * the duplicate-title warning it would otherwise trigger.
       */
      exactTitle: paged ? null : cat.seo?.meta_title,
      title: paged
        ? [
            `${cat.name} in Nigeria - Page ${page}`,
            `${cat.name} - Page ${page}`,
          ]
        : [
            `${cat.name} in Nigeria - Prices & Bulk Supply`,
            `${cat.name} - Prices & Bulk Supply`,
            `${cat.name} in Nigeria`,
            cat.name,
          ],
      description: cat.seo?.meta_description ?? summarise(cat.intro_copy),
      // og:image from the hero when there is one. The `og` derivative is a
      // 1200x630 crop made for exactly this; `src` is the page variant and
      // would be cropped badly by every social card renderer.
      image: (cat.hero ?? cat.hero_inherited)?.src ?? undefined,
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

/**
 * A meta description fallback derived from intro copy.
 *
 * The copy is HTML, and it was being handed to the description tag whole -
 * tags, entities and all - whenever a category had no explicit
 * meta_description. Strip the markup and cut at a word boundary near the
 * length search engines actually display.
 *
 * A fallback, not a substitute: an editor-written meta_description still
 * wins, and should, because the first 155 characters of a good intro are
 * rarely the 155 characters that earn a click.
 */
function summarise(html: string | null | undefined): string | undefined {
  if (!html) {
    return undefined;
  }

  const text = html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#(\d+);/g, (_, code: string) => String.fromCharCode(Number(code)))
    .replace(/\s+/g, ' ')
    .trim();

  if (text.length <= 155) {
    return text || undefined;
  }

  const cut = text.slice(0, 155);

  return cut.slice(0, cut.lastIndexOf(' ')) + '…';
}
