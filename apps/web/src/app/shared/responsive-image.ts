import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import type { Image } from '../core/api/catalogue-api';

/**
 * A responsive `<picture>` built from the server’s derivative list (§12.5).
 *
 * Not product-specific: a category hero is the same element with a different
 * `sizes`. It started life on the product card, which is why it was called
 * ProductImage; the name was the only thing tying it there.
 *
 * ── Why the srcsets are not composed here ────────────────────────────────
 * Generation is resumable and throttled (Phase 4), so a product ingested
 * minutes ago may have `thumb` and `card` but not `zoom`. Only the server
 * knows which derivatives exist, so it sends them; this component renders
 * what it is given and invents nothing. A srcset that advertises a 404 is
 * worse than a narrower one.
 *
 * ── Why width and height are always set ──────────────────────────────────
 * They are the CLS fix, and CLS is a Core Web Vital the Phase 8 gate
 * measures. An `<img>` without intrinsic dimensions reflows everything below
 * it when it loads. They are attributes rather than CSS because the browser
 * uses them to reserve space before any stylesheet has applied.
 *
 * ── priority ─────────────────────────────────────────────────────────────
 * The LCP element - the first PDP gallery image, or nothing at all on a grid -
 * takes `fetchpriority="high"` and eager loading. Everything else is lazy.
 * Marking several images high priority is the same as marking none.
 */
@Component({
  selector: 'ol-image',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (image(); as img) {
      <picture>
        @for (source of img.sources; track source.type) {
          <source [attr.type]="source.type" [attr.srcset]="source.srcset" [attr.sizes]="sizes()" />
        }
        <img
          [src]="img.src"
          [alt]="img.alt"
          [attr.width]="img.width"
          [attr.height]="img.height"
          [attr.sizes]="sizes()"
          [attr.loading]="priority() ? 'eager' : 'lazy'"
          [attr.fetchpriority]="priority() ? 'high' : null"
          [attr.decoding]="priority() ? 'sync' : 'async'"
          [class]="imgClass()"
        />
      </picture>
    } @else {
      <!--
        A designed empty state, not an empty box.

        ── Why there is no generated placeholder image ──────────────────────
        The obvious move when 1,200 products have no photograph is to
        generate one. It is the wrong move: a generated product image is a
        picture of a product that does not look like that, and on a site
        where a buyer is deciding whether a chair suits their boardroom, an
        invented photograph is worse than an honest absence. It is also the
        kind of thing that survives to production because it looks finished.
        So this says "no photograph yet" in a way that is deliberate rather
        than broken.

        Aspect ratio is held, so a catalogue part-way through ingestion does
        not reflow as real images land - the reason this is a sized box and
        not a collapsed one.

        aria-hidden with no alt: there is nothing to describe. An empty alt
        is correct for decoration, and inventing one ("product image") tells
        a screen-reader user something false.
      -->
      <div
        [class]="placeholderClass()"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 48 48"
          fill="none"
          stroke="currentColor"
          stroke-width="1.25"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="h-1/4 w-1/4 max-h-12 max-w-12 text-ink-300"
        >
          <rect x="6" y="10" width="36" height="28" rx="3" />
          <path d="M6 30l9-8a3 3 0 0 1 4 0l7 7" />
          <path d="M28 26l4-3a3 3 0 0 1 4 0l6 5" />
          <circle cx="18" cy="19" r="2.5" />
        </svg>
      </div>
    }
  `,
})
export class ResponsiveImage {
  readonly image = input<Image | null>(null);
  readonly priority = input<boolean>(false);

  /**
   * Defaults to the PLP grid, which is the overwhelmingly common case.
   * Without a `sizes` attribute the browser assumes 100vw and downloads the
   * widest candidate for a card that renders at 400px - the single most
   * common way a responsive image wastes bandwidth.
   */
  readonly sizes = input<string>('(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw');

  readonly imgClass = input<string>('h-full w-full object-contain');
  readonly placeholderClass = input<string>(
    'flex h-full w-full items-center justify-center bg-ink-50',
  );

  /** Present so a caller can branch without reaching into the shape. */
  protected readonly hasImage = computed(() => this.image() !== null);
}
