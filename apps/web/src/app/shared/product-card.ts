import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { ProductCard as ProductCardData } from '../core/api/catalogue-api';
import { ResponsiveImage } from './responsive-image';

/**
 * The product card - one component, one prop set, one test suite (8b).
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
  imports: [RouterLink, ResponsiveImage],
  template: `
    <article
      class="group relative flex h-full flex-col rounded-lg border border-ink-100 bg-white p-4 transition hover:border-brand-600"
    >
      <a [routerLink]="product().url" class="mb-3 block aspect-square" tabindex="-1" aria-hidden="true">
        <ol-image [image]="product().image ?? null" [sizes]="imageSizes()" />
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

      <!--
        Brand, product code and availability as three chips on one line.

        They are the three things a buyer checks before adding a line to a
        requisition: who makes it, which one it is, and whether they can get
        it. Grouping them as chips puts all three in one glance instead of
        three stacked paragraphs, and gives the card a fixed-height band
        under the title regardless of how long the name is.

        The row wraps rather than truncating: on a narrow card a long brand
        and a long code will not fit, and a chip cut in half is worse than a
        chip on the next line.

        The brand chip is a link and sits above the stretched ::after so it
        stays clickable inside a card that is otherwise one big target.
      -->
      <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @if (product().brand; as brand) {
          <a
            [routerLink]="brand.url"
            class="relative z-10 inline-block rounded border border-ink-200 px-2 py-0.5 text-[0.6875rem] font-medium text-ink-700 hover:border-brand-600 hover:text-brand-600"
          >{{ brand.name }}</a>
        }

        @if (modelCode(); as code) {
          <span class="inline-block rounded border border-ink-200 px-2 py-0.5 font-mono text-[0.6875rem] tracking-tight text-ink-600">
            {{ code }}
          </span>
        }

        <!--
          Availability is tinted, the other two are not. It is the only one
          of the three that carries a judgement rather than a fact, and a
          buyer scanning a grid for what they can have this week should be
          able to find it without reading. Colour is not the only signal —
          the text says which it is.
        -->
        <span
          class="inline-block rounded border px-2 py-0.5 text-[0.6875rem] font-medium"
          [class]="inStock()
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-amber-200 bg-amber-50 text-amber-800'"
        >{{ availabilityLabel() }}</span>
      </div>

      @if (product().key_specs?.length) {
        <ul class="mt-3 space-y-1 text-xs text-ink-600">
          @for (spec of product().key_specs ?? []; track spec.label) {
            <li><span class="text-ink-400">{{ spec.label }}:</span> {{ spec.value }}</li>
          }
        </ul>
      }

      <!--
        No price block, and no space held for one.

        D-002 means nothing here has a price, so the card ends on the action
        rather than on a gap where a number would be. "mt-auto" pushes this
        to the bottom, which is what keeps a row of cards aligned when their
        names wrap to different heights.
      -->
      <div class="mt-auto pt-3">
        @if (minimumLabel(); as minimum) {
          <p class="mb-2 text-xs text-ink-400">{{ minimum }}</p>
        }

        <!-- Above the stretched ::after so the button is reachable. -->
        <button
          type="button"
          class="relative z-10 w-full rounded-lg bg-brand-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-brand-700"
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
   * consumables are matched to machines - the most valuable identifier on the
   * card, so it is shown rather than hidden in the payload.
   */
  protected readonly modelCode = computed(() => {
    const p = this.product();
    return p.model_code ?? p.mpn ?? p.sku ?? null;
  });


  /**
   * Whether the availability chip is green or amber.
   *
   * Only in_stock earns green. "Made to order" is a legitimate answer for a
   * supplier who fabricates to a measure, but tinting it as available tells
   * a buyer with a deadline the opposite of what they need to know.
   *
   * The chip's text says which state it is, so colour is reinforcement
   * rather than the only signal.
   */
  protected readonly inStock = computed(() => this.product().availability === 'in_stock');
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
