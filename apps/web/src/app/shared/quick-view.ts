import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  PLATFORM_ID,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ResponsiveImage } from './responsive-image';
import { Icon } from './icon';
import { QuickViewService } from './quick-view.service';
import { BasketStore } from '../core/quotes/basket-store';

/**
 * The quick-view overlay, mounted once at the shell.
 *
 * ── It is pure enhancement, and invisible to a crawler ───────────────────
 * At build time (D-009) nothing is open, so the service holds null and this
 * renders nothing: the prerendered HTML has no dialog, no duplicated product
 * markup, and no crawl surface competing with the real detail page. It only
 * ever exists in a browser, after a click. Everything it shows is data the
 * card already holds, and its "View full details" link is the canonical PDP.
 *
 * ── The parts that need a real dialog ────────────────────────────────────
 * `role="dialog"` with `aria-modal` and a label, Escape to close, a click on
 * the backdrop to close, the body scroll locked while it is open, focus moved
 * into the panel on open and Tab kept inside it. Skipping any of these makes
 * an overlay that traps a keyboard user or lets the page scroll behind it.
 * All of it is browser-guarded, because none of it means anything on the
 * server.
 */
@Component({
  selector: 'ol-quick-view',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ResponsiveImage, Icon],
  template: `
    @if (product(); as p) {
      <div
        class="ol-qv-backdrop fixed inset-0 z-[100] flex items-end justify-center bg-ink-900/50 p-0 backdrop-blur-sm sm:items-center sm:p-6"
        (click)="onBackdrop($event)"
      >
        <div
          #panel
          class="ol-qv-panel relative flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
          role="dialog"
          aria-modal="true"
          [attr.aria-labelledby]="titleId"
          (keydown)="onKeydown($event)"
        >
          <button
            #closeButton
            type="button"
            class="absolute right-3 top-3 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-ink-600 shadow-sm transition-colors hover:bg-ink-100 hover:text-ink-900"
            aria-label="Close quick view"
            (click)="close()"
          >
            <ol-icon name="x" [size]="18" />
          </button>

          <div class="grid gap-0 overflow-y-auto sm:grid-cols-2">
            <div class="ol-zoom-frame ol-contain aspect-square bg-ink-50 sm:aspect-auto">
              <ol-image [image]="p.image ?? null" sizes="(min-width: 640px) 24rem, 100vw" />
            </div>

            <div class="flex flex-col p-6">
              <h2 [id]="titleId" class="font-heading text-xl font-semibold leading-snug text-ink-900">
                {{ p.name }}
              </h2>

              <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @if (p.brand; as brand) {
                  <a
                    [routerLink]="brand.url"
                    (click)="close()"
                    class="inline-block rounded border border-ink-200 px-2 py-0.5 text-[0.6875rem] font-medium text-ink-700 hover:border-brand-600 hover:text-brand-600"
                  >{{ brand.name }}</a>
                }
                @if (modelCode(); as code) {
                  <span class="inline-block rounded border border-ink-200 px-2 py-0.5 font-mono text-[0.6875rem] tracking-tight text-ink-600">
                    {{ code }}
                  </span>
                }
                <span
                  class="inline-block rounded border px-2 py-0.5 text-[0.6875rem] font-medium"
                  [class]="inStock()
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-amber-200 bg-amber-50 text-amber-800'"
                >{{ availabilityLabel() }}</span>
              </div>

              @if (p.short_description) {
                <p class="mt-4 text-sm leading-relaxed text-ink-600">{{ p.short_description }}</p>
              }

              @if (p.key_specs?.length) {
                <dl class="mt-4 space-y-1.5 border-t border-ink-100 pt-4 text-sm">
                  @for (spec of p.key_specs ?? []; track spec.label) {
                    <div class="flex justify-between gap-4">
                      <dt class="text-ink-400">{{ spec.label }}</dt>
                      <dd class="text-right font-medium text-ink-800">{{ spec.value }}</dd>
                    </div>
                  }
                </dl>
              }

              @if (minimumLabel(); as minimum) {
                <p class="mt-3 text-xs text-ink-400">{{ minimum }}</p>
              }

              <div class="mt-auto pt-5">
                <div class="flex flex-col gap-2 sm:flex-row">
                  <button
                    type="button"
                    class="flex-1 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-700"
                    (click)="addToQuote(p)"
                  >
                    {{ added() ? 'Added to quote' : 'Add to quote' }}
                  </button>
                  <a
                    [routerLink]="p.url"
                    (click)="close()"
                    class="flex-1 rounded-lg border border-ink-200 px-4 py-2.5 text-center text-sm font-semibold text-ink-800 transition-colors hover:border-brand-600 hover:text-brand-600"
                  >
                    View full details
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    }
  `,
  styles: `
    .ol-qv-backdrop {
      animation: ol-qv-fade 0.2s var(--ease-out-soft);
    }

    .ol-qv-panel {
      animation: ol-qv-rise 0.28s var(--ease-out-soft);
    }

    @keyframes ol-qv-fade {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    @keyframes ol-qv-rise {
      from {
        opacity: 0;
        transform: translateY(1.5rem) scale(0.98);
      }
      to {
        opacity: 1;
        transform: none;
      }
    }
  `,
})
export class QuickView {
  private readonly service = inject(QuickViewService);
  private readonly basket = inject(BasketStore);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  protected readonly product = this.service.product;
  protected readonly titleId = 'quick-view-title';

  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');
  private readonly closeButton = viewChild<ElementRef<HTMLButtonElement>>('closeButton');

  /** Reset to its "Add to quote" label whenever a different product opens. */
  protected readonly added = signal(false);

  protected readonly modelCode = computed(() => {
    const p = this.product();
    return p ? (p.model_code ?? p.mpn ?? p.sku ?? null) : null;
  });

  protected readonly inStock = computed(() => this.product()?.availability === 'in_stock');

  /** Mirrors the card and the PDP so a product never describes itself two ways. */
  protected readonly availabilityLabel = computed(() => {
    const p = this.product();
    if (!p) {
      return '';
    }
    switch (p.availability) {
      case 'in_stock':
        return 'In stock';
      case 'limited':
        return 'Limited stock';
      case 'made_to_order':
        return 'Made to order';
      case 'discontinued':
        return 'Discontinued';
      default:
        return p.lead_time_days ? `${p.lead_time_days} day lead time` : 'Available on lead time';
    }
  });

  protected readonly minimumLabel = computed(() => {
    const p = this.product();
    if (!p) {
      return null;
    }
    const min = p.min_order_qty ?? 1;
    const step = p.qty_step ?? 1;
    if (min > 1 && step > 1 && step !== min) return `Minimum order ${min}, in multiples of ${step}`;
    if (min > 1) return `Minimum order ${min}`;
    if (step > 1) return `Sold in multiples of ${step}`;
    return null;
  });

  constructor() {
    // Body scroll lock, and focus into the panel on open. Browser-only: there
    // is no document on the server, and no open dialog during prerender.
    effect(() => {
      const open = this.product() !== null;

      if (!this.isBrowser) {
        return;
      }

      document.body.style.overflow = open ? 'hidden' : '';
      this.added.set(false);

      if (open) {
        // After the panel has rendered, move focus to its close button so the
        // next Tab stays inside the dialog and Escape has somewhere to land.
        queueMicrotask(() => this.closeButton()?.nativeElement.focus());
      }
    });
  }

  protected close(): void {
    this.service.close();
  }

  protected addToQuote(p: NonNullable<ReturnType<QuickViewService['product']>>): void {
    this.basket.addProduct(p.slug);
    this.added.set(true);
  }

  protected onBackdrop(event: MouseEvent): void {
    // Only a click on the backdrop itself, not a click that bubbled up from
    // inside the panel.
    if (event.target === event.currentTarget) {
      this.close();
    }
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    // A minimal focus trap: keep Tab and Shift+Tab inside the panel by wrapping
    // at its first and last focusable elements.
    const panel = this.panel()?.nativeElement;
    if (!panel) {
      return;
    }

    const focusable = panel.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])',
    );
    if (focusable.length === 0) {
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }
}
