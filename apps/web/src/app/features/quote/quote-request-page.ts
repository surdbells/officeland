import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { BasketStore } from '../../core/quotes/basket-store';
import { SeoService } from '../../core/seo/seo.service';

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
 * JavaScript — which is traffic this site must not turn away.
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
  imports: [RouterLink],
  template: `
    <div class="mx-auto max-w-(--container-page) px-(--spacing-gutter) py-8">
      @if (confirmation(); as done) {
        <section aria-labelledby="confirmed">
          <h1 id="confirmed" class="font-heading text-3xl font-bold">Request sent</h1>

          <p class="mt-4 max-w-2xl text-lg">
            Your reference is
            <strong class="font-mono">{{ done.reference }}</strong>.
          </p>

          <p class="mt-3 max-w-2xl text-ink-600">
            {{ done.response_expectation ?? 'We respond within one business day.' }}
            A copy has gone to <strong>{{ done.email }}</strong> &mdash; if it does not arrive,
            check your spam folder, then call us on
            <a href="tel:+2347074723165" class="text-brand-600 hover:underline">+234 707 472 3165</a>
            and quote that reference.
          </p>

          <div class="mt-8 flex flex-wrap gap-3">
            <a routerLink="/c/" class="rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white hover:bg-brand-700">
              Continue browsing
            </a>
            <a routerLink="/" class="rounded-lg border border-ink-200 px-6 py-3 font-medium hover:border-brand-600">
              Back to home
            </a>
          </div>
        </section>
      } @else {
        <h1 class="font-heading text-3xl font-bold">Request a quote</h1>

        @if (store.isEmpty()) {
          <p class="mt-4 max-w-2xl text-ink-600">
            Your list is empty.
            <a routerLink="/quote/" class="text-brand-600 hover:underline">Add the items you need</a>
            and come back.
          </p>
        } @else {
          <p class="mt-2 text-ink-600">
            {{ store.lineCount() }} line{{ store.lineCount() === 1 ? '' : 's' }} &mdash;
            <a routerLink="/quote/" class="text-brand-600 hover:underline">review the list</a>
          </p>

          @if (error(); as message) {
            <p role="alert" class="mt-4 max-w-2xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
              {{ message }}
            </p>
          }

          <form class="mt-6 max-w-lg" novalidate (submit)="onSubmit($event)">
            <div>
              <label for="email" class="block text-sm font-medium">
                Email <span aria-hidden="true" class="text-red-600">*</span>
              </label>
              <input
                id="email"
                name="email"
                type="email"
                autocomplete="email"
                required
                class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2"
                [attr.aria-invalid]="fieldError('email') ? 'true' : null"
                [attr.aria-describedby]="fieldError('email') ? 'email-error' : null"
                [value]="email()"
                (input)="email.set($any($event.target).value)"
              />
              @if (fieldError('email'); as message) {
                <p id="email-error" class="mt-1 text-sm text-red-700">{{ message }}</p>
              }
              <p class="mt-1 text-xs text-ink-400">Where we send the quote.</p>
            </div>

            <div class="mt-4">
              <label for="phone" class="block text-sm font-medium">
                Phone <span aria-hidden="true" class="text-red-600">*</span>
              </label>
              <input
                id="phone"
                name="phone"
                type="tel"
                autocomplete="tel"
                required
                class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2"
                [attr.aria-invalid]="fieldError('phone') ? 'true' : null"
                [attr.aria-describedby]="fieldError('phone') ? 'phone-error' : null"
                [value]="phone()"
                (input)="phone.set($any($event.target).value)"
              />
              @if (fieldError('phone'); as message) {
                <p id="phone-error" class="mt-1 text-sm text-red-700">{{ message }}</p>
              }
              <p class="mt-1 text-xs text-ink-400">
                For anything that is quicker to ask than to email.
              </p>
            </div>

            <div class="mt-4">
              <label for="message" class="block text-sm font-medium">
                Anything else? <span class="font-normal text-ink-400">(optional)</span>
              </label>
              <textarea
                id="message"
                name="message"
                rows="4"
                maxlength="2000"
                class="mt-1 w-full rounded-lg border border-ink-200 px-3 py-2"
                placeholder="Delivery location, timing, or anything we should know."
                [value]="message()"
                (input)="message.set($any($event.target).value)"
              ></textarea>
            </div>

            <!--
              Anti-abuse (section 9e). A human never sees this field; a naive
              bot fills it. Hidden from assistive technology too, so a screen
              reader does not announce a field its user must not complete.
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
              class="mt-6 w-full rounded-lg bg-brand-600 px-6 py-3 font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
              [disabled]="sending()"
            >
              {{ sending() ? 'Sending…' : 'Send request' }}
            </button>

            <p class="mt-3 text-xs text-ink-400">
              We use your details to respond to this request and nothing else. See our
              <a routerLink="/privacy/" class="underline">privacy notice</a>.
            </p>
          </form>
        }
      }
    </div>
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
