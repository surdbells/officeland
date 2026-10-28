import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  HostListener,
  inject,
  input,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import type { FacetGroup } from '../core/api/catalogue-api';
import { FacetRail } from './facet-rail';
import { Icon } from './icon';

/** One sort choice, as an option the sheet turns into a `?sort=` link. */
export interface SortOption {
  readonly value: string;
  readonly label: string;
}

/**
 * The mobile filter-and-sort bottom sheet.
 *
 * Why a sheet and not the inline disclosure it replaces: on a phone the inline
 * rail pushed the products off the first two screens the moment it opened, so
 * refining a listing meant losing sight of what you were refining. A sheet
 * slides over the page, scrolls on its own, and reports the live result count
 * on its primary button, so a buyer narrows and confirms without leaving the
 * grid - the thing that makes filtering usable one-handed.
 *
 * It is mobile only (lg:hidden). The desktop rail/bar is untouched; this is the
 * phone affordance for the same URL-driven facets, so it reuses FacetRail and
 * every facet link inside stays a real anchor that works before hydration. The
 * sheet chrome (open/close, scroll-lock) is the only part that needs JS, and if
 * that JS never runs the facets are still reachable through the desktop markup.
 *
 * Sort lives here too because a listing with no sort control is a listing you
 * can only read newest-first; the options are plain `?sort=` links for the same
 * reason the facets are.
 */
@Component({
  selector: 'ol-filter-sheet',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, FacetRail, Icon],
  template: `
    <!-- The trigger row: two thumb-width buttons. Both open the one sheet; Sort
         is a shortcut to the section a tap away inside it. -->
    <div class="flex items-stretch gap-2 lg:hidden">
      <button
        type="button"
        class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-ink-200 px-4 py-3 text-sm font-semibold hover:border-brand-600"
        (click)="openSheet()"
      >
        <ol-icon name="chevron-down" [size]="16" class="text-brand-600" />
        Sort
      </button>
      <button
        type="button"
        class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-ink-200 px-4 py-3 text-sm font-semibold hover:border-brand-600"
        [attr.aria-expanded]="open()"
        aria-controls="filter-sheet"
        (click)="openSheet()"
      >
        <ol-icon name="funnel" [size]="18" class="text-brand-600" />
        Filters
        @if (filterCount() > 0) {
          <span class="rounded-full bg-brand-600 px-2 text-xs text-white">{{ filterCount() }}</span>
        }
      </button>
    </div>

    @if (open()) {
      <!-- Overlay. role=dialog + aria-modal so assistive tech treats the page
           behind it as inert; the backdrop and Escape both dismiss. -->
      <div
        id="filter-sheet"
        class="fixed inset-0 z-50 lg:hidden"
        role="dialog"
        aria-modal="true"
        aria-label="Filter and sort"
      >
        <div class="absolute inset-0 bg-ink-900/40 ol-sheet-backdrop" (click)="close()"></div>

        <div
          class="ol-sheet absolute inset-x-0 bottom-0 flex max-h-[85vh] flex-col rounded-t-2xl bg-white shadow-2xl"
          style="padding-bottom: env(safe-area-inset-bottom, 0px)"
        >
          <div class="flex items-center justify-between border-b border-ink-100 px-4 py-3">
            <h2 class="font-heading text-base font-semibold">Filter &amp; sort</h2>
            <button
              type="button"
              class="-mr-2 flex h-9 w-9 items-center justify-center rounded-full text-xl text-ink-500 hover:bg-ink-50"
              aria-label="Close filter and sort"
              (click)="close()"
            >&times;</button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto px-4 py-2">
            <section aria-labelledby="sort-heading">
              <h3 id="sort-heading" class="mt-2 font-medium">Sort</h3>
              <ul class="mt-2 space-y-1">
                @for (option of sortOptions(); track option.value) {
                  <li>
                    <a
                      [routerLink]="basePath()"
                      [queryParams]="sortParams(option.value)"
                      [attr.aria-current]="isActiveSort(option.value) ? 'true' : null"
                      class="flex items-center justify-between gap-2 rounded px-1 py-1.5 hover:text-brand-600"
                      [class.font-semibold]="isActiveSort(option.value)"
                      [class.text-brand-700]="isActiveSort(option.value)"
                      (click)="close()"
                    >
                      <span>
                        @if (isActiveSort(option.value)) {
                          <span aria-hidden="true">&check;</span>
                        }
                        {{ option.label }}
                      </span>
                    </a>
                  </li>
                }
              </ul>
            </section>

            <ol-facet-rail
              [facets]="facets()"
              [selected]="selected()"
              [basePath]="basePath()"
              [preserved]="preserved()"
              layout="rail"
            />
          </div>

          <!-- Sticky footer: the primary button confirms and carries the live
               count, so the whole sheet reads as "narrow, then show these". -->
          <div class="flex items-center gap-3 border-t border-ink-100 p-3">
            @if (filterCount() > 0) {
              <a
                [routerLink]="basePath()"
                [queryParams]="clearedParams()"
                class="rounded-lg px-3 py-2.5 text-sm font-semibold text-ink-600 hover:text-brand-600"
                (click)="close()"
              >Clear all</a>
            }
            <button
              type="button"
              class="flex-1 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"
              (click)="close()"
            >
              Show {{ total() }} {{ total() === 1 ? 'result' : 'results' }}
            </button>
          </div>
        </div>
      </div>
    }
  `,
  styles: `
    /* The sheet rises; the scrim fades. Honoured only where motion is welcome. */
    @media (prefers-reduced-motion: no-preference) {
      .ol-sheet {
        animation: ol-sheet-up 0.26s var(--ease-out-soft, ease-out);
      }
      .ol-sheet-backdrop {
        animation: ol-sheet-fade 0.26s ease-out;
      }
    }

    @keyframes ol-sheet-up {
      from {
        transform: translateY(100%);
      }
    }

    @keyframes ol-sheet-fade {
      from {
        opacity: 0;
      }
    }
  `,
})
export class FilterSheet {
  private readonly document = inject(DOCUMENT);

  readonly facets = input.required<readonly FacetGroup[]>();
  readonly selected = input<Readonly<Record<string, readonly string[]>>>({});
  readonly basePath = input.required<string>();

  /** Params to carry across every facet change, such as the current sort. */
  readonly preserved = input<Readonly<Record<string, string>>>({});

  /** Live result count, shown on the confirm button. */
  readonly total = input<number>(0);

  /** The active sort value, for the tick and aria-current. */
  readonly sort = input<string>('newest');

  readonly sortOptions = input<readonly SortOption[]>([
    { value: 'newest', label: 'Newest' },
    { value: 'name', label: 'Name A–Z' },
  ]);

  protected readonly open = signal(false);

  protected readonly filterCount = computed(() =>
    Object.values(this.selected()).reduce((total, values) => total + values.length, 0),
  );

  protected openSheet(): void {
    this.open.set(true);
  }

  protected close(): void {
    this.open.set(false);
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.open()) {
      this.close();
    }
  }

  protected isActiveSort(value: string): boolean {
    return this.sort() === value;
  }

  /** Keep the selected facets, change only the sort. */
  protected sortParams(value: string): Record<string, string> {
    const params: Record<string, string> = {};

    for (const [code, values] of Object.entries(this.selected())) {
      if (values.length > 0) {
        params[`filters[${code}]`] = values.join(',');
      }
    }

    if (value) {
      params['sort'] = value;
    }

    return params;
  }

  /** Clear every facet but keep what the caller preserves (the sort). */
  protected readonly clearedParams = computed(() => ({ ...this.preserved() }));

  constructor() {
    // Lock the page behind the sheet so a scroll gesture moves the sheet's own
    // list, not the grid underneath. Guarded in try/catch and always released:
    // a sheet that leaves the body unscrollable is worse than one that does not
    // lock at all. The cleanup also runs if the component is destroyed open.
    effect((onCleanup) => {
      const body = this.document.body;
      if (!body) {
        return;
      }

      if (this.open()) {
        const previous = body.style.overflow;
        try {
          body.style.overflow = 'hidden';
        } catch {
          /* styling the body can throw in exotic embeddings; the sheet still works */
        }
        onCleanup(() => {
          body.style.overflow = previous;
        });
      }
    });
  }
}
