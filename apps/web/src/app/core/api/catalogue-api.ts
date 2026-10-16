import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import type { components } from '@contracts/api';

/**
 * Typed catalogue client.
 *
 * ── Why not openapi-fetch ────────────────────────────────────────────────
 * Section 15.1 requires a generated client and rejects hand-written HTTP
 * calls. Every type below comes from packages/contracts, generated from the
 * OpenAPI spec, and CI fails if that contract drifts - so response shapes are
 * checked against the server rather than hand-maintained.
 *
 * The transport is Angular's HttpClient rather than openapi-fetch because
 * openapi-fetch uses global fetch, bypassing HttpClient and therefore
 * TransferState. Section 14.1 requires TransferState so hydration does not
 * re-fetch what the render already fetched, and calls duplicate hydration
 * requests a gate failure. A client that reintroduced every request on
 * hydration would fail the gate it was meant to serve.
 * ─────────────────────────────────────────────────────────────────────────
 */

export type Brand = components['schemas']['Brand'];
export type Category = components['schemas']['Category'];
export type Product = components['schemas']['Product'];
export type ProductCard = components['schemas']['ProductCard'];
export type Image = components['schemas']['Image'];
export type SpecificationGroup = components['schemas']['SpecificationGroup'];
export type Basket = components['schemas']['Basket'];
export type BasketLine = components['schemas']['BasketLine'];
export type ApiError = components['schemas']['Error'];

/**
 * A brand as the megamenu shows it.
 *
 * `logo` is null until somebody uploads one, and the menu falls back to the
 * name as text - which is all it has ever shown, so a missing logo costs
 * nothing and an uploaded one is a pure gain.
 */
export interface NavigationBrand {
  readonly name: string;
  readonly slug: string;
  readonly url: string;
  readonly logo: Image | null;
}

export interface NavigationNode {
  readonly name: string;
  readonly slug: string;
  readonly url: string;
  readonly depth: number;
  readonly product_count: number;
  readonly children: readonly NavigationNode[];

  /**
   * Departments only, and derived from the catalogue rather than curated: a
   * brand appears here when it has an active product somewhere in this
   * department's subtree.
   *
   * Absent on subcategories and leaves, and empty on a department with no
   * products - which is why the storefront must treat it as optional rather
   * than assume a row to render.
   */
  readonly brands?: readonly NavigationBrand[];

  /**
   * The department's hero photograph, for the homepage rail tiles. Departments
   * only and null until one is uploaded, so a tile falls back to its lettered
   * placeholder rather than a broken frame. Subtree nodes never carry it - the
   * navigation payload rides on every page and 170 heroes would bloat it.
   */
  readonly hero?: Image | null;
}

export interface Navigation {
  readonly departments: readonly NavigationNode[];
}

/** One typeahead row. `meta` carries the model code, or null. */
export interface Suggestion {
  readonly label: string;
  readonly url: string;
  readonly meta?: string | null;
}

/**
 * Typeahead results, grouped as the endpoint returns them.
 *
 * Deliberately a separate shape from Listing: a suggestion is a label and a
 * destination, not a product card, and giving it the card's payload would
 * make every keystroke fetch images and specifications nobody renders.
 */
export interface Suggestions {
  readonly products: readonly Suggestion[];
  readonly categories: readonly Suggestion[];
  readonly brands: readonly Suggestion[];
}

export interface FacetValue {
  readonly value: string;
  readonly label?: string;
  readonly count: number;
}

export interface FacetGroup {
  readonly code: string;
  readonly label: string;
  /** Only an indexable facet may ever become a landing page. */
  readonly indexable: boolean;
  readonly values: readonly FacetValue[];
}

export interface ListingMeta {
  readonly total: number;
  readonly page: number;
  readonly per_page: number;
  readonly last_page: number;
  readonly has_more: boolean;
  readonly sort: string;
  readonly duration_ms: number;
  readonly used_fallback: boolean;
}

/**
 * One envelope for every listing surface - PLP, search, brand hub - because
 * one card component renders all three (section 23.5).
 */
export interface Listing {
  readonly data: readonly ProductCard[];
  readonly meta: ListingMeta;
  readonly facets: readonly FacetGroup[];
}

export interface ListingParams {
  readonly page?: number;
  readonly perPage?: number;
  readonly sort?: string;
  /** attribute code => selected values */
  readonly filters?: Readonly<Record<string, readonly string[]>>;
}

export interface Paginated<T> {
  readonly data: readonly T[];
  readonly meta: Record<string, unknown>;
}

interface Envelope<T> {
  readonly data: T;
}

@Injectable({ providedIn: 'root' })
export class CatalogueApi {
  private readonly http = inject(HttpClient);

  /**
   * Same-origin: Caddy routes /api/** to PHP and everything else to the
   * prerendered files, so a relative base works in the browser and is
   * rewritten by the render worker at build time.
   */
  private readonly base = '/api/v1';

  navigation(): Observable<Navigation> {
    return this.unwrap<Navigation>(`${this.base}/navigation`);
  }

  categories(): Observable<readonly Category[]> {
    return this.collection<Category>(`${this.base}/categories`);
  }

  category(path: string): Observable<Category> {
    return this.unwrap<Category>(`${this.base}/categories/${this.segment(path)}`);
  }

  categoryProducts(path: string, params: ListingParams = {}): Observable<Listing> {
    return this.http.get<Listing>(
      `${this.base}/categories/${this.segment(path)}/products`,
      { params: this.listingParams(params) },
    );
  }

  /**
   * The whole catalogue as one faceted listing - the shop page.
   *
   * Same envelope as every other listing surface, because it is the same
   * question with the category filter left out.
   */
  catalogue(params: ListingParams = {}): Observable<Listing> {
    return this.http.get<Listing>(`${this.base}/products`, {
      params: this.listingParams(params),
    });
  }

  brandProducts(slug: string, params: ListingParams = {}): Observable<Listing> {
    return this.http.get<Listing>(
      `${this.base}/brands/${encodeURIComponent(slug)}/products`,
      { params: this.listingParams(params) },
    );
  }

  search(term: string, params: ListingParams = {}): Observable<Listing> {
    return this.http.get<Listing>(`${this.base}/search`, {
      params: this.listingParams(params).set('q', term),
    });
  }

  /**
   * Typeahead for the header search.
   *
   * Rate-limited server-side on its own throttle group, and debounced by the
   * caller, so a fast typist makes one request rather than one per keystroke.
   */
  suggest(term: string): Observable<Suggestions> {
    return this.unwrap<Suggestions>(`${this.base}/search/suggest`, new HttpParams().set('q', term));
  }

  product(slug: string): Observable<Product> {
    return this.unwrap<Product>(`${this.base}/products/${encodeURIComponent(slug)}`);
  }

  brands(): Observable<readonly Brand[]> {
    return this.collection<Brand>(`${this.base}/brands`);
  }

  brand(slug: string): Observable<Brand> {
    return this.unwrap<Brand>(`${this.base}/brands/${encodeURIComponent(slug)}`);
  }

  /**
   * Filters are sent as `filters[code]=a,b` rather than repeated keys.
   * It is the shorter URL, and the URL is the state: it is what a facet
   * anchor points at, what the browser bookmarks, and what the render worker
   * would have to reproduce if a facet page were ever prerendered.
   */
  private listingParams(params: ListingParams): HttpParams {
    let http = new HttpParams();

    if (params.page && params.page > 1) http = http.set('page', params.page);
    if (params.perPage) http = http.set('per_page', params.perPage);
    if (params.sort) http = http.set('sort', params.sort);

    for (const [code, values] of Object.entries(params.filters ?? {})) {
      if (values.length > 0) http = http.set(`filters[${code}]`, values.join(','));
    }

    return http;
  }

  private unwrap<T>(url: string, params?: HttpParams): Observable<T> {
    return this.http
      .get<Envelope<T>>(url, params ? { params } : {})
      .pipe(map((response) => response.data));
  }

  private collection<T>(url: string): Observable<readonly T[]> {
    return this.http.get<Paginated<T>>(url).pipe(map((response) => response.data));
  }

  /**
   * Category paths are stored slash-delimited ('/office-furniture/seating/');
   * the URL segment is not. Each part is encoded separately so the slashes
   * survive as path separators rather than becoming %2F.
   */
  private segment(path: string): string {
    return path
      .split('/')
      .filter((part) => part !== '')
      .map(encodeURIComponent)
      .join('/');
  }
}
