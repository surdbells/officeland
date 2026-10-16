import { Injectable, signal } from '@angular/core';
import type { ProductCard } from '../core/api/catalogue-api';

/**
 * Holds whichever product the quick-view is showing, or null when it is shut.
 *
 * ── Why it carries the card, not a slug to re-fetch ──────────────────────
 * The listing already has everything a preview needs - name, brand, code,
 * availability, the primary image, key specs and the short description - so
 * the quick-view opens from data that is already in memory rather than firing
 * a request on click. That keeps it instant, adds nothing to the JS budget's
 * network cost, and means the overlay cannot fail to open because an endpoint
 * was slow. The full detail page is one click away for everything the card
 * does not carry (the gallery, the full spec table), and its link is in the
 * overlay.
 *
 * ── Why a root signal and not a component output chain ───────────────────
 * A card is rendered deep inside a rail or a grid; the overlay must sit at the
 * top of the document to cover the page. Threading an event up through every
 * intervening component to a dialog at the shell would couple all of them to a
 * feature none of them own. One root-provided signal is the seam: the card
 * writes to it, the dialog reads it, and nothing between them knows.
 */
@Injectable({ providedIn: 'root' })
export class QuickViewService {
  private readonly current = signal<ProductCard | null>(null);

  /** The product on show, or null. Read by the dialog. */
  readonly product = this.current.asReadonly();

  open(product: ProductCard): void {
    this.current.set(product);
  }

  close(): void {
    this.current.set(null);
  }
}
