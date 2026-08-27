import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * The shell every editorial page sits in.
 *
 * ── Why a shell and not twelve bespoke pages ─────────────────────────────
 * These twelve pages were all missing at once, and the reason twelve pages
 * can go missing at once is that each was "a small page to write later".
 * Giving them one shell makes each of them a body of copy rather than a
 * layout exercise, which is the difference between writing them and
 * intending to.
 *
 * It also means the breadcrumb, the measure, the heading scale and the
 * closing call to action are decided once. A policy page that quietly reads
 * differently from a delivery page is a page nobody trusts.
 *
 * ── The measure is deliberate ────────────────────────────────────────────
 * `max-w-3xl` on the prose, not the full 1564px container. Long-form text set
 * across a page that wide is unreadable - the eye loses the line on the
 * return sweep. The container width was raised for grids and menus, not for
 * paragraphs.
 */
@Component({
  selector: 'ol-content-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <nav aria-label="Breadcrumb" class="text-sm text-ink-600">
        <ol class="flex flex-wrap items-center gap-1.5">
          <li><a routerLink="/" class="hover:text-brand-600">Home</a></li>
          <li aria-hidden="true">/</li>
          <li aria-current="page" class="text-ink-900">{{ heading() }}</li>
        </ol>
      </nav>

      <header class="mt-4 max-w-3xl">
        <h1 class="font-heading text-3xl font-bold sm:text-4xl">{{ heading() }}</h1>
        @if (lede()) {
          <p class="mt-4 text-lg text-ink-600">{{ lede() }}</p>
        }
      </header>

      <div class="ol-prose mt-8 max-w-3xl text-ink-600">
        <ng-content />
      </div>

      <!--
        Every editorial page ends in the same place, because on a quote-first
        site every page is a step towards an enquiry. Reusing one block also
        means the closing offer cannot drift into twelve slightly different
        promises.
      -->
      <aside class="mt-12 max-w-3xl rounded-2xl border border-ink-100 bg-brand-50 p-6">
        <h2 class="font-heading text-lg font-semibold text-ink-900">Still not sure?</h2>
        <p class="mt-2 text-ink-600">
          Send us the list, the drawing or just a description. We will come back with a
          specification and a price, usually within one business day.
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
          <a
            routerLink="/quote/"
            class="rounded-xl bg-brand-600 px-5 py-2.5 font-semibold text-white hover:bg-brand-700"
          >Request a quote</a>
          <a
            href="https://wa.me/2347074723165"
            rel="noopener"
            class="rounded-xl border border-ink-200 bg-white px-5 py-2.5 font-semibold text-ink-900 hover:border-brand-600"
          >WhatsApp us</a>
        </div>
      </aside>
    </div>
  `,
})
export class ContentPage {
  readonly heading = input.required<string>();

  /** One sentence under the title. Optional - a policy page does not need one. */
  readonly lede = input<string>('');
}
