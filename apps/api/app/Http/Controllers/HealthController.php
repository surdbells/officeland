<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and readiness probes (MASTER BUILD PROMPT section 19.1).
 *
 * live()  - is the process up? Never touches a dependency. If this fails the
 *           container is dead and should be restarted.
 * ready() - can this instance serve traffic? Checks every dependency a request
 *           can touch. A failure here should remove the instance from the load
 *           balancer, not restart it.
 *
 * Conflating the two causes restart loops: a database blip restarts every
 * healthy worker instead of pausing traffic.
 */
final class HealthController extends Controller
{
    /**
     * Disk usage above this fraction fails readiness.
     * Local-disk media storage means we own the durability problem
     * (MASTER BUILD PROMPT section 12.8), so running out of disk is a
     * foreseeable, high-impact failure rather than an edge case.
     */
    private const DISK_CRITICAL = 0.90;

    private const QUEUE_DEPTH_CRITICAL = 10_000;

    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'officeland-api',
            'time' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'database_direct' => $this->checkDatabaseDirect(),
            'redis' => $this->checkRedis(),
            'disk' => $this->checkDisk(),
            'queue' => $this->checkQueueDepth(),
        ];

        $healthy = array_reduce(
            $checks,
            static fn (bool $carry, array $check): bool => $carry && $check['ok'],
            true,
        );

        return response()->json([
            'status' => $healthy ? 'ready' : 'degraded',
            'service' => 'officeland-api',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Application path: through PgBouncer, transaction pooling.
     *
     * `SELECT 1` with a bound parameter rather than a literal is deliberate -
     * it exercises the prepared-statement path that transaction pooling
     * breaks when PDO emulation is misconfigured (docs/ARCHITECTURE.md 5).
     * A literal query would pass even with the pooler misconfigured.
     *
     * @return array{ok: bool, detail: string, latency_ms?: float}
     */
    private function checkDatabase(): array
    {
        return $this->timed(static function (): string {
            $result = DB::connection('pgsql')->select('select ?::int as probe', [1]);

            if (($result[0]->probe ?? null) !== 1) {
                throw new \RuntimeException('unexpected probe result');
            }

            return 'pooled connection ok (parameterised)';
        });
    }

    /** @return array{ok: bool, detail: string, latency_ms?: float} */
    private function checkDatabaseDirect(): array
    {
        return $this->timed(static function (): string {
            DB::connection('pgsql_direct')->select('select 1');

            return 'direct connection ok';
        });
    }

    /** @return array{ok: bool, detail: string, latency_ms?: float} */
    private function checkRedis(): array
    {
        return $this->timed(static function (): string {
            $pong = Redis::connection()->ping();

            if ($pong === false) {
                throw new \RuntimeException('ping returned false');
            }

            return 'ok';
        });
    }

    /** @return array{ok: bool, detail: string} */
    private function checkDisk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return ['ok' => false, 'detail' => 'unable to read disk statistics'];
        }

        $used = 1 - ($free / $total);

        return [
            'ok' => $used < self::DISK_CRITICAL,
            'detail' => sprintf(
                '%.1f%% used, %.1f GB free',
                $used * 100,
                $free / 1_073_741_824,
            ),
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private function checkQueueDepth(): array
    {
        try {
            $depth = (int) Redis::connection()->llen('queues:default');
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'unreadable: '.$e->getMessage()];
        }

        return [
            'ok' => $depth < self::QUEUE_DEPTH_CRITICAL,
            'detail' => sprintf('%d job(s) pending', $depth),
        ];
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string, latency_ms?: float}
     */
    private function timed(callable $probe): array
    {
        $start = hrtime(true);

        try {
            $detail = $probe();
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'detail' => $detail,
            'latency_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
        ];
    }
}
