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
  imports: [Icon],
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
          class="absolute inset-x-0 top-full z-50 mt-1 max-h-96 overflow-y-auto rounded-xl border border-ink-200 bg-white py-2 shadow-xl"
        >
          @for (group of groups(); track group.heading) {
            <p class="px-3 pb-1 pt-2 font-heading text-xs font-semibold uppercase tracking-[0.12em] text-ink-400">
              {{ group.heading }}
            </p>

            @for (option of group.options; track option.url) {
              <div
                [id]="'suggest-' + option.index"
                role="option"
                [attr.aria-selected]="option.index === active()"
                class="cursor-pointer px-3 py-2 text-sm"
                [class.bg-brand-50]="option.index === active()"
                (mouseenter)="active.set(option.index)"
                (mousedown)="go($event, option.url)"
              >
                <span class="block text-ink-900">{{ option.label }}</span>
                @if (option.meta) {
                  <span class="block font-mono text-xs text-ink-400">{{ option.meta }}</span>
                }
              </div>
            }
          }

          @if (flat().length === 0) {
            <p class="px-3 py-2 text-sm text-ink-600">No matches. Press Enter to search anyway.</p>
          }
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
          index: index++,
        })),
      }));
  });

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
