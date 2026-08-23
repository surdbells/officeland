import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import type { Image } from '../core/api/catalogue-api';
import { ResponsiveImage } from './responsive-image';

/**
 * The PDP gallery.
 *
 * ── Every image is in the HTML ───────────────────────────────────────────
 * The non-active images are rendered and hidden with CSS, not withheld until
 * a click. Google indexes product imagery, and an image that only exists after
 * a click is an image it may never see. It also means switching is instant and
 * needs no fetch.
 *
 * ── Only the first image is the LCP element ──────────────────────────────
 * It takes `fetchpriority="high"` and eager loading; every other image is
 * lazy. Marking several images high priority is the same as marking none, and
 * the PDP page-weight budget is 800 KB (§14.9) — a five-image gallery at full
 * width would spend most of it before the buyer has scrolled.
 *
 * ── Thumbnails are buttons, not links ────────────────────────────────────
 * They change state within the page rather than navigating, so `<button>` is
 * the honest element. `aria-pressed` communicates which is active, and the
 * live region announces the change to a screen reader that cannot see it.
 */
@Component({
  selector: 'ol-product-gallery',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ResponsiveImage],
  template: `
    @if (images().length) {
      <div>
        <div class="overflow-hidden rounded-lg border border-ink-100 bg-white">
          @for (image of images(); track image.uuid; let i = $index) {
            <!-- Rendered whether or not active: hidden is a CSS state here,
                 never an absent element. -->
            <div class="aspect-square" [hidden]="i !== activeIndex()">
              <ol-image
                [image]="image"
                [priority]="i === 0"
                [sizes]="'(min-width: 1024px) 40vw, 100vw'"
                imgClass="h-full w-full object-contain p-4"
              />
            </div>
          }
        </div>

        @if (images().length > 1) {
          <p class="sr-only" aria-live="polite">
            Image {{ activeIndex() + 1 }} of {{ images().length }}
          </p>

          <ul class="mt-3 flex flex-wrap gap-2" role="list">
            @for (image of images(); track image.uuid; let i = $index) {
              <li>
                <button
                  type="button"
                  class="block h-16 w-16 overflow-hidden rounded border p-1"
                  [class.border-brand-600]="i === activeIndex()"
                  [class.border-ink-200]="i !== activeIndex()"
                  [attr.aria-pressed]="i === activeIndex()"
                  [attr.aria-label]="'Show image ' + (i + 1) + ': ' + image.alt"
                  (click)="show(i)"
                >
                  <ol-image
                    [image]="image"
                    [sizes]="'64px'"
                    imgClass="h-full w-full object-contain"
                  />
                </button>
              </li>
            }
          </ul>
        }
      </div>
    } @else {
      <!-- Holds the layout so a catalogue mid-way through image ingestion
           does not reflow as pictures arrive. -->
      <div class="aspect-square rounded-lg bg-ink-50" aria-hidden="true"></div>
    }
  `,
})
export class ProductGallery {
  readonly images = input.required<readonly Image[]>();

  private readonly active = signal(0);

  protected readonly activeIndex = computed(() => {
    const count = this.images().length;

    // Clamped rather than trusted: navigating between products reuses the
    // component, and the new product may have fewer images than the index
    // left over from the last one.
    return count === 0 ? 0 : Math.min(this.active(), count - 1);
  });

  protected show(index: number): void {
    this.active.set(index);
  }
}
