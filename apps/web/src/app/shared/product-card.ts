import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { ProductCard as ProductCardData } from '../core/api/catalogue-api';
import { ProductImage } from './product-image';

/**
 * The product card — one component, one prop set, one test suite (8b).
 *
 * Used by the PLP, search results, related rails and brand hubs. Section 23.5
 * is explicit that this is a single component: the legacy site has four card
 * variants that disagree about what a product is, and the reference site
 * renders each card title three times for responsive variants. This renders it
 * once and styles responsively.
 *
 * ── Markup decisions that are not cosmetic ───────────────────────────────
 *
 * The whole card is NOT one big anchor. A card contains a product link, a
 * brand link and a quote button; nesting interactive elements inside an anchor
 * is invalid HTML and gives screen readers one enormous unlabelled link. The
 * title is the link, and `::after` stretches its hit area over the card, so a
 * pointer gets a big target while assistive technology gets a real one.
 *
 * There is no price and no "from" figure (D-002), so the card's lower half
 * carries the facts a business buyer actually decides on: availability, lead
 * time, and minimum order quantity where it is not 1.
 *
 * The heading level is a parameter. This card appears under an `h1` on a PLP
 * and under an `h2` in a PDP related rail; a hardcoded level breaks the
 * document outline on one of them, and heading order is an accessibility gate.
 */
@Component({
  selector: 'ol-product-card',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ProductImage],
  template: `
    <article
      class="group relative flex h-full flex-col rounded-lg border border-ink-100 bg-white p-4 transition hover:border-brand-600"
    >
      <a [routerLink]="product().url" class="mb-3 block aspect-square" tabindex="-1" aria-hidden="true">
        <ol-product-image [image]="product().image ?? null" [sizes]="imageSizes()" />
      </a>

      @switch (headingLevel()) {
        @case (2) {
          <h2 class="font-heading text-sm font-semibold leading-snug">
            <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">
              {{ product().name }}
            </a>
          </h2>
        }
        @case (4) {
          <h4 class="font-heading text-sm font-semibold leading-snug">
            <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">
              {{ product().name }}
            </a>
          </h4>
        }
        @default {
          <h3 class="font-heading text-sm font-semibold leading-snug">
            <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">
              {{ product().name }}
            </a>
          </h3>
        }
      }

      @if (product().brand; as brand) {
        <!-- Above the stretched ::after, so the brand link stays clickable. -->
        <p class="relative z-10 mt-1 text-xs text-ink-600">
          <a [routerLink]="brand.url" class="hover:text-brand-600">{{ brand.name }}</a>
        </p>
      }

      @if (modelCode(); as code) {
        <p class="mt-1 text-xs text-ink-400">{{ code }}</p>
      }

      @if (product().key_specs?.length) {
        <ul class="mt-3 space-y-1 text-xs text-ink-600">
          @for (spec of product().key_specs ?? []; track spec.label) {
            <li><span class="text-ink-400">{{ spec.label }}:</span> {{ spec.value }}</li>
          }
        </ul>
      }

      <div class="mt-auto pt-3">
        <p class="text-xs font-medium">{{ availabilityLabel() }}</p>

        @if (minimumLabel(); as minimum) {
          <p class="mt-1 text-xs text-ink-400">{{ minimum }}</p>
        }

        <!-- Above the stretched ::after so the button is reachable. -->
        <button
          type="button"
          class="relative z-10 mt-3 w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700"
          (click)="addToQuote.emit(product())"
        >
          Add to quote
        </button>
      </div>
    </article>
  `,
})
export class ProductCard {
  readonly product = input.required<ProductCardData>();

  /** 3 by default: a PLP card sits under the page h1 and a section h2. */
  readonly headingLevel = input<2 | 3 | 4>(3);

  readonly imageSizes = input<string>('(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw');

  readonly addToQuote = output<ProductCardData>();

  /**
   * Model code, MPN or SKU, in that order.
   *
   * This is the string B2B buyers search and cross-reference, and it is how
   * consumables are matched to machines — the most valuable identifier on the
   * card, so it is shown rather than hidden in the payload.
   */
  protected readonly modelCode = computed(() => {
    const p = this.product();
    return p.model_code ?? p.mpn ?? p.sku ?? null;
  });

  /** Mirrors the PDP so a product does not describe itself two ways. */
  protected readonly availabilityLabel = computed(() => {
    const p = this.product();

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

  /** Only when it constrains the buyer; "minimum order 1" is noise. */
  protected readonly minimumLabel = computed(() => {
    const p = this.product();
    const min = p.min_order_qty ?? 1;
    const step = p.qty_step ?? 1;

    if (min > 1 && step > 1 && step !== min) return `Min ${min}, in multiples of ${step}`;
    if (min > 1) return `Minimum order ${min}`;
    if (step > 1) return `Sold in multiples of ${step}`;

    return null;
  });
}
