import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';
import { CatalogueApi } from '../core/api/catalogue-api';
import { Icon } from './icon';
import { ResponsiveImage } from './responsive-image';

/**
 * The departments as photo tiles - the "start here" block for pages that must
 * never be a dead end: a search with no results, a search with no term, the
 * 404. Read from the navigation payload every page already carries, so it costs
 * no extra request, and a department added in admin appears here unprompted.
 */
@Component({
  selector: 'ol-department-tiles',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, ResponsiveImage],
  template: `
    @if (departments().length) {
      <nav [attr.aria-label]="label()">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
          @for (department of departments(); track department.slug) {
            <li>
              <a
                [routerLink]="department.url"
                class="ol-lift ol-zoom-frame group relative isolate flex aspect-[4/3] flex-col justify-end overflow-hidden rounded-2xl bg-brand-900 p-4 text-white"
              >
                @if (department.card ?? department.hero; as image) {
                  <span class="absolute inset-0 -z-20">
                    <ol-image
                      class="block h-full w-full [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                      [image]="image"
                      sizes="(min-width: 1024px) 18vw, (min-width: 640px) 30vw, 45vw"
                      imgClass="h-full w-full object-cover"
                    />
                  </span>
                }
                <span aria-hidden="true" class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-900/85 via-ink-900/25 to-transparent"></span>
                <span class="flex items-end justify-between gap-2">
                  <span class="font-heading text-sm font-semibold leading-tight text-white sm:text-base">{{ department.name }}</span>
                  <ol-icon name="arrow-right" [size]="15" class="shrink-0 text-white/80 transition-transform group-hover:translate-x-0.5 group-hover:text-accent-400" />
                </span>
              </a>
            </li>
          }
        </ul>
      </nav>
    }
  `,
})
export class DepartmentTiles {
  private readonly api = inject(CatalogueApi);

  /** Accessible name for the nav landmark. */
  readonly label = input('Browse departments');

  protected readonly departments = toSignal(
    this.api.navigation().pipe(
      map((navigation) => navigation.departments),
      catchError(() => of([])),
    ),
    { initialValue: [] },
  );
}
