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
 * OpenAPI spec, and CI fails if that contract drifts — so response shapes are
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
export type ApiError = components['schemas']['Error'];

export interface NavigationNode {
  readonly name: string;
  readonly slug: string;
  readonly url: string;
  readonly depth: number;
  readonly product_count: number;
  readonly children: readonly NavigationNode[];
}

export interface Navigation {
  readonly departments: readonly NavigationNode[];
  readonly brands: ReadonlyArray<{ name: string; slug: string; url: string }>;
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

  categoryProducts(path: string, cursor?: string, perPage = 24): Observable<Paginated<ProductCard>> {
    let params = new HttpParams().set('per_page', perPage);
    if (cursor) params = params.set('cursor', cursor);

    return this.http.get<Paginated<ProductCard>>(
      `${this.base}/categories/${this.segment(path)}/products`,
      { params },
    );
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

  private unwrap<T>(url: string): Observable<T> {
    return this.http.get<Envelope<T>>(url).pipe(map((response) => response.data));
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
