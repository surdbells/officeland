import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Icon, type IconName } from '../../shared/icon';
import { iconForCategory, iconForDepartment } from './category-icons';
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
  imports: [RouterLink, Icon],
  template: `
    <!-- The skip link moved to the shell when the masthead took over as the
         first thing in the document; it has to stay the first focusable
         element, and the header is no longer first. -->

    <!-- One rotating service promise, not a discount shout (section 23.1).

         A <section> with an accessible name rather than a <div>: it sits
         outside <header>, so as a plain div its text belonged to no landmark
         and was skipped entirely by screen-reader landmark navigation. Caught
         by the axe-core gate (Phase 13c). -->
    @if (announcementVisible()) {
      <section aria-label="Service announcement" class="bg-ink-900 text-white text-sm">
        <div class="mx-auto flex max-w-(--container-page) items-center justify-between gap-4 px-(--spacing-gutter) py-2">
          <p class="truncate">Quotes answered within 1 business day · Nationwide delivery &amp; installation</p>
          <button
            type="button"
            class="shrink-0 rounded px-2 py-1 text-white/70 hover:text-white"
            aria-label="Dismiss announcement"
            (click)="dismissAnnouncement()"
          >&times;</button>
        </div>
      </section>
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
        <!-- No wordmark here. The masthead above carries the brand and the
             link home; a second mark in the sticky bar duplicated it. -->

        <!-- Search is a real form so it works before JS loads. -->
        <!-- Named because several pages carry a second search landmark; without
             distinct names a screen reader offers two identical "search"
             regions and neither says which is which (Phase 13c). -->
        <form action="/search" method="get" role="search" aria-label="Search the catalogue" class="hidden flex-1 md:block md:max-w-2xl">
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
      <!--
        Departments. Level 1 and 2 are real anchors in the HTML.

        "relative" sits on the NAV, not on each item, so the panel can span the
        full container width. Anchored to the list item it could only ever be
        as wide as one department label allows.

        ── One line, never two ──────────────────────────────────────────────
        Nine departments with icons do not fit across a 1366 laptop, and a
        navigation bar that wraps onto a second line reads as an accident. So
        the row never wraps: every label is nowrap and the list does not wrap
        either.

        What it does instead is show the first six and put the rest behind
        "More". Six is a fixed split rather than a measured one, because
        measuring means JavaScript, and under D-009 the bar has to be correct
        in the prerendered HTML before any script runs.

        The overflow departments are still in that HTML. They are hidden with
        a class, never with @if — an @if would remove three department
        anchors from every page on the site, which is exactly the internal
        linking the megamenu exists to provide. Same rule as the PDP gallery.
      -->
      <nav aria-label="Departments" class="relative hidden border-t border-ink-100 md:block">
        <ul class="mx-auto flex max-w-(--container-page) flex-nowrap items-stretch gap-0.5 px-(--spacing-gutter)">
          @for (department of departments(); track department.slug; let i = $index) {
            <li
              class="shrink-0"
              [class.hidden]="i >= visibleDepartments && !moreOpen()"
              (mouseenter)="openWithIntent(department.slug)"
              (mouseleave)="closeWithIntent()"
            >
              <!-- The department itself is a link AND the panel trigger. A
                   button alone would make the department page unreachable
                   without JS; a link alone would make the panel unopenable by
                   keyboard. -->
              <a
                [routerLink]="department.url"
                class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-2.5 py-3 text-sm font-medium transition-colors"
                [class.border-brand-600]="open() === department.slug"
                [class.text-brand-600]="open() === department.slug"
                [class.border-transparent]="open() !== department.slug"
                [attr.aria-expanded]="open() === department.slug"
                [attr.aria-controls]="'panel-' + department.slug"
                (keydown.enter)="toggle(department.slug, $event)"
                (keydown.space)="toggle(department.slug, $event)"
                (focus)="open.set(department.slug)"
              >
                <ol-icon [name]="iconForDept(department.slug)" [size]="17" class="text-ink-400" />
                {{ department.name }}
                @if (department.children.length) {
                  <ol-icon name="chevron-down" [size]="14" />
                }
              </a>
            </li>
          }

          <!--
            The overflow toggle. A real button: it discloses, it does not
            navigate. Hidden when every department already fits, so it does
            not appear on a taxonomy with six or fewer.
          -->
          @if (departments().length > visibleDepartments) {
            <li class="shrink-0">
              <button
                type="button"
                class="flex items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2.5 py-3 text-sm font-medium text-ink-600 transition-colors hover:text-brand-600"
                [attr.aria-expanded]="moreOpen()"
                (click)="moreOpen.set(!moreOpen())"
              >
                <ol-icon name="ellipsis" [size]="17" />
                {{ moreOpen() ? 'Less' : 'More' }}
              </button>
            </li>
          }

          <li class="ml-auto flex shrink-0 items-center">
            <a
              routerLink="/shop/"
              class="flex items-center gap-1.5 whitespace-nowrap px-2.5 py-3 text-sm font-semibold text-brand-600 hover:underline"
            >
              <ol-icon name="sliders-horizontal" [size]="16" />
              Shop all
            </a>
          </li>
        </ul>

        <!--
          One panel for whichever department is open, rendered OUTSIDE the
          loop.

          Only the open department's subtree is ever in the DOM — that is
          §23.1 rule 2's "level 3 on intent", and it is what keeps the header
          under the ~150-anchor budget. Rendering all seven departments' trees
          eagerly would put roughly 250 anchors on every page and dilute the
          crawl signal the megamenu exists to provide.

          Absolute, so opening it shifts nothing (rule 5). Measured CLS is 0
          and the gate protects that.
        -->
        @if (activeDepartment(); as active) {
          <div
            [id]="'panel-' + active.slug"
            class="absolute inset-x-0 top-full z-50 border-t border-ink-100 bg-white shadow-xl"
            (mouseenter)="cancelClose()"
            (mouseleave)="closeWithIntent()"
          >
            <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
              <div class="grid gap-8 lg:grid-cols-[14rem_1fr]">
                <!--
                  The department rail. Lets a visitor move between departments
                  without closing and reopening the menu, which is the whole
                  ergonomic advantage of this pattern over a per-item dropdown.
                -->
                <div class="hidden border-r border-ink-100 pr-6 lg:block">
                  <h3 class="font-heading text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">
                    Departments
                  </h3>
                  <ul class="mt-3 space-y-0.5">
                    @for (department of departments(); track department.slug) {
                      <li>
                        <a
                          [routerLink]="department.url"
                          class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-sm transition-colors hover:bg-brand-50 hover:text-brand-700"
                          [class.bg-brand-50]="department.slug === active.slug"
                          [class.font-semibold]="department.slug === active.slug"
                          [class.text-brand-700]="department.slug === active.slug"
                          (mouseenter)="open.set(department.slug)"
                          (focus)="open.set(department.slug)"
                        >
                          <span class="flex items-center gap-2.5">
                            <ol-icon
                              [name]="iconForDept(department.slug)"
                              [size]="17"
                              [class]="department.slug === active.slug ? 'text-brand-600' : 'text-ink-400'"
                            />
                            {{ department.name }}
                          </span>
                          <ol-icon name="chevron-right" [size]="14" class="text-ink-400" />
                        </a>
                      </li>
                    }
                  </ul>
                </div>

                <div>
                  <!--
                    Level 2 as a column heading, level 3 beneath it. This is
                    the shape the taxonomy already has (department →
                    subcategory → leaf), so the menu mirrors the URL structure
                    rather than inventing a second one.
                  -->
                  <div class="grid gap-x-8 gap-y-7 sm:grid-cols-2 lg:grid-cols-4">
                    @for (group of active.children; track group.slug) {
                      <div>
                        <!--
                          The subcategory heading carries its icon.

                          Decorative, and it has to stay that way: the icon is
                          inside the link, so if it were exposed to assistive
                          technology it would become part of the link's name
                          and every menu item would announce a glyph before
                          its label. Icon already sets aria-hidden.

                          Level 3 stays text. Forty-two icons is a scan aid;
                          a hundred and nineteen is wallpaper, and every one
                          of them is bytes in the bundle.
                        -->
                        <h3 class="border-b border-ink-100 pb-2">
                          <a
                            [routerLink]="group.url"
                            class="flex items-center gap-2 font-heading text-sm font-semibold text-ink-900 hover:text-brand-600"
                          >
                            <ol-icon [name]="iconFor(group.slug)" [size]="18" class="text-brand-600" />
                            {{ group.name }}
                          </a>
                        </h3>

                        @if (group.children.length) {
                          <ul class="mt-3 space-y-1.5">
                            @for (leaf of group.children; track leaf.slug) {
                              <li>
                                <a
                                  [routerLink]="leaf.url"
                                  class="text-sm text-ink-600 hover:text-brand-600"
                                >{{ leaf.name }}</a>
                              </li>
                            }
                          </ul>
                        }
                      </div>
                    }
                  </div>

                  <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-ink-100 pt-5">
                    <a
                      [routerLink]="active.url"
                      class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
                    >
                      All {{ active.name }}
                      <ol-icon name="arrow-right" [size]="16" />
                    </a>

                    <!--
                      Brands as TEXT, not logos.

                      Logos would need licensed files per brand and a decision
                      about trademark use; the names are already in the
                      navigation payload, they are crawlable, and they carry
                      the same "we stock this" signal. Logos can replace them
                      when the assets exist without changing this structure.
                    -->
                    @if (brands().length) {
                      <p class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <span class="font-heading text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">
                          Brands
                        </span>
                        @for (brand of brands(); track brand.slug) {
                          <a
                            [routerLink]="brand.url"
                            class="font-medium text-ink-600 hover:text-brand-600"
                          >{{ brand.name }}</a>
                        }
                      </p>
                    }
                  </div>
                </div>
              </div>
            </div>
          </div>
        }
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

  /**
   * How many departments the header bar shows before "More".
   *
   * A fixed number, not a measured one. Measuring requires JavaScript and a
   * layout pass, and under D-009 the bar has to be right in the prerendered
   * HTML before any of that happens — a bar that reflows on hydration is a
   * bar that shifts the page.
   *
   * Six is what fits with icons on a 1366 laptop, which is the narrowest
   * desktop this bar is shown on at all (it is hidden below md). Raising it
   * without checking that width is how the row ends up on two lines again.
   */
  protected readonly visibleDepartments = 6;

  protected readonly moreOpen = signal(false);

  /** The glyph for a department, in the bar and in the panel rail. */
  protected iconForDept(slug: string): IconName {
    return iconForDepartment(slug);
  }

  protected readonly departments = computed<readonly NavigationNode[]>(
    () => this.navigation()?.departments ?? [],
  );

  /**
   * The glyph for a subcategory heading.
   *
   * A method, not a pipe or a computed: it is a pure lookup over a constant,
   * called once per subcategory heading, and only for the one department whose
   * panel is open — six times on the widest of them.
   */
  protected iconFor(slug: string): IconName {
    return iconForCategory(slug);
  }

  /**
   * Brands for the panel footer. Text links rather than logos -- the names
   * are already in the payload, they are crawlable, and logos would need
   * licensed files plus a trademark decision per brand.
   */
  protected readonly brands = computed(() => this.navigation()?.brands ?? []);

  /**
   * The open department, resolved once rather than re-scanned per column.
   *
   * Returns null when nothing is open OR when the open department has no
   * children, so a childless department behaves as a plain link instead of
   * opening an empty panel.
   */
  protected readonly activeDepartment = computed<NavigationNode | null>(() => {
    const slug = this.open();

    if (slug === null) {
      return null;
    }

    const match = this.departments().find((d) => d.slug === slug);

    return match && match.children.length > 0 ? match : null;
  });

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

  /**
   * Keeps the panel open while the pointer is inside it.
   *
   * Without this the panel closes the moment the pointer leaves the trigger,
   * which makes every link in it unreachable by mouse.
   */
  protected cancelClose(): void {
    this.clearTimers();
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
