import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { ProductCard as ProductCardData } from '../core/api/catalogue-api';
import { ResponsiveImage } from './responsive-image';
import { Icon } from './icon';
import { QuickViewService } from './quick-view.service';
import { BasketStore } from '../core/quotes/basket-store';

/**
 * The product card - one component, one prop set, one test suite (8b).
 *
 * Used by the PLP, search results, related rails, brand hubs and the homepage
 * showcases. Section 23.5 is explicit that this is a single component: the
 * legacy site has four card variants that disagree about what a product is.
 * The `feature` variant is the same card laid out as a spotlight (image beside
 * the details) for the one product a homepage showcase leads with - same data,
 * same anchors, same button; only the arrangement changes.
 *
 * ── The premium treatment ────────────────────────────────────────────────
 * The photograph sits in a padded white well so a product shot on white
 * floats rather than touching the card edge; the brand is an eyebrow, not a
 * boxed chip; availability is a status dot; and the title is clamped to two
 * lines with its height reserved, so a row of cards shares one baseline no
 * matter how long the names are. The supplier boilerplate summary ("…available
 * from OfficeLand with nationwide delivery…") is not shown on the compact card:
 * it is identical on thousands of products and only crowds the decision facts.
 *
 * ── Markup decisions that are not cosmetic ───────────────────────────────
 * The whole card is NOT one big anchor. A card contains a product link, a
 * brand link and a quote button; nesting interactive elements inside an anchor
 * is invalid HTML. The title is the link, and `::after` stretches its hit area
 * over the card, so a pointer gets a big target while assistive technology
 * gets a real one. There is no price (D-002): the card ends on availability,
 * lead time and minimum order - the facts a business buyer decides on.
 *
 * The heading level is a parameter: a hardcoded level breaks the document
 * outline under either a PLP h1 or a PDP related-rail h2.
 */
@Component({
  selector: 'ol-product-card',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, ResponsiveImage, Icon],
  host: { '[class.is-feature]': 'isFeature()' },
  template: `
    <article
      class="ol-pcard group relative flex h-full flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white"
      [class]="cardClass()"
    >
      <a
        [routerLink]="product().url"
        class="ol-zoom-frame ol-contain ol-pcard__well relative block shrink-0"
        [class]="wellClass()"
        tabindex="-1"
        aria-hidden="true"
      >
        <ol-image [image]="product().image ?? null" [sizes]="imageSizes()" />
      </a>

      <!--
        Quick view. Above the stretched ::after (z-10) so it is clickable inside
        a card that is otherwise one big target. Revealed on hover with a
        pointer, always shown on touch, and keyboard-focusable.
      -->
      <button
        type="button"
        class="absolute right-3 top-3 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/95 text-ink-700 opacity-0 shadow-md ring-1 ring-ink-100 backdrop-blur transition-opacity duration-200 hover:text-brand-600 focus-visible:opacity-100 group-hover:opacity-100 pointer-coarse:opacity-100"
        [attr.aria-label]="'Quick view of ' + product().name"
        (click)="quickView.open(product())"
      >
        <ol-icon name="search" [size]="16" />
      </button>

      <div class="ol-pcard__body flex flex-1 flex-col p-4 sm:p-5" [class]="bodyClass()">
        <!-- Eyebrow: who makes it, and which one it is. -->
        <div class="flex min-h-3 min-w-0 items-center gap-2 text-[0.6875rem] leading-none">
          @if (product().brand; as brand) {
            <a
              [routerLink]="brand.url"
              class="relative z-10 truncate font-semibold uppercase tracking-[0.12em] text-brand-600 hover:underline"
            >{{ brand.name }}</a>
          }
          @if (modelCode(); as code) {
            <span class="truncate font-mono tracking-tight text-ink-400">{{ code }}</span>
          }
        </div>

        @switch (headingLevel()) {
          @case (2) {
            <h2 class="ol-pcard__title mt-2" [class.ol-pcard__title--feature]="isFeature()">
              <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">{{ product().name }}</a>
            </h2>
          }
          @case (4) {
            <h4 class="ol-pcard__title mt-2" [class.ol-pcard__title--feature]="isFeature()">
              <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">{{ product().name }}</a>
            </h4>
          }
          @default {
            <h3 class="ol-pcard__title mt-2" [class.ol-pcard__title--feature]="isFeature()">
              <a [routerLink]="product().url" class="after:absolute after:inset-0 hover:text-brand-600">{{ product().name }}</a>
            </h3>
          }
        }

        <!--
          Availability as a status dot. Colour reinforces; the words carry it.
          Only in_stock earns green - "made to order" tinted as available tells
          a buyer with a deadline the opposite of what they need to know.
        -->
        <p class="mt-2.5 inline-flex items-center gap-1.5 text-xs font-medium" [class.text-emerald-700]="inStock()" [class.text-amber-700]="!inStock()">
          <span class="h-1.5 w-1.5 rounded-full" [class.bg-emerald-500]="inStock()" [class.bg-amber-500]="!inStock()" aria-hidden="true"></span>
          {{ availabilityLabel() }}
        </p>

        @if (specs().length) {
          <dl class="mt-3 grid gap-1 text-xs">
            @for (spec of specs(); track spec.label) {
              <div class="flex min-w-0 gap-1.5">
                <dt class="shrink-0 text-ink-400">{{ spec.label }}</dt>
                <dd class="truncate font-medium text-ink-700">{{ spec.value }}</dd>
              </div>
            }
          </dl>
        }

        @if (isFeature() && product().short_description; as summary) {
          <p class="mt-4 line-clamp-3 text-sm leading-relaxed text-ink-600">{{ summary }}</p>
        }

        <!-- "mt-auto" keeps a row of cards aligned on the action. -->
        <div class="mt-auto pt-4" [class]="actionClass()">
          @if (minimumLabel(); as minimum) {
            <p class="mb-2 text-xs text-ink-400">{{ minimum }}</p>
          }

          <div class="flex gap-2">
            <!-- Above the stretched ::after so the button is reachable. -->
            <button
              type="button"
              class="ol-pcard__cta relative z-10 inline-flex h-10 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold"
              [class.ol-pcard__cta--solid]="isFeature()"
              (click)="addToBasket()"
            >
              <ol-icon name="file-text" [size]="16" />
              Add to quote
            </button>
            @if (isFeature()) {
              <a
                [routerLink]="product().url"
                class="relative z-10 inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-ink-200 px-4 text-sm font-semibold text-ink-800 transition-colors hover:border-ink-300 hover:bg-ink-50"
              >
                Details
                <ol-icon name="arrow-right" [size]="16" />
              </a>
            }
          </div>
        </div>
      </div>
    </article>
  `,
  styles: `
    :host {
      display: block;
      height: 100%;
    }

    /*
     * Lift on hover with a soft, spread shadow - transform and shadow only, so
     * it never reflows a neighbour. The resting card carries a hairline shadow
     * so it reads as an object on the page rather than a ruled table cell.
     */
    .ol-pcard {
      box-shadow: 0 1px 2px rgb(16 24 40 / 0.04);
      transition:
        transform 0.28s var(--ease-out-soft),
        box-shadow 0.28s var(--ease-out-soft),
        border-color 0.28s var(--ease-out-soft);
    }

    @media (hover: hover) and (pointer: fine) {
      .ol-pcard:hover {
        transform: translateY(-3px);
        border-color: var(--color-ink-200);
        box-shadow: 0 22px 44px -22px rgb(16 24 40 / 0.28);
      }
    }

    /*
     * The image well: plain white with generous padding. Supplier photos are
     * shot on white, so any tinted ground shows each photo as a white box; on
     * white the product itself is the only thing that reads.
     * The image keeps object-fit: contain (.ol-contain) - a product is never
     * cropped.
     */
    .ol-pcard__well {
      background: #fff;
      padding: 1.25rem;
    }

    @media (min-width: 640px) {
      .ol-pcard__well {
        padding: 1.5rem;
      }
    }

    /*
     * The separator between photo and details: a light grey hairline, so the
     * white image well and the white body read as two parts of one object
     * without competing with the photo. On the spotlight card the body sits
     * beside the photo, so the line turns vertical from md up.
     */
    .ol-pcard__body {
      border-top: 1px solid var(--color-ink-100);
    }

    @media (min-width: 768px) {
      :host(.is-feature) .ol-pcard__body {
        border-top: 0;
        border-left: 1px solid var(--color-ink-100);
      }
    }

    /* Two lines, reserved: every title in a row takes the same height. */
    .ol-pcard__title {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      min-height: 2.6em;
      font-family: var(--font-heading);
      font-size: 0.9375rem;
      font-weight: 600;
      line-height: 1.3;
      color: var(--color-ink-900);
    }

    .ol-pcard__title--feature {
      -webkit-line-clamp: 3;
      min-height: 0;
      font-size: clamp(1.125rem, 1.6vw, 1.5rem);
      line-height: 1.25;
    }

    /* Quiet by default, brand on hover - a grid of solid blue bars shouts. */
    .ol-pcard__cta {
      border: 1px solid color-mix(in oklab, var(--color-brand-600) 22%, transparent);
      background: color-mix(in oklab, var(--color-brand-600) 6%, white);
      color: var(--color-brand-700);
      transition:
        background-color 0.2s var(--ease-out-soft),
        color 0.2s var(--ease-out-soft),
        border-color 0.2s var(--ease-out-soft);
    }

    .ol-pcard__cta:hover,
    .ol-pcard__cta--solid {
      border-color: var(--color-brand-600);
      background: var(--color-brand-600);
      color: #fff;
    }

    .ol-pcard__cta--solid:hover {
      background: var(--color-brand-700);
    }
  `,
})
export class ProductCard {
  protected readonly quickView = inject(QuickViewService);
  private readonly basket = inject(BasketStore);

  readonly product = input.required<ProductCardData>();

  /** 3 by default: a PLP card sits under the page h1 and a section h2. */
  readonly headingLevel = input<2 | 3 | 4>(3);

  /** `feature` lays the same card out as a spotlight: image beside the details. */
  readonly variant = input<'default' | 'feature'>('default');

  readonly imageSizes = input<string>('(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw');

  /**
   * Still emitted for any parent that wants to react (a toast, analytics), but
   * the card adds to the quote basket itself, so every grid and rail works
   * without wiring an output.
   */
  readonly addToQuote = output<ProductCardData>();

  protected readonly isFeature = computed(() => this.variant() === 'feature');

  /*
   * Variant classes as whole strings: Angular's [class.x] binding cannot take
   * Tailwind names with brackets or slashes, and listing them here keeps every
   * class literal for Tailwind's source scan.
   */
  protected readonly cardClass = computed(() => (this.isFeature() ? 'md:flex-row' : ''));
  protected readonly wellClass = computed(() => (this.isFeature() ? 'aspect-[4/3] md:aspect-[5/4] md:w-2/5' : 'aspect-square'));
  protected readonly bodyClass = computed(() => (this.isFeature() ? 'md:justify-center md:p-8' : ''));
  protected readonly actionClass = computed(() => (this.isFeature() ? 'md:mt-6 md:pt-0' : ''));

  /** Two key specs on a compact card, four on the spotlight. */
  protected readonly specs = computed(() => (this.product().key_specs ?? []).slice(0, this.isFeature() ? 4 : 2));

  /** Add this product to the quote basket, and notify any listener. */
  protected addToBasket(): void {
    this.basket.addProduct(this.product().slug);
    this.addToQuote.emit(this.product());
  }

  /**
   * Model code, MPN or SKU, in that order - the string B2B buyers search and
   * cross-reference, and how consumables are matched to machines.
   */
  protected readonly modelCode = computed(() => {
    const p = this.product();
    return p.model_code ?? p.mpn ?? p.sku ?? null;
  });

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
        // No lead-time figure on the card: it is confirmed per line on the quote.
        return 'Available to order';
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
