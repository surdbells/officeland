import type { Routes } from '@angular/router';

/**
 * Storefront routes (SEO_SPEC.md section 2).
 *
 * Lazy-loaded so the initial JS budget stays within section 14.9's limits
 * (130 KB home, 160 KB PDP, gzipped).
 */
export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/home/home-page').then((m) => m.HomePage),
  },
  {
    path: 'c',
    loadComponent: () =>
      import('./features/catalogue/category-index-page').then((m) => m.CategoryIndexPage),
  },
  {
    // Depth is capped at 3, but the path is captured whole and resolved
    // against the materialized path server-side.
    path: 'c/**',
    loadComponent: () =>
      import('./features/catalogue/category-page').then((m) => m.CategoryPage),
  },
  {
    /*
     * The shop page: the whole catalogue behind one set of filters, for
     * buyers who did not arrive through the taxonomy.
     *
     * Prerendered as a shell and noindex, like /search - the listing depends
     * on query parameters, which a static file cannot vary on, and every
     * filter combination would otherwise be another crawlable URL of the
     * same products (SEO_SPEC §10.3).
     */
    path: 'shop',
    loadComponent: () => import('./features/catalogue/shop-page').then((m) => m.ShopPage),
  },
  /*
   * Editorial pages (Phase 12c/12d/12e).
   *
   * These twelve were linked from the header and footer for months before
   * they existed - 404s on all 194 pages, roughly one internal link in seven.
   * `npm run gate:links` now fails the build if that recurs.
   *
   * Grouped into four lazy chunks rather than twelve. They are static text,
   * a few kilobytes each, and twelve chunks would cost more round trips than
   * the separation is worth.
   */
  {
    path: 'about',
    loadComponent: () => import('./features/pages/company-pages').then((m) => m.AboutPage),
  },
  {
    path: 'contact',
    loadComponent: () => import('./features/pages/company-pages').then((m) => m.ContactPage),
  },
  {
    path: 'locations',
    loadComponent: () => import('./features/pages/company-pages').then((m) => m.LocationsPage),
  },
  {
    path: 'delivery',
    loadComponent: () => import('./features/pages/service-pages').then((m) => m.DeliveryPage),
  },
  {
    path: 'returns',
    loadComponent: () => import('./features/pages/service-pages').then((m) => m.ReturnsPage),
  },
  {
    path: 'warranty',
    loadComponent: () => import('./features/pages/service-pages').then((m) => m.WarrantyPage),
  },
  {
    path: 'bulk-supply',
    loadComponent: () => import('./features/pages/sales-pages').then((m) => m.BulkSupplyPage),
  },
  {
    path: 'guides',
    loadComponent: () => import('./features/pages/sales-pages').then((m) => m.GuidesPage),
  },
  {
    path: 'privacy',
    loadComponent: () => import('./features/pages/policy-pages').then((m) => m.PrivacyPage),
  },
  {
    path: 'terms',
    loadComponent: () => import('./features/pages/policy-pages').then((m) => m.TermsPage),
  },
  {
    path: 'cookies',
    loadComponent: () => import('./features/pages/policy-pages').then((m) => m.CookiesPage),
  },
  {
    path: 'brands',
    loadComponent: () =>
      import('./features/brands/brand-index-page').then((m) => m.BrandIndexPage),
  },
  {
    /*
     * A wildcard rather than 'brands/:slug' so the hub can carry its own
     * `page/N` pagination segments (D-032). BrandPage splits them off.
     */
    path: 'brands/**',
    loadComponent: () => import('./features/brands/brand-page').then((m) => m.BrandPage),
  },
  {
    /*
     * Search is the one catalogue surface rendered at request time rather
     * than at build time (SEO_SPEC.md section 2). It is prerendered as an
     * empty shell so the URL resolves to a file; the results arrive client
     * side, and the page is noindex either way.
     */
    path: 'search',
    loadComponent: () => import('./features/search/search-page').then((m) => m.SearchPage),
  },
  {
    /*
     * The basket and the request form are per-visitor and noindex. They are
     * prerendered as shells so the URLs resolve to files on a static host;
     * their contents arrive client side from the basket cookie.
     */
    path: 'quote',
    loadComponent: () => import('./features/quote/basket-page').then((m) => m.BasketPage),
  },
  {
    /*
     * A help page, not an account page. There is no self-service lookup: a
     * quotation carries commercial pricing, so a lookup has to prove the
     * asker is the person who requested it, and half-building that is worse
     * than saying plainly how to check on a quote today.
     */
    path: 'quote/track',
    loadComponent: () => import('./features/pages/sales-pages').then((m) => m.QuoteTrackPage),
  },
  {
    path: 'quote/request',
    loadComponent: () =>
      import('./features/quote/quote-request-page').then((m) => m.QuoteRequestPage),
  },
  {
    // Flat product URLs (D-008): one product, one URL, whatever its categories.
    path: 'p/:slug',
    loadComponent: () =>
      import('./features/catalogue/product-page').then((m) => m.ProductPage),
  },
  {
    /*
     * An explicit path so the 404 template PRERENDERS to a real file.
     * A wildcard route cannot: Angular has no parameter to render it under,
     * so the build emitted no 404 page at all and Caddy fell back to serving
     * the homepage for every unknown URL - the home content under a 404
     * status, which is neither a 404 page nor a useful one.
     */
    path: '404',
    loadComponent: () => import('./features/errors/not-found-page').then((m) => m.NotFoundPage),
  },
  {
    // Client-side only: on a static host an unknown URL never reaches the
    // router, Caddy answers it with 404/index.html directly.
    path: '**',
    loadComponent: () => import('./features/errors/not-found-page').then((m) => m.NotFoundPage),
  },
];
