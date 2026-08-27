import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { Category } from '../core/api/catalogue-api';
import { Icon } from './icon';
import { iconForCategory } from '../core/layout/category-icons';
import { ResponsiveImage } from './responsive-image';

/**
 * A row of round category chips - image, name, link.
 *
 * ── What it shows depends on where you are ───────────────────────────────
 * Children where there are children; siblings where there are not. A leaf
 * page has nothing deeper to offer, and the useful thing at that point is
 * not an empty row but "the other kinds of chair" - somebody on Executive
 * Chairs who wanted Task Chairs is then one click away instead of two.
 *
 * ── It does not replace the breadcrumb, and must not ─────────────────────
 * A chip row reads like a breadcrumb and is not one. The breadcrumb carries
 * `BreadcrumbList` JSON-LD and the ancestor trail, which is what Google
 * reads for site structure; these are lateral links to peers or children,
 * which is a different relationship entirely. Both stay on the page.
 *
 * ── It does not replace the text category list either ────────────────────
 * The two-level anchor block below it is what keeps every product within 3
 * clicks of the homepage (D-011, SEO_SPEC 10.4) - a department that lists
 * only its subcategories puts products 4 clicks deep, which is exactly the
 * regression the crawl-depth gate caught once already. These chips show one
 * level; the block shows two. Removing the block to avoid the repetition
 * would trade a crawl guarantee for tidiness.
 *
 * ── Tinted when there is no photograph ───────────────────────────────────
 * Most categories have no image, so the fallback is the category's own icon
 * on a tint rather than a grey disc. It reads as a design rather than a
 * gap, and it upgrades to a photograph the moment one is imported without
 * anything here changing.
 */
@Component({
  selector: 'ol-category-chips',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, ResponsiveImage],
  template: `
    @if (categories().length) {
      <nav [attr.aria-label]="label()" class="ol-chips">
        <ul class="flex gap-6 overflow-x-auto pb-2 sm:gap-8">
          @for (category of categories(); track category.slug) {
            <li class="w-24 shrink-0 sm:w-28">
              <a [routerLink]="category.url" class="group block text-center">
                <span
                  class="mx-auto flex aspect-square w-20 items-center justify-center overflow-hidden rounded-full bg-brand-50 ring-1 ring-ink-100 transition group-hover:ring-brand-600 sm:w-24"
                >
                  @if (category.hero) {
                    <ol-image
                      [image]="category.hero"
                      sizes="96px"
                      imgClass="h-full w-full object-cover"
                    />
                  } @else {
                    <ol-icon
                      [name]="iconFor(category.slug)"
                      [size]="28"
                      class="text-brand-600"
                    />
                  }
                </span>
                <span
                  class="mt-2 block text-sm font-medium leading-snug text-ink-900 group-hover:text-brand-600"
                >{{ category.name }}</span>
              </a>
            </li>
          }
        </ul>
      </nav>
    }
  `,
  styles: `
    /*
     * Same treatment as the homepage department rail: the row scrolls, the
     * scrollbar does not show. Nothing here depends on the bar being visible
     * - touch drag, trackpad and tabbing to an off-screen link all still
     * scroll it natively.
     */
    .ol-chips ul {
      scrollbar-width: none;
    }

    .ol-chips ul::-webkit-scrollbar {
      display: none;
    }
  `,
})
export class CategoryChips {
  readonly categories = input.required<readonly Category[]>();

  /** Names the landmark: "Subcategories" or "Related categories". */
  readonly label = input<string>('Categories');

  protected iconFor(slug: string) {
    return iconForCategory(slug);
  }
}
