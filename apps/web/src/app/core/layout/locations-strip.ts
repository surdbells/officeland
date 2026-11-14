import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Icon } from '../../shared/icon';
import { Reveal } from '../../shared/reveal';

/**
 * The three real locations (docs/BUSINESS_FACTS.md).
 *
 * ── Why this is a component and not part of the footer ───────────────────
 * Two Lagos addresses plus a UAE entity is a **local-search asset**, not
 * footer furniture. For a supplier whose competitors are online-only, "you
 * can walk in and look at the chair" is the differentiator, and burying it
 * under the copyright line wastes it.
 *
 * ── The Dubai entry is deliberately plain ────────────────────────────────
 * Office Land Solutions DMCC is a registered UAE entity, but **whether it
 * trades is unresolved (Q14)**. So it is listed as an office with an address
 * and nothing more: no "visit us", no directions link, and - importantly - it
 * must NOT gain `LocalBusiness` markup, which would tell Google the business
 * serves UAE customers. If the answer to Q14 is that it sells, this needs an
 * hreflang decision, not a styling one.
 *
 * ── No `AggregateRating`, and no map embed ───────────────────────────────
 * The 4.4★/138 reviews are Google's, collected on Google. Marking them up as
 * our own rating is self-serving review markup and against Google's
 * structured-data policy - displaying is fine, claiming is not (Q15).
 *
 * Directions link out rather than embedding a map: a Maps iframe is several
 * hundred KB of third-party JavaScript against a 130 KB total budget, and the
 * CSP has no `frame-src` (D-053). A link gives directions in one tap and
 * costs nothing.
 */
@Component({
  selector: 'ol-locations-strip',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal],
  template: `
    <section aria-labelledby="locations-heading" class="border-t border-ink-100 bg-ink-50">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14 sm:py-16">
        <div olReveal="fade" class="flex flex-wrap items-end justify-between gap-4">
          <div>
            <p class="ol-eyebrow">Showrooms &amp; offices</p>
            <h2 id="locations-heading" class="mt-1 font-heading text-display-sm font-semibold">
              See it before you buy
            </h2>
            <p class="mt-2 max-w-xl text-ink-600">
              Two showrooms on Victoria Island. Sit in the chair, open the drawer, then order.
            </p>
          </div>
          <a
            routerLink="/locations/"
            class="inline-flex items-center gap-1.5 rounded-full border border-brand-600 px-5 py-2.5 text-sm font-semibold text-brand-600 transition-colors hover:bg-brand-600 hover:text-white"
          >
            All locations
            <ol-icon name="arrow-right" [size]="16" />
          </a>
        </div>

        <ul class="mt-8 grid gap-5 md:grid-cols-3 md:gap-6">
          @for (location of locations; track location.name; let i = $index) {
            <li
              olReveal="scale"
              [olRevealDelay]="i * 90"
              class="ol-lift group flex flex-col overflow-hidden rounded-3xl border border-ink-100 bg-white shadow-xs"
            >
              <!--
                The photograph carries the card: the place itself, with its
                kind and its name set over a scrim at the foot. Served from
                public/images/locations/ rather than the media pipeline - three
                fixed brand assets, not catalogue content. The frame zooms a
                touch on hover; a location without a photo keeps a brand ground
                and its icon instead of a drawn-up storefront.
              -->
              <div class="ol-zoom-frame relative isolate aspect-[4/3] w-full overflow-hidden bg-brand-900 text-white">
                @if (location.photo) {
                  <img
                    [src]="location.photo"
                    [alt]="location.alt"
                    width="600"
                    height="450"
                    loading="lazy"
                    decoding="async"
                    class="absolute inset-0 -z-20 h-full w-full object-cover"
                  />
                } @else {
                  <span class="absolute inset-0 -z-20 flex items-center justify-center text-white/40">
                    <ol-icon [name]="location.icon" [size]="56" />
                  </span>
                }
                <span aria-hidden="true" class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-900/85 via-ink-900/20 to-transparent"></span>

                <span class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-ink-900 shadow-sm backdrop-blur">
                  <ol-icon [name]="location.icon" [size]="14" class="text-brand-600" />
                  {{ location.kind }}
                </span>

                <div class="absolute inset-x-0 bottom-0 p-5">
                  <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">{{ location.place }}</p>
                  <h3 class="mt-0.5 font-heading text-xl font-semibold text-white">{{ location.name }}</h3>
                </div>
              </div>

              <div class="flex flex-1 flex-col p-5 sm:p-6">
                <address class="flex gap-2.5 text-sm not-italic leading-relaxed text-ink-600">
                  <ol-icon name="map-pin" [size]="18" class="mt-0.5 shrink-0 text-brand-600" />
                  <span>{{ location.address }}</span>
                </address>

                <div class="mt-auto pt-5">
                  @if (location.directions) {
                    <a
                      [href]="location.directions"
                      rel="noopener"
                      class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                    >
                      Get directions
                      <ol-icon name="arrow-right" [size]="16" class="transition-transform group-hover:translate-x-0.5" />
                    </a>
                  } @else {
                    <p class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-ink-100 bg-ink-50 px-5 py-2.5 text-sm font-medium text-ink-600">
                      <ol-icon name="building-2" [size]="16" />
                      Registered office - not a showroom
                    </p>
                  }
                </div>
              </div>
            </li>
          }
        </ul>
      </div>
    </section>
  `,
})
export class LocationsStrip {
  /**
   * Hardcoded until the `locations` table is seeded (12d).
   *
   * The table has existed since Phase 2 with address, coordinates and
   * opening hours for `LocalBusiness` JSON-LD; it was never populated because
   * nobody had the addresses. They are now known - but opening hours and
   * coordinates still are not, and those are what make the markup worth
   * emitting. This renders the facts we have and no more.
   */
  protected readonly locations = [
    {
      name: 'Head Office',
      kind: 'Head office',
      place: 'Victoria Island, Lagos',
      photo: '/images/locations/head-office.webp',
      alt: 'The OfficeLand counter inside Century 21 Mall, with HP authorised reseller signage and shelves of stationery and IT supplies',
      icon: 'building-2' as const,
      address: 'Century 21 Mall, 14B Idowu Martins Street, Victoria Island, Lagos',
      directions:
        'https://www.google.com/maps/search/?api=1&query=Office+Land+Ltd+14B+Idowu+Martins+Street+Victoria+Island+Lagos',
    },
    {
      name: 'Furniture Showroom',
      kind: 'Showroom',
      place: 'Victoria Island, Lagos',
      photo: '/images/locations/furniture-showroom.webp',
      alt: 'The OfficeLand furniture showroom on Idowu Martins Street, its frontage listing desks, chairs, conference tables and filing cabinets',
      icon: 'store' as const,
      address: 'No 18, Idowu Martins Street, Victoria Island, Lagos State',
      directions:
        'https://www.google.com/maps/search/?api=1&query=Office+Land+Ltd+18+Idowu+Martins+Street+Victoria+Island+Lagos',
    },
    {
      // No directions link, on purpose - see the class comment on Q14.
      name: 'Dubai Office',
      kind: 'Registered office',
      place: 'Jumeirah Lakes Towers, Dubai',
      photo: '/images/locations/dubai-office.jpg',
      alt: 'High-rise towers at Jumeirah Lakes Towers, Dubai',
      icon: 'building-2' as const,
      address:
        'Office Land Solutions DMCC, Unit 808-001 Platinum Tower, Plot JLT-PH1-12, Jumeirah Lakes Towers, Dubai, UAE',
      directions: null,
    },
  ];
}
