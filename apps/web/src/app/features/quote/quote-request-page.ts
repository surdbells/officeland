import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { BasketStore } from '../../core/quotes/basket-store';
import { SeoService } from '../../core/seo/seo.service';
import { Icon } from '../../shared/icon';
import { ResponsiveImage } from '../../shared/responsive-image';
import { QuoteHeader } from './quote-header';

interface Confirmation {
  readonly reference: string;
  readonly email: string;
  readonly line_count?: number;
  readonly response_expectation?: string;
}

/**
 * The three-field request form (D-004, §9d).
 *
 * Email, phone, optional message. No name, no company, no address, no account.
 * Every additional field is a reason not to submit, and sales can ask for
 * anything else in the reply they are about to send anyway.
 *
 * ── Anti-abuse is invisible here ─────────────────────────────────────────
 * A honeypot the buyer never sees and a timestamp captured on load. Both are
 * optional server-side, so the form still works for a client that never ran
 * JavaScript - which is traffic this site must not turn away.
 *
 * ── A rejection is always shown ──────────────────────────────────────────
 * If the server declines, the buyer is told. Accepting-and-discarding would
 * leave someone waiting for a reply that is never coming, with nobody aware
 * it happened. That is the worst failure this form has.
 */
@Component({
  selector: 'ol-quote-request-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, QuoteHeader, Icon, ResponsiveImage],
  template: `
    @if (confirmation(); as done) {
      <ol-quote-header heading="Request sent" crumb="Sent" [step]="3" lede="Thank you. A person reads every request - you will hear back within one business day." />

      <div class="bg-ink-50">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-10 sm:py-14">
          <section aria-labelledby="confirmed" class="mx-auto max-w-3xl rounded-3xl border border-ink-100 bg-white p-6 shadow-md sm:p-10">
            <div class="flex flex-col items-center text-center">
              <span class="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
              </span>
              <h2 id="confirmed" class="mt-5 font-heading text-2xl font-bold text-ink-900">Your request is with us</h2>

              <p class="mt-4 text-sm text-ink-500">Your reference</p>
              <div class="mt-1 flex flex-wrap items-center justify-center gap-2">
                <strong class="rounded-xl bg-ink-900 px-4 py-2 font-mono text-xl tracking-wider text-white">{{ done.reference }}</strong>
                <button
                  type="button"
                  class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-ink-200 px-4 py-2 text-sm font-semibold text-ink-700 transition-colors hover:border-brand-600 hover:text-brand-700"
                  (click)="copyReference(done.reference)"
                >
                  <ol-icon name="copy" [size]="15" /> {{ copied() ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <p class="mt-4 max-w-xl text-ink-600">
                {{ done.response_expectation ?? 'We respond within one business day.' }}
                A copy has gone to <strong class="font-semibold text-ink-900">{{ done.email }}</strong>.
              </p>
            </div>

            <!-- What happens next. -->
            <ol class="mt-8 grid gap-3 border-t border-ink-100 pt-8 sm:grid-cols-3">
              @for (item of nextSteps; track item.title; let i = $index) {
                <li class="rounded-2xl bg-ink-50 p-4">
                  <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ i + 1 }}</span>
                  <p class="mt-3 font-semibold text-ink-900">{{ item.title }}</p>
                  <p class="mt-0.5 text-sm text-ink-600">{{ item.text }}</p>
                </li>
              }
            </ol>

            <p class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              No email after a few minutes? Check spam, then call
              <a href="tel:+2347074723165" class="font-semibold underline">+234 707 472 3165</a> and quote your reference.
            </p>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
              <a routerLink="/c/" class="inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700">
                Continue browsing <ol-icon name="arrow-right" [size]="16" />
              </a>
              <a href="https://wa.me/2347074723165" rel="noopener" class="inline-flex items-center gap-2 rounded-full border border-ink-200 px-6 py-3 font-semibold text-ink-900 transition-colors hover:border-emerald-500 hover:text-emerald-700">
                <ol-icon name="message-circle" [size]="16" /> WhatsApp us
              </a>
            </div>
          </section>
        </div>
      </div>
    } @else {
      <ol-quote-header
        heading="Send your request"
        crumb="Your details"
        [step]="2"
        lede="Two details so we can reply - an email and a phone number. No account, no payment, no obligation."
      />

      <div class="bg-ink-50">
        <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8 sm:py-12">
          @if (store.isEmpty()) {
            <section class="mx-auto max-w-2xl rounded-3xl border border-ink-100 bg-white px-6 py-12 text-center shadow-xs">
              <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><ol-icon name="file-text" [size]="30" /></span>
              <h2 class="mt-5 font-heading text-2xl font-semibold text-ink-900">Your list is empty</h2>
              <p class="mt-2 text-ink-600">Add the items you need, or describe them, and come back here to send.</p>
              <a routerLink="/quote/" class="mt-6 inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-3 font-semibold text-white shadow-brand hover:bg-brand-700">
                Build your list <ol-icon name="arrow-right" [size]="16" />
              </a>
            </section>
          } @else {
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem] lg:gap-8">
              <section aria-labelledby="details-heading" class="rounded-3xl border border-ink-100 bg-white p-6 shadow-md sm:p-8">
                <h2 id="details-heading" class="font-heading text-xl font-semibold text-ink-900">Where should we send the quote?</h2>
                <p class="mt-1 text-sm text-ink-600">Sales can ask for anything else in the reply.</p>

                @if (error(); as message) {
                  <p role="alert" class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ message }}</p>
                }

                <form class="mt-6 space-y-5" novalidate (submit)="onSubmit($event)">
                  <div>
                    <label for="email" class="block text-sm font-semibold text-ink-900">
                      Email <span aria-hidden="true" class="text-red-600">*</span>
                    </label>
                    <div class="relative mt-1.5">
                      <ol-icon name="mail" [size]="18" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400" />
                      <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        required
                        placeholder="you@organisation.com"
                        [class]="fieldClass('email')"
                        [attr.aria-invalid]="fieldError('email') ? 'true' : null"
                        [attr.aria-describedby]="fieldError('email') ? 'email-error' : 'email-hint'"
                        [value]="email()"
                        (input)="email.set($any($event.target).value)"
                      />
                    </div>
                    @if (fieldError('email'); as message) {
                      <p id="email-error" class="mt-1.5 text-sm text-red-700">{{ message }}</p>
                    } @else {
                      <p id="email-hint" class="mt-1.5 text-xs text-ink-400">Where we send the priced quotation.</p>
                    }
                  </div>

                  <div>
                    <label for="phone" class="block text-sm font-semibold text-ink-900">
                      Phone <span aria-hidden="true" class="text-red-600">*</span>
                    </label>
                    <div class="relative mt-1.5">
                      <ol-icon name="phone" [size]="18" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-400" />
                      <input
                        id="phone"
                        name="phone"
                        type="tel"
                        autocomplete="tel"
                        required
                        placeholder="0803 000 0000"
                        [class]="fieldClass('phone')"
                        [attr.aria-invalid]="fieldError('phone') ? 'true' : null"
                        [attr.aria-describedby]="fieldError('phone') ? 'phone-error' : 'phone-hint'"
                        [value]="phone()"
                        (input)="phone.set($any($event.target).value)"
                      />
                    </div>
                    @if (fieldError('phone'); as message) {
                      <p id="phone-error" class="mt-1.5 text-sm text-red-700">{{ message }}</p>
                    } @else {
                      <p id="phone-hint" class="mt-1.5 text-xs text-ink-400">For anything quicker to ask than to email.</p>
                    }
                  </div>

                  <div>
                    <label for="message" class="block text-sm font-semibold text-ink-900">
                      Anything else? <span class="font-normal text-ink-400">(optional)</span>
                    </label>
                    <textarea
                      id="message"
                      name="message"
                      rows="4"
                      maxlength="2000"
                      class="mt-1.5 w-full rounded-xl border border-ink-200 bg-white px-4 py-3 text-ink-900 placeholder:text-ink-400 focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/20"
                      placeholder="Delivery location, the date you need it by, or anything we should know."
                      [value]="message()"
                      (input)="message.set($any($event.target).value)"
                    ></textarea>
                    <p class="mt-1.5 text-right text-xs text-ink-400">{{ message().length }} / 2000</p>
                  </div>

                  <!--
                    Anti-abuse (section 9e). A human never sees this field; a
                    naive bot fills it. Hidden from assistive technology too.
                  -->
                  <div class="hidden" aria-hidden="true">
                    <label for="website_url">Leave this empty</label>
                    <input
                      id="website_url"
                      name="website_url"
                      type="text"
                      tabindex="-1"
                      autocomplete="off"
                      [value]="honeypot()"
                      (input)="honeypot.set($any($event.target).value)"
                    />
                  </div>

                  <button
                    type="submit"
                    class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-brand-600 px-6 py-4 font-semibold text-white shadow-brand transition-colors hover:bg-brand-700 disabled:cursor-wait disabled:opacity-70"
                    [disabled]="sending()"
                  >
                    @if (sending()) {
                      <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
                      Sending&hellip;
                    } @else {
                      <ol-icon name="send" [size]="18" /> Send request
                    }
                  </button>

                  <p class="flex items-start gap-2 text-xs text-ink-400">
                    <ol-icon name="shield-check" [size]="15" class="mt-px shrink-0" />
                    <span>
                      We use your details to respond to this request and nothing else. See our
                      <a routerLink="/privacy/" class="underline hover:text-brand-600">privacy notice</a>.
                    </span>
                  </p>
                </form>
              </section>

              <!-- The list being sent, so the buyer can see what they are asking for. -->
              <aside class="space-y-4 lg:sticky lg:top-28 lg:self-start">
                <section aria-labelledby="sending-heading" class="rounded-3xl border border-ink-100 bg-white p-6 shadow-xs">
                  <div class="flex items-baseline justify-between gap-3">
                    <h2 id="sending-heading" class="font-heading text-lg font-semibold text-ink-900">Your list</h2>
                    <a routerLink="/quote/" class="text-sm font-semibold text-brand-600 hover:underline">Edit</a>
                  </div>
                  <p class="mt-0.5 text-sm text-ink-500">
                    {{ store.lineCount() }} line{{ store.lineCount() === 1 ? '' : 's' }} &middot;
                    {{ store.unitCount() }} unit{{ store.unitCount() === 1 ? '' : 's' }}
                  </p>
                  <ul class="mt-4 space-y-3">
                    @for (line of previewLines(); track line.id) {
                      <li class="flex items-center gap-3">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-ink-100 bg-white">
                          @if (line.image; as image) {
                            <ol-image
                              class="block h-full w-full p-1 [&>picture]:block [&>picture]:h-full [&>picture]:w-full"
                              [image]="image"
                              sizes="48px"
                              imgClass="h-full w-full object-contain"
                            />
                          } @else {
                            <ol-icon [name]="line.is_free_text ? 'pen-line' : 'box'" [size]="18" class="text-ink-300" />
                          }
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm text-ink-800">{{ line.name }}</span>
                        <span class="shrink-0 rounded-full bg-ink-50 px-2.5 py-0.5 text-xs font-semibold text-ink-700">&times; {{ line.qty }}</span>
                      </li>
                    }
                  </ul>
                  @if (store.lineCount() > previewLines().length) {
                    <p class="mt-3 text-sm text-ink-500">and {{ store.lineCount() - previewLines().length }} more</p>
                  }
                </section>

                <section class="rounded-3xl bg-ink-900 p-6 text-white">
                  <h2 class="font-heading text-base font-semibold text-white">What happens next</h2>
                  <ol class="mt-4 space-y-3">
                    @for (item of nextSteps; track item.title; let i = $index) {
                      <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-500 text-xs font-bold text-ink-900">{{ i + 1 }}</span>
                        <span class="text-sm text-white/85"><strong class="font-semibold text-white">{{ item.title }}.</strong> {{ item.text }}</span>
                      </li>
                    }
                  </ol>
                </section>
              </aside>
            </div>
          }
        </div>
      </div>
    }
  `,
})
export class QuoteRequestPage {
  protected readonly store = inject(BasketStore);
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly seo = inject(SeoService);

  protected readonly email = signal('');
  protected readonly phone = signal('');
  protected readonly message = signal('');
  protected readonly honeypot = signal('');

  protected readonly sending = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly confirmation = signal<Confirmation | null>(null);

  private readonly fieldErrors = signal<Record<string, string>>({});

  /** Captured on load: a form submitted faster than this was not read (§9e). */
  private readonly loadedAt = Math.floor(Date.now() / 1000);

  protected readonly copied = signal(false);

  /** The first few lines, for the summary beside the form. */
  protected readonly previewLines = computed(() => this.store.lines().slice(0, 5));

  protected readonly nextSteps = [
    { title: 'We read it', text: 'A person, not an autoresponder, the same business day.' },
    { title: 'We price it', text: 'Every line, with lead times and any substitutions stated.' },
    { title: 'You decide', text: 'Nothing is ordered until you accept the quotation.' },
  ] as const;

  protected fieldClass(field: string): string {
    return (
      'h-12 w-full rounded-xl border bg-white pl-11 pr-4 text-ink-900 placeholder:text-ink-400 focus:outline-none focus:ring-2 ' +
      (this.fieldError(field)
        ? 'border-red-300 focus:border-red-500 focus:ring-red-500/20'
        : 'border-ink-200 focus:border-brand-600 focus:ring-brand-600/20')
    );
  }

  /** Copy inside the click handler; fall back silently where the clipboard is refused. */
  protected copyReference(reference: string): void {
    navigator.clipboard
      ?.writeText(reference)
      .then(() => {
        this.copied.set(true);
        setTimeout(() => this.copied.set(false), 2000);
      })
      .catch(() => undefined);
  }

  protected fieldError(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected readonly canSubmit = computed(
    () => !this.sending() && this.email().trim() !== '' && this.phone().trim() !== '',
  );

  constructor() {
    this.seo.apply({
      title: 'Request a quote',
      description:
        'Send OfficeLand your requisition list and we will price it. We respond within one business day.',
      path: '/quote/request/',
      indexable: false,
    });

    this.seo.setStructuredData([]);
    this.store.load();
  }

  protected onSubmit(event: Event): void {
    event.preventDefault();

    if (this.sending()) return;

    /*
     * Validated here as well as server-side, so a mistake is caught before a
     * round trip. The server remains authoritative: this is a convenience,
     * not a gate.
     */
    const errors: Record<string, string> = {};

    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.email().trim())) {
      errors['email'] = 'Enter an email address we can reply to.';
    }

    if (this.phone().trim().length < 7) {
      errors['phone'] = 'Enter a phone number we can reach you on.';
    }

    this.fieldErrors.set(errors);

    if (Object.keys(errors).length > 0) return;

    this.sending.set(true);
    this.error.set(null);

    this.http
      .post<{ data: Confirmation }>('/api/v1/quotes', {
        email: this.email().trim(),
        phone: this.phone().trim(),
        message: this.message().trim() === '' ? null : this.message().trim(),
        website_url: this.honeypot(),
        form_loaded_at: this.loadedAt,
        landing_page: this.router.url,
        referrer: typeof document === 'undefined' ? null : document.referrer || null,
      })
      .subscribe({
        next: (response) => {
          this.confirmation.set(response.data);
          this.sending.set(false);

          // The basket is closed server-side; clear the mirror so the header
          // count drops immediately rather than at the next navigation.
          this.store.reset();
        },
        error: (failure: { error?: { error?: { message?: string } } }) => {
          this.error.set(
            failure?.error?.error?.message ??
              'We could not send that. Please try again, or call us on +234 707 472 3165.',
          );
          this.sending.set(false);
        },
      });
  }
}
