import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of, switchMap } from 'rxjs';
import { CatalogueApi, type Category } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema } from '../../core/seo/structured-data';

/**
 * Category page.
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
  imports: [RouterLink],
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
              @for (child of cat.children ?? []; track child.slug) {
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

        <!-- The product grid, facet rail and pagination land in Phase 8. -->
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

  private readonly result = toSignal(
    this.route.url.pipe(
      /*
       * ActivatedRoute.url for a 'c/**' route yields every segment the route
       * consumed, INCLUDING the literal 'c'. The API keys categories on the
       * materialized path, which has no /c/ prefix (D-008), so passing this
       * through unmodified requests /api/v1/categories/c/office-furniture and
       * 404s every category page on the site.
       */
      map((segments) => segments.map((s) => s.path)),
      map((parts) => (parts[0] === 'c' ? parts.slice(1) : parts).join('/')),
      switchMap((path) =>
        this.api.category(path).pipe(
          map((category) => ({ category, failed: false })),
          catchError(() => of({ category: null, failed: true })),
        ),
      ),
    ),
    { initialValue: { category: null as Category | null, failed: false } },
  );

  protected readonly category = computed(() => this.result().category);
  protected readonly failed = computed(() => this.result().failed);

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

    const crumbs = [
      { name: 'Home', url: '/' },
      ...(cat.breadcrumb ?? []).map((c) => ({ name: c.name, url: c.url })),
      { name: cat.name, url: cat.url },
    ];

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
      title: [
        `${cat.name} in Nigeria — Prices & Bulk Supply`,
        `${cat.name} — Prices & Bulk Supply`,
        `${cat.name} in Nigeria`,
        cat.name,
      ],
      description: cat.seo?.meta_description ?? cat.intro_copy ?? undefined,
      path: cat.url,
      indexable: cat.seo?.is_indexable !== false,
      canonicalOverride: cat.seo?.canonical_override ?? null,
    });

    this.seo.setStructuredData([breadcrumbSchema(crumbs)]);
  }
}
