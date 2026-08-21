<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * What one render pass did.
 *
 * Failures are keyed by route rather than counted, because a render failure is
 * silent by nature — the old page keeps being served — and the route name is
 * the only thing that makes it actionable.
 */
final readonly class RenderResult
{
    /**
     * @param  array<string, string>  $failures  route => reason
     */
    public function __construct(
        public int $rendered = 0,
        public array $failures = [],
        public int $durationMs = 0,
    ) {}

    public function isClean(): bool
    {
        return $this->failures === [];
    }
}
