import { Directive, ElementRef, afterNextRender, inject } from '@angular/core';

/**
 * Counts a number up from zero when it scrolls into view.
 *
 * ── The final value is authored, not injected ────────────────────────────
 * The directive reads its target straight out of the element's own text
 * content: `<span olCount>138</span>`. That is the number the prerendered
 * HTML already contains, so a crawler and a no-JS visitor read "138", and
 * the animation is purely something the browser does on top. There is no
 * second source of truth to drift — the DOM text is the target.
 *
 * It preserves the decimal places it finds, so `4.4` counts up in tenths and
 * `138` in whole numbers, and it leaves any surrounding text (a star, a plus,
 * a currency mark placed in sibling nodes) untouched.
 *
 * Like the Reveal directive it is browser-only and reduced-motion-aware: if
 * motion is suppressed, or the value cannot be parsed, the authored text is
 * simply left as it is.
 */
@Directive({
  selector: '[olCount]',
  standalone: true,
})
export class CountUp {
  private readonly host = inject(ElementRef<HTMLElement>);

  constructor() {
    afterNextRender(() => {
      const el = this.host.nativeElement;
      const raw = (el.textContent ?? '').trim();
      const target = Number.parseFloat(raw.replace(/,/g, ''));

      if (
        Number.isNaN(target) ||
        typeof IntersectionObserver === 'undefined' ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
      ) {
        return;
      }

      const decimals = raw.includes('.') ? (raw.split('.')[1]?.length ?? 0) : 0;
      const grouped = raw.includes(',');

      const observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (entry.isIntersecting) {
              observer.disconnect();
              run(el, target, decimals, grouped);
            }
          }
        },
        { threshold: 0.4 },
      );

      observer.observe(el);
    });
  }
}

/**
 * Eases the count with the same decelerating curve the CSS easings use, so a
 * number arriving on screen feels of a piece with the panels sliding in
 * around it. 1100ms is long enough to register and short enough not to make a
 * reader wait on a figure they came to read.
 */
function run(el: HTMLElement, target: number, decimals: number, grouped: boolean): void {
  const duration = 1100;
  const start = performance.now();

  const format = (value: number): string => {
    const fixed = value.toFixed(decimals);
    if (!grouped) {
      return fixed;
    }
    const [whole, fraction] = fixed.split('.');
    const withSeparators = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return fraction ? `${withSeparators}.${fraction}` : withSeparators;
  };

  const tick = (now: number): void => {
    const progress = Math.min(1, (now - start) / duration);
    // cubic-bezier(0.16, 1, 0.3, 1) approximated as an ease-out cubic.
    const eased = 1 - Math.pow(1 - progress, 3);
    el.textContent = format(target * eased);

    if (progress < 1) {
      requestAnimationFrame(tick);
    } else {
      el.textContent = format(target);
    }
  };

  requestAnimationFrame(tick);
}
