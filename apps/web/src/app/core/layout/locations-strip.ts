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
    <section aria-labelledby="locations-heading" class="border-t border-ink-100 bg-white">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-14">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
          <h2 id="locations-heading" class="font-heading text-display-sm font-semibold">
            See it before you buy
          </h2>
          <a
            routerLink="/locations/"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline"
          >
            All locations
            <ol-icon name="arrow-right" [size]="16" />
          </a>
        </div>

        <ul class="mt-8 grid gap-6 md:grid-cols-3">
          @for (location of locations; track location.name; let i = $index) {
            <li
              olReveal="scale"
              [olRevealDelay]="i * 90"
              class="ol-lift group overflow-hidden rounded-2xl border border-ink-100 shadow-xs"
            >
              <!--
                A photograph where there is one, the icon where there is not.

                The photographs are the missing half: a supplier telling you
                to come and look at the furniture, without showing you the
                place, is asking for a trip on trust. The field is here and
                the layout is here; the files are not, and drawing a fake
                storefront would be worse than the icon.

                Served from public/images/locations/ rather than the media
                pipeline: these are three fixed brand assets that never
                change per environment, not catalogue content somebody
                uploads, so a content-addressed store buys nothing.

                The photo sits in an .ol-zoom-frame so it drifts in a touch on
                card hover - the one cinematic beat this component earns, given
                these are the only real photographs on the page.
              -->
              @if (location.photo) {
                <div class="ol-zoom-frame aspect-[4/3] w-full">
                  <img
                    [src]="location.photo"
                    [alt]="location.alt"
                    width="600"
                    height="450"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover"
                  />
                </div>
              }

              <div class="p-6">
                @if (!location.photo) {
                  <span class="inline-flex rounded-xl bg-brand-50 p-2.5 text-brand-600">
                    <ol-icon [name]="location.icon" [size]="22" />
                  </span>
                }

                <h3 [class]="location.photo ? 'font-heading text-lg font-semibold' : 'mt-4 font-heading text-lg font-semibold'">{{ location.name }}</h3>

              <address class="mt-2 text-sm not-italic leading-relaxed text-ink-600">
                {{ location.address }}
              </address>

              @if (location.directions) {
                <a
                  [href]="location.directions"
                  rel="noopener"
                  class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline"
                >
                  <ol-icon name="map-pin" [size]="16" />
                  Get directions
                </a>
              } @else {
                <p class="mt-4 inline-flex items-center gap-1.5 text-sm text-ink-400">
                  <ol-icon name="building-2" [size]="16" />
                  Registered office
                </p>
              }
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
      photo: '/images/locations/head-office.webp',
      alt: 'The OfficeLand counter inside Century 21 Mall, with HP authorised reseller signage and shelves of stationery and IT supplies',
      icon: 'building-2' as const,
      address: 'Century 21 Mall, 14B Idowu Martins Street, Victoria Island, Lagos',
      directions:
        'https://www.google.com/maps/search/?api=1&query=Office+Land+Ltd+14B+Idowu+Martins+Street+Victoria+Island+Lagos',
    },
    {
      name: 'Furniture Showroom',
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
      photo: '/images/locations/dubai-office.jpg',
      alt: 'High-rise towers at Jumeirah Lakes Towers, Dubai',
      icon: 'building-2' as const,
      address:
        'Office Land Solutions DMCC, Unit 808-001 Platinum Tower, Plot JLT-PH1-12, Jumeirah Lakes Towers, Dubai, UAE',
      directions: null,
    },
  ];
}
