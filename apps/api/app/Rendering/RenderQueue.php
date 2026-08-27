<?php

declare(strict_types=1);

namespace App\Rendering;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;

/**
 * Accumulates routes awaiting regeneration.
 *
 * Backed by Redis sorted sets rather than a job per route, for two reasons:
 *
 *   Deduplication. A bulk import touching 500 products enqueues the same
 *   category listing 500 times. As a set it is stored once.
 *
 *   Debouncing. Each route carries the timestamp at which it became eligible.
 *   The renderer (Phase 8) claims only routes older than the debounce window,
 *   so a burst of edits coalesces into one render instead of one per save.
 *
 * Direct and cascade routes are held separately so the renderer can drain the
 * pages a buyer would actually land on first (docs/ARCHITECTURE.md 4.3).
 */
final class RenderQueue
{
    public const KEY_DIRECT = 'render:pending:direct';

    public const KEY_CASCADE = 'render:pending:cascade';

    /**
     * Seconds a route waits before it is eligible to render.
     *
     * Long enough to absorb an editor saving several times in a row, short
     * enough that a correction appears while they are still looking at it.
     */
    public const DEBOUNCE_SECONDS = 60;

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * @param  list<RenderRequest>  $requests
     */
    public function push(array $requests): int
    {
        $pushed = 0;

        foreach ($requests as $request) {
            if ($request->isEmpty()) {
                continue;
            }

            $key = $request->priority === RenderRequest::PRIORITY_CASCADE
                ? self::KEY_CASCADE
                : self::KEY_DIRECT;

            $eligibleAt = now()->getTimestamp() + self::DEBOUNCE_SECONDS;
            $connection = $this->redis->connection();

            foreach ($request->routes as $route) {
                // The score is the timestamp at which the route becomes
                // eligible to render. A route already waiting must KEEP its
                // original score - otherwise a page being edited repeatedly is
                // pushed back on every save and never becomes eligible at all.
                //
                // ZADD's NX flag expresses this, but its argument form differs
                // between phpredis and predis and silently no-ops when wrong,
                // which is how this was originally shipped broken. An explicit
                // existence check costs one extra round trip and behaves
                // identically on both clients.
                if ($connection->command('zscore', [$key, $route]) !== false) {
                    continue;
                }

                $connection->command('zadd', [$key, $eligibleAt, $route]);
                $pushed++;
            }

            Log::debug('render routes enqueued', [
                'priority' => $request->priority,
                'count' => $request->count(),
                'reason' => $request->reason,
            ]);
        }

        return $pushed;
    }

    /**
     * Routes whose debounce window has elapsed, direct before cascade.
     *
     * @return list<string>
     */
    public function claim(int $limit = 100): array
    {
        $now = now()->getTimestamp();
        $connection = $this->redis->connection();
        $claimed = [];

        foreach ([self::KEY_DIRECT, self::KEY_CASCADE] as $key) {
            if (count($claimed) >= $limit) {
                break;
            }

            $due = $connection->command('zrangebyscore', [
                $key, '-inf', $now, ['limit' => [0, $limit - count($claimed)]],
            ]);

            if ($due === []) {
                continue;
            }

            $connection->command('zrem', [$key, ...$due]);
            /** @var list<string> $due */
            $claimed = [...$claimed, ...$due];
        }

        return $claimed;
    }

    /** @return array{direct: int, cascade: int} */
    public function depth(): array
    {
        $connection = $this->redis->connection();

        return [
            'direct' => (int) $connection->command('zcard', [self::KEY_DIRECT]),
            'cascade' => (int) $connection->command('zcard', [self::KEY_CASCADE]),
        ];
    }

    public function flush(): void
    {
        $this->redis->connection()->command('del', [self::KEY_DIRECT, self::KEY_CASCADE]);
    }
}
