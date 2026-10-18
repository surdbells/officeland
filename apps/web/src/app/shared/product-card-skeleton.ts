import { ChangeDetectionStrategy, Component } from '@angular/core';
import { Skeleton } from './skeleton';

/**
 * The loading placeholder for a product card.
 *
 * It mirrors the real card's box - border, padding, square image, a title, the
 * chip row and the action button - so a grid of these holds the exact height a
 * grid of cards will take, and the real cards drop in without shifting the page
 * (the layout-stability half of why a skeleton beats a spinner here).
 *
 * Used wherever a listing grid fetches on the client: the PLP, search and
 * brand hubs. A crawler is served the products directly and never this.
 */
@Component({
  selector: 'ol-product-card-skeleton',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Skeleton],
  host: { 'aria-hidden': 'true' },
  template: `
    <div class="flex h-full flex-col rounded-lg border border-ink-100 bg-white p-4">
      <ol-skeleton shape="mb-3 aspect-square w-full rounded-md bg-ink-100" />
      <ol-skeleton shape="h-4 w-5/6 rounded bg-ink-100" />
      <ol-skeleton shape="mt-2 h-4 w-2/3 rounded bg-ink-100" />
      <div class="mt-3 flex gap-1.5">
        <ol-skeleton shape="h-5 w-14 rounded bg-ink-100" />
        <ol-skeleton shape="h-5 w-16 rounded bg-ink-100" />
      </div>
      <div class="mt-auto pt-3">
        <ol-skeleton shape="h-10 w-full rounded-lg bg-ink-100" />
      </div>
    </div>
  `,
})
export class ProductCardSkeleton {}
