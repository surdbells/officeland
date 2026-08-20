import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { CatalogueApi } from './core/api/catalogue-api';
import { SiteFooter } from './core/layout/site-footer';
import { SiteHeader } from './core/layout/site-header';
import { SeoService } from './core/seo/seo.service';
import { organizationSchema, webSiteSchema } from './core/seo/structured-data';

/**
 * Application shell.
 *
 * Fetches navigation once and passes it down. Under D-009 this runs at build
 * time, so the megamenu's level 1 and 2 anchors are baked into every page —
 * which is what makes deep categories crawlable (section 23.1 rule 1).
 */
@Component({
  selector: 'app-root',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterOutlet, SiteHeader, SiteFooter],
  template: `
    <ol-site-header [navigation]="navigation()" [quoteCount]="quoteCount()" />
    <main id="main" tabindex="-1">
      <router-outlet />
    </main>
    <ol-site-footer />
  `,
})
export class App {
  private readonly api = inject(CatalogueApi);
  private readonly seo = inject(SeoService);

  protected readonly quoteCount = signal(0);

  /**
   * Navigation failing must not take the page down. A category page without a
   * megamenu is degraded; a category page that throws is gone.
   */
  protected readonly navigation = toSignal(
    this.api.navigation().pipe(catchError(() => of(null))),
    { initialValue: null },
  );

  constructor() {
    /*
     * Organization and WebSite are site-wide (section 14.4), so they are set
     * once here. Page-level nodes (Product, BreadcrumbList, ItemList) are
     * added by each route in a separate scope, so a route setting its own
     * nodes replaces those rather than wiping these.
     *
     * sameAs is empty until the real social URLs arrive (Q17).
     */
    this.seo.setSiteStructuredData([organizationSchema(), webSiteSchema()]);
  }
}
