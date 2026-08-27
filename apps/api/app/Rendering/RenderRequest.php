<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * A set of routes that must be regenerated, with the reason and priority.
 *
 * Priority is not cosmetic. Direct routes - the page a buyer would land on
 * after an edit - must regenerate promptly. Cascade routes, where a product
 * merely appears in someone else's related rail, can lag by minutes with no
 * user or SEO cost. Treating them identically is what turns one edit into
 * hundreds of synchronous renders (docs/ARCHITECTURE.md section 4.3).
 */
final readonly class RenderRequest
{
    public const PRIORITY_DIRECT = 'direct';

    public const PRIORITY_CASCADE = 'cascade';

    /**
     * @param  list<string>  $routes
     */
    public function __construct(
        public array $routes,
        public string $priority = self::PRIORITY_DIRECT,
        public string $reason = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->routes === [];
    }

    public function count(): int
    {
        return count($this->routes);
    }
}
