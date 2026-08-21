<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 13a findings, as regression tests.
 *
 * Each of these asserts a control that **did not exist** before the security
 * review: until then the public API had no rate limit of any kind, and nothing
 * recorded a failed login. The review is in `docs/SECURITY_REVIEW.md`; these
 * are what stop the findings coming back.
 */
final class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('search');
        RateLimiter::clear('catalogue');
        RateLimiter::clear('basket');

        /*
         * Requests from the test client arrive as 127.0.0.1, which is on the
         * internal exemption list — the render worker has to be able to walk
         * 15,000 routes without being throttled. So these tests present a
         * public address, which is what a user's request looks like.
         */
        config()->set('api.trusted_prefixes', ['10.', '::1']);
    }

    /** @return array<string, array{string, string, int}> */
    public static function throttledSurfaces(): array
    {
        return [
            'search' => ['/api/v1/search?q=chair', 'search', 60],
            'search suggest' => ['/api/v1/search/suggest?q=ch', 'search', 60],
            'basket' => ['/api/v1/basket', 'basket', 60],
            'catalogue' => ['/api/v1/brands', 'catalogue', 300],
        ];
    }

    /**
     * The limiter is wired to the route and keyed by client address.
     *
     * Asserted through the response headers rather than by making hundreds of
     * requests: `X-RateLimit-Limit` is the limiter reporting its own budget, so
     * its presence proves the middleware ran and its value proves which
     * limiter ran. Hammering the endpoint would assert the same thing far more
     * slowly, and would make the suite's runtime depend on the limit.
     */
    #[DataProvider('throttledSurfaces')]
    public function test_a_public_surface_is_rate_limited(string $uri, string $surface, int $expected): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get($uri);

        $response->assertHeader('X-RateLimit-Limit', (string) $expected);
    }

    /**
     * Search is separated from the catalogue on purpose: it runs FTS, then a
     * trigram fallback, then facet aggregation, and it was the single most
     * expensive unthrottled endpoint in the system.
     */
    public function test_search_is_held_to_a_tighter_limit_than_the_catalogue(): void
    {
        $this->assertLessThan(
            (int) config('api.rate_limits.catalogue'),
            (int) config('api.rate_limits.search'),
        );
    }

    /**
     * Exhausting the budget returns 429 rather than continuing to serve.
     */
    public function test_exceeding_the_limit_is_refused(): void
    {
        config()->set('api.rate_limits.search', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
                ->get('/api/v1/search?q=chair')
                ->assertSuccessful();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->get('/api/v1/search?q=chair')
            ->assertStatus(429);
    }

    /** One client's spending must not consume another's budget. */
    public function test_the_limit_is_per_client(): void
    {
        config()->set('api.rate_limits.search', 2);

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])->get('/api/v1/search?q=chair');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->get('/api/v1/search?q=chair')
            ->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.13'])
            ->get('/api/v1/search?q=chair')
            ->assertSuccessful();
    }

    /**
     * The render worker walks every route in the manifest during a full build
     * — 15,000+ requests. Throttling it would turn a template fix into a
     * half-built site, so internal addresses are exempt.
     */
    public function test_the_render_worker_is_not_throttled(): void
    {
        config()->set('api.trusted_prefixes', ['10.']);
        config()->set('api.rate_limits.catalogue', 2);

        Product::factory()->create(['status' => 'active']);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
                ->get('/api/v1/render/manifest')
                ->assertSuccessful();
        }
    }

    /**
     * The exemption must not be reachable from the internet. PHP runs inside
     * Caddy under FrankenPHP, so `$request->ip()` is the real socket address
     * and a public client cannot present a private one — but the config that
     * relies on that has to be asserted, not assumed.
     */
    public function test_a_public_address_is_never_treated_as_internal(): void
    {
        config()->set('api.trusted_prefixes', ['10.', '127.']);
        config()->set('api.rate_limits.catalogue', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.14'])->get('/api/v1/brands');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.14'])
            ->get('/api/v1/brands')
            ->assertStatus(429);
    }

    // -----------------------------------------------------------------
    // OWASP A09 — the authentication trail
    // -----------------------------------------------------------------

    /**
     * Before this listener nothing recorded a failed login anywhere. Filament
     * caps attempts at five a minute, so brute force cannot succeed quickly —
     * but "cannot succeed quickly" is not "somebody will notice", and a
     * fortnight of patient credential stuffing left no trace at all.
     */
    public function test_a_failed_login_is_logged(): void
    {
        $logged = [];

        Log::listen(function ($message) use (&$logged): void {
            $logged[] = [$message->level, $message->message, $message->context];
        });

        Event::dispatch(new Failed('web', null, ['email' => 'attacker@example.com', 'password' => 'hunter2']));

        $this->assertNotEmpty($logged, 'A failed login produced no log entry.');

        [$level, $message, $context] = $logged[0];

        $this->assertSame('warning', $level, 'A failed login at info level is a line nobody alerts on.');
        $this->assertSame('auth.failed', $message);
        $this->assertSame('attacker@example.com', $context['email']);
        $this->assertFalse($context['user_exists']);
    }

    /** The password must never reach the log, whatever else does. */
    public function test_the_password_is_never_logged(): void
    {
        $logged = [];

        Log::listen(function ($message) use (&$logged): void {
            $logged[] = json_encode($message->context);
        });

        Event::dispatch(new Failed('web', null, [
            'email' => 'someone@example.com',
            'password' => 'correct-horse-battery-staple',
        ]));

        foreach ($logged as $entry) {
            $this->assertStringNotContainsString('correct-horse-battery-staple', (string) $entry);
        }
    }

    /**
     * Whether the account exists separates an attack on a real user from a
     * scanner working through a leaked address list — different responses.
     */
    public function test_a_failed_login_records_whether_the_account_exists(): void
    {
        $user = User::factory()->create(['email' => 'real@officelandng.com']);

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->context;
        });

        Event::dispatch(new Failed('web', $user, ['email' => 'real@officelandng.com']));

        $this->assertTrue($logged[0]['user_exists']);
    }

    /**
     * The limiter tripping is the strongest single signal available: five
     * attempts in a minute is not somebody mistyping their own password.
     */
    public function test_a_lockout_is_logged(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = [$message->level, $message->message];
        });

        Event::dispatch(new Lockout(request()));

        $this->assertSame(['warning', 'auth.lockout'], $logged[0]);
    }

    // -----------------------------------------------------------------
    // OWASP A05 — configuration that must not regress
    // -----------------------------------------------------------------

    /**
     * The admin session cookie is a live grant of catalogue write access, so
     * the secure flag has to be the default rather than something an operator
     * remembers to switch on.
     */
    public function test_the_session_cookie_is_secure_by_default_in_production(): void
    {
        /*
         * The config file is re-evaluated with APP_ENV forced, rather than the
         * booted config being read: `session.secure` is decided at load time,
         * and by the time a test runs the value has long since been computed
         * for the testing environment.
         *
         * Both superglobals are set because `env()` resolves through an
         * adapter chain that reads $_SERVER as well as $_ENV — setting one and
         * asserting on the other is how this test quietly passes for the wrong
         * reason.
         */
        $previous = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];

        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        try {
            $config = require base_path('config/session.php');

            $this->assertTrue(
                $config['secure'],
                'The admin session cookie would be sent over plain HTTP in production.',
            );
        } finally {
            [$env, $server] = $previous;

            if ($env === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $env;
            }

            if ($server === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $server;
            }
        }
    }

    public function test_the_session_cookie_is_http_only_and_same_site(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }
}
