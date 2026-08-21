<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Whether one page clears the content floor, and what is missing if not.
 *
 * A plain value object rather than a boolean, because "not ready" is useless
 * to the person who has to fix it. Every failure reads as an instruction.
 */
final readonly class Readiness
{
    /** @param list<string> $failures */
    public function __construct(public array $failures) {}

    public static function ready(): self
    {
        return new self([]);
    }

    public function isReady(): bool
    {
        return $this->failures === [];
    }

    /** "needs 42 more words · no warranty stated" */
    public function summary(): string
    {
        return $this->isReady() ? 'Ready to index' : implode(' · ', $this->failures);
    }
}
