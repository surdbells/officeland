import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';
import { Icon } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import { Reveal } from '../../shared/reveal';

/**
 * All-departments hub at /c/ (SEO_SPEC.md section 2).
 *
 * A second crawl path into the tree alongside the megamenu, and for a buyer
 * the one page that shows the whole range at a glance - so it is drawn from
 * the categories' own artwork rather than as a sitemap of text links.
 *
 * Layout: a dark header band with a quick-jump row of department roundels,
 * then one panel per department - its hero photograph on the left carrying the
 * name and the way in, its subcategories as image tiles on the right. Every
 * tile is a plain anchor, so the crawl path is exactly what it was when this
 * page was three columns of links.
 *
 * Images are optional everywhere: a department without a hero keeps the brand
 * ground, a subcategory without a card shows an icon tile. Uploading artwork
 * is an improvement, never a prerequisite.
 */
@Component({
  selector: 'ol-category-index-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, ResponsiveImage, Reveal],
  template: `
    <!-- Header band -->
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      <span aria-hidden="true" class="ol-hub-glow absolute inset-0 -z-10"></span>

      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pb-10 pt-6 sm:pb-14 sm:pt-8">
        <!-- Every non-home page carries breadcrumbs (section 23.5), matching the
             BreadcrumbList emitted below. -->
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-white/70">
            <li><a routerLink="/" class="hover:text-white">Home</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-white">All departments</li>
          </ol>
        </nav>

        <p class="mt-8 text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">The full range</p>
        <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-white sm:text-display">All departments</h1>
        <p class="mt-3 max-w-2xl text-white/80 sm:text-lg">
          Furniture, IT, printing, stationery and PVC interiors - everything an office, school or
          ministry buys, from one supplier. Pick a department, or send us the list and
          <a routerLink="/quote/" class="font-semibold text-white underline decoration-accent-400 underline-offset-4 hover:text-accent-400">we will quote it</a>.
        </p>

        @if (departments().length) {
          <p class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-white/70">
            <span><span class="font-semibold text-white">{{ departments().length }}</span> departments</span>
            <span><span class="font-semibold text-white">{{ subcategoryCount() }}</span> categories</span>
            @if (productCount() > 0) {
              <span><span class="font-semibold text-white">{{ productCount().toLocaleString('en') }}</span> products</span>
            }
          </p>

          <!-- Quick jump: one roundel per department. Scrolls sideways on a phone. -->
          <ul class="ol-hub-rail mt-8 flex gap-5 overflow-x-auto pb-1 sm:gap-7">
            @for (department of departments(); track department.slug) {
              <li class="w-20 shrink-0 sm:w-24">
                <a [routerLink]="department.url" class="group block text-center">
                  <span class="mx-auto flex aspect-square w-16 items-center justify-center overflow-hidden rounded-full bg-white/10 text-white ring-2 ring-white/20 transition group-hover:ring-accent-400 sm:w-20">
                    @if (department.card ?? department.hero; as image) {
                      <ol-image
                        class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                        [image]="image"
                        sizes="80px"
                        imgClass="h-full w-full object-cover"
                      />
                    } @else {
                      <ol-icon name="layers" [size]="24" />
                    }
                  </span>
                  <span class="mt-2 block text-xs font-medium leading-tight text-white/85 group-hover:text-white sm:text-sm">{{ department.name }}</span>
                </a>
              </li>
            }
          </ul>
        }
      </div>
    </section>

    <!-- One panel per department -->
    <div class="bg-ink-50">
      <div class="mx-auto max-w-(--container-page) space-y-6 px-(--spacing-gutter) py-8 sm:space-y-8 sm:py-12">
        @for (department of departments(); track department.slug; let i = $index) {
          <section
            olReveal
            [attr.aria-labelledby]="'dept-' + department.slug"
            class="grid overflow-hidden rounded-3xl border border-ink-100 bg-white shadow-xs lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]"
          >
            <!-- The department: photograph, name and the way in. -->
            <a
              [routerLink]="department.url"
              class="ol-zoom-frame group relative isolate flex min-h-[13rem] flex-col justify-end overflow-hidden bg-brand-900 p-6 text-white sm:min-h-[16rem] sm:p-8 lg:min-h-[20rem]"
            >
              @if (department.hero ?? department.card; as image) {
                <span class="absolute inset-0 -z-20">
                  <ol-image
                    class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                    [image]="image"
                    [priority]="i === 0"
                    sizes="(min-width: 1024px) 40vw, 100vw"
                    imgClass="h-full w-full object-cover"
                  />
                </span>
              }
              <span aria-hidden="true" class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-900/90 via-ink-900/45 to-ink-900/10"></span>

              <span class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
                Department {{ i + 1 < 10 ? '0' + (i + 1) : i + 1 }}
              </span>
              <h2 [id]="'dept-' + department.slug" class="mt-1 font-heading text-2xl font-bold text-white sm:text-3xl">
                {{ department.name }}
              </h2>
              @if (department.product_count) {
                <span class="mt-1 text-sm text-white/80">
                  {{ department.product_count.toLocaleString('en') }} products
                </span>
              }
              <span class="mt-4 inline-flex w-fit items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-ink-900 transition-colors group-hover:bg-accent-400">
                Shop {{ department.name }}
                <ol-icon name="arrow-right" [size]="16" class="transition-transform group-hover:translate-x-0.5" />
              </span>
            </a>

            <!-- Its subcategories, as image tiles. -->
            <ul class="grid grid-cols-2 content-start gap-3 p-4 sm:grid-cols-3 sm:gap-4 sm:p-6 xl:grid-cols-4">
              @for (child of department.children ?? []; track child.slug) {
                <li>
                  <a
                    [routerLink]="child.url"
                    class="ol-lift group flex h-full flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white hover:border-brand-600"
                  >
                    <span class="ol-zoom-frame flex aspect-[4/3] items-center justify-center overflow-hidden bg-[#f6f3ee] text-brand-600">
                      @if (child.card; as card) {
                        <ol-image
                          class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                          [image]="card"
                          sizes="(min-width: 1280px) 12rem, (min-width: 640px) 28vw, 45vw"
                          imgClass="h-full w-full object-cover"
                        />
                      } @else {
                        <ol-icon name="layers" [size]="28" />
                      }
                    </span>
                    <span class="flex flex-1 items-start justify-between gap-2 p-3">
                      <span class="min-w-0">
                        <span class="block text-sm font-semibold leading-snug text-ink-900 group-hover:text-brand-700">{{ child.name }}</span>
                        @if (child.product_count) {
                          <span class="mt-0.5 block text-xs text-ink-400">{{ child.product_count.toLocaleString('en') }} products</span>
                        }
                      </span>
                      <ol-icon name="arrow-right" [size]="14" class="mt-1 shrink-0 text-ink-400 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-600" />
                    </span>
                  </a>
                </li>
              }
            </ul>
          </section>
        }

        <!-- The way out for a buyer who did not find the department they need. -->
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-ink-900 p-6 text-white sm:p-8">
          <div>
            <h2 class="font-heading text-xl font-semibold text-white">Not sure where it lives?</h2>
            <p class="mt-1 max-w-xl text-sm text-white/80">
              Send the requisition list as it is. We price every line and supply far more than is listed here.
            </p>
          </div>
          <div class="flex flex-wrap gap-3">
            <a routerLink="/quote/" class="rounded-full bg-accent-500 px-6 py-3 text-sm font-semibold text-ink-900 transition-colors hover:bg-accent-400">Request a quote</a>
            <a routerLink="/shop/" class="rounded-full border border-white/60 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10">Browse everything</a>
          </div>
        </section>
      </div>
    </div>
  `,
  styles: `
    /* A soft brand glow in the header band, so it is a designed surface and not a black bar. */
    .ol-hub-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }

    /* The scroll stays, the bar goes - same as every other rail on the site. */
    .ol-hub-rail {
      scrollbar-width: none;
    }

    .ol-hub-rail::-webkit-scrollbar {
      display: none;
    }
  `,
})
export class CategoryIndexPage {
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  protected readonly departments = toSignal(
    this.api.categories(true).pipe(catchError(() => of([]))),
    { initialValue: [] },
  );

  protected readonly subcategoryCount = computed(() =>
    this.departments().reduce((total, department) => total + (department.children?.length ?? 0), 0),
  );

  protected readonly productCount = computed(() =>
    this.departments().reduce((total, department) => total + (department.product_count ?? 0), 0),
  );

  constructor() {
    this.seo.apply({
      title: 'All departments',
      description:
        'Every product department at OfficeLand: office furniture, IT, printers and consumables, stationery, paper, filing and school supplies.',
      path: '/c/',
      indexable: true,
    });

    /*
     * An effect because the ItemList depends on the fetched departments.
     * The BreadcrumbList inside it is static, but emitting the two separately
     * would mean the second call cleared the first - page-scope structured
     * data is replaced wholesale, by design.
     */
    effect(() => {
      const departments = this.departments();

      this.seo.setStructuredData([
        breadcrumbSchema([
          { name: 'Home', url: '/' },
          { name: 'All departments', url: '/c/' },
        ]),
        itemListSchema(departments.map((d) => ({ name: d.name, url: d.url }))),
      ]);
    });
  }
}
