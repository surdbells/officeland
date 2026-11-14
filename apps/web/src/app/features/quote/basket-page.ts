import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BasketStore } from '../../core/quotes/basket-store';
import { SeoService } from '../../core/seo/seo.service';
import { toCsv } from './basket-csv';
import type { BasketLine } from '../../core/api/catalogue-api';
import { Icon } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import { QuoteHeader } from './quote-header';

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
  imports: [RouterLink, QuoteHeader, Icon, ResponsiveImage],
  template: `
    <ol-quote-header
      heading="Your quote list"
      crumb="Quote list"
      [step]="1"
      lede="Add what you need, set the quantities, then send it. No prices, no payment - we reply with a priced quotation within one business day."
    />

    <div class="bg-ink-50">
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8 sm:py-12">
        @if (store.error(); as message) {
          <p role="alert" class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
            {{ message }}
          </p>
        }

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-8">
          <div class="min-w-0 space-y-6">
            @if (store.isEmpty()) {
              <!-- Empty: a way in, not a dead end. -->
              <section class="rounded-3xl border border-ink-100 bg-white px-6 py-12 text-center shadow-xs sm:px-10">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                  <ol-icon name="file-text" [size]="30" />
                </span>
                <h2 class="mt-5 font-heading text-2xl font-semibold text-ink-900">Your list is empty</h2>
                <p class="mx-auto mt-2 max-w-md text-ink-600">
                  Browse the catalogue and press <strong class="font-semibold text-ink-900">Add to quote</strong> on anything
                  you need - or describe it below if we do not have a page for it.
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                  <a routerLink="/c/" class="inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700">
                    Browse departments <ol-icon name="arrow-right" [size]="16" />
                  </a>
                  <a routerLink="/shop/" class="inline-flex items-center gap-2 rounded-full border border-ink-200 bg-white px-6 py-3 font-semibold text-ink-900 transition-colors hover:border-brand-600 hover:text-brand-700">
                    Shop all products
                  </a>
                </div>
              </section>
            } @else {
              <section aria-labelledby="list-heading" class="rounded-3xl border border-ink-100 bg-white shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
                  <h2 id="list-heading" class="font-heading text-lg font-semibold text-ink-900">
                    Items
                    <span class="ml-1 font-normal text-ink-400">({{ store.lineCount() }})</span>
                  </h2>
                  <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-ink-500 hover:text-red-700"
                    (click)="confirmClear.set(true)"
                  >
                    <ol-icon name="x" [size]="14" /> Empty the list
                  </button>
                </div>

                <!-- In-page confirmation: the viewer has no confirm() dialog, and emptying is not undoable. -->
                @if (confirmClear()) {
                  <div role="alert" class="flex flex-wrap items-center justify-between gap-3 border-b border-red-100 bg-red-50 px-5 py-3 text-sm text-red-800 sm:px-6">
                    <span>Remove all {{ store.lineCount() }} items from the list?</span>
                    <span class="flex gap-2">
                      <button type="button" class="cursor-pointer rounded-full bg-red-600 px-4 py-1.5 font-semibold text-white hover:bg-red-700" (click)="store.clear(); confirmClear.set(false)">Empty it</button>
                      <button type="button" class="cursor-pointer rounded-full border border-red-200 bg-white px-4 py-1.5 font-semibold text-red-800" (click)="confirmClear.set(false)">Keep</button>
                    </span>
                  </div>
                }

                <ul class="divide-y divide-ink-100">
                  @for (line of store.lines(); track line.id) {
                    <li class="grid grid-cols-[4rem_minmax(0,1fr)] gap-4 px-5 py-5 sm:grid-cols-[5rem_minmax(0,1fr)] sm:px-6">
                      <!-- Thumbnail: the product photo, or a tile for a described item. -->
                      <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-ink-100 bg-white sm:h-20 sm:w-20">
                        @if (line.image; as image) {
                          <ol-image
                            class="block h-full w-full p-1.5 [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                            [image]="image"
                            sizes="80px"
                            imgClass="h-full w-full object-contain"
                          />
                        } @else {
                          <ol-icon [name]="line.is_free_text ? 'pen-line' : 'box'" [size]="26" class="text-ink-300" />
                        }
                      </span>

                      <div class="min-w-0">
                        <div class="flex items-start justify-between gap-3">
                          <div class="min-w-0">
                            @if (line.url) {
                              <a [routerLink]="line.url" class="font-semibold leading-snug text-ink-900 hover:text-brand-700">{{ line.name }}</a>
                            } @else {
                              <span class="font-semibold leading-snug text-ink-900">{{ line.name }}</span>
                            }
                            <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                              @if (line.is_free_text) {
                                <span class="rounded-full bg-accent-500/15 px-2.5 py-0.5 font-medium text-amber-800">Described item - we will source it</span>
                              }
                              @if (line.brand) {
                                <span class="rounded-full bg-brand-50 px-2.5 py-0.5 font-semibold text-brand-700">{{ line.brand }}</span>
                              }
                              @if (line.mpn) {
                                <span class="rounded-full border border-ink-100 px-2.5 py-0.5 font-mono text-ink-500">{{ line.mpn }}</span>
                              }
                              @if (line.availability) {
                                <span class="inline-flex items-center gap-1.5 text-ink-500">
                                  <span class="h-1.5 w-1.5 rounded-full" [class]="line.availability === 'in_stock' ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                  {{ availability(line) }}
                                </span>
                              }
                            </p>
                          </div>
                          <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-400 transition-colors hover:bg-red-50 hover:text-red-700"
                            [attr.aria-label]="'Remove ' + line.name"
                            (click)="store.remove(line)"
                          >
                            <ol-icon name="x" [size]="16" />
                          </button>
                        </div>

                        <div class="mt-4 flex flex-wrap items-start gap-3">
                          <div>
                            <span class="mb-1 block text-xs font-medium text-ink-500">Quantity</span>
                            <div class="flex items-center rounded-full border border-ink-200 bg-white">
                              <button
                                type="button"
                                class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-l-full text-ink-600 hover:text-brand-600 disabled:cursor-not-allowed disabled:text-ink-300"
                                [attr.aria-label]="'Decrease quantity of ' + line.name"
                                [disabled]="line.qty <= (line.min_order_qty ?? 1)"
                                (click)="step(line, -1)"
                              >&minus;</button>
                              <input
                                [id]="'qty-' + line.id"
                                type="number"
                                inputmode="numeric"
                                class="h-10 w-14 border-0 bg-transparent text-center font-semibold text-ink-900 [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none"
                                [value]="line.qty"
                                [attr.min]="line.min_order_qty ?? 1"
                                [attr.step]="line.qty_step ?? 1"
                                [attr.aria-label]="'Quantity for ' + line.name"
                                (change)="onQuantity(line, $event)"
                              />
                              <button
                                type="button"
                                class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-r-full text-ink-600 hover:text-brand-600"
                                [attr.aria-label]="'Increase quantity of ' + line.name"
                                (click)="step(line, 1)"
                              >+</button>
                            </div>
                            @if (line.quantity_note) {
                              <p class="mt-1 text-xs text-ink-400">{{ line.quantity_note }}</p>
                            }
                          </div>

                          <div class="min-w-[12rem] flex-1">
                            <label [attr.for]="'note-' + line.id" class="mb-1 block text-xs font-medium text-ink-500">Note for this line</label>
                            <input
                              [id]="'note-' + line.id"
                              type="text"
                              maxlength="500"
                              placeholder="Colour, finish, delivery&hellip;"
                              class="h-10 w-full rounded-full border border-ink-200 bg-white px-4 text-sm text-ink-900 placeholder:text-ink-400 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/20"
                              [value]="line.note ?? ''"
                              (change)="onNote(line, $event)"
                            />
                          </div>
                        </div>
                      </div>
                    </li>
                  }
                </ul>
              </section>
            }

            <!-- Free text (§9b): present whether or not the list has lines; a requisition often starts here. -->
            <section aria-labelledby="add-free-text" class="rounded-3xl border border-dashed border-ink-200 bg-white p-5 sm:p-6">
              <div class="flex items-start gap-3">
                <span class="inline-flex shrink-0 rounded-xl bg-accent-500/15 p-2.5 text-amber-700"><ol-icon name="pen-line" [size]="20" /></span>
                <div>
                  <h2 id="add-free-text" class="font-heading text-lg font-semibold text-ink-900">Need something we do not list?</h2>
                  <p class="mt-0.5 text-sm text-ink-600">We supply far more than we list. Describe it and we will price it.</p>
                </div>
              </div>

              <form class="mt-4 flex flex-wrap items-end gap-3" (submit)="onFreeText($event)">
                <div class="min-w-[14rem] flex-1">
                  <label for="free-text" class="mb-1 block text-xs font-medium text-ink-500">Description</label>
                  <input
                    id="free-text"
                    name="description"
                    type="text"
                    required
                    maxlength="500"
                    class="h-11 w-full rounded-full border border-ink-200 bg-white px-4 text-sm text-ink-900 placeholder:text-ink-400 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/20"
                    placeholder="Whiteboard 1200x900, magnetic"
                    [value]="freeText()"
                    (input)="freeText.set($any($event.target).value)"
                  />
                </div>
                <div>
                  <label for="free-text-qty" class="mb-1 block text-xs font-medium text-ink-500">Quantity</label>
                  <input
                    id="free-text-qty"
                    name="qty"
                    type="number"
                    min="1"
                    class="h-11 w-24 rounded-full border border-ink-200 bg-white px-4 text-center text-sm font-semibold text-ink-900 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/20"
                    [value]="freeTextQty()"
                    (input)="freeTextQty.set(+$any($event.target).value || 1)"
                  />
                </div>
                <button
                  type="submit"
                  class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-full bg-ink-900 px-5 text-sm font-semibold text-white transition-colors hover:bg-ink-800 disabled:cursor-not-allowed disabled:opacity-50"
                  [disabled]="freeText().trim() === ''"
                >
                  Add to list
                </button>
              </form>
            </section>
          </div>

          <!-- Summary: what is on the list and the way forward. Sticky beside a long list. -->
          <aside class="lg:sticky lg:top-28 lg:self-start">
            <div class="rounded-3xl border border-ink-100 bg-white p-6 shadow-md">
              <h2 class="font-heading text-lg font-semibold text-ink-900">Summary</h2>
              <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-ink-600">Lines</dt><dd class="font-semibold text-ink-900">{{ store.lineCount() }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-600">Units</dt><dd class="font-semibold text-ink-900">{{ store.unitCount() }}</dd></div>
                <div class="flex justify-between border-t border-ink-100 pt-2"><dt class="text-ink-600">Price</dt><dd class="font-semibold text-ink-900">Quoted to you</dd></div>
              </dl>

              @if (store.isEmpty()) {
                <span class="mt-5 flex w-full items-center justify-center gap-2 rounded-full bg-ink-100 px-6 py-3.5 font-semibold text-ink-400" aria-disabled="true">
                  Request a quote
                </span>
              } @else {
                <a
                  routerLink="/quote/request/"
                  class="mt-5 flex w-full items-center justify-center gap-2 rounded-full bg-brand-600 px-6 py-3.5 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700"
                >
                  Request a quote <ol-icon name="arrow-right" [size]="16" />
                </a>
                <!--
                  D-006. A real anchor with a download attribute rather than a
                  server round-trip: the data is already here.
                -->
                <a
                  [href]="csvHref()"
                  download="officeland-quote-request.csv"
                  class="mt-3 flex w-full items-center justify-center gap-2 rounded-full border border-ink-200 px-6 py-3 text-sm font-semibold text-ink-900 transition-colors hover:border-brand-600 hover:text-brand-700"
                >
                  <ol-icon name="file-text" [size]="16" /> Download as CSV
                </a>
              }

              <ul class="mt-6 space-y-3 border-t border-ink-100 pt-5 text-sm text-ink-600">
                <li class="flex gap-2.5"><ol-icon name="clock" [size]="18" class="mt-0.5 shrink-0 text-brand-600" /> Priced within one business day.</li>
                <li class="flex gap-2.5"><ol-icon name="shield-check" [size]="18" class="mt-0.5 shrink-0 text-brand-600" /> No payment and no obligation - it is a request, not an order.</li>
                <li class="flex gap-2.5"><ol-icon name="truck" [size]="18" class="mt-0.5 shrink-0 text-brand-600" /> Delivery and installation priced with it.</li>
              </ul>
            </div>

            <a
              href="https://wa.me/2347074723165"
              rel="noopener"
              class="mt-4 flex items-center gap-3 rounded-3xl border border-ink-100 bg-white p-5 shadow-xs transition-colors hover:border-emerald-500"
            >
              <span class="inline-flex rounded-xl bg-emerald-500 p-2.5 text-white"><ol-icon name="message-circle" [size]="20" /></span>
              <span>
                <span class="block font-semibold text-ink-900">Rather talk it through?</span>
                <span class="block text-sm text-ink-600">WhatsApp +234 707 472 3165</span>
              </span>
            </a>
          </aside>
        </div>
      </div>
    </div>
  `,
})
export class BasketPage {
  protected readonly store = inject(BasketStore);
  private readonly seo = inject(SeoService);

  protected readonly freeText = signal('');
  protected readonly freeTextQty = signal(1);

  /** Emptying the list asks first, in the page. */
  protected readonly confirmClear = signal(false);

  /** One step of the line's own multiple; the server snaps anything else. */
  protected step(line: BasketLine, direction: 1 | -1): void {
    const step = Math.max(1, line.qty_step ?? 1);
    const min = Math.max(1, line.min_order_qty ?? 1);
    this.store.setQuantity(line, Math.max(min, line.qty + direction * step));
  }

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
