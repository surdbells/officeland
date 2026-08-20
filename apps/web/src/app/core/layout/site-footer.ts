import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Fat footer, on the five-column model of section 23.4 item 10.
 *
 * Three legacy defects are fixed here specifically:
 *
 *  4G  Social links pointed at `http://officeland` — not a valid URL — in five
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
  imports: [RouterLink],
  template: `
    <footer class="mt-16 border-t border-ink-100 bg-ink-50">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-12">
        <div class="grid gap-8 md:grid-cols-5">
          <nav aria-labelledby="footer-shop">
            <h2 id="footer-shop" class="font-heading text-sm font-semibold uppercase tracking-wide">Shop</h2>
            <ul class="mt-3 space-y-2 text-sm text-ink-600">
              <li><a routerLink="/c/office-furniture/" class="hover:text-brand-600">Office furniture</a></li>
              <li><a routerLink="/c/it-products/" class="hover:text-brand-600">IT products</a></li>
              <li><a routerLink="/c/printers-consumables/" class="hover:text-brand-600">Printers &amp; consumables</a></li>
              <li><a routerLink="/c/office-supplies/" class="hover:text-brand-600">Office supplies</a></li>
              <li><a routerLink="/c/paper-notebooks/" class="hover:text-brand-600">Paper &amp; notebooks</a></li>
              <li><a routerLink="/c/filing-storage/" class="hover:text-brand-600">Filing &amp; storage</a></li>
              <li><a routerLink="/c/school-supplies/" class="hover:text-brand-600">School supplies</a></li>
            </ul>
          </nav>

          <nav aria-labelledby="footer-help">
            <h2 id="footer-help" class="font-heading text-sm font-semibold uppercase tracking-wide">Help &amp; support</h2>
            <ul class="mt-3 space-y-2 text-sm text-ink-600">
              <li><a routerLink="/delivery/" class="hover:text-brand-600">Delivery &amp; lead times</a></li>
              <li><a routerLink="/quote/track/" class="hover:text-brand-600">Track my quote</a></li>
              <li><a routerLink="/returns/" class="hover:text-brand-600">Returns</a></li>
              <li><a routerLink="/warranty/" class="hover:text-brand-600">Warranty</a></li>
              <li><a routerLink="/guides/" class="hover:text-brand-600">Buying guides</a></li>
            </ul>
          </nav>

          <nav aria-labelledby="footer-company">
            <h2 id="footer-company" class="font-heading text-sm font-semibold uppercase tracking-wide">Company</h2>
            <ul class="mt-3 space-y-2 text-sm text-ink-600">
              <li><a routerLink="/about/" class="hover:text-brand-600">About OfficeLand</a></li>
              <li><a routerLink="/locations/" class="hover:text-brand-600">Showrooms</a></li>
              <li><a routerLink="/bulk-supply/" class="hover:text-brand-600">Bulk &amp; tender supply</a></li>
              <li><a routerLink="/brands/" class="hover:text-brand-600">Brands we supply</a></li>
              <li><a routerLink="/contact/" class="hover:text-brand-600">Contact</a></li>
            </ul>
          </nav>

          <nav aria-labelledby="footer-policies">
            <h2 id="footer-policies" class="font-heading text-sm font-semibold uppercase tracking-wide">Policies</h2>
            <ul class="mt-3 space-y-2 text-sm text-ink-600">
              <li><a routerLink="/privacy/" class="hover:text-brand-600">Privacy &amp; NDPA</a></li>
              <li><a routerLink="/terms/" class="hover:text-brand-600">Terms</a></li>
              <li><a routerLink="/cookies/" class="hover:text-brand-600">Cookies</a></li>
            </ul>
          </nav>

          <div>
            <h2 class="font-heading text-sm font-semibold uppercase tracking-wide">Contact</h2>
            <address class="mt-3 space-y-2 text-sm not-italic text-ink-600">
              <p>Century 21 Mall, 14B Idowu Martins Street,<br />Victoria Island, Lagos</p>
              <!-- Plain text, machine-readable (legacy defect 4H). -->
              <p><a href="mailto:sales@officelandng.com" class="hover:text-brand-600">sales&#64;officelandng.com</a></p>
              <p><a href="tel:+2347074723165" class="hover:text-brand-600">+234 707 472 3165</a></p>
            </address>
            <!--
              Social profile links are intentionally absent until the real URLs
              are supplied (Q17). The legacy footer linked five platforms to
              http://officeland (not a URL), and those links also feed
              Organization.sameAs, so asserting broken URLs as the company
              identity is worse than asserting none.
            -->
          </div>
        </div>

        <p class="mt-10 border-t border-ink-100 pt-6 text-sm text-ink-400">
          &copy; {{ year }} OfficeLand Limited. Office supplies, IT and furniture for business,
          schools and government across Nigeria.
        </p>
      </div>
    </footer>
  `,
})
export class SiteFooter {
  // Static per render. Under D-009 pages are prerendered, so this is the year
  // at build time — which is why a full rebuild is part of the deploy routine.
  protected readonly year = new Date().getFullYear();
}
