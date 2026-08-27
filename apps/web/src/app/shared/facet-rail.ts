import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { FacetGroup } from '../core/api/catalogue-api';

/**
 * The facet rail (§23.2, and the per-family groups in UX_SPEC.md §4).
 *
 * ── Every facet is a real anchor ─────────────────────────────────────────
 * Not a checkbox with a click handler. The URL is the state, so a facet
 * selection is bookmarkable, shareable, back-button-correct and functional
 * before JavaScript loads. RouterLink gives a real `href` in the prerendered
 * HTML and upgrades to client-side navigation once hydrated - the same
 * pattern as the megamenu, and for the same reason.
 *
 * The pages behind those anchors are `noindex, follow` (SEO_SPEC.md §2), so
 * a crawler may walk them to discover products without adding a combinatorial
 * explosion of filtered URLs to the index. `follow` is the point: these links
 * are a discovery surface, and `nofollow` would waste it.
 *
 * ── Counts are mandatory ─────────────────────────────────────────────────
 * §23.2 is explicit. A count is what stops a shopper selecting a combination
 * that returns nothing, and §16 requires that facets never offer a zero-result
 * combination - which is only enforceable if the counts are live against the
 * current filter set, as they are here.
 */
@Component({
  selector: 'ol-facet-rail',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    @if (visibleGroups().length) {
      <aside
        aria-labelledby="facets-heading"
        class="text-sm"
        [class.ol-facets-bar]="layout() === 'bar'"
      >
        <div [class]="layout() === 'bar' ? 'flex flex-wrap items-baseline gap-x-4 gap-y-2' : ''">
          <h2 id="facets-heading" class="font-heading text-sm font-semibold uppercase tracking-wide">
            Refine
          </h2>

          @if (hasSelection()) {
            <p [class]="layout() === 'bar' ? '' : 'mt-2'">
              <a
                [routerLink]="basePath()"
                [queryParams]="clearedParams()"
                class="text-brand-600 hover:underline"
              >Clear all filters</a>
            </p>
          }
        </div>

        <!--
          Two layouts, one component.

          A second component would be a second place for the facet URL rules
          to live - toggling, page-dropping, preserved params, the
          aria-current handling - and those are the parts that are easy to get
          subtly wrong. The markup differs; the behaviour must not.

          In "bar" the groups sit side by side above the grid, each one a
          native <details>. In "rail" they are always-open sections in a
          column. <details> is deliberate: it discloses without JavaScript, so
          it works in the prerendered HTML before hydration, and every facet
          anchor inside is still in the DOM for a crawler to follow.
        -->
        @if (layout() === 'bar') {
          <div class="mt-3 flex flex-wrap gap-2">
            @for (group of visibleGroups(); track group.code) {
              <details class="ol-facet-group relative">
                <summary
                  class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-ink-200 px-3 py-2 font-medium hover:border-brand-600"
                  [class.border-brand-600]="selectedCount(group.code) > 0"
                  [class.text-brand-700]="selectedCount(group.code) > 0"
                >
                  {{ group.label }}
                  @if (selectedCount(group.code) > 0) {
                    <span class="rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ selectedCount(group.code) }}</span>
                  }
                  <span aria-hidden="true" class="text-ink-400">&#9662;</span>
                </summary>

                <ul class="ol-facet-popover absolute left-0 z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-ink-200 bg-white p-2 shadow-xl">
                  @for (value of group.values; track value.value) {
                    <li>
                      <a
                        [routerLink]="basePath()"
                        [queryParams]="paramsToggling(group.code, value.value)"
                        [attr.aria-current]="isSelected(group.code, value.value) ? 'true' : null"
                        class="flex items-center justify-between gap-2 rounded px-2 py-1.5 hover:bg-brand-50 hover:text-brand-600"
                        [class.font-semibold]="isSelected(group.code, value.value)"
                        [class.text-brand-700]="isSelected(group.code, value.value)"
                      >
                        <span>
                          @if (isSelected(group.code, value.value)) {
                            <span aria-hidden="true">&check;</span>
                          }
                          {{ value.label ?? value.value }}
                        </span>
                        <span class="text-ink-400">{{ value.count }}</span>
                      </a>
                    </li>
                  }
                </ul>
              </details>
            }
          </div>
        } @else {
          @for (group of visibleGroups(); track group.code) {
            <section class="mt-6 border-t border-ink-100 pt-4">
              <h3 class="font-medium">{{ group.label }}</h3>

              <ul class="mt-2 space-y-1">
                @for (value of group.values; track value.value) {
                  <li>
                    <!--
                      aria-current, NOT aria-pressed. "aria-pressed" is only
                      supported where the role is button; on an anchor it is
                      invalid ARIA, which axe rates critical because a screen
                      reader may ignore it entirely and announce nothing about
                      the applied state.

                      These are deliberately real anchors (D-012), so the
                      correct token is aria-current="true" -- "the current item
                      in a set". Null rather than "false" when unselected:
                      aria-current="false" is legal but puts an attribute on
                      every unapplied facet for no benefit.
                    -->
                    <a
                      [routerLink]="basePath()"
                      [queryParams]="paramsToggling(group.code, value.value)"
                      [attr.aria-current]="isSelected(group.code, value.value) ? 'true' : null"
                      class="flex items-center justify-between gap-2 rounded px-1 py-0.5 hover:text-brand-600"
                      [class.font-semibold]="isSelected(group.code, value.value)"
                      [class.text-brand-700]="isSelected(group.code, value.value)"
                    >
                      <span>
                        @if (isSelected(group.code, value.value)) {
                          <span aria-hidden="true">&check;</span>
                        }
                        {{ value.label ?? value.value }}
                      </span>
                      <!-- Counts are mandatory (section 23.2). -->
                      <span class="text-ink-400">{{ value.count }}</span>
                    </a>
                  </li>
                }
              </ul>
            </section>
          }
        }
      </aside>
    }
  `,
  styles: `
    /* The default triangle would sit beside the one in the markup. */
    .ol-facet-group summary::-webkit-details-marker {
      display: none;
    }
  `,
})
export class FacetRail {
  readonly facets = input.required<readonly FacetGroup[]>();

  /** Currently selected values, attribute code => values. */
  readonly selected = input<Readonly<Record<string, readonly string[]>>>({});

  /** The path facet links point back at, e.g. '/c/office-furniture/'. */
  readonly basePath = input.required<string>();

  /** Query params to preserve across facet changes, such as `sort`. */
  readonly preserved = input<Readonly<Record<string, string>>>({});

  /**
   * 'rail' is a vertical column beside the grid; 'bar' is a horizontal row of
   * dropdowns above it.
   *
   * Defaulting to 'bar' because that is what most listings use - category,
   * brand and search. Only the shop page asks for 'rail', and it is the one
   * page where filtering is the primary act rather than a refinement of a
   * scope the visitor already chose.
   */
  readonly layout = input<'rail' | 'bar'>('bar');

  /** How many values are applied in one group, for the bar's badge. */
  protected selectedCount(code: string): number {
    return this.selectedValues(code).length;
  }

  /**
   * A group with a single value cannot narrow anything - every result already
   * has it - so it is hidden rather than shown as a no-op. A SELECTED group is
   * kept whatever its length, because hiding it would remove the only control
   * that can deselect it and strand the user on a filtered page.
   */
  protected readonly visibleGroups = computed(() =>
    this.facets().filter(
      (group) => group.values.length > 1 || this.selectedValues(group.code).length > 0,
    ),
  );

  protected readonly hasSelection = computed(() =>
    Object.values(this.selected()).some((values) => values.length > 0),
  );

  protected isSelected(code: string, value: string): boolean {
    return this.selectedValues(code).includes(value);
  }

  /** Params with everything cleared except what the caller preserves. */
  protected readonly clearedParams = computed(() => ({ ...this.preserved() }));

  /**
   * Adding a value narrows; clicking a selected value removes it. One anchor
   * does both, so the control that applies a filter is the control that
   * removes it - there is no separate "remove" affordance to miss.
   *
   * Page is dropped on every change: staying on page 7 of a result set that
   * now has two pages is an empty page.
   */
  protected paramsToggling(code: string, value: string): Record<string, string> {
    const current = this.selectedValues(code);

    const next = current.includes(value)
      ? current.filter((v) => v !== value)
      : [...current, value];

    const params: Record<string, string> = { ...this.preserved() };

    for (const [otherCode, values] of Object.entries(this.selected())) {
      if (otherCode !== code && values.length > 0) {
        params[`filters[${otherCode}]`] = values.join(',');
      }
    }

    if (next.length > 0) {
      params[`filters[${code}]`] = next.join(',');
    }

    return params;
  }

  private selectedValues(code: string): readonly string[] {
    return this.selected()[code] ?? [];
  }
}
