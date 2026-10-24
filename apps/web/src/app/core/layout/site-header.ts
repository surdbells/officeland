import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, NavigationSkipped, Router, RouterLink } from '@angular/router';
import { filter } from 'rxjs';
import { Icon, type IconName } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import { SiteSearch } from '../../shared/site-search';
import { iconForCategory, iconForDepartment } from './category-icons';
import { CatalogueApi, type ProductCard } from '../api/catalogue-api';
import type { Announcement, Navigation, NavigationNode } from '../api/catalogue-api';

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
 *  5. No layout shift when a panel opens - it overlays rather than pushes.
 */
@Component({
  selector: 'ol-site-header',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, ResponsiveImage, SiteSearch],
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
      <section aria-label="Service announcement" class="text-sm" [class]="announcementClass()">
        <div class="mx-auto flex max-w-(--container-page) items-center justify-between gap-4 px-(--spacing-gutter) py-2">
          @if (announcement(); as a) {
            <!-- Admin-curated banner. One line; an optional link makes the
                 whole thing actionable without a second element. -->
            <p class="truncate">
              {{ a.message }}
              @if (a.url) {
                @if (isExternal(a.url)) {
                  <a [href]="a.url" rel="noopener" class="ml-2 font-semibold underline underline-offset-2">{{ a.link_label || 'Learn more' }}</a>
                } @else {
                  <a [routerLink]="a.url" class="ml-2 font-semibold underline underline-offset-2">{{ a.link_label || 'Learn more' }}</a>
                }
              }
            </p>
          } @else {
            <!-- The standing service promise, shown when nothing is curated. -->
            <p class="truncate">Quotes answered within 1 business day · Nationwide delivery &amp; installation</p>
          }
          <button
            type="button"
            class="shrink-0 rounded px-2 py-1 opacity-70 hover:opacity-100"
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

        <!--
          Live search. Still a real GET form underneath, so Enter and the
          button both work before hydration; the suggestion list is layered
          on top of something that already functioned.
        -->
        <ol-site-search class="hidden flex-1 md:block" inputId="site-search" />

        <div class="ml-auto flex shrink-0 items-center gap-3">
          <!-- Section 23.1: WhatsApp converts better than a phone number in
               this market, and the reference site has no equivalent.

               The icon is decorative and the text is the accessible name;
               Icon sets aria-hidden itself, so nothing here announces
               "message circle" before the label. -->
          <a
            href="https://wa.me/2347074723165"
            class="hidden items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm font-medium hover:border-brand-600 sm:flex"
            rel="noopener"
          >
            <ol-icon name="message-circle" [size]="17" class="text-brand-600" />
            WhatsApp us
          </a>

          <a
            routerLink="/quote/"
            class="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
          >
            <ol-icon name="file-text" [size]="17" />
            Quote
            @if (quoteCount() > 0) {
              <span class="rounded-full bg-white px-2 text-brand-700">{{ quoteCount() }}</span>
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
        a class, never with @if - an @if would remove three department
        anchors from every page on the site, which is exactly the internal
        linking the megamenu exists to provide. Same rule as the PDP gallery.
      -->
      <nav aria-label="Departments" class="relative hidden border-t border-ink-100 md:block">
        <ul class="ol-dept-row mx-auto flex max-w-(--container-page) flex-nowrap items-stretch gap-0.5 overflow-x-auto px-(--spacing-gutter)">
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

          Only the open department's subtree is ever in the DOM - that is
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
            class="ol-mega-panel absolute inset-x-0 top-full z-50 border-t border-ink-100 bg-white shadow-xl"
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
                  <!--
                    The promo card, at the foot of the department rail.

                    The reference megamenu puts a merchandising tile here and
                    spends it on a seasonal offer. This business has no offers
                    to run - no prices, no deals, no stock (D-002, D-005) - so
                    a "10% off" card would be an invention. What it does have
                    is one thing it wants from a visitor big enough to be
                    browsing a whole department, and that is the schedule.

                    Deliberately the same on every panel. A per-department
                    promo needs per-department copy somebody maintains, and
                    nine cards that go stale are worse than one that stays
                    true.

                    No image. It is sized to take one - this is where a
                    department hero would go now that categories carry them -
                    but a card whose picture is a grey rectangle is worse than
                    a card that never promised one.
                  -->
                  <a
                    routerLink="/bulk-supply/"
                    class="mt-6 block rounded-xl border border-brand-100 bg-brand-50 p-4 transition-colors hover:border-brand-600"
                    (click)="close()"
                  >
                    <span class="flex items-center gap-2 font-heading text-sm font-semibold text-brand-700">
                      <ol-icon name="file-text" [size]="16" />
                      Fitting out a whole floor?
                    </span>
                    <span class="mt-1.5 block text-xs leading-relaxed text-ink-600">
                      Send the requisition schedule as it is. We price every line and
                      state lead times per item.
                    </span>
                    <span class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-600">
                      Bulk &amp; tender supply
                      <ol-icon name="arrow-right" [size]="14" />
                    </span>
                  </a>
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

                  <!--
                    Featured products for this department, lazy-loaded on open.

                    Never in the prerendered HTML: the panel renders only on
                    hover/focus (open() is null at build), so these add nothing
                    to the navigation payload or the served page - the category
                    links above are the crawl surface. Fetched once per
                    department and cached, from the same listing endpoint the
                    PLP uses.
                  -->
                  @if (activeProducts().length) {
                    <div class="mt-7 border-t border-ink-100 pt-5">
                      <h3 class="font-heading text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">
                        Featured in {{ active.name }}
                      </h3>
                      <ul class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
                        @for (product of activeProducts(); track product.uuid) {
                          <li>
                            <a [routerLink]="product.url" class="group block" (click)="close()">
                              <span class="block aspect-square overflow-hidden rounded-lg border border-ink-100 bg-ink-50">
                                @if (product.image; as image) {
                                  <ol-image [image]="image" sizes="8rem" imgClass="h-full w-full object-contain p-2" />
                                }
                              </span>
                              <span class="mt-1.5 block truncate text-xs text-ink-600 group-hover:text-brand-600">
                                {{ product.name }}
                              </span>
                            </a>
                          </li>
                        }
                      </ul>
                    </div>
                  }

                  <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-ink-100 pt-5">
                    <a
                      [routerLink]="active.url"
                      class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
                    >
                      All {{ active.name }}
                      <ol-icon name="arrow-right" [size]="16" />
                    </a>

                    <!--
                      The brands this department actually carries.

                      It used to be one global featured list rendered on every
                      panel, so Office Furniture advertised HP, Canon, Epson
                      and Kingston. A brands row is a claim about what is
                      behind the link, and that one was contradicted by the
                      products. The API now derives it per department from the
                      catalogue, so it is true by construction and empty when
                      there is nothing to say.

                      Logo when there is one, name when there is not. Both are
                      inside the same anchor, so the link is never nameless:
                      the name stays as visible text beside a logo rather than
                      being replaced by it, which also keeps the row crawlable
                      and keeps a wordmark legible at 20px, which most are
                      not.
                    -->
                    @if (active.brands?.length) {
                      <p class="flex flex-wrap items-center gap-x-5 gap-y-3 text-sm">
                        <span class="font-heading text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">
                          Brands
                        </span>
                        @for (brand of active.brands; track brand.slug) {
                          <a
                            [routerLink]="brand.url"
                            class="flex items-center gap-2 font-medium text-ink-600 transition-colors hover:text-brand-600"
                          >
                            @if (brand.logo) {
                              <ol-image
                                [image]="brand.logo"
                                sizes="72px"
                                imgClass="h-5 w-auto max-w-[4.5rem] object-contain"
                              />
                            }
                            {{ brand.name }}
                          </a>
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

    <!--
      Mobile navigation (below md).

      The utility bar, the search box and the department megamenu above are all
      hidden on a phone, where a hover-driven bar does not belong. This gives a
      handset the same reach: a fixed bottom bar - the thumb-friendly place for
      primary navigation on a phone - with the full category tree and the search
      box opening as bottom sheets from it.

      It is a browser-only enhancement, not a second crawl surface: a crawler
      reads the desktop nav above (which is in the DOM, only display:none on
      mobile), so these are @if-gated and add no anchors to the served HTML.
    -->
    <nav aria-label="Mobile navigation" class="ol-bottomnav md:hidden">
      <a routerLink="/" class="ol-bottomnav__item">
        <ol-icon name="home" [size]="21" />
        <span>Home</span>
      </a>
      <button
        type="button"
        class="ol-bottomnav__item"
        [class.is-active]="sheet() === 'menu'"
        [attr.aria-expanded]="sheet() === 'menu'"
        (click)="openSheet('menu')"
      >
        <ol-icon name="menu" [size]="21" />
        <span>Browse</span>
      </button>
      <button
        type="button"
        class="ol-bottomnav__item"
        [class.is-active]="sheet() === 'search'"
        [attr.aria-expanded]="sheet() === 'search'"
        (click)="openSheet('search')"
      >
        <ol-icon name="search" [size]="21" />
        <span>Search</span>
      </button>
      <a routerLink="/quote/" class="ol-bottomnav__item">
        <span class="relative inline-flex">
          <ol-icon name="file-text" [size]="21" />
          @if (quoteCount() > 0) {
            <span class="ol-bottomnav__badge">{{ quoteCount() }}</span>
          }
        </span>
        <span>Quote</span>
      </a>
    </nav>

    @if (sheet() !== null) {
      <div class="ol-sheet-backdrop md:hidden" (click)="closeSheet()" aria-hidden="true"></div>
    }

    @if (sheet() === 'menu') {
      <div class="ol-sheet md:hidden" role="dialog" aria-modal="true" aria-label="Browse departments">
        <span class="ol-sheet__grip" aria-hidden="true"></span>
        <div class="ol-sheet__head">
          <h2 class="font-heading text-base font-bold">Browse</h2>
          <button type="button" class="ol-sheet__close" (click)="closeSheet()" aria-label="Close">
            <ol-icon name="x" [size]="20" />
          </button>
        </div>
        <ul class="ol-sheet__body">
          @for (department of departments(); track department.slug) {
            <li class="border-b border-ink-100">
              <div class="flex items-stretch">
                <a
                  [routerLink]="department.url"
                  class="flex flex-1 items-center gap-3 py-3.5 font-medium text-ink-900"
                >
                  <ol-icon [name]="iconForDept(department.slug)" [size]="20" class="text-brand-600" />
                  {{ department.name }}
                </a>
                @if (department.children.length) {
                  <button
                    type="button"
                    class="flex w-12 shrink-0 items-center justify-center text-ink-400"
                    [attr.aria-expanded]="expanded() === department.slug"
                    [attr.aria-label]="'Show ' + department.name + ' categories'"
                    (click)="toggleExpand(department.slug)"
                  >
                    <ol-icon
                      name="chevron-right"
                      [size]="20"
                      class="transition-transform"
                      [class.rotate-90]="expanded() === department.slug"
                    />
                  </button>
                }
              </div>
              @if (expanded() === department.slug) {
                <ul class="pb-3 pl-11">
                  @for (sub of department.children; track sub.slug) {
                    <li>
                      <a [routerLink]="sub.url" class="block py-2 text-sm text-ink-600 hover:text-brand-600">
                        {{ sub.name }}
                      </a>
                    </li>
                  }
                </ul>
              }
            </li>
          }
        </ul>
        <a routerLink="/c/" class="ol-sheet__foot">
          All departments
          <ol-icon name="arrow-right" [size]="16" />
        </a>
      </div>
    }

    @if (sheet() === 'search') {
      <div class="ol-sheet ol-sheet--search md:hidden" role="dialog" aria-modal="true" aria-label="Search">
        <span class="ol-sheet__grip" aria-hidden="true"></span>
        <div class="ol-sheet__head">
          <h2 class="font-heading text-base font-bold">Search</h2>
          <button type="button" class="ol-sheet__close" (click)="closeSheet()" aria-label="Close">
            <ol-icon name="x" [size]="20" />
          </button>
        </div>
        <div class="px-4 pb-5 pt-1">
          <ol-site-search inputId="mobile-search" />
        </div>
      </div>
    }
  `,
  styles: `
    /*
     * The department row scrolls rather than pushing the page.
     *
     * Six departments plus "More" and "Shop all" fit comfortably at 1366 and
     * above, which is what the fixed split was chosen against. But this bar
     * is shown from 768px up, and between roughly 768 and 1000 the same six
     * do not fit - with flex-nowrap and shrink-0 they widened the document
     * and gave the whole site a horizontal scrollbar. Caught by measuring
     * scrollWidth against clientWidth in the browser, not by any gate.
     *
     * overflow-x on the row contains it: the row scrolls, the page does not,
     * and the single line survives at every width. The panel is a sibling of
     * this list rather than a child, so it is unaffected by the new scroll
     * container.
     */
    .ol-dept-row {
      scrollbar-width: none;
    }

    .ol-dept-row::-webkit-scrollbar {
      display: none;
    }

    /*
     * The panel eases down as it mounts rather than snapping in. It is added
     * to the DOM by @if on open, so an animation (not a transition) plays once
     * each time it appears; there is nothing to animate on close because the
     * element is gone. Short and shallow - a menu that takes its time to open
     * is a menu that feels slow. The global reduced-motion block collapses the
     * duration, so it simply appears.
     */
    .ol-mega-panel {
      animation: ol-mega-in 0.22s var(--ease-out-soft);
      transform-origin: top center;
    }

    @keyframes ol-mega-in {
      from {
        opacity: 0;
        transform: translateY(-8px);
      }
      to {
        opacity: 1;
        transform: none;
      }
    }

    /*
     * Mobile bottom navigation.
     *
     * Fixed to the foot of the viewport - the thumb's reach on a handset - and
     * shown only below md. The hide is done HERE, not with the template's
     * md:hidden: Angular's emulated encapsulation scopes this selector with an
     * attribute, which outranks Tailwind's single-class .md\:hidden, so the
     * utility could never win. Defaulting to display:none and switching to flex
     * under the breakpoint keeps the whole decision inside one specificity
     * class. It carries the safe-area inset so it clears the home indicator on
     * a notched phone, and the global body padding in styles.css reserves its
     * height so it never covers the last of the page.
     */
    .ol-bottomnav {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 50;
      display: none;
      align-items: stretch;
      border-top: 1px solid var(--color-ink-100, #e5e7eb);
      background: #fff;
      padding-bottom: env(safe-area-inset-bottom, 0);
      box-shadow: 0 -1px 8px rgb(15 23 42 / 6%);
    }

    @media (max-width: 767px) {
      .ol-bottomnav {
        display: flex;
      }
    }

    .ol-bottomnav__item {
      flex: 1 1 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.1875rem;
      padding: 0.5rem 0.25rem;
      font-size: 0.6875rem;
      font-weight: 500;
      color: var(--color-ink-500, #6b7280);
      background: none;
      border: 0;
      cursor: pointer;
      min-height: 3.25rem;
    }

    .ol-bottomnav__item.is-active,
    .ol-bottomnav__item:active {
      color: var(--color-brand-600, #2563eb);
    }

    .ol-bottomnav__badge {
      position: absolute;
      top: -0.375rem;
      left: 0.75rem;
      min-width: 1rem;
      height: 1rem;
      padding: 0 0.25rem;
      border-radius: 9999px;
      background: var(--color-brand-600, #2563eb);
      color: #fff;
      font-size: 0.625rem;
      font-weight: 700;
      line-height: 1rem;
      text-align: center;
    }

    /*
     * The sheets slide up from the bar and stop short of the top, so the page
     * behind stays partly visible - a sheet, not a full-screen takeover. The
     * backdrop dims the rest and closes on tap. Both animate in; @if removes
     * them on close, so there is nothing to animate out, and the global
     * reduced-motion block collapses the slide.
     */
    .ol-sheet-backdrop {
      position: fixed;
      inset: 0;
      z-index: 55;
      background: rgb(15 23 42 / 45%);
      animation: ol-backdrop-in 0.2s ease-out;
    }

    .ol-sheet {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 60;
      display: flex;
      flex-direction: column;
      max-height: 80vh;
      border-radius: 1rem 1rem 0 0;
      background: #fff;
      padding-bottom: env(safe-area-inset-bottom, 0);
      box-shadow: 0 -8px 32px rgb(15 23 42 / 18%);
      animation: ol-sheet-in 0.26s var(--ease-out-soft, cubic-bezier(0.22, 1, 0.36, 1));
    }

    .ol-sheet__grip {
      width: 2.25rem;
      height: 0.25rem;
      margin: 0.5rem auto 0;
      border-radius: 9999px;
      background: var(--color-ink-200, #e5e7eb);
    }

    .ol-sheet__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.5rem 1rem 0.75rem;
      border-bottom: 1px solid var(--color-ink-100, #f3f4f6);
    }

    .ol-sheet__close {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.25rem;
      height: 2.25rem;
      margin-right: -0.5rem;
      border-radius: 9999px;
      color: var(--color-ink-500, #6b7280);
      background: none;
      border: 0;
      cursor: pointer;
    }

    .ol-sheet__body {
      flex: 1 1 auto;
      overflow-y: auto;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      padding: 0 1rem;
    }

    .ol-sheet__foot {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.375rem;
      padding: 0.875rem 1rem;
      border-top: 1px solid var(--color-ink-100, #f3f4f6);
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--color-brand-600, #2563eb);
    }

    /*
     * The search sheet drops from the TOP, not up from the bottom.
     *
     * The suggestions list opens directly below the input (position:absolute,
     * top:100%). Anchored at the bottom of the screen like the Browse sheet,
     * the input sat near the foot and its results opened off the bottom edge -
     * typed matches were there but invisible, which read as "search is broken
     * on mobile". From the top, the input is near the top and the whole screen
     * below it is free for results. The sheet keeps overflow visible so the
     * list can extend past the sheet's own short box over the dimmed page.
     */
    .ol-sheet--search {
      top: 0;
      bottom: auto;
      padding-top: env(safe-area-inset-top, 0);
      padding-bottom: 0;
      border-radius: 0 0 1rem 1rem;
      animation: ol-sheet-down 0.26s var(--ease-out-soft, cubic-bezier(0.22, 1, 0.36, 1));
    }

    /* A drag grip belongs on a bottom sheet; at the top of the search sheet it
       is just noise, so it is removed there. */
    .ol-sheet--search .ol-sheet__grip {
      display: none;
    }

    @keyframes ol-sheet-down {
      from {
        transform: translateY(-100%);
      }
      to {
        transform: none;
      }
    }

    @keyframes ol-backdrop-in {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    @keyframes ol-sheet-in {
      from {
        transform: translateY(100%);
      }
      to {
        transform: none;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .ol-sheet,
      .ol-sheet-backdrop {
        animation-duration: 0.01ms;
      }
    }
  `,
  host: {
    '(document:keydown.escape)': 'close()',
  },
})
export class SiteHeader {
  readonly navigation = input<Navigation | null>(null);
  readonly quoteCount = input<number>(0);

  private readonly api = inject(CatalogueApi);
  private readonly destroyRef = inject(DestroyRef);

  /** Featured products per department path, lazy-loaded when its panel opens. */
  private readonly deptProducts = signal<Record<string, readonly ProductCard[]>>({});

  /** Paths already fetched (or in flight), so each department loads once. */
  private readonly deptProductsRequested = new Set<string>();

  protected readonly open = signal<string | null>(null);
  protected readonly announcementVisible = signal(true);

  /**
   * The admin-curated announcement, or null when none is live - in which case
   * the bar shows the standing service promise instead. Carried on the
   * navigation payload (it is global chrome every page loads).
   */
  protected readonly announcement = computed<Announcement | null>(
    () => this.navigation()?.announcement ?? null,
  );

  /** The bar's colour, from the announcement variant; the default promise is neutral. */
  protected announcementClass(): string {
    switch (this.announcement()?.variant) {
      case 'promo':
        return 'bg-brand-600 text-white';
      case 'warning':
        return 'bg-amber-500 text-ink-900';
      default:
        return 'bg-ink-900 text-white';
    }
  }

  /** A full URL leaves the SPA and needs a plain anchor; a path stays in-app. */
  protected isExternal(url: string): boolean {
    return /^https?:\/\//i.test(url);
  }

  /**
   * How many departments the header bar shows before "More".
   *
   * A fixed number, not a measured one. Measuring requires JavaScript and a
   * layout pass, and under D-009 the bar has to be right in the prerendered
   * HTML before any of that happens - a bar that reflows on hydration is a
   * bar that shifts the page.
   *
   * Six is what fits with icons on a 1366 laptop, which is the narrowest
   * desktop this bar is shown on at all (it is hidden below md). Raising it
   * without checking that width is how the row ends up on two lines again.
   */
  protected readonly visibleDepartments = 6;

  protected readonly moreOpen = signal(false);

  /**
   * The open mobile bottom sheet, or null when none is.
   *
   * The desktop megamenu is hover-driven and hidden below md; on a phone the
   * same reach is given by a fixed bottom bar whose Browse and Search buttons
   * raise these sheets. It is a browser-only affordance - the sheets are
   * @if-gated, so they add nothing to the prerendered HTML a crawler reads.
   */
  protected readonly sheet = signal<'menu' | 'search' | null>(null);

  /** The department expanded inside the Browse sheet; one open at a time. */
  protected readonly expanded = signal<string | null>(null);

  constructor() {
    /*
     * Close the menu the instant a navigation lands.
     *
     * The panel opens on hover and used to close only on mouse-leave or
     * Escape, so clicking a department or a subcategory navigated but left the
     * panel sitting over the page it had just loaded - you had to move the
     * pointer away to see where you had gone. Closing on NavigationEnd makes a
     * click reveal the page at once. NavigationSkipped covers clicking the link
     * for the route you are already on, which fires no NavigationEnd but should
     * still shut the menu.
     */
    const router = inject(Router);
    router.events
      .pipe(
        filter((event) => event instanceof NavigationEnd || event instanceof NavigationSkipped),
        takeUntilDestroyed(),
      )
      .subscribe(() => {
        this.clearTimers();
        this.open.set(null);
        this.moreOpen.set(false);
        // A mobile sheet is a navigation surface too: tapping a link in it
        // must reveal the page it loaded, not leave the sheet over it.
        this.sheet.set(null);
        this.expanded.set(null);
      });

    // Lazy-load the open department's featured products. Runs only when a panel
    // is open (hover/focus in the browser); open() is null during prerender, so
    // this adds no build-time request and keeps the navigation payload lean.
    effect(() => {
      const dept = this.activeDepartment();
      if (dept) this.loadDeptProducts(dept);
    });
  }

  /** Fetch a department's featured products once, from the shared listing endpoint. */
  private loadDeptProducts(dept: NavigationNode): void {
    const path = dept.url.replace(/^\/c/, '');
    if (this.deptProductsRequested.has(path)) return;
    this.deptProductsRequested.add(path);

    this.api
      .categoryProducts(path, { perPage: 6 })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (listing) =>
          this.deptProducts.update((map) => ({ ...map, [path]: listing.data })),
        // Leave it absent and allow a later retry; the panel just shows no row.
        error: () => this.deptProductsRequested.delete(path),
      });
  }

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
   * panel is open - six times on the widest of them.
   */
  protected iconFor(slug: string): IconName {
    return iconForCategory(slug);
  }

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

  /** The open department's featured products, or empty while they load. */
  protected readonly activeProducts = computed<readonly ProductCard[]>(() => {
    const dept = this.activeDepartment();
    if (!dept) return [];
    return this.deptProducts()[dept.url.replace(/^\/c/, '')] ?? [];
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
    // Escape also dismisses an open mobile sheet.
    this.sheet.set(null);
  }

  /**
   * Open a bottom sheet, closing the other one if it was up.
   *
   * Opening Search from the menu sheet (or the reverse) swaps them rather than
   * stacking, and a fresh menu opens with every department collapsed.
   */
  protected openSheet(which: 'menu' | 'search'): void {
    this.expanded.set(null);
    this.sheet.set(which);
  }

  protected closeSheet(): void {
    this.sheet.set(null);
    this.expanded.set(null);
  }

  /** Expand a department's subcategories in the Browse sheet; one at a time. */
  protected toggleExpand(slug: string): void {
    this.expanded.update((current) => (current === slug ? null : slug));
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
