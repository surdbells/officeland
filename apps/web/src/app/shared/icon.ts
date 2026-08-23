import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * Inline SVG icons, from Lucide 1.33 (ISC licensed).
 *
 * ── Why the paths are inlined and not a package ──────────────────────────
 * `lucide-angular` caps its peer range at Angular 21 and this project is on
 * 22, so installing it needs `--legacy-peer-deps` — an unresolved dependency
 * conflict carried into a production build to save copying some path data.
 * Not a trade worth making.
 *
 * Inlining is also better on the merits:
 *
 *   • **Budget.** Only the icons used ship. An icon package that tree-shakes
 *     badly is the classic way a 130 KB budget (SEO_SPEC §9) becomes 200 KB.
 *   • **Prerendering.** These render into the static HTML at build time
 *     (D-009). An icon library that mounts client-side would leave holes in
 *     the prerendered page and shift the layout on hydration.
 *   • **CSP.** No external request, so `default-src 'self'` is untouched.
 *
 * ── Why a @switch and not [innerHTML] ────────────────────────────────────
 * The obvious implementation binds sanitised markup to `[innerHTML]`. It
 * builds, and then **every prerendered route fails with
 * `Error: NotYetImplemented`** — Angular's SSR DOM (Domino) does not
 * implement the innerHTML setter for SVG elements, so the icons cannot be
 * server-rendered at all.
 *
 * Declaring the shapes in the template is more verbose and it prerenders,
 * which under D-009 is the only thing that counts. It also removes the
 * sanitiser bypass the other approach required.
 *
 * ── Always decorative, never the label ───────────────────────────────────
 * `aria-hidden="true"` and `focusable="false"`, always. An icon is never the
 * accessible name of a control — the a11y gate fails any link or button
 * without one, and a screen reader announcing "phone" for a number is worse
 * than useless. Every icon sits beside real text or an `sr-only` label.
 *
 * `currentColor` throughout, so an icon inherits the colour of the text it
 * sits with and contrast is decided once, by the text.
 */
export type IconName =
  | 'phone'
  | 'message-circle'
  | 'map-pin'
  | 'mail'
  | 'search'
  | 'chevron-right'
  | 'chevron-down'
  | 'shield-check'
  | 'truck'
  | 'store'
  | 'clock'
  | 'file-text'
  | 'arrow-right'
  | 'arrow-up'
  | 'send'
  | 'badge-check'
  | 'building-2'
  | 'droplets'
  | 'bug-off'
  | 'flame'
  | 'layers'
  | 'leaf'
  | 'sparkles';

@Component({
  selector: 'ol-icon',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg
      xmlns="http://www.w3.org/2000/svg"
      [attr.width]="size()"
      [attr.height]="size()"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      [attr.stroke-width]="strokeWidth()"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      @switch (name()) {
        @case ('phone') {
          <path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384" />
        }
        @case ('message-circle') {
          <path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719" />
        }
        @case ('map-pin') {
          <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
          <circle cx="12" cy="10" r="3" />
        }
        @case ('mail') {
          <path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" />
          <rect x="2" y="4" width="20" height="16" rx="2" />
        }
        @case ('search') {
          <path d="m21 21-4.34-4.34" />
          <circle cx="11" cy="11" r="8" />
        }
        @case ('chevron-right') {
          <path d="m9 18 6-6-6-6" />
        }
        @case ('chevron-down') {
          <path d="m6 9 6 6 6-6" />
        }
        @case ('shield-check') {
          <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
          <path d="m9 12 2 2 4-4" />
        }
        @case ('truck') {
          <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" />
          <path d="M15 18H9" />
          <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14" />
          <circle cx="17" cy="18" r="2" />
          <circle cx="7" cy="18" r="2" />
        }
        @case ('store') {
          <path d="M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5" />
          <path d="M17.774 10.31a1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.451 0 1.12 1.12 0 0 0-1.548 0 2.5 2.5 0 0 1-3.452 0 1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.77-3.248l2.889-4.184A2 2 0 0 1 7 2h10a2 2 0 0 1 1.653.873l2.895 4.192a2.5 2.5 0 0 1-3.774 3.244" />
          <path d="M4 10.95V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8.05" />
        }
        @case ('clock') {
          <circle cx="12" cy="12" r="10" />
          <path d="M12 6v6l4 2" />
        }
        @case ('file-text') {
          <path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z" />
          <path d="M14 2v5a1 1 0 0 0 1 1h5" />
          <path d="M10 9H8" />
          <path d="M16 13H8" />
          <path d="M16 17H8" />
        }
        @case ('arrow-right') {
          <path d="M5 12h14" />
          <path d="m12 5 7 7-7 7" />
        }
        @case ('arrow-up') {
          <path d="m5 12 7-7 7 7" />
          <path d="M12 19V5" />
        }
        @case ('send') {
          <path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" />
          <path d="m21.854 2.147-10.94 10.939" />
        }
        @case ('badge-check') {
          <path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
          <path d="m9 12 2 2 4-4" />
        }
        @case ('droplets') {
          <path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z" />
          <path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97" />
        }
        @case ('bug-off') {
          <path d="M12 20v-8" />
          <path d="M12.656 7H14a4 4 0 0 1 4 4v1.344" />
          <path d="M14.12 3.88 16 2" />
          <path d="M17.123 17.123A6 6 0 0 1 6 14v-3a4 4 0 0 1 1.72-3.287" />
          <path d="m2 2 20 20" />
          <path d="M21 5a4 4 0 0 1-3.55 3.97" />
          <path d="M22 13h-3.344" />
          <path d="M3 21a4 4 0 0 1 3.81-4" />
          <path d="M3 5a4 4 0 0 0 3.55 3.97" />
          <path d="M6 13H2" />
          <path d="m8 2 1.88 1.88" />
          <path d="M9.712 4.06A3 3 0 0 1 15 6v1.13" />
        }
        @case ('flame') {
          <path d="M12 3q1 4 4 6.5t3 5.5a1 1 0 0 1-14 0 5 5 0 0 1 1-3 1 1 0 0 0 5 0c0-2-1.5-3-1.5-5q0-2 2.5-4" />
        }
        @case ('layers') {
          <path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z" />
          <path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12" />
          <path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17" />
        }
        @case ('leaf') {
          <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
          <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />
        }
        @case ('sparkles') {
          <path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z" />
          <path d="M20 2v4" />
          <path d="M22 4h-4" />
          <circle cx="4" cy="20" r="2" />
        }
        @case ('building-2') {
          <path d="M10 12h4" />
          <path d="M10 8h4" />
          <path d="M14 21v-3a2 2 0 0 0-4 0v3" />
          <path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2" />
          <path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" />
        }
      }
    </svg>
  `,
  styles: `
    :host {
      display: inline-flex;
      flex-shrink: 0;
    }
  `,
})
export class Icon {
  readonly name = input.required<IconName>();

  /** Matches the text it sits beside; 20 suits body copy. */
  readonly size = input(20);

  /**
   * 1.5 rather than Lucide's default 2. At 20px and below a 2px stroke reads
   * heavy next to text at normal weight — the icon shouts over its label.
   */
  readonly strokeWidth = input(1.5);
}
