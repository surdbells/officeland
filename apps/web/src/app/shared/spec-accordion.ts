import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import type { SpecificationGroup } from '../core/api/catalogue-api';

/**
 * Grouped specifications, as an accordion whose content is always present.
 *
 * §23.3 calls the grouped spec table "the single highest-leverage fix" for the
 * legacy site's unstructured supplier prose, and the Phase 8 brief is explicit
 * that the content must be **present in the HTML whether or not expanded**.
 *
 * ── Which is why this is not `<details>` ─────────────────────────────────
 * `<details>` would be less code and would work without JavaScript. But a
 * closed `<details>` hides its content from in-page find, and more
 * importantly the first group needs to be open on load while the rest are
 * closed - which `<details open>` can express, but not while also being
 * controllable as a set. The compromise chosen here keeps the semantics
 * explicit: a real `<button aria-expanded>` per group, and panels that are
 * always rendered and hidden with `hidden` when collapsed.
 *
 * Either way the requirement is the same and is met: every specification is in
 * the markup a crawler receives. Collapsed is a presentation state.
 *
 * ── Ordering ─────────────────────────────────────────────────────────────
 * Groups arrive already ordered from the source (Icecat's own
 * CategoryFeatureGroup ordering, ICECAT_MAPPING.md F-2). They are not
 * re-sorted here: the supplier's ordering reflects buyer priority for that
 * product category, and alphabetising it would put "Weight" above "Print
 * speed" on a printer.
 */
@Component({
  selector: 'ol-spec-accordion',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (groups().length) {
      <div class="mt-3 max-w-3xl divide-y divide-ink-100 border-y border-ink-100">
        @for (group of groups(); track group.group; let i = $index) {
          <section>
            <h3>
              <button
                type="button"
                class="flex w-full items-center justify-between gap-4 py-3 text-left font-medium"
                [attr.aria-expanded]="isOpen(i)"
                [attr.aria-controls]="panelId(i)"
                [id]="triggerId(i)"
                (click)="toggle(i)"
              >
                <span>{{ group.group }}</span>
                <span aria-hidden="true" class="text-ink-400">{{ isOpen(i) ? '−' : '+' }}</span>
              </button>
            </h3>

            <!--
              Always rendered. The hidden attribute is a presentation state,
              not an excuse to withhold the content: these specifications are
              the most valuable text on the page and also feed
              additionalProperty in Product JSON-LD.
            -->
            <div [id]="panelId(i)" role="region" [attr.aria-labelledby]="triggerId(i)" [hidden]="!isOpen(i)">
              <table class="w-full pb-4 text-sm">
                <tbody>
                  @for (item of group.items; track $index) {
                    <tr class="border-t border-ink-100">
                      <th scope="row" class="w-1/2 py-2 pr-4 text-left font-medium align-top">
                        {{ item.label }}
                      </th>
                      <td class="py-2 text-ink-600">{{ item.value }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </section>
        }
      </div>
    }
  `,
})
export class SpecAccordion {
  readonly groups = input.required<readonly SpecificationGroup[]>();

  /**
   * The first group starts open. A page that opens with every panel shut
   * looks empty, and the first group is the one the source ranked highest.
   */
  private readonly open = signal<ReadonlySet<number>>(new Set([0]));

  protected isOpen(index: number): boolean {
    return this.open().has(index);
  }

  protected toggle(index: number): void {
    this.open.update((current) => {
      const next = new Set(current);

      if (!next.delete(index)) next.add(index);

      return next;
    });
  }

  protected panelId(index: number): string {
    return `spec-panel-${index}`;
  }

  protected triggerId(index: number): string {
    return `spec-trigger-${index}`;
  }
}
