import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { Router } from '@angular/router';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { catchError, debounceTime, distinctUntilChanged, of, switchMap } from 'rxjs';
import { CatalogueApi, type Suggestions } from '../core/api/catalogue-api';
import { Icon } from './icon';
import { ResponsiveImage } from './responsive-image';

/**
 * Splits a label around the typed term (case-insensitive) so the match can be
 * set in bold - the cue that tells a buyer why each row is there.
 */
function highlight(label: string, term: string): { text: string; match: boolean }[] {
  const needle = term.trim().toLowerCase();
  if (needle.length < 2) return [{ text: label, match: false }];

  const parts: { text: string; match: boolean }[] = [];
  const haystack = label.toLowerCase();
  let from = 0;

  for (let at = haystack.indexOf(needle); at !== -1; at = haystack.indexOf(needle, from)) {
    if (at > from) parts.push({ text: label.slice(from, at), match: false });
    parts.push({ text: label.slice(at, at + needle.length), match: true });
    from = at + needle.length;
  }
  if (from < label.length) parts.push({ text: label.slice(from), match: false });

  return parts;
}

/**
 * The header search: a real form, with live suggestions layered on top.
 *
 * ── The form works without any of this ───────────────────────────────────
 * It is still `action="/search" method="get"`, so Enter submits and lands on
 * a real results page even with JavaScript off, mid-hydration, or if the
 * suggest endpoint is down. Everything below is an enhancement over a thing
 * that already worked — which is the only way to add a typeahead to a site
 * whose pages are prerendered (D-009).
 *
 * ── The submit button is a bug fix, not decoration ───────────────────────
 * There was no submit button. HTML implicit submission means Enter worked,
 * so the form was not broken in the strict sense — but a search field with
 * no button gives a visitor nothing to click, and on a touch keyboard it
 * depends on them finding "Go". Reported as "the search bar is not
 * working", which is the correct description of a control that offers no
 * way to operate it.
 *
 * ── Combobox semantics, not a div with a list ────────────────────────────
 * `role="combobox"` with `aria-expanded`, `aria-controls` and
 * `aria-activedescendant` is what makes a typeahead usable by a screen
 * reader: the input keeps focus while the highlighted option is announced.
 * Arrow keys move, Enter opens the highlight, Escape closes and keeps the
 * typed text. Without these it is a mouse-only feature.
 *
 * ── Why suggestions are not links in the prerendered HTML ────────────────
 * They are generated per keystroke and cannot exist at build time. They are
 * also not a crawl surface: everything they point at is already reachable
 * from the megamenu and the category tree, so nothing is lost by them being
 * client-only.
 */
@Component({
  selector: 'ol-site-search',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, ResponsiveImage],
  template: `
    <form
      action="/search"
      method="get"
      role="search"
      [attr.aria-label]="label()"
      class="relative"
      (submit)="onSubmit($event)"
    >
      <label [attr.for]="inputId()" class="sr-only">Search products</label>

      <div class="flex">
        <input
          #field
          [id]="inputId()"
          name="q"
          type="search"
          autocomplete="off"
          placeholder="Search by product, brand or model code"
          class="w-full rounded-l-lg border border-r-0 border-ink-200 px-4 py-2.5 focus:border-brand-600"
          role="combobox"
          aria-autocomplete="list"
          [attr.aria-expanded]="isOpen()"
          [attr.aria-controls]="listId()"
          [attr.aria-activedescendant]="activeId()"
          (input)="onInput($event)"
          (keydown)="onKeydown($event)"
          (focus)="onInput($event)"
        />

        <!--
          The missing button. Type "search" so it submits the form natively:
          a click works before hydration exactly as Enter always did.
        -->
        <button
          type="submit"
          class="rounded-r-lg border border-brand-600 bg-brand-600 px-4 text-white transition-colors hover:bg-brand-700"
          aria-label="Search"
        >
          <ol-icon name="search" [size]="18" />
        </button>
      </div>

      @if (isOpen()) {
        <div
          [id]="listId()"
          role="listbox"
          aria-label="Search suggestions"
          class="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-2xl ring-1 ring-ink-900/5"
        >
          <div class="max-h-[min(32rem,55vh)] sm:max-h-[min(32rem,70vh)] overflow-y-auto overscroll-contain p-2">
            @if (products().length) {
              <p class="px-3 pb-1.5 pt-2 font-heading text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-ink-400">Products</p>
              @for (option of products(); track option.url) {
                <div
                  [id]="'suggest-' + option.index"
                  role="option"
                  [attr.aria-selected]="option.index === active()"
                  class="flex cursor-pointer items-center gap-3 rounded-xl px-2 py-2 transition-colors"
                  [class.bg-brand-50]="option.index === active()"
                  (mouseenter)="active.set(option.index)"
                  (mousedown)="go($event, option.url)"
                >
                  <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-ink-100 bg-white">
                    @if (option.image; as image) {
                      <ol-image [image]="image" sizes="48px" imgClass="h-12 w-12 object-contain p-1" />
                    } @else {
                      <ol-icon name="box" [size]="20" class="text-ink-300" />
                    }
                  </span>
                  <span class="min-w-0 flex-1">
                    @if (option.brand) {
                      <span class="block text-[0.7rem] font-semibold uppercase tracking-[0.1em] text-brand-600">{{ option.brand }}</span>
                    }
                    <span class="line-clamp-1 text-sm text-ink-700">
                      <!-- One line on purpose: whitespace between the parts would split words. -->
                      @for (part of option.parts; track $index) {<span [class]="part.match ? 'font-semibold text-ink-900' : ''">{{ part.text }}</span>}
                    </span>
                    @if (option.meta) {
                      <span class="mt-0.5 inline-block rounded bg-ink-50 px-1.5 font-mono text-[0.7rem] text-ink-500">{{ option.meta }}</span>
                    }
                  </span>
                  <ol-icon
                    name="arrow-right"
                    [size]="15"
                    class="shrink-0 text-brand-600 transition-opacity"
                    [class.opacity-0]="option.index !== active()"
                  />
                </div>
              }
            }

            @if (categories().length || brands().length) {
              <div
                class="grid gap-3 px-1 pb-1"
                [class.mt-1]="products().length"
                [class.border-t]="products().length"
                [class.border-ink-100]="products().length"
                [class.pt-3]="products().length"
                [class.sm:grid-cols-2]="categories().length && brands().length"
              >
                @if (categories().length) {
                  <div>
                    <p class="px-2 pb-1.5 font-heading text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-ink-400">Categories</p>
                    @for (option of categories(); track option.url) {
                      <div
                        [id]="'suggest-' + option.index"
                        role="option"
                        [attr.aria-selected]="option.index === active()"
                        class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-ink-800 transition-colors"
                        [class.bg-brand-50]="option.index === active()"
                        (mouseenter)="active.set(option.index)"
                        (mousedown)="go($event, option.url)"
                      >
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-600"><ol-icon name="layers" [size]="14" /></span>
                        <span class="truncate">{{ option.label }}</span>
                      </div>
                    }
                  </div>
                }
                @if (brands().length) {
                  <div>
                    <p class="px-2 pb-1.5 font-heading text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-ink-400">Brands</p>
                    @for (option of brands(); track option.url) {
                      <div
                        [id]="'suggest-' + option.index"
                        role="option"
                        [attr.aria-selected]="option.index === active()"
                        class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-ink-800 transition-colors"
                        [class.bg-brand-50]="option.index === active()"
                        (mouseenter)="active.set(option.index)"
                        (mousedown)="go($event, option.url)"
                      >
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-ink-900 text-[0.65rem] font-bold text-white">{{ option.label.slice(0, 2).toUpperCase() }}</span>
                        <span class="truncate">{{ option.label }}</span>
                      </div>
                    }
                  </div>
                }
              </div>
            }

            @if (flat().length === 0) {
              <div class="flex items-center gap-3 px-3 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink-50 text-ink-400"><ol-icon name="search" [size]="18" /></span>
                <div class="text-sm">
                  <p class="font-semibold text-ink-900">No quick matches for &ldquo;{{ term().trim() }}&rdquo;</p>
                  <p class="text-ink-500">Press Enter for a full search, or ask us to quote it.</p>
                </div>
              </div>
            }
          </div>

          <!-- Always offer the full results page: the dropdown is a shortcut, not the answer. -->
          <button
            type="submit"
            class="flex w-full cursor-pointer items-center justify-between gap-3 border-t border-ink-100 bg-ink-50 px-4 py-3 text-left text-sm transition-colors hover:bg-brand-50"
            (mousedown)="$event.preventDefault()"
          >
            <span class="min-w-0 truncate text-ink-700">
              See all results for <span class="font-semibold text-brand-700">&ldquo;{{ term().trim() }}&rdquo;</span>
            </span>
            <span class="hidden shrink-0 items-center gap-1.5 text-xs text-ink-400 sm:inline-flex">
              <kbd class="rounded border border-ink-200 bg-white px-1.5 py-0.5 font-sans text-[0.7rem] text-ink-500">&uarr;&darr;</kbd> to move
              <kbd class="ml-1 rounded border border-ink-200 bg-white px-1.5 py-0.5 font-sans text-[0.7rem] text-ink-500">Enter</kbd> to open
            </span>
          </button>
        </div>
      }
    </form>
  `,
})
export class SiteSearch {
  private readonly api = inject(CatalogueApi);
  private readonly router = inject(Router);

  /**
   * Distinct per instance, because several pages carry a second search
   * landmark. Two inputs sharing an id makes one label point at the wrong
   * control, and axe fails the duplicate outright.
   */
  readonly inputId = input<string>('site-search');

  readonly label = input<string>('Search the catalogue');

  protected readonly listId = computed(() => `${this.inputId()}-suggestions`);

  private readonly field = viewChild<ElementRef<HTMLInputElement>>('field');

  protected readonly term = signal('');
  protected readonly active = signal(-1);
  private readonly dismissed = signal(false);

  /**
   * Debounced so a fast typist makes one request rather than eight, and
   * distinct so re-focusing or moving the caret does not refetch what is
   * already on screen.
   *
   * Two characters is the floor: one letter matches most of the catalogue
   * and the list is noise.
   */
  private readonly suggestions = toSignal(
    toObservable(this.term).pipe(
      debounceTime(150),
      distinctUntilChanged(),
      switchMap((term) =>
        term.trim().length < 2
          ? of(null)
          : this.api.suggest(term).pipe(catchError(() => of(null))),
      ),
    ),
    { initialValue: null as Suggestions | null },
  );

  protected readonly groups = computed(() => {
    const data = this.suggestions();

    if (!data) {
      return [];
    }

    let index = 0;

    return (
      [
        { heading: 'Products', items: data.products ?? [] },
        { heading: 'Categories', items: data.categories ?? [] },
        { heading: 'Brands', items: data.brands ?? [] },
      ] as const
    )
      .filter((group) => group.items.length > 0)
      .map((group) => ({
        heading: group.heading,
        options: group.items.map((item) => ({
          label: item.label,
          url: item.url,
          meta: item.meta ?? null,
          // "HP / HP OfficeJet..." says it twice; show the brand only when the name does not.
          brand:
            item.brand && !item.label.toLowerCase().startsWith(item.brand.toLowerCase())
              ? item.brand
              : null,
          image: item.image ?? null,
          parts: highlight(item.label, this.term()),
          index: index++,
        })),
      }));
  });

  /** Products carry thumbnails, so they render apart from the text-only groups. */
  protected readonly products = computed(() => this.groupNamed('Products'));
  protected readonly categories = computed(() => this.groupNamed('Categories'));
  protected readonly brands = computed(() => this.groupNamed('Brands'));

  private groupNamed(heading: string) {
    return this.groups().find((group) => group.heading === heading)?.options ?? [];
  }

  /** One flat list, so arrow keys cross group boundaries without special cases. */
  protected readonly flat = computed(() => this.groups().flatMap((group) => group.options));

  protected readonly isOpen = computed(
    () => !this.dismissed() && this.term().trim().length >= 2 && this.suggestions() !== null,
  );

  protected readonly activeId = computed(() =>
    this.active() >= 0 ? `suggest-${this.active()}` : null,
  );

  protected onInput(event: Event): void {
    this.term.set((event.target as HTMLInputElement).value);
    this.dismissed.set(false);
    this.active.set(-1);
  }

  protected onKeydown(event: KeyboardEvent): void {
    const options = this.flat();

    switch (event.key) {
      case 'ArrowDown':
        if (options.length > 0) {
          event.preventDefault();
          this.active.set((this.active() + 1) % options.length);
        }
        break;

      case 'ArrowUp':
        if (options.length > 0) {
          event.preventDefault();
          this.active.set(this.active() <= 0 ? options.length - 1 : this.active() - 1);
        }
        break;

      case 'Enter': {
        const chosen = options[this.active()];

        // Nothing highlighted means the visitor wants the full results page,
        // so the form submits as it always did.
        if (chosen) {
          event.preventDefault();
          this.navigate(chosen.url);
        }
        break;
      }

      case 'Escape':
        // Closes the list and keeps what was typed. Clearing the field on
        // Escape is the behaviour people complain about.
        this.dismissed.set(true);
        this.active.set(-1);
        break;

      default:
        break;
    }
  }

  /**
   * mousedown rather than click: the input's blur fires first on click, and
   * a list closed on blur is gone before the click lands.
   */
  protected go(event: Event, url: string): void {
    event.preventDefault();
    this.navigate(url);
  }

  protected onSubmit(event: Event): void {
    const value = this.field()?.nativeElement.value.trim() ?? '';

    if (value === '') {
      // An empty search would land on a results page for nothing.
      event.preventDefault();
      return;
    }

    // Let the native GET proceed. It works without hydration, and the
    // results page reads `q` from the query string either way.
    this.dismissed.set(true);
  }

  private navigate(url: string): void {
    this.dismissed.set(true);
    this.active.set(-1);
    void this.router.navigateByUrl(url);
  }
}
