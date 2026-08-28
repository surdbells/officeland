import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { ProductCard as ProductCardData } from '../../core/api/catalogue-api';
import { Icon } from '../../shared/icon';
import { ProductCard } from '../../shared/product-card';

/**
 * One department's products on the homepage.
 *
 * ── Why the layout varies by section ─────────────────────────────────────
 * Nine identical four-up grids stacked down a page is a spreadsheet. The
 * eye stops reading it after the second one, and every department below
 * that is wasted. Alternating the shape gives each section its own silhouette
 * so a visitor scrolling past registers "a different kind of thing" rather
 * than "more of the same".
 *
 * The variation is structural rather than decorative — different emphasis,
 * not different colours:
 *
 *   grid     an even four-up. The neutral default.
 *   feature  one product at double size, four beside it. Says "this
 *            department has a flagship" and suits furniture and fit-out.
 *   rail     a horizontal scroll. Suits departments with many small,
 *            similar items where breadth matters more than any one product.
 *
 * ── Style is assigned, not random ────────────────────────────────────────
 * The caller passes it, cycling through the three. Random would re-roll on
 * every build, so the homepage would look different for no reason and no
 * screenshot would be reproducible.
 *
 * ── Everything here is a real anchor ─────────────────────────────────────
 * D-009 prerenders this page, so the products and the "all of it" link are
 * in the static HTML. That also means this section is a genuine internal
 * linking surface into all nine departments, which the homepage previously
 * only had via the tile rail.
 */
export type ShowcaseStyle = 'grid' | 'feature' | 'rail';

@Component({
  selector: 'ol-department-showcase',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, ProductCard],
  template: `
    @if (products().length) {
      <section [attr.aria-labelledby]="headingId()" class="border-b border-ink-100">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
          <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h2 [id]="headingId()" class="font-heading text-xl font-semibold sm:text-2xl">
              {{ name() }}
            </h2>
            <a
              [routerLink]="url()"
              class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline"
            >
              All {{ name() }}
              <ol-icon name="arrow-right" [size]="16" />
            </a>
          </div>

          @switch (style()) {
            @case ('feature') {
              <!--
                One large, four small. The large card gets its own sizes
                attribute: at 50vw it is the widest image on the homepage,
                and inheriting the grid default would have the browser
                download a card-sized file and stretch it.
              -->
              <div class="mt-6 grid gap-4 lg:grid-cols-2">
                @if (lead(); as first) {
                  <ol-product-card
                    [product]="first"
                    [headingLevel]="3"
                    imageSizes="(min-width: 1024px) 50vw, 100vw"
                  />
                }

                <ul class="grid gap-4 sm:grid-cols-2">
                  @for (product of rest(); track product.uuid) {
                    <li class="h-full">
                      <ol-product-card [product]="product" [headingLevel]="3" />
                    </li>
                  }
                </ul>
              </div>
            }

            @case ('rail') {
              <!--
                Scrolls, with the scrollbar hidden and no buttons.

                Unlike the department rail at the top of the page this one is
                below the fold and repeated many times; a pair of floating
                buttons on each of three sections is clutter. Touch, trackpad
                and keyboard all still scroll it, and tabbing to an off-screen
                card brings it into view.
              -->
              <ul class="ol-showcase-rail mt-6 flex snap-x gap-4 overflow-x-auto pb-2">
                @for (product of products(); track product.uuid) {
                  <li class="w-56 shrink-0 snap-start sm:w-64">
                    <ol-product-card
                      [product]="product"
                      [headingLevel]="3"
                      imageSizes="16rem"
                    />
                  </li>
                }
              </ul>
            }

            @default {
              <ul class="ol-showcase-grid mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                @for (product of products(); track product.uuid) {
                  <li class="h-full">
                    <ol-product-card [product]="product" [headingLevel]="3" />
                  </li>
                }
              </ul>
            }
          }
        </div>
      </section>
    }
  `,
  styles: `
    /*
     * Ten products fill two rows of five, and two rows of four with the last
     * two hidden. A ragged final row - five then three - reads as a listing
     * that was truncated rather than a section that was composed, and the
     * homepage is the one page where that matters most.
     *
     * Hidden with CSS rather than sliced in the template, so the two extra
     * anchors stay in the prerendered HTML at every breakpoint. Same rule as
     * the overflow departments in the header.
     */
    @media (min-width: 1024px) and (max-width: 1279px) {
      .ol-showcase-grid > li:nth-child(n + 9) {
        display: none;
      }
    }

    /* Same treatment as every other rail here: the scroll stays, the bar goes. */
    .ol-showcase-rail {
      scrollbar-width: none;
    }

    .ol-showcase-rail::-webkit-scrollbar {
      display: none;
    }
  `,
})
export class DepartmentShowcase {
  readonly name = input.required<string>();
  readonly url = input.required<string>();
  readonly slug = input.required<string>();
  readonly products = input.required<readonly ProductCardData[]>();
  readonly style = input<ShowcaseStyle>('grid');

  /** Ties the section landmark to its own heading, so each one is uniquely named. */
  protected readonly headingId = computed(() => `showcase-${this.slug()}`);

  protected readonly lead = computed<ProductCardData | null>(() => this.products()[0] ?? null);

  protected readonly rest = computed(() => this.products().slice(1, 5));
}
