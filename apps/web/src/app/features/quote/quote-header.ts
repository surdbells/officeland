import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * The band at the top of the quote journey: breadcrumb, title, one line of
 * reassurance, and where the buyer is in the three steps.
 *
 * The steps are the whole journey - build the list, leave a way to reply,
 * done - and showing them answers the question every first-time buyer has on
 * a quote-only site: "is this going to ask me for a card?" It is not, and the
 * third step says so before they get there.
 */
@Component({
  selector: 'ol-quote-header',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <section class="relative isolate overflow-hidden bg-ink-900 text-white">
      <span aria-hidden="true" class="ol-quote-glow absolute inset-0 -z-10"></span>
      <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) pb-8 pt-6 sm:pb-10 sm:pt-8">
        <nav aria-label="Breadcrumb">
          <ol class="flex flex-wrap items-center gap-2 text-sm text-white/70">
            <li><a routerLink="/" class="hover:text-white">Home</a></li>
            <li aria-hidden="true">/</li>
            @if (step() > 1) {
              <li><a routerLink="/quote/" class="hover:text-white">Quote list</a></li>
              <li aria-hidden="true">/</li>
            }
            <li aria-current="page" class="text-white">{{ crumb() }}</li>
          </ol>
        </nav>

        <div class="mt-6 flex flex-wrap items-end justify-between gap-6">
          <div class="max-w-2xl">
            <h1 class="font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ heading() }}</h1>
            <p class="mt-2 text-white/80">{{ lede() }}</p>
          </div>

          <ol class="flex items-center gap-2 text-sm" aria-label="Quote steps">
            @for (label of steps; track label; let i = $index) {
              <li class="flex items-center gap-2" [attr.aria-current]="step() === i + 1 ? 'step' : null">
                <span
                  class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                  [class]="stepClass(i + 1)"
                >
                  @if (step() > i + 1) {
                    <svg viewBox="0 0 12 12" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 6.5l2.5 2.5 4.5-5.5" /></svg>
                  } @else {
                    {{ i + 1 }}
                  }
                </span>
                <span [class]="step() === i + 1 ? 'font-semibold text-white' : 'hidden text-white/60 sm:inline'">{{ label }}</span>
                @if (i < steps.length - 1) {
                  <span aria-hidden="true" class="mx-1 h-px w-6 bg-white/25 sm:w-10"></span>
                }
              </li>
            }
          </ol>
        </div>
      </div>
    </section>
  `,
  styles: `
    .ol-quote-glow {
      background:
        radial-gradient(60rem 28rem at 85% -10%, rgb(5 91 240 / 0.35), transparent 60%),
        radial-gradient(40rem 22rem at 0% 110%, rgb(245 158 11 / 0.14), transparent 60%);
    }
  `,
})
export class QuoteHeader {
  readonly heading = input.required<string>();
  readonly lede = input<string>('');
  readonly crumb = input<string>('Quote list');
  /** 1 = the list, 2 = your details, 3 = sent. */
  readonly step = input<1 | 2 | 3>(1);

  protected readonly steps = ['Your list', 'Your details', 'Sent'] as const;

  protected stepClass(n: number): string {
    const step = this.step();
    if (n < step) return 'bg-emerald-500 text-white';
    if (n === step) return 'bg-accent-500 text-ink-900';
    return 'bg-white/10 text-white/70';
  }
}
