import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Numbered pagination as real anchors.
 *
 * ── Why not infinite scroll or a "load more" button ──────────────────────
 * Under D-009 every page of every listing is a prerendered file, and a crawler
 * reaches page 2 only by following a link to it. A button that fetches the
 * next page leaves pages 2..N unreachable and their products undiscoverable,
 * which fails the crawl-depth gate for any category past its first 24 items.
 *
 * This is also why the API pages by number rather than by cursor: a cursor is
 * opaque, so no `?page=3` anchor can be rendered without first fetching page 2
 * (D-031).
 *
 * ── The window ───────────────────────────────────────────────────────────
 * First, last, and a window around the current page. Rendering all 208 pages
 * of a large brand hub would put more link equity into pagination than into
 * products, and give a crawler 208 near-identical anchors on every page.
 */
@Component({
  selector: 'ol-pagination',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    @if (lastPage() > 1) {
      <nav aria-label="Pagination" class="mt-10 border-t border-ink-100 pt-6">
        <ul class="flex flex-wrap items-center gap-2 text-sm">
          @if (page() > 1) {
            <li>
              <a
                [routerLink]="pathForPage(page() - 1)"
                [queryParams]="preserved()"
                rel="prev"
                class="rounded border border-ink-200 px-3 py-2 hover:border-brand-600"
              >Previous</a>
            </li>
          }

          @for (entry of window(); track entry.key) {
            @if (entry.page === null) {
              <li aria-hidden="true" class="px-2 text-ink-400">&hellip;</li>
            } @else if (entry.page === page()) {
              <li>
                <span
                  aria-current="page"
                  class="rounded border border-brand-600 bg-brand-600 px-3 py-2 font-semibold text-white"
                >{{ entry.page }}</span>
              </li>
            } @else {
              <li>
                <a
                  [routerLink]="pathForPage(entry.page)"
                  [queryParams]="preserved()"
                  [attr.aria-label]="'Page ' + entry.page"
                  class="rounded border border-ink-200 px-3 py-2 hover:border-brand-600"
                >{{ entry.page }}</a>
              </li>
            }
          }

          @if (page() < lastPage()) {
            <li>
              <a
                [routerLink]="pathForPage(page() + 1)"
                [queryParams]="preserved()"
                rel="next"
                class="rounded border border-ink-200 px-3 py-2 hover:border-brand-600"
              >Next</a>
            </li>
          }
        </ul>

        <p class="mt-3 text-sm text-ink-600">Page {{ page() }} of {{ lastPage() }}</p>
      </nav>
    }
  `,
})
export class Pagination {
  readonly page = input.required<number>();
  readonly lastPage = input.required<number>();
  readonly basePath = input.required<string>();

  /** Filters and sort, preserved as query params so paging does not drop them. */
  readonly preserved = input<Readonly<Record<string, string>>>({});

  /** Pages either side of the current one. */
  private static readonly RADIUS = 2;

  protected readonly window = computed<ReadonlyArray<{ key: string; page: number | null }>>(() => {
    const last = this.lastPage();
    const current = this.page();

    const pages = new Set<number>([1, last]);

    for (let p = current - Pagination.RADIUS; p <= current + Pagination.RADIUS; p++) {
      if (p >= 1 && p <= last) pages.add(p);
    }

    const ordered = [...pages].sort((a, b) => a - b);
    const entries: Array<{ key: string; page: number | null }> = [];

    let previous = 0;

    for (const p of ordered) {
      // A gap of exactly one would render an ellipsis in place of a single
      // page number, which is longer than the number it replaces.
      if (p - previous === 2) {
        entries.push({ key: `p${previous + 1}`, page: previous + 1 });
      } else if (p - previous > 2) {
        entries.push({ key: `gap${previous}`, page: null });
      }

      entries.push({ key: `p${p}`, page: p });
      previous = p;
    }

    return entries;
  });

  /**
   * Page 2 is `/c/x/page/2/`, a PATH, not `?page=2`.
   *
   * Angular prerenders paths; it cannot emit a file for a query string. Under
   * D-009 every page of every listing has to be a file on disk, so
   * query-string pagination would leave pages 2..N with no file behind them —
   * served as page 1 by the static host, and therefore duplicate content that
   * lists the wrong products.
   *
   * Page 1 keeps the bare path. `/c/x/` and `/c/x/page/1/` would otherwise be
   * two URLs for one page, which the canonical would have to clean up after.
   */
  protected pathForPage(target: number): string {
    const base = this.basePath();

    return target > 1 ? `${base}page/${target}/` : base;
  }
}
