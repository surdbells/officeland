import { ChangeDetectionStrategy, Component, effect, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi } from '../../core/api/catalogue-api';
import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema, itemListSchema } from '../../core/seo/structured-data';

/**
 * All-departments hub at /c/ (SEO_SPEC.md section 2).
 *
 * Also the destination for the legacy /shop/ redirect, and a second crawl
 * path into the tree alongside the megamenu.
 */
@Component({
  selector: 'ol-category-index-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <!-- Every non-home page carries breadcrumbs (section 23.5), matching the
           BreadcrumbList emitted below. This page is one level down, so the
           trail is short — but a hub with no trail is still a page a crawler
           cannot place in the hierarchy. -->
      <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-ink-600">
          <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
          <li aria-hidden="true">/</li>
          <li aria-current="page" class="text-ink-900">All departments</li>
        </ol>
      </nav>

      <h1 class="mt-4 font-heading text-3xl font-bold">All departments</h1>

      <div class="mt-8 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
        @for (department of departments(); track department.slug) {
          <section>
            <h2 class="font-heading text-lg font-semibold">
              <a [routerLink]="department.url" class="hover:text-brand-600">{{ department.name }}</a>
            </h2>
            <ul class="mt-2 space-y-1 text-sm text-ink-600">
              @for (child of department.children ?? []; track child.slug) {
                <li><a [routerLink]="child.url" class="hover:text-brand-600">{{ child.name }}</a></li>
              }
            </ul>
          </section>
        }
      </div>
    </div>
  `,
})
export class CategoryIndexPage {
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  protected readonly departments = toSignal(
    this.api.categories().pipe(catchError(() => of([]))),
    { initialValue: [] },
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
     * would mean the second call cleared the first — page-scope structured
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
