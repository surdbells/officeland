import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * The masthead - the brand band that opens every page.
 *
 * ── Why it sits above everything ─────────────────────────────────────────
 * This is the shape the business asked for and it matches the legacy site:
 * a full-bleed band in the brand colour carrying the company name and what
 * the company sells, then the navigation beneath it. It is the first thing
 * in the document, before the announcement bar and the utility row.
 *
 * It replaced a small wordmark inside the sticky header, so this component
 * is now the site's only identity mark. That makes it a link to the home
 * page rather than decoration.
 *
 * ── Fixed height, not fitted height ──────────────────────────────────────
 * The band's height is set by the band, not by the size of the text inside
 * it. The wordmark scales with the viewport (`clamp`), and if the box grew
 * with the type then every viewport-width change - and every webfont swap
 * once the licensed files land (Q17) - would move the rest of the page.
 * Fixing the height keeps CLS at 0, which the accessibility gate asserts.
 *
 * ── It is on the LCP path ────────────────────────────────────────────────
 * Large text at the top of the document is a candidate for Largest
 * Contentful Paint on every route. Text, deliberately: an image here would
 * put a network round-trip in front of the metric, and SEO_SPEC §9 has no
 * room for that. If this is ever replaced with a logo file it must be
 * inlined or preloaded, and the LCP measured again.
 *
 * ── Naming ───────────────────────────────────────────────────────────────
 * The accessible name contains the visible text verbatim. WCAG 2.5.3 (Label
 * in Name) requires it, and it is also what makes "click Office Land Ltd"
 * work for a voice-control user.
 */
@Component({
  selector: 'ol-brand-masthead',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <!--
      A named <section>, not a bare <div>.

      It sits outside <header>, so as a plain div its content belonged to no
      landmark and screen-reader landmark navigation skipped the site's own
      name. The axe-core gate failed all 193 pages on it. Same fix, and the
      same reason, as the announcement bar in site-header.

      It cannot be a second <header>: that would be a second banner landmark.
      And it cannot be folded into the existing one, because the header below
      is sticky and a sticky element only sticks within its own containing
      block - wrapping the two would make the nav unstick the moment the
      masthead scrolled away.
    -->
    <section aria-label="Office Land Ltd">
      <a
        routerLink="/"
        aria-label="Office Land Ltd, home"
        class="ol-masthead flex flex-col items-center justify-center bg-brand-600 px-(--spacing-gutter) text-center"
      >
        <span class="ol-masthead__name font-heading font-extrabold uppercase leading-none tracking-tight text-white">
          Office Land Ltd
        </span>
        <span
          class="ol-masthead__tagline mt-2 font-heading font-semibold uppercase leading-none text-white/90"
        >
          Office Furniture &ndash; IT Products &ndash; Stationery
        </span>
      </a>
    </section>
  `,
  styles: `
    /*
     * Heights are fixed per breakpoint; only the type inside them scales.
     * See the class comment - a band that grows with its own text moves the
     * page every time the type resolves differently.
     */
    .ol-masthead {
      height: 7rem;
    }

    .ol-masthead__name {
      font-size: clamp(1.75rem, 6.5vw, 3rem);
    }

    .ol-masthead__tagline {
      font-size: clamp(0.625rem, 1.6vw, 0.8125rem);
      letter-spacing: 0.16em;
    }

    @media (min-width: 640px) {
      .ol-masthead {
        height: 9rem;
      }

      .ol-masthead__name {
        font-size: clamp(3rem, 6.5vw, 4.5rem);
      }

      .ol-masthead__tagline {
        font-size: 0.9375rem;
      }
    }
  `,
})
export class BrandMasthead {}
