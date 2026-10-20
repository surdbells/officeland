import { ChangeDetectionStrategy, Component, Injectable, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Icon } from './icon';

/**
 * A transient confirmation, such as "Added to your quote request".
 *
 * Actions that succeed silently read as actions that did nothing - the reason
 * "add to quote" was reported broken even after the basket call started
 * returning 200. The badge ticks up in the header, but on a phone the header
 * is off-screen by the time the thumb leaves the button, so there is no visible
 * acknowledgement at the point of the tap. This provides one.
 */
export interface Toast {
  readonly id: number;
  readonly text: string;
  readonly actionLabel?: string;
  readonly actionUrl?: string;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  private seq = 0;
  readonly toasts = signal<readonly Toast[]>([]);

  /**
   * Show a toast. Auto-dismisses after `duration` ms (0 keeps it until the
   * viewer closes it). Only ever called in the browser - the paths that raise
   * one (a basket mutation) are themselves browser-only - so the timer is safe.
   */
  show(
    text: string,
    options: { actionLabel?: string; actionUrl?: string; duration?: number } = {},
  ): void {
    const id = ++this.seq;
    this.toasts.update((list) => [
      ...list,
      { id, text, actionLabel: options.actionLabel, actionUrl: options.actionUrl },
    ]);

    const duration = options.duration ?? 4000;
    if (duration > 0 && typeof setTimeout !== 'undefined') {
      setTimeout(() => this.dismiss(id), duration);
    }
  }

  dismiss(id: number): void {
    this.toasts.update((list) => list.filter((toast) => toast.id !== id));
  }
}

/**
 * Renders the stack of live toasts. Mounted once in the shell.
 *
 * The container is the single `aria-live="polite"` region, declared empty in
 * the DOM up front so a toast inserted into it is announced; the toasts
 * themselves carry no role, which would double-announce. It sits above the
 * mobile bottom nav and the sheets (z-index), and clears the bar's height on a
 * phone so a confirmation is never hidden behind it.
 */
@Component({
  selector: 'ol-toast-host',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    <div class="ol-toast-host" aria-live="polite" aria-atomic="false">
      @for (toast of toasts(); track toast.id) {
        <div class="ol-toast">
          <ol-icon name="badge-check" [size]="20" class="ol-toast__icon" />
          <span class="ol-toast__text">{{ toast.text }}</span>
          @if (toast.actionUrl) {
            <a [routerLink]="toast.actionUrl" class="ol-toast__action" (click)="dismiss(toast.id)">
              {{ toast.actionLabel ?? 'View' }}
            </a>
          }
          <button
            type="button"
            class="ol-toast__close"
            (click)="dismiss(toast.id)"
            aria-label="Dismiss"
          >
            <ol-icon name="x" [size]="16" />
          </button>
        </div>
      }
    </div>
  `,
  styles: `
    .ol-toast-host {
      position: fixed;
      left: 50%;
      bottom: calc(1rem + env(safe-area-inset-bottom, 0));
      transform: translateX(-50%);
      z-index: 70;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.5rem;
      width: max-content;
      max-width: calc(100vw - 2rem);
      pointer-events: none;
    }

    /* Clear the mobile bottom nav (≈3.25rem + inset) so a toast is not hidden. */
    @media (max-width: 767px) {
      .ol-toast-host {
        bottom: calc(4rem + env(safe-area-inset-bottom, 0));
      }
    }

    .ol-toast {
      pointer-events: auto;
      display: flex;
      align-items: center;
      gap: 0.625rem;
      padding: 0.75rem 0.5rem 0.75rem 0.875rem;
      border-radius: 0.75rem;
      background: var(--color-ink-900, #0f172a);
      color: #fff;
      box-shadow: 0 10px 30px rgb(15 23 42 / 25%);
      animation: ol-toast-in 0.25s var(--ease-out-soft, cubic-bezier(0.22, 1, 0.36, 1));
    }

    .ol-toast__icon {
      flex-shrink: 0;
      color: #34d399;
    }

    .ol-toast__text {
      font-size: 0.875rem;
      font-weight: 500;
    }

    .ol-toast__action {
      flex-shrink: 0;
      padding: 0.25rem 0.5rem;
      border-radius: 0.5rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: #93c5fd;
    }

    .ol-toast__action:hover {
      color: #fff;
    }

    .ol-toast__close {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.75rem;
      height: 1.75rem;
      border-radius: 9999px;
      color: rgb(255 255 255 / 60%);
      background: none;
      border: 0;
      cursor: pointer;
    }

    .ol-toast__close:hover {
      color: #fff;
    }

    @keyframes ol-toast-in {
      from {
        opacity: 0;
        transform: translateY(0.75rem);
      }
      to {
        opacity: 1;
        transform: none;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .ol-toast {
        animation-duration: 0.01ms;
      }
    }
  `,
})
export class ToastHost {
  private readonly service = inject(ToastService);
  protected readonly toasts = this.service.toasts;

  protected dismiss(id: number): void {
    this.service.dismiss(id);
  }
}
