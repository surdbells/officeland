import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi, type Brand } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';

/**
 * The brands index at /brands/.
 *
 * ── Why brands are not categories ────────────────────────────────────────
 * The legacy tree modelled them as categories: 35 of its 161 terms were brand
 * branches, under `/product-category/hp/` (defect 4E). That conflates two
 * different axes — what a thing IS and who made it — so a chair and a toner
 * cartridge ended up as siblings because HP made both, and the taxonomy could
 * never answer "office chairs" cleanly.
 *
 * A brand is a facet of the catalogue with its own hub. This page is the entry
 * to those hubs and the destination of the legacy brand-category redirects.
 */
@Component({
  selector: 'ol-brand-index-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-ink-600">
          <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
          <li aria-hidden="true">/</li>
          <li aria-current="page" class="text-ink-900">Brands</li>
        </ol>
      </nav>

      <h1 class="mt-4 font-heading text-3xl font-bold">Brands we supply</h1>
      <p class="mt-3 max-w-3xl text-ink-600">
        We supply and support the manufacturers below across Nigeria. If you need a brand that is
        not listed, ask &mdash; we source far more than we list.
      </p>

      @if (featured().length) {
        <section aria-labelledby="featured-brands" class="mt-10">
          <h2 id="featured-brands" class="font-heading text-lg font-semibold">Featured</h2>
          <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @for (brand of featured(); track brand.slug) {
              <li>
                <a
                  [routerLink]="brand.url"
                  class="block rounded-lg border border-ink-100 px-4 py-3 hover:border-brand-600"
                >
                  <span class="font-medium">{{ brand.name }}</span>
                  @if (brand.product_count) {
                    <span class="ml-1 text-sm text-ink-400">({{ brand.product_count }})</span>
                  }
                </a>
              </li>
            }
          </ul>
        </section>
      }

      <section aria-labelledby="all-brands" class="mt-10">
        <h2 id="all-brands" class="font-heading text-lg font-semibold">All brands</h2>

        @if (grouped().length) {
          @for (group of grouped(); track group.letter) {
            <div class="mt-6">
              <h3 class="font-heading text-sm font-semibold uppercase tracking-wide text-ink-600">
                {{ group.letter }}
              </h3>
              <ul class="mt-2 grid gap-2 sm:grid-cols-3 lg:grid-cols-5">
                @for (brand of group.brands; track brand.slug) {
                  <li>
                    <a [routerLink]="brand.url" class="text-sm hover:text-brand-600">{{ brand.name }}</a>
                  </li>
                }
              </ul>
            </div>
          }
        } @else {
          <p class="mt-3 text-ink-600">Brand hubs are being prepared.</p>
        }
      </section>
    </div>
  `,
})
export class BrandIndexPage {
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  private readonly brands = toSignal(this.api.brands().pipe(catchError(() => of([]))), {
    initialValue: [] as readonly Brand[],
  });

  protected readonly featured = computed(() => this.brands().filter((brand) => brand.is_featured));

  /**
   * Grouped alphabetically. At a few hundred brands a flat list is a wall of
   * text; the letter headings are what make it scannable, and they cost
   * nothing to a crawler because every brand is still a plain anchor.
   */
  protected readonly grouped = computed<ReadonlyArray<{ letter: string; brands: Brand[] }>>(() => {
    const groups = new Map<string, Brand[]>();

    for (const brand of this.brands()) {
      // Digits and symbols collect under '#' so "3M" does not create a
      // single-entry heading between nothing and A.
      const first = brand.name.charAt(0).toUpperCase();
      const letter = /[A-Z]/.test(first) ? first : '#';

      const bucket = groups.get(letter) ?? [];
      bucket.push(brand);
      groups.set(letter, bucket);
    }

    return [...groups.entries()]
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([letter, brands]) => ({ letter, brands }));
  });

  constructor() {
    this.seo.apply({
      title: 'Brands we supply',
      description:
        'Every manufacturer OfficeLand supplies and supports in Nigeria, from office furniture and IT to printers and consumables.',
      path: '/brands/',
      indexable: true,
    });

    effect(() => {
      this.seo.setStructuredData([
        breadcrumbSchema([
          { name: 'Home', url: '/' },
          { name: 'Brands', url: '/brands/' },
        ]),
        itemListSchema(this.brands().map((brand) => ({ name: brand.name, url: brand.url }))),
      ]);
    });
  }
}
