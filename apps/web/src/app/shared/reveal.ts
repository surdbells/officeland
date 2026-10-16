import { Directive, ElementRef, afterNextRender, inject, input } from '@angular/core';

/**
 * Reveal-on-scroll, as progressive enhancement.
 *
 * ── Why the hidden state is added in the browser, never in the HTML ───────
 * The obvious way to fade content in is to author it at `opacity: 0` and
 * animate to `1`. On a prerendered site (D-009) that is a correctness bug:
 * the static HTML a crawler reads, and the page a visitor sees before
 * hydration or with JavaScript off, would have every section below the hero
 * invisible. Google renders JS and might recover; Bing, WhatsApp and
 * Facebook link unfurls do not, and neither does the raw-HTML gate.
 *
 * So this directive adds the `ol-reveal` class itself, from the browser,
 * after render. The prerendered document is untouched: content is visible by
 * default, and the animation is a thing that only ever happens once real JS
 * is running. If hydration never happens, nothing hides.
 *
 * ── Reduced motion is a hard opt-out, checked twice ──────────────────────
 * The global `prefers-reduced-motion` block neutralises the transition, but a
 * neutralised transition on `opacity: 0` would leave the content hidden
 * rather than snapping it in. So the directive also refuses to add the class
 * at all when reduced motion is set — the CSS guard is the belt, this is the
 * braces.
 *
 * ── One shared observer ──────────────────────────────────────────────────
 * Every revealed element on a page shares a single IntersectionObserver
 * rather than each holding its own; the callback reads a per-element handler
 * off a WeakMap. It disconnects each element the first time it lands, so the
 * observer is only ever tracking what has not yet appeared.
 */
@Directive({
  selector: '[olReveal]',
  standalone: true,
})
export class Reveal {
  private readonly host = inject(ElementRef<HTMLElement>);

  /**
   * The entrance. 'up' (the default) rises and fades; 'fade' only fades, for
   * elements a translate would look wrong on; 'scale' settles up from 96%,
   * for imagery and feature panels.
   */
  readonly olReveal = input<'up' | 'fade' | 'scale' | ''>('');

  /**
   * A per-element delay in milliseconds, so a row of cards can be staggered
   * by passing `$index * 60`. Set as a custom property from the browser only,
   * so it never reaches the prerendered HTML.
   */
  readonly olRevealDelay = input<number>(0);

  constructor() {
    afterNextRender(() => {
      const el = this.host.nativeElement;

      // Both a capability check and the reduced-motion opt-out. Either one
      // missing means the content simply stays as authored: fully visible.
      if (
        typeof IntersectionObserver === 'undefined' ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
      ) {
        return;
      }

      const variant = this.olReveal();
      el.classList.add('ol-reveal');
      if (variant === 'fade' || variant === 'scale') {
        el.classList.add(`ol-reveal-${variant}`);
      }

      const delay = this.olRevealDelay();
      if (delay > 0) {
        el.style.setProperty('--ol-reveal-delay', `${delay}ms`);
      }

      observe(el);
    });
  }
}

/**
 * The shared observer, created lazily on first use so importing the directive
 * costs nothing on a page that never reveals anything.
 *
 * `rootMargin` with a negative bottom fires the reveal a little before the
 * element reaches the fold, so it is already settling as it scrolls into
 * view rather than starting the moment its top edge crosses the boundary.
 */
let sharedObserver: IntersectionObserver | null = null;

function observe(el: HTMLElement): void {
  sharedObserver ??= new IntersectionObserver(
    (entries, observer) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          entry.target.classList.add('ol-reveal-in');
          observer.unobserve(entry.target);
        }
      }
    },
    { rootMargin: '0px 0px -10% 0px', threshold: 0.05 },
  );

  sharedObserver.observe(el);
}
