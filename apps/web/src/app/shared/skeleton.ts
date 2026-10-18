import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * A single shimmering placeholder block.
 *
 * The CSR shell boots empty under Dynamic Rendering and fills as client
 * fetches resolve; a skeleton holds a section's layout and says "loading" in
 * that window rather than leaving a gap that reads as "empty". A crawler never
 * renders one - its HTML is produced after the data resolves (D-009), so the
 * loading branch is never its serialized state.
 *
 * It is purely decorative: `aria-hidden` keeps it out of the accessibility
 * tree, and the container that swaps it for real content is what carries
 * `aria-busy`. Compose several blocks for a card; the shape comes from the
 * utility classes passed in, which also set the base colour (`bg-ink-100` on
 * light ground, `bg-white/10` on a dark band) that `.ol-shimmer` sweeps over.
 */
@Component({
  selector: 'ol-skeleton',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '',
  host: {
    'aria-hidden': 'true',
    '[class]': 'hostClass()',
  },
})
export class Skeleton {
  /**
   * Tailwind classes for size, shape and base tone. Added to the always-on
   * `ol-shimmer block`, so a caller passes only what varies - e.g.
   * `h-4 w-2/3 rounded bg-ink-100` for a text line, or
   * `aspect-square w-full rounded-xl bg-ink-100` for an image well.
   */
  readonly shape = input<string>('h-4 w-full rounded bg-ink-100');

  // Composed rather than a static host class plus a [class] binding: that pair
  // can clobber in either direction, and this guarantees `ol-shimmer` survives
  // whatever shape a caller passes.
  protected readonly hostClass = computed(() => `ol-shimmer block ${this.shape()}`);
}
