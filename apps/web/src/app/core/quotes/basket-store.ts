import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import type { Basket, BasketLine } from '../api/catalogue-api';

/**
 * The quote basket, as the storefront sees it.
 *
 * ── The server is authoritative; this is a mirror ────────────────────────
 * Every mutation posts and replaces local state with the response. Quantities
 * come back coerced to what the product allows (§9b), so applying the response
 * rather than the request is what keeps the displayed quantity equal to the
 * one that will be quoted. Optimistic local arithmetic would show 7 where the
 * server recorded 10.
 *
 * ── Never touched during prerender ───────────────────────────────────────
 * Under D-009 every page is rendered at build time, where there is no visitor
 * and no cookie. A basket fetch there would run 15,000 times during a build,
 * create a basket row per route, and bake one visitor's basket into every
 * static page. So the store does nothing on the server and loads on first use
 * in the browser.
 */
@Injectable({ providedIn: 'root' })
export class BasketStore {
  private readonly http = inject(HttpClient);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private readonly state = signal<Basket>({
    items: [],
    line_count: 0,
    unit_count: 0,
  });

  private readonly busy = signal(false);
  private readonly failure = signal<string | null>(null);
  private loaded = false;

  readonly basket = this.state.asReadonly();
  readonly pending = this.busy.asReadonly();
  readonly error = this.failure.asReadonly();

  readonly lines = computed<readonly BasketLine[]>(() => this.state().items ?? []);
  readonly lineCount = computed(() => this.state().line_count ?? 0);
  readonly unitCount = computed(() => this.state().unit_count ?? 0);
  readonly isEmpty = computed(() => this.lineCount() === 0);

  /**
   * Loads once per browser session.
   *
   * Called from the shell rather than from each page, so a buyer moving
   * between products does not re-fetch a basket that has not changed.
   */
  load(): void {
    if (!this.isBrowser || this.loaded) return;

    this.loaded = true;
    this.request(this.http.get<{ data: Basket }>('/api/v1/basket'));
  }

  addProduct(slug: string, qty = 1, note?: string): void {
    this.request(
      this.http.post<{ data: Basket }>('/api/v1/basket/items', { slug, qty, note: note ?? null }),
    );
  }

  addFreeText(description: string, qty = 1, note?: string): void {
    this.request(
      this.http.post<{ data: Basket }>('/api/v1/basket/items/free-text', {
        description,
        qty,
        note: note ?? null,
      }),
    );
  }

  setQuantity(line: BasketLine, qty: number): void {
    this.request(this.http.patch<{ data: Basket }>(`/api/v1/basket/items/${line.id}`, { qty }));
  }

  setNote(line: BasketLine, note: string): void {
    this.request(
      this.http.patch<{ data: Basket }>(`/api/v1/basket/items/${line.id}`, {
        note: note.trim() === '' ? null : note,
      }),
    );
  }

  remove(line: BasketLine): void {
    this.request(this.http.delete<{ data: Basket }>(`/api/v1/basket/items/${line.id}`));
  }

  clear(): void {
    this.request(this.http.delete<{ data: Basket }>('/api/v1/basket'));
  }

  /** Called after a successful submission, so the header count drops to zero. */
  reset(): void {
    this.state.set({ items: [], line_count: 0, unit_count: 0 });
    this.loaded = false;
  }

  private request(observable: ReturnType<HttpClient['get']>): void {
    if (!this.isBrowser) return;

    this.busy.set(true);
    this.failure.set(null);

    (observable as unknown as import('rxjs').Observable<{ data: Basket }>).subscribe({
      next: (response) => {
        this.state.set(response.data);
        this.busy.set(false);
      },
      error: (error: { error?: { error?: { message?: string } } }) => {
        /*
         * The server's message is shown verbatim. It is written for a buyer —
         * "A quote request is limited to 100 lines. Send this one and start
         * another, or call us." — and replacing it with a generic failure
         * would throw away the only actionable part.
         */
        this.failure.set(
          error?.error?.error?.message ??
            'Something went wrong. Please try again, or call us on +234 707 472 3165.',
        );
        this.busy.set(false);
      },
    });
  }
}
