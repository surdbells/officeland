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
    path: '**',
    loadComponent: () => import('./features/errors/not-found-page').then((m) => m.NotFoundPage),
  },
];
