import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { NavigationNode, ShopFilters } from '../../core/api/catalogue-api';
import { Icon } from '../../shared/icon';

/** Everything the shop URL can carry, already parsed. */
export interface ShopState {
  /** Bare category path ("it-products/computers"), or null for the whole catalogue. */
  readonly category: string | null;
  readonly brand: string | null;
  readonly availability: readonly string[];
  readonly sort: string;
  /** Attribute filters as query params (`filters[code]` => csv), carried untouched. */
  readonly filterParams: Readonly<Record<string, string>>;
}

interface ActiveChip {
  readonly label: string;
  readonly params: Record<string, string>;
}

/**
 * The shop page's filter sidebar.
 *
 * Four groups that exist for every product - sort, category, availability,
 * brand - so the sidebar is useful on the whole catalogue, where no attribute
 * facet is curated. (Specification facets, where a category has them, are
 * rendered beneath by the page with the shared facet rail.)
 *
 * Every control is a plain `<a>` to /shop/ with query params, never a form or
 * a click handler: the URL is the state, so a filtered view can be bookmarked,
 * shared and reached with the back button, and it works before hydration.
 *
 * What survives a change is deliberate:
 *  - changing CATEGORY drops brand and specification filters (they may not
 *    exist in the new category, and a filter that silently matches nothing is
 *    worse than starting clean); sort and availability stay.
 *  - changing brand / availability / sort keeps everything else.
 *  - every change drops `page`.
 */
@Component({
  selector: 'ol-shop-sidebar',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    <div class="space-y-4">
      <!-- What is applied, and the way back out. -->
      @if (chips().length) {
        <section aria-label="Active filters" class="rounded-2xl border border-brand-600/30 bg-brand-50 p-4">
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-ink-900">Active filters</h3>
            <a routerLink="/shop/" [queryParams]="clearAllParams()" class="text-sm font-semibold text-brand-600 hover:underline">Clear all</a>
          </div>
          <ul class="mt-3 flex flex-wrap gap-2">
            @for (chip of chips(); track chip.label) {
              <li>
                <a
                  routerLink="/shop/"
                  [queryParams]="chip.params"
                  [attr.aria-label]="'Remove filter: ' + chip.label"
                  class="inline-flex items-center gap-1.5 rounded-full border border-brand-600/40 bg-white py-1 pl-3 pr-2 text-sm text-ink-800 transition-colors hover:border-brand-600 hover:text-brand-700"
                >
                  {{ chip.label }}
                  <ol-icon name="x" [size]="14" class="text-ink-400" />
                </a>
              </li>
            }
          </ul>
        </section>
      }

      <!-- Sort -->
      <details open class="ol-fgroup rounded-2xl border border-ink-100 bg-white">
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 text-sm font-semibold text-ink-900">
          Sort by
          <ol-icon name="chevron-right" [size]="16" class="ol-fgroup__chev text-ink-400" />
        </summary>
        <ul class="space-y-1 px-2 pb-3">
          @for (option of sortOptions; track option.value) {
            <li>
              <a
                routerLink="/shop/"
                [queryParams]="params({ sort: option.value === 'newest' ? null : option.value })"
                [attr.aria-current]="state().sort === option.value ? 'true' : null"
                [class]="rowClass(state().sort === option.value)"
              >
                <span [class]="radioClass(state().sort === option.value)"></span>
                {{ option.label }}
              </a>
            </li>
          }
        </ul>
      </details>

      <!-- Category tree: departments, the chosen one opened to its children and theirs. -->
      <details open class="ol-fgroup rounded-2xl border border-ink-100 bg-white">
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 text-sm font-semibold text-ink-900">
          Category
          <ol-icon name="chevron-right" [size]="16" class="ol-fgroup__chev text-ink-400" />
        </summary>
        <ul class="space-y-0.5 px-2 pb-3">
          <li>
            <a routerLink="/shop/" [queryParams]="categoryParams(null)" [class]="rowClass(state().category === null)">
              <span class="flex-1">All products</span>
            </a>
          </li>
          @for (department of departments(); track department.slug) {
            <li>
              <a routerLink="/shop/" [queryParams]="categoryParams(department)" [class]="rowClass(isActive(department))">
                <span class="flex-1">{{ department.name }}</span>
                <span class="text-xs tabular-nums text-ink-400">{{ department.product_count.toLocaleString('en') }}</span>
              </a>

              @if (isOpen(department) && department.children.length) {
                <ul class="ml-3 mt-0.5 space-y-0.5 border-l border-ink-100 pl-2">
                  @for (child of department.children; track child.slug) {
                    <li>
                      <a routerLink="/shop/" [queryParams]="categoryParams(child)" [class]="rowClass(isActive(child))">
                        <span class="flex-1">{{ child.name }}</span>
                        <span class="text-xs tabular-nums text-ink-400">{{ child.product_count.toLocaleString('en') }}</span>
                      </a>

                      @if (isOpen(child) && child.children.length) {
                        <ul class="ml-3 mt-0.5 space-y-0.5 border-l border-ink-100 pl-2">
                          @for (leaf of child.children; track leaf.slug) {
                            <li>
                              <a routerLink="/shop/" [queryParams]="categoryParams(leaf)" [class]="rowClass(isActive(leaf))">
                                <span class="flex-1">{{ leaf.name }}</span>
                                <span class="text-xs tabular-nums text-ink-400">{{ leaf.product_count.toLocaleString('en') }}</span>
                              </a>
                            </li>
                          }
                        </ul>
                      }
                    </li>
                  }
                </ul>
              }
            </li>
          }
        </ul>
      </details>

      <!-- Availability: multi-select. -->
      @if (filters().availability.length) {
        <details open class="ol-fgroup rounded-2xl border border-ink-100 bg-white">
          <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 text-sm font-semibold text-ink-900">
            Availability
            <ol-icon name="chevron-right" [size]="16" class="ol-fgroup__chev text-ink-400" />
          </summary>
          <ul class="space-y-1 px-2 pb-3">
            @for (option of filters().availability; track option.value) {
              <li>
                <a
                  routerLink="/shop/"
                  [queryParams]="toggleAvailability(option.value)"
                  role="checkbox"
                  [attr.aria-checked]="hasAvailability(option.value)"
                  [class]="rowClass(hasAvailability(option.value))"
                >
                  <span [class]="checkClass(hasAvailability(option.value))">
                    @if (hasAvailability(option.value)) {
                      <svg viewBox="0 0 12 12" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.5 6.5l2.5 2.5 4.5-5.5" />
                      </svg>
                    }
                  </span>
                  <span class="flex-1">{{ option.label }}</span>
                  <span class="text-xs tabular-nums text-ink-400">{{ option.count.toLocaleString('en') }}</span>
                </a>
              </li>
            }
          </ul>
        </details>
      }

      <!-- Brand: one at a time, searchable, the long tail behind "show all". -->
      @if (filters().brands.length) {
        <details open class="ol-fgroup rounded-2xl border border-ink-100 bg-white">
          <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 text-sm font-semibold text-ink-900">
            <span>Brand <span class="ml-1 font-normal text-ink-400">({{ filters().brands.length }})</span></span>
            <ol-icon name="chevron-right" [size]="16" class="ol-fgroup__chev text-ink-400" />
          </summary>
          <div class="px-2 pb-3">
            @if (filters().brands.length > visibleBrands) {
              <label class="relative mx-2 mb-2 block">
                <span class="sr-only">Search brands</span>
                <ol-icon name="search" [size]="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400" />
                <input
                  type="search"
                  placeholder="Search brands"
                  autocomplete="off"
                  [value]="brandQuery()"
                  (input)="brandQuery.set($any($event.target).value)"
                  class="w-full rounded-full border border-ink-200 bg-white py-2 pl-9 pr-3 text-sm text-ink-900 placeholder:text-ink-400 focus:border-brand-600 focus:outline-none"
                />
              </label>
            }

            <ul class="space-y-1">
              @for (brand of shownBrands(); track brand.slug) {
                <li>
                  <a
                    routerLink="/shop/"
                    [queryParams]="params({ brand: state().brand === brand.slug ? null : brand.slug })"
                    [attr.aria-current]="state().brand === brand.slug ? 'true' : null"
                    [class]="rowClass(state().brand === brand.slug)"
                  >
                    <span [class]="radioClass(state().brand === brand.slug)"></span>
                    <span class="flex-1">{{ brand.name }}</span>
                    <span class="text-xs tabular-nums text-ink-400">{{ brand.count.toLocaleString('en') }}</span>
                  </a>
                </li>
              } @empty {
                <li class="px-3 py-2 text-sm text-ink-400">No brand matches "{{ brandQuery() }}".</li>
              }
            </ul>

            @if (!brandQuery() && filters().brands.length > visibleBrands) {
              <button
                type="button"
                (click)="showAllBrands.set(!showAllBrands())"
                class="mx-2 mt-2 text-sm font-semibold text-brand-600 hover:underline"
              >
                {{ showAllBrands() ? 'Show fewer' : 'Show all ' + filters().brands.length + ' brands' }}
              </button>
            }
          </div>
        </details>
      }
    </div>
  `,
  styles: `
    .ol-fgroup > summary {
      list-style: none;
    }

    .ol-fgroup > summary::-webkit-details-marker {
      display: none;
    }

    .ol-fgroup__chev {
      transition: transform 0.2s ease;
    }

    .ol-fgroup[open] .ol-fgroup__chev {
      transform: rotate(90deg);
    }
  `,
})
export class ShopSidebar {
  readonly departments = input.required<readonly NavigationNode[]>();
  readonly filters = input.required<ShopFilters>();
  readonly state = input.required<ShopState>();

  /** Brands shown before "show all". */
  protected readonly visibleBrands = 8;

  protected readonly sortOptions = [
    { value: 'newest', label: 'Newest first' },
    { value: 'name', label: 'Name A-Z' },
  ] as const;

  protected readonly brandQuery = signal('');
  protected readonly showAllBrands = signal(false);

  protected readonly shownBrands = computed(() => {
    const brands = this.filters().brands;
    const query = this.brandQuery().trim().toLowerCase();

    if (query) {
      return brands.filter((brand) => brand.name.toLowerCase().includes(query));
    }
    if (this.showAllBrands()) {
      return brands;
    }

    // The chosen brand is always in view, even when it is outside the top few.
    const top = brands.slice(0, this.visibleBrands);
    const chosen = brands.find((brand) => brand.slug === this.state().brand);

    return chosen && !top.includes(chosen) ? [...top, chosen] : top;
  });

  /** One removable chip per applied filter (specification filters are cleared from their own rail). */
  protected readonly chips = computed<readonly ActiveChip[]>(() => {
    const state = this.state();
    const chips: ActiveChip[] = [];

    if (state.category) {
      const node = this.find(state.category);
      chips.push({ label: node?.name ?? state.category, params: this.categoryParams(null) });
    }
    if (state.brand) {
      const brand = this.filters().brands.find((b) => b.slug === state.brand);
      chips.push({ label: brand?.name ?? state.brand, params: this.params({ brand: null }) });
    }
    for (const value of state.availability) {
      const option = this.filters().availability.find((a) => a.value === value);
      chips.push({ label: option?.label ?? value, params: this.toggleAvailability(value) });
    }

    return chips;
  });

  /** Clear everything but the sort. */
  protected readonly clearAllParams = computed<Record<string, string>>(() =>
    this.state().sort !== 'newest' ? { sort: this.state().sort } : ({} as Record<string, string>),
  );

  /**
   * The current state as query params with some keys overridden (null removes).
   * `page` is never included, so any change returns to page 1.
   */
  protected params(overrides: Partial<Record<'category' | 'brand' | 'availability' | 'sort', string | null>> = {}): Record<string, string> {
    const state = this.state();
    const base: Record<string, string | null> = {
      category: state.category,
      brand: state.brand,
      availability: state.availability.length ? state.availability.join(',') : null,
      sort: state.sort !== 'newest' ? state.sort : null,
      ...overrides,
    };

    const params: Record<string, string> = { ...state.filterParams };
    for (const [key, value] of Object.entries(base)) {
      if (value) params[key] = value;
    }

    return params;
  }

  /** Selecting a category keeps sort and availability; brand and specification filters reset. */
  protected categoryParams(node: NavigationNode | null): Record<string, string> {
    const state = this.state();
    const params: Record<string, string> = {};

    if (node) params['category'] = this.pathOf(node.url);
    if (state.availability.length) params['availability'] = state.availability.join(',');
    if (state.sort !== 'newest') params['sort'] = state.sort;

    return params;
  }

  protected toggleAvailability(value: string): Record<string, string> {
    const current = this.state().availability;
    const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];

    return this.params({ availability: next.length ? next.join(',') : null });
  }

  protected hasAvailability(value: string): boolean {
    return this.state().availability.includes(value);
  }

  protected isActive(node: NavigationNode): boolean {
    return this.state().category === this.pathOf(node.url);
  }

  /** A node is open when the chosen category is it or sits beneath it. */
  protected isOpen(node: NavigationNode): boolean {
    const category = this.state().category;
    const path = this.pathOf(node.url);

    return category !== null && (category === path || category.startsWith(path + '/'));
  }

  protected rowClass(active: boolean): string {
    return (
      'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors ' +
      (active ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink-800 hover:bg-ink-50 hover:text-brand-700')
    );
  }

  protected radioClass(active: boolean): string {
    return (
      'h-4 w-4 shrink-0 rounded-full border-2 ' +
      (active ? 'border-brand-600 bg-brand-600 shadow-[inset_0_0_0_2.5px_#fff]' : 'border-ink-200 bg-white')
    );
  }

  protected checkClass(active: boolean): string {
    return (
      'flex h-4 w-4 shrink-0 items-center justify-center rounded-[4px] border-2 text-white ' +
      (active ? 'border-brand-600 bg-brand-600' : 'border-ink-200 bg-white')
    );
  }

  /** "/c/it-products/computers/" -> "it-products/computers". */
  private pathOf(url: string): string {
    return url.replace(/^\/c\//, '').replace(/\/+$/, '');
  }

  private find(path: string): NavigationNode | null {
    const walk = (nodes: readonly NavigationNode[]): NavigationNode | null => {
      for (const node of nodes) {
        if (this.pathOf(node.url) === path) return node;
        const hit = walk(node.children);
        if (hit) return hit;
      }

      return null;
    };

    return walk(this.departments());
  }
}
