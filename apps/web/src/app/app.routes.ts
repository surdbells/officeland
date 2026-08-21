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
     * the homepage for every unknown URL — the home content under a 404
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
