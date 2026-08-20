import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { Navigation, NavigationNode } from '../api/catalogue-api';

/**
 * Site header, utility bar and megamenu (MASTER BUILD PROMPT section 23.1).
 *
 * The engineering requirements matter more than the visuals here, and the
 * brief says so:
 *
 *  1. Levels 1 and 2 ship as real <a href> in the rendered HTML. This is how
 *     deep categories are crawled at all. A menu that only exists after JS has
 *     run is worth nothing to a crawler.
 *  2. Level 3 loads on intent, keeping the header under ~150 anchors. The
 *     reference site puts its whole menu in the DOM and pays for it on every
 *     page.
 *  3. Disclosure pattern, NOT role="menu". role="menu" is for application
 *     menus and makes screen-reader users navigate site navigation as though
 *     it were a menubar.
 *  4. Never hover-only: hover with intent delay on pointer devices, click and
 *     Enter/Space for keyboard and touch, Escape closes and returns focus.
 *  5. No layout shift when a panel opens — it overlays rather than pushes.
 */
@Component({
  selector: 'ol-site-header',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <!-- Section 23.5: the first focusable element on every page. -->
    <a class="skip-link" href="#main">Skip to main content</a>

    <!-- One rotating service promise, not a discount shout (section 23.1). -->
    @if (announcementVisible()) {
      <div class="bg-ink-900 text-white text-sm">
        <div class="mx-auto flex max-w-(--container-page) items-center justify-between gap-4 px-(--spacing-gutter) py-2">
          <p class="truncate">Quotes answered within 1 business day · Nationwide delivery &amp; installation</p>
          <button
            type="button"
            class="shrink-0 rounded px-2 py-1 text-white/70 hover:text-white"
            aria-label="Dismiss announcement"
            (click)="dismissAnnouncement()"
          >&times;</button>
        </div>
      </div>
    }

    <!-- Utility bar: the quote-first equivalents of the reference site's
         order-tracking row (section 23.1). -->
    <div class="hidden border-b border-ink-100 bg-ink-50 text-sm md:block">
      <nav aria-label="Support" class="mx-auto max-w-(--container-page) px-(--spacing-gutter)">
        <ul class="flex items-center gap-6 py-2 text-ink-600">
          <li><a routerLink="/quote/" class="hover:text-brand-600">Request a quote</a></li>
          <li><a routerLink="/quote/track/" class="hover:text-brand-600">Track my quote</a></li>
          <li><a routerLink="/delivery/" class="hover:text-brand-600">Delivery &amp; lead times</a></li>
          <li><a routerLink="/locations/" class="hover:text-brand-600">Showrooms</a></li>
          <li><a routerLink="/bulk-supply/" class="hover:text-brand-600">Bulk &amp; tender supply</a></li>
          <li><a routerLink="/guides/" class="hover:text-brand-600">Buying guides</a></li>
          <li class="ml-auto"><a routerLink="/contact/" class="hover:text-brand-600">Contact</a></li>
        </ul>
      </nav>
    </div>

    <header class="sticky top-0 z-40 border-b border-ink-100 bg-white">
      <div class="mx-auto flex max-w-(--container-page) items-center gap-4 px-(--spacing-gutter) py-3">
        <a routerLink="/" class="font-heading text-xl font-bold text-brand-600">
          OfficeLand
        </a>

        <!-- Search is a real form so it works before JS loads. -->
        <form action="/search" method="get" role="search" class="ml-auto hidden flex-1 md:block md:max-w-xl">
          <label for="site-search" class="sr-only">Search products</label>
          <input
            id="site-search"
            name="q"
            type="search"
            placeholder="Search by product, brand or model code"
            class="w-full rounded-lg border border-ink-200 px-4 py-2 focus:border-brand-600"
          />
        </form>

        <div class="ml-auto flex items-center gap-3 md:ml-0">
          <!-- Section 23.1: WhatsApp converts better than a phone number in
               this market, and the reference site has no equivalent. -->
          <a
            href="https://wa.me/2347074723165"
            class="hidden rounded-lg border border-ink-200 px-3 py-2 text-sm font-medium hover:border-brand-600 sm:block"
            rel="noopener"
          >WhatsApp us</a>

          <a
            routerLink="/quote/"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
          >
            Quote basket
            @if (quoteCount() > 0) {
              <span class="ml-1 rounded-full bg-white px-2 text-brand-700">{{ quoteCount() }}</span>
            }
          </a>
        </div>
      </div>

      <!-- Departments. Level 1 and 2 are real anchors in the HTML. -->
      <nav aria-label="Departments" class="hidden border-t border-ink-100 md:block">
        <ul class="mx-auto flex max-w-(--container-page) items-stretch gap-1 px-(--spacing-gutter)">
          @for (department of departments(); track department.slug) {
            <li
              class="relative"
              (mouseenter)="openWithIntent(department.slug)"
              (mouseleave)="closeWithIntent()"
            >
              <!-- The department itself is a link AND the panel trigger. A
                   button alone would make the department page unreachable
                   without JS; a link alone would make the panel unopenable by
                   keyboard. -->
              <a
                [routerLink]="department.url"
                class="block px-3 py-3 text-sm font-medium hover:text-brand-600"
                [attr.aria-expanded]="open() === department.slug"
                [attr.aria-controls]="'panel-' + department.slug"
                (keydown.enter)="toggle(department.slug, $event)"
                (keydown.space)="toggle(department.slug, $event)"
                (focus)="open.set(department.slug)"
              >{{ department.name }}</a>

              @if (open() === department.slug && department.children.length) {
                <!-- Absolute, so opening the panel shifts nothing
                     (section 23.1 rule 5). -->
                <div
                  [id]="'panel-' + department.slug"
                  class="absolute left-0 top-full z-50 w-max min-w-[36rem] border border-ink-100 bg-white p-6 shadow-lg"
                >
                  <ul class="grid grid-cols-3 gap-x-8 gap-y-2">
                    @for (child of department.children; track child.slug) {
                      <li>
                        <a [routerLink]="child.url" class="text-sm hover:text-brand-600">{{ child.name }}</a>
                      </li>
                    }
                  </ul>
                  <p class="mt-4 border-t border-ink-100 pt-3 text-sm">
                    <a [routerLink]="department.url" class="font-medium text-brand-600">
                      All {{ department.name }} &rarr;
                    </a>
                  </p>
                </div>
              }
            </li>
          }

          <!-- Section 23.1: "Brands" as a peer nav entry is what replaces the
               broken /product-category/hp/ pattern (legacy defect 4E). -->
          <li><a routerLink="/brands/" class="block px-3 py-3 text-sm font-medium hover:text-brand-600">Brands</a></li>
          <li><a routerLink="/services/" class="block px-3 py-3 text-sm font-medium hover:text-brand-600">Services</a></li>
        </ul>
      </nav>
    </header>
  `,
  host: {
    '(document:keydown.escape)': 'close()',
  },
})
export class SiteHeader {
  readonly navigation = input<Navigation | null>(null);
  readonly quoteCount = input<number>(0);

  protected readonly open = signal<string | null>(null);
  protected readonly announcementVisible = signal(true);

  protected readonly departments = computed<readonly NavigationNode[]>(
    () => this.navigation()?.departments ?? [],
  );

  /**
   * ~150ms of intent before opening. Without it, dragging the pointer across
   * the bar flickers every panel in turn.
   */
  private openTimer: ReturnType<typeof setTimeout> | null = null;
  private closeTimer: ReturnType<typeof setTimeout> | null = null;

  protected openWithIntent(slug: string): void {
    this.clearTimers();
    this.openTimer = setTimeout(() => this.open.set(slug), 150);
  }

  protected closeWithIntent(): void {
    this.clearTimers();
    // Slightly longer than the open delay: the pointer has to cross a gap
    // between the trigger and the panel, and closing on that gap is the
    // single most irritating megamenu bug.
    this.closeTimer = setTimeout(() => this.open.set(null), 250);
  }

  /** Keyboard and touch both land here (section 23.1 rule 4). */
  protected toggle(slug: string, event: Event): void {
    event.preventDefault();
    this.clearTimers();
    this.open.update((current) => (current === slug ? null : slug));
  }

  protected close(): void {
    this.clearTimers();
    this.open.set(null);
  }

  protected dismissAnnouncement(): void {
    this.announcementVisible.set(false);
  }

  private clearTimers(): void {
    if (this.openTimer) clearTimeout(this.openTimer);
    if (this.closeTimer) clearTimeout(this.closeTimer);
    this.openTimer = null;
    this.closeTimer = null;
  }
}
