<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mail\ZeptoMailApiTransport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers the ZeptoMail transport (§10a).
 *
 * `Mail::extend` rather than a custom Mailer: every Mailable, every queued
 * send and every notification then goes through it without knowing it exists,
 * which is what makes the provider swappable and what keeps the mail code in
 * the rest of the application free of ZeptoMail's field names.
 */
final class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('zeptomail', function (array $config): ZeptoMailApiTransport {
            $token = $config['token'] ?? null;

            if (! is_string($token) || $token === '') {
                /*
                 * Fail at construction, not at send time.
                 *
                 * A missing token discovered inside a queued job produces a
                 * failed job for every quote, retried five times each, with an
                 * error that reads like a provider outage. Failing here means
                 * the misconfiguration surfaces the first time anything tries
                 * to use the mailer, and says what is actually wrong.
                 */
                throw new RuntimeException(
                    'ZEPTOMAIL_TOKEN is not set. The client enters this on their own server (Q18); '.
                    'it is never held by the development team.'
                );
            }

            return new ZeptoMailApiTransport(
                http: $this->app->make(HttpFactory::class),
                token: $token,
                endpoint: (string) ($config['endpoint'] ?? 'https://api.zeptomail.com/v1.1/email'),
                timeout: (int) ($config['timeout'] ?? 15),
                trackOpens: (bool) ($config['track_opens'] ?? false),
                trackClicks: (bool) ($config['track_clicks'] ?? false),
            );
        });
    }
}
