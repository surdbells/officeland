import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi, type Brand } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';
import { ResponsiveImage } from '../../shared/responsive-image';
import { Icon } from '../../shared/icon';
import { Reveal } from '../../shared/reveal';

/**
 * The brands index at /brands/.
 *
 * ── Why brands are not categories ────────────────────────────────────────
 * The legacy tree modelled them as categories: 35 of its 161 terms were brand
 * branches, under `/product-category/hp/` (defect 4E). That conflates two
 * different axes - what a thing IS and who made it - so a chair and a toner
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
  imports: [RouterLink, ResponsiveImage, Icon, Reveal],
  template: `
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      <span aria-hidden="true" class="ol-brands-glow absolute inset-0 -z-10"></span>
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pb-10 pt-6 sm:pb-14 sm:pt-8">
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-white/70">
            <li><a routerLink="/" class="hover:text-white">Home</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-white">Brands</li>
          </ol>
        </nav>
        <p class="mt-8 text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Authorised supply</p>
        <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-white sm:text-display">Brands we supply</h1>
        <p class="mt-3 max-w-2xl text-white/80 sm:text-lg">
          The manufacturers we supply and support across Nigeria. Need a brand that is not listed?
          <a routerLink="/quote/" class="font-semibold text-white underline decoration-accent-400 underline-offset-4 hover:text-accent-400">Ask</a>
          - we source far more than we list.
        </p>

        @if (brands().length) {
          <!-- Jump to a letter. -->
          <nav aria-label="Brands by letter" class="mt-8">
            <ul class="flex flex-wrap gap-1.5">
              @for (group of grouped(); track group.letter) {
                <li>
                  <a
                    [href]="'#brands-' + group.letter"
                    class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-sm font-semibold text-white transition-colors hover:bg-accent-400 hover:text-ink-900"
                  >{{ group.letter }}</a>
                </li>
              }
            </ul>
          </nav>
        }
      </div>
    </section>

    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10 sm:py-14">
      <!-- The roster, logo-first: the brands with the most products lead. -->
      @if (leading().length) {
        <section aria-labelledby="featured-brands">
          <p class="ol-eyebrow">Most stocked</p>
          <h2 id="featured-brands" class="mt-1 font-heading text-2xl font-bold sm:text-3xl">Leading brands</h2>
          <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-6">
            @for (brand of leading(); track brand.slug; let i = $index) {
              <li olReveal [olRevealDelay]="(i % 6) * 50">
                <a
                  [routerLink]="brand.url"
                  class="ol-lift group flex h-full flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white hover:border-brand-600"
                >
                  <span class="flex aspect-[3/2] items-center justify-center bg-white p-6">
                    @if (brand.logo; as logo) {
                      <ol-image
                        class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                        [image]="logo"
                        sizes="12rem"
                        imgClass="h-full w-full object-contain transition-transform duration-300 group-hover:scale-105"
                      />
                    } @else {
                      <span class="font-heading text-2xl font-bold tracking-tight text-ink-800">{{ brand.name }}</span>
                    }
                  </span>
                  <span class="flex items-center justify-between gap-2 border-t border-ink-100 px-4 py-3">
                    <span class="min-w-0">
                      <span class="block truncate text-sm font-semibold text-ink-900 group-hover:text-brand-700">{{ brand.name }}</span>
                      @if (brand.product_count) {
                        <span class="block text-xs text-ink-400">{{ brand.product_count.toLocaleString('en') }} products</span>
                      }
                    </span>
                    <ol-icon name="arrow-right" [size]="14" class="shrink-0 text-ink-400 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-600" />
                  </span>
                </a>
              </li>
            }
          </ul>
        </section>
      }

      <!-- A to Z: every brand, every one a plain anchor for the crawler. -->
      <section aria-labelledby="all-brands" class="mt-16">
        <p class="ol-eyebrow">A to Z</p>
        <h2 id="all-brands" class="mt-1 font-heading text-2xl font-bold sm:text-3xl">All brands</h2>

        @if (grouped().length) {
          <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @for (group of grouped(); track group.letter) {
              <div [id]="'brands-' + group.letter" class="scroll-mt-28 rounded-2xl border border-ink-100 bg-white p-5 shadow-xs">
                <h3 class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 font-heading text-lg font-bold text-white">{{ group.letter }}</h3>
                <ul class="mt-4 space-y-1">
                  @for (brand of group.brands; track brand.slug) {
                    <li>
                      <a [routerLink]="brand.url" class="group flex items-center gap-3 rounded-lg px-2 py-1.5 transition-colors hover:bg-ink-50">
                        <span class="flex h-8 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-ink-100 bg-white">
                          @if (brand.logo; as logo) {
                            <ol-image
                              class="block h-full w-full p-1 [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                              [image]="logo"
                              sizes="48px"
                              imgClass="h-full w-full object-contain"
                            />
                          } @else {
                            <span class="text-xs font-bold text-ink-400">{{ brand.name.charAt(0) }}</span>
                          }
                        </span>
                        <span class="flex-1 text-sm font-medium text-ink-800 group-hover:text-brand-700">{{ brand.name }}</span>
                        @if (brand.product_count) {
                          <span class="text-xs tabular-nums text-ink-400">{{ brand.product_count.toLocaleString('en') }}</span>
                        }
                      </a>
                    </li>
                  }
                </ul>
              </div>
            }
          </div>
        } @else {
          <p class="mt-3 text-ink-600">Brand hubs are being prepared.</p>
        }
      </section>
    </div>
  `,
  styles: `
    .ol-brands-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }
  `,
})
export class BrandIndexPage {
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  protected readonly brands = toSignal(this.api.brands().pipe(catchError(() => of([]))), {
    initialValue: [] as readonly Brand[],
  });

  /**
   * The logo grid: featured brands first, then by product count - the brands a
   * buyer is most likely looking for. Capped, because the A-Z below lists all.
   */
  protected readonly leading = computed(() =>
    [...this.brands()]
      .filter((brand) => brand.logo || brand.is_featured || (brand.product_count ?? 0) > 0)
      .sort(
        (a, b) =>
          Number(b.is_featured ?? false) - Number(a.is_featured ?? false) ||
          (b.product_count ?? 0) - (a.product_count ?? 0) ||
          a.name.localeCompare(b.name),
      )
      .slice(0, 24),
  );

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
