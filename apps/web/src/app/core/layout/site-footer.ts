import { ChangeDetectionStrategy, Component } from '@angular/core';
import { Icon } from '../../shared/icon';
import { RouterLink } from '@angular/router';

/**
 * Fat footer, on the five-column model of section 23.4 item 10, restyled to
 * the dark-band reference the client supplied.
 *
 * ── Three bands, not one block ───────────────────────────────────────────
 * A call to action, then the links, then the legal line. The reference site
 * uses the top band for a loyalty scheme signup; there is no equivalent here
 * (D-002: no prices, no accounts, no orders), so it carries the only action
 * this business actually wants from a visitor who has reached the bottom of a
 * page - send us the list and we will price it.
 *
 * ── Dark, and the contrast is deliberate ─────────────────────────────────
 * Link colour is white at 70% over ink-900, not an ink token. The ink ramp is
 * built for dark text on light ground: ink-400 (#68717d) is the right weight
 * for a secondary link on white and is 3.4:1 on ink-900, which fails AA
 * outright. Reversing a palette is not a matter of swapping its two ends, and
 * the a11y gate asserts the result.
 *
 * Three legacy defects are fixed here specifically:
 *
 *  4G  Social links pointed at `http://officeland` - not a valid URL - in five
 *      places. They are OMITTED entirely until the real profile URLs arrive
 *      (Q17), because a broken link is worse than no link and these also feed
 *      Organization.sameAs.
 *  4H  Emails rendered as `[email protected]`, machine-unreadable for entity
 *      extraction. They are plain text and marked up.
 *  4K  No discernible internal linking. The footer is a real link surface.
 */
@Component({
  selector: 'ol-site-footer',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    <footer class="mt-16 bg-ink-900 text-white">
      <!--
        Band 1: the ask.

        Eyebrow, headline and the two actions on one line - the reference
        footer's top band. On a page somebody has scrolled to the bottom of,
        this is the last chance to turn a browse into a quote.
      -->
      <div class="border-b border-white/10">
        <div class="mx-auto flex max-w-(--container-page) flex-col items-start justify-between gap-6 px-(--spacing-gutter) py-12 lg:flex-row lg:items-center">
          <div>
            <p class="font-heading text-xs font-semibold uppercase tracking-[0.16em] text-accent-400">Bulk &amp; tender supply</p>
            <h2 class="mt-2 text-2xl font-bold text-white sm:text-3xl">Supplying a school, ministry or head office?</h2>
          </div>
          <div class="flex flex-wrap gap-3">
            <a
              routerLink="/quote/"
              class="inline-flex items-center gap-2 rounded-full bg-white px-7 py-3.5 font-semibold text-ink-900 transition-colors hover:bg-brand-50"
            >
              <ol-icon name="file-text" [size]="18" />
              Request a quote
            </a>
            <a
              href="https://wa.me/2347074723165"
              rel="noopener"
              class="inline-flex items-center gap-2 rounded-full border border-white/40 px-7 py-3.5 font-semibold text-white transition-colors hover:bg-white/10"
            >
              <ol-icon name="message-circle" [size]="18" />
              WhatsApp us
            </a>
          </div>
        </div>
      </div>

      <!-- Band 2: the link surface (legacy defect 4K). -->
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
        <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-6">
          <!--
            Brand column, the reference footer's first column: identity, what
            the business is, and the one certification that qualifies it for a
            government tender. Social is still omitted until the real URLs
            arrive (Q17) - a broken link is worse than none, and these feed
            Organization.sameAs.
          -->
          <div class="md:col-span-2">
            <p class="font-heading text-xl font-extrabold tracking-tight text-white">OFFICE LAND LTD</p>
            <p class="mt-1 font-heading text-xs font-semibold uppercase tracking-[0.14em] text-white/50">
              Office furniture &middot; IT products &middot; Stationery
            </p>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/70">
              Nigeria's office supply partner. Furniture, IT and consumables for businesses,
              schools and government, with two Victoria Island showrooms and nationwide
              delivery and installation.
            </p>
            <p class="mt-5 flex max-w-sm items-start gap-2.5 rounded-xl bg-white/5 p-3 text-sm text-white/70">
              <ol-icon name="badge-check" [size]="18" class="mt-0.5 text-white" />
              <span>Certified by the <strong class="font-medium text-white">Standards Organisation of Nigeria</strong></span>
            </p>
          </div>

          <nav aria-labelledby="footer-shop">
            <h2 id="footer-shop" class="font-heading text-sm font-semibold uppercase tracking-wide text-white">Shop</h2>
            <ul class="mt-4 space-y-2.5 text-sm">
              <li><a routerLink="/c/office-furniture/" class="text-white/70 hover:text-white">Office furniture</a></li>
              <li><a routerLink="/c/it-products/" class="text-white/70 hover:text-white">IT products</a></li>
              <li><a routerLink="/c/smart-tech/" class="text-white/70 hover:text-white">Smart tech</a></li>
              <li><a routerLink="/c/printers-consumables/" class="text-white/70 hover:text-white">Printers &amp; consumables</a></li>
              <li><a routerLink="/c/office-supplies/" class="text-white/70 hover:text-white">Office supplies</a></li>
              <li><a routerLink="/c/paper-notebooks/" class="text-white/70 hover:text-white">Paper &amp; notebooks</a></li>
              <li><a routerLink="/c/filing-storage/" class="text-white/70 hover:text-white">Filing &amp; storage</a></li>
              <li><a routerLink="/c/school-supplies/" class="text-white/70 hover:text-white">School supplies</a></li>
              <li><a routerLink="/c/pvc-doors-interiors/" class="text-white/70 hover:text-white">PVC doors &amp; interiors</a></li>
            </ul>
          </nav>

          <nav aria-labelledby="footer-help">
            <h2 id="footer-help" class="font-heading text-sm font-semibold uppercase tracking-wide text-white">Help &amp; support</h2>
            <ul class="mt-4 space-y-2.5 text-sm">
              <li><a routerLink="/delivery/" class="text-white/70 hover:text-white">Delivery &amp; lead times</a></li>
              <li><a routerLink="/quote/track/" class="text-white/70 hover:text-white">Track my quote</a></li>
              <li><a routerLink="/returns/" class="text-white/70 hover:text-white">Returns</a></li>
              <li><a routerLink="/warranty/" class="text-white/70 hover:text-white">Warranty</a></li>
              <li><a routerLink="/guides/" class="text-white/70 hover:text-white">Buying guides</a></li>
              <li><a routerLink="/shop/" class="text-white/70 hover:text-white">Shop the catalogue</a></li>
            </ul>
          </nav>

          <nav aria-labelledby="footer-company">
            <h2 id="footer-company" class="font-heading text-sm font-semibold uppercase tracking-wide text-white">Company</h2>
            <ul class="mt-4 space-y-2.5 text-sm">
              <li><a routerLink="/about/" class="text-white/70 hover:text-white">About OfficeLand</a></li>
              <li><a routerLink="/locations/" class="text-white/70 hover:text-white">Showrooms</a></li>
              <li><a routerLink="/bulk-supply/" class="text-white/70 hover:text-white">Bulk &amp; tender supply</a></li>
              <li><a routerLink="/brands/" class="text-white/70 hover:text-white">Brands we supply</a></li>
              <li><a routerLink="/contact/" class="text-white/70 hover:text-white">Contact</a></li>
              <li class="pt-2"><a routerLink="/privacy/" class="text-white/70 hover:text-white">Privacy &amp; NDPA</a></li>
              <li><a routerLink="/terms/" class="text-white/70 hover:text-white">Terms</a></li>
              <li><a routerLink="/cookies/" class="text-white/70 hover:text-white">Cookies</a></li>
            </ul>
          </nav>

          <div>
            <h2 class="font-heading text-sm font-semibold uppercase tracking-wide text-white">Contact</h2>
            <address class="mt-4 space-y-3 text-sm not-italic">
              <p class="flex items-start gap-2.5">
                <ol-icon name="map-pin" [size]="18" class="mt-0.5 text-white/50" />
                <span class="text-white/70">Century 21 Mall, 14B Idowu Martins Street,<br />Victoria Island, Lagos</span>
              </p>
              <!-- Plain text, machine-readable (legacy defect 4H). The icon is
                   decorative; the address IS the label. -->
              <p class="flex items-center gap-2.5">
                <ol-icon name="mail" [size]="18" class="text-white/50" />
                <a href="mailto:sales@officelandng.com" class="ol-wrap-anywhere text-white/70 hover:text-white">sales&#64;officelandng.com</a>
              </p>
              <p class="flex items-center gap-2.5">
                <ol-icon name="mail" [size]="18" class="text-white/50" />
                <a href="mailto:support@officelandng.com" class="ol-wrap-anywhere text-white/70 hover:text-white">support&#64;officelandng.com</a>
              </p>
              <p class="flex items-center gap-2.5">
                <ol-icon name="phone" [size]="18" class="text-white/50" />
                <a href="tel:+2347074723165" class="text-white/70 hover:text-white">+234 707 472 3165</a>
              </p>
              <p class="flex items-center gap-2.5">
                <ol-icon name="message-circle" [size]="18" class="text-white/50" />
                <a href="https://wa.me/2347074723165" rel="noopener" class="text-white/70 hover:text-white">WhatsApp us</a>
              </p>
            </address>

            <!--
              SON certification now lives in the brand column with the rest of
              the identity, where the reference footer carries its trust row.
              Social profile links stay absent until the real URLs arrive (Q17):
              a broken link is worse than none and they feed Organization.sameAs.
            -->
          </div>
        </div>
      </div>

      <!-- Band 3: the legal line. -->
      <div class="border-t border-white/10">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-6 text-sm text-white/50">
          <p>
            &copy; {{ year }} OfficeLand Limited. Office supplies, IT and furniture for business,
            schools and government across Nigeria.
          </p>
          <p class="mt-2">
            Quotations hold for the period stated on the quotation. Lead times are stated
            per line and confirmed at order.
          </p>
        </div>
      </div>
    </footer>
  `,
  styles: `
    /*
     * Email addresses are long unbreakable strings, and in the narrow column
     * this footer uses at tablet widths they set the column's minimum width
     * and pushed the whole document into a horizontal scrollbar. Found by
     * measuring scrollWidth against clientWidth in the browser; nothing in
     * the build would have flagged it.
     *
     * "anywhere" rather than "break-word" on purpose: only "anywhere"
     * affects the min-content size, which is the measurement that was
     * causing the overflow. break-word wraps the text and leaves the box
     * just as wide.
     */
    .ol-wrap-anywhere {
      overflow-wrap: anywhere;
    }
  `,
})
export class SiteFooter {
  // Static per render. Under D-009 pages are prerendered, so this is the year
  // at build time - which is why a full rebuild is part of the deploy routine.
  protected readonly year = new Date().getFullYear();
}
