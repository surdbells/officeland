import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BasketStore } from '../../core/quotes/basket-store';
import { SeoService } from '../../core/seo/seo.service';
import { toCsv } from './basket-csv';
import type { BasketLine } from '../../core/api/catalogue-api';

/**
 * The quote basket page (§9c).
 *
 * Editable quantities, per-line notes, free-text lines, and a CSV export
 * (D-006). No prices and no total anywhere - there is nothing to total until
 * sales prices it (D-002).
 *
 * `noindex`: it is per-visitor by definition and there is nothing here to
 * index. It is also not prerendered with content - the basket belongs to the
 * browser, and the page is a shell the store fills in.
 */
@Component({
  selector: 'ol-basket-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      <h1 class="font-heading text-2xl font-bold sm:text-3xl">Your quote request</h1>

      @if (store.error(); as message) {
        <p role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {{ message }}
        </p>
      }

      @if (store.isEmpty()) {
        <p class="mt-6 max-w-2xl text-ink-600">
          Nothing added yet. Browse the
          <a routerLink="/c/" class="text-brand-600 hover:underline">catalogue</a>
          and add what you need - or list it below if we do not have a page for it.
        </p>
      } @else {
        <p class="mt-2 text-sm text-ink-600">
          {{ store.lineCount() }} line{{ store.lineCount() === 1 ? '' : 's' }},
          {{ store.unitCount() }} unit{{ store.unitCount() === 1 ? '' : 's' }}
        </p>

        <!--
          A table on desktop, stacked cards on a phone. A real <table> forces
          the long product name into a sliver of a column and squeezes the two
          inputs beside it - unreadable at 375px. This is one CSS grid instead:
          a single column on mobile (name, then a labelled quantity and note,
          then remove) and a four-column row at md. Column headers show only
          where the row layout needs them; on mobile each field carries its own
          visible label, because there is no header row to read across.
        -->
        <div
          class="mt-6 hidden grid-cols-[1fr_8rem_1fr_6rem] gap-x-6 border-b border-ink-200 pb-2 text-xs font-semibold uppercase tracking-wide text-ink-500 md:grid"
          aria-hidden="true"
        >
          <span>Item</span>
          <span>Quantity</span>
          <span>Note</span>
          <span></span>
        </div>

        <ul class="divide-y divide-ink-100 text-sm">
          @for (line of store.lines(); track line.id) {
            <li class="grid grid-cols-1 gap-x-6 gap-y-3 py-4 md:grid-cols-[1fr_8rem_1fr_6rem] md:items-start md:py-3">
              <div class="min-w-0">
                @if (line.url) {
                  <a [routerLink]="line.url" class="font-medium hover:text-brand-600">{{ line.name }}</a>
                } @else {
                  <span class="font-medium">{{ line.name }}</span>
                  <span class="ml-2 rounded bg-ink-100 px-2 py-0.5 text-xs text-ink-600">Not in catalogue</span>
                }

                <div class="mt-1 text-xs text-ink-400">
                  @if (line.brand) { <span>{{ line.brand }}</span> }
                  @if (line.mpn) { <span> &middot; {{ line.mpn }}</span> }
                  @if (line.availability) { <span> &middot; {{ availability(line) }}</span> }
                </div>
              </div>

              <div>
                <label [attr.for]="'qty-' + line.id" class="mb-1 block text-xs font-medium text-ink-500 md:sr-only">
                  Quantity
                </label>
                <input
                  [id]="'qty-' + line.id"
                  type="number"
                  inputmode="numeric"
                  class="w-24 rounded border border-ink-200 px-3 py-2"
                  [value]="line.qty"
                  [attr.min]="line.min_order_qty ?? 1"
                  [attr.step]="line.qty_step ?? 1"
                  [attr.aria-label]="'Quantity for ' + line.name"
                  (change)="onQuantity(line, $event)"
                />
                @if (line.quantity_note) {
                  <p class="mt-1 text-xs text-ink-400">{{ line.quantity_note }}</p>
                }
              </div>

              <div class="min-w-0">
                <label [attr.for]="'note-' + line.id" class="mb-1 block text-xs font-medium text-ink-500 md:sr-only">
                  Note
                </label>
                <input
                  [id]="'note-' + line.id"
                  type="text"
                  maxlength="500"
                  placeholder="Colour, finish, delivery&hellip;"
                  class="w-full rounded border border-ink-200 px-3 py-2"
                  [value]="line.note ?? ''"
                  [attr.aria-label]="'Note for ' + line.name"
                  (change)="onNote(line, $event)"
                />
              </div>

              <div class="md:text-right">
                <button
                  type="button"
                  class="text-sm font-medium text-ink-600 underline hover:text-red-700"
                  (click)="store.remove(line)"
                >Remove</button>
              </div>
            </li>
          }
        </ul>

        <!-- Stacked and full-width on a phone so the primary action is a clear
             thumb target, a row on desktop. -->
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
          <a
            routerLink="/quote/request/"
            class="rounded-lg bg-brand-600 px-6 py-3 text-center font-semibold text-white hover:bg-brand-700"
          >Request a quote</a>

          <!--
            D-006. A real anchor with a download attribute rather than a
            server round-trip: the data is already here, and a buyer who wants
            it in their procurement system should not wait for a request.
          -->
          <a
            [href]="csvHref()"
            download="officeland-quote-request.csv"
            class="rounded-lg border border-ink-200 px-4 py-3 text-center text-sm font-medium hover:border-brand-600"
          >Download as CSV</a>

          <button
            type="button"
            class="py-1 text-sm text-ink-600 underline hover:text-red-700 sm:py-0"
            (click)="store.clear()"
          >Empty the list</button>
        </div>
      }

      <!-- Free text (§9b). Present whether or not the basket has lines: a
           buyer working from a requisition often starts here. -->
      <section aria-labelledby="add-free-text" class="mt-12 max-w-2xl border-t border-ink-100 pt-8">
        <h2 id="add-free-text" class="font-heading text-lg font-semibold">
          Need something we do not list?
        </h2>
        <p class="mt-1 text-sm text-ink-600">
          We supply far more than we list. Describe it and we will price it.
        </p>

        <form class="mt-4 flex flex-wrap items-end gap-3" (submit)="onFreeText($event)">
          <div class="min-w-64 flex-1">
            <label for="free-text" class="block text-sm font-medium">Description</label>
            <input
              id="free-text"
              name="description"
              type="text"
              required
              maxlength="500"
              class="mt-1 w-full rounded border border-ink-200 px-3 py-2"
              placeholder="Whiteboard 1200x900, magnetic"
              [value]="freeText()"
              (input)="freeText.set($any($event.target).value)"
            />
          </div>

          <div>
            <label for="free-text-qty" class="block text-sm font-medium">Quantity</label>
            <input
              id="free-text-qty"
              name="qty"
              type="number"
              min="1"
              class="mt-1 w-24 rounded border border-ink-200 px-3 py-2"
              [value]="freeTextQty()"
              (input)="freeTextQty.set(+$any($event.target).value || 1)"
            />
          </div>

          <button
            type="submit"
            class="rounded-lg border border-brand-600 px-4 py-2 font-semibold text-brand-600 hover:bg-brand-50"
            [disabled]="freeText().trim() === ''"
          >Add to list</button>
        </form>
      </section>
    </div>
  `,
})
export class BasketPage {
  protected readonly store = inject(BasketStore);
  private readonly seo = inject(SeoService);

  protected readonly freeText = signal('');
  protected readonly freeTextQty = signal(1);

  /**
   * A data: URL rather than a Blob URL.
   *
   * Blob URLs must be revoked or they leak, and they cannot be produced during
   * prerender. A data: URL is a pure function of the basket, so it is simply
   * recomputed whenever the basket changes.
   */
  protected readonly csvHref = computed(
    () => `data:text/csv;charset=utf-8,${encodeURIComponent(toCsv(this.store.lines()))}`,
  );

  constructor() {
    this.seo.apply({
      title: 'Your quote request',
      path: '/quote/',
      // Per-visitor by definition, and there is nothing here to index.
      indexable: false,
    });

    this.seo.setStructuredData([]);
    this.store.load();
  }

  protected availability(line: BasketLine): string {
    switch (line.availability) {
      case 'in_stock':
        return 'In stock';
      case 'limited':
        return 'Limited stock';
      case 'made_to_order':
        return 'Made to order';
      case 'discontinued':
        return 'Discontinued';
      default:
        return line.lead_time_days ? `${line.lead_time_days} day lead time` : 'On lead time';
    }
  }

  protected onQuantity(line: BasketLine, event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);

    // The server coerces to the nearest allowed quantity and the response
    // replaces this value, so an out-of-step entry corrects itself visibly.
    this.store.setQuantity(line, Number.isFinite(value) && value > 0 ? Math.floor(value) : 1);
  }

  protected onNote(line: BasketLine, event: Event): void {
    this.store.setNote(line, (event.target as HTMLInputElement).value);
  }

  protected onFreeText(event: Event): void {
    event.preventDefault();

    const description = this.freeText().trim();
    if (description === '') return;

    this.store.addFreeText(description, this.freeTextQty());

    this.freeText.set('');
    this.freeTextQty.set(1);
  }
}
