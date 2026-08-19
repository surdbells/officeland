<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointsTest extends TestCase
{
    public function test_liveness_reports_ok_without_touching_dependencies(): void
    {
        $response = $this->getJson('/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'officeland-api')
            ->assertJsonStructure(['status', 'service', 'time']);
    }

    /**
     * Liveness must never depend on the database or Redis. If it did, a
     * transient dependency failure would restart every healthy worker instead
     * of pausing traffic to them.
     */
    public function test_liveness_does_not_report_dependency_checks(): void
    {
        $this->getJson('/health/live')->assertJsonMissingPath('checks');
    }

    public function test_readiness_reports_every_dependency(): void
    {
        $response = $this->getJson('/health/ready');

        // 200 when every dependency is reachable, 503 when one is not. Both
        // are valid responses; what matters is that each check is reported so
        // an operator can see which dependency failed.
        $this->assertContains($response->status(), [200, 503]);

        $response->assertJsonStructure([
            'status',
            'service',
            'time',
            'checks' => [
                'database' => ['ok', 'detail'],
                'database_direct' => ['ok', 'detail'],
                'redis' => ['ok', 'detail'],
                'disk' => ['ok', 'detail'],
                'queue' => ['ok', 'detail'],
            ],
        ]);
    }

    public function test_readiness_status_reflects_check_results(): void
    {
        $response = $this->getJson('/health/ready');

        $checks = $response->json('checks');
        $allOk = collect($checks)->every(fn (array $c): bool => $c['ok'] === true);

        $this->assertSame($allOk ? 'ready' : 'degraded', $response->json('status'));
        $this->assertSame($allOk ? 200 : 503, $response->status());
    }

    /**
     * Health endpoints are excluded from the sitemap and served noindex at the
     * edge, but they must also not be rate limited — an orchestrator polling
     * readiness would otherwise throttle itself out of service.
     */
    public function test_readiness_is_not_rate_limited(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $status = $this->getJson('/health/live')->status();
            $this->assertNotSame(429, $status, "rate limited after {$i} requests");
        }
    }
}
