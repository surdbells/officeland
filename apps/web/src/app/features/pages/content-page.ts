import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { Icon } from '../../shared/icon';

interface TocEntry {
  readonly id: string;
  readonly label: string;
}

/**
 * The shell every editorial page sits in.
 *
 * ── Why a shell and not bespoke pages ────────────────────────────────────
 * Giving every editorial page one shell makes each a body of copy rather than
 * a layout exercise, and decides the breadcrumb, the hero, the measure and the
 * closing call to action once. A policy page that quietly reads differently
 * from a delivery page is a page nobody trusts.
 *
 * ── Three body modes ─────────────────────────────────────────────────────
 *  - default: prose at a reading measure (max-w-3xl).
 *  - toc:     prose plus a sticky "On this page" list built from the h2s -
 *             for the long policy pages. Built in the browser after render
 *             (ids are added to the h2s then), so it is an enhancement: the
 *             prerendered HTML is the same document without the list.
 *  - wide:    the projected content takes the full container, for pages with
 *             their own designed sections (About, Contact, Bulk supply).
 *
 * ── The hero ─────────────────────────────────────────────────────────────
 * A photograph under a scrim when the page has one, otherwise the dark band
 * with a brand glow. Either way white type on a dark ground, so every
 * editorial page opens the same way.
 */
@Component({
  selector: 'ol-content-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      @if (image()) {
        <img
          [src]="image()"
          alt=""
          width="2048"
          height="1024"
          fetchpriority="high"
          decoding="async"
          class="absolute inset-0 -z-20 h-full w-full object-cover"
        />
        <span aria-hidden="true" class="absolute inset-0 -z-10 bg-gradient-to-r from-ink-900/90 via-ink-900/70 to-ink-900/30"></span>
      } @else {
        <span aria-hidden="true" class="ol-content-glow absolute inset-0 -z-10"></span>
      }

      <div
        class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pt-6 sm:pt-8"
        [class]="image() ? 'pb-14 sm:pb-24' : 'pb-10 sm:pb-14'"
      >
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-white/70">
            <li><a routerLink="/" class="hover:text-white">Home</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-white">{{ crumb() || heading() }}</li>
          </ol>
        </nav>

        <div class="mt-8 max-w-3xl sm:mt-12">
          @if (eyebrow()) {
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">{{ eyebrow() }}</p>
          }
          <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-white sm:text-display">{{ heading() }}</h1>
          @if (lede()) {
            <p class="mt-4 text-base text-white/85 sm:text-lg">{{ lede() }}</p>
          }
          <ng-content select="[heroActions]" />
        </div>
      </div>
    </section>

    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10 sm:py-14">
      <!--
        ONE default <ng-content>, never one per branch of an @if: Angular
        projects into a single slot, so a second, conditional slot silently
        receives nothing. The mode changes the wrapper's classes instead.
      -->
      <div [class]="toc() && !wide() ? 'grid gap-10 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-14' : ''">
          @if (toc() && !wide()) {
            <aside class="hidden lg:block">
              @if (entries().length) {
                <nav aria-label="On this page" class="sticky top-28 rounded-2xl border border-ink-100 bg-white p-5 shadow-xs">
                  <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">On this page</p>
                  <ol class="mt-3 space-y-1 border-l border-ink-100">
                    @for (entry of entries(); track entry.id) {
                      <li>
                        <a
                          [href]="'#' + entry.id"
                          class="-ml-px block border-l-2 border-transparent py-1 pl-3 text-sm text-ink-600 transition-colors hover:border-brand-600 hover:text-brand-700"
                        >{{ entry.label }}</a>
                      </li>
                    }
                  </ol>
                </nav>
              }
            </aside>
          }

          <div #prose [class]="wide() ? '' : 'ol-prose ol-content-prose max-w-3xl text-ink-600'">
            <ng-content />
          </div>
      </div>

      <!--
        Every editorial page ends in the same place, because on a quote-first
        site every page is a step towards an enquiry. One block means the
        closing offer cannot drift into slightly different promises.
      -->
      <aside class="relative isolate mt-14 overflow-hidden rounded-3xl bg-ink-900 p-8 text-white sm:p-10">
        <span aria-hidden="true" class="ol-content-glow absolute inset-0 -z-10"></span>
        <div class="flex flex-wrap items-center justify-between gap-6">
          <div class="max-w-xl">
            <h2 class="font-heading text-2xl font-semibold text-white">Still not sure?</h2>
            <p class="mt-2 text-white/80">
              Send us the list, the drawing or just a description. We come back with a
              specification and a price, usually within one business day.
            </p>
          </div>
          <div class="flex flex-wrap gap-3">
            <a
              routerLink="/quote/"
              class="inline-flex items-center gap-2 rounded-full bg-accent-500 px-6 py-3 font-semibold text-ink-900 transition-colors hover:bg-accent-400"
            >
              Request a quote
              <ol-icon name="arrow-right" [size]="16" />
            </a>
            <a
              href="https://wa.me/2347074723165"
              rel="noopener"
              class="inline-flex items-center gap-2 rounded-full border border-white/60 px-6 py-3 font-semibold text-white transition-colors hover:bg-white/10"
            >
              <ol-icon name="message-circle" [size]="16" />
              WhatsApp us
            </a>
          </div>
        </div>
      </aside>
    </div>
  `,
  styles: `
    .ol-content-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }

    /* Headings jumped to from the table of contents clear the sticky header. */
    .ol-content-prose :is(h2, h3) {
      scroll-margin-top: 7rem;
    }
  `,
})
export class ContentPage {
  readonly heading = input.required<string>();

  /** One sentence under the title. Optional - a policy page does not need one. */
  readonly lede = input<string>('');

  /** Small label above the title. */
  readonly eyebrow = input<string>('');

  /** Breadcrumb label, when it should be shorter than the heading. */
  readonly crumb = input<string>('');

  /** Hero photograph (a /images/... path). Without one the band is dark with a glow. */
  readonly image = input<string>('');

  /** Build an "On this page" list from the h2s (long policy pages). */
  readonly toc = input<boolean>(false);

  /** Projected content takes the full width (pages with designed sections). */
  readonly wide = input<boolean>(false);

  private readonly prose = viewChild<ElementRef<HTMLElement>>('prose');

  protected readonly entries = signal<readonly TocEntry[]>([]);

  constructor() {
    afterNextRender(() => {
      const root = this.prose()?.nativeElement;
      if (!this.toc() || !root) return;

      const used = new Set<string>();
      const entries: TocEntry[] = [];

      for (const heading of Array.from(root.querySelectorAll('h2'))) {
        const label = (heading.textContent ?? '').trim();
        if (!label) continue;

        let id = heading.id || label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        while (used.has(id)) id += '-x';
        used.add(id);
        heading.id = id;
        entries.push({ id, label });
      }

      this.entries.set(entries);
    });
  }
}
