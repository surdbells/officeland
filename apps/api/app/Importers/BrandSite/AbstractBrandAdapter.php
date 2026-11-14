<?php

declare(strict_types=1);

namespace App\Importers\BrandSite;

/** Sensible defaults every adapter can override. */
abstract class AbstractBrandAdapter implements BrandSiteAdapter
{
    /** @var array<string, mixed> run options from the command (e.g. deep) */
    public array $options = [];

    public function userAgent(): string
    {
        return BrandHttp::UA;
    }

    public function imageHeaders(): array
    {
        return [];
    }

    public function minDelayMs(): int
    {
        return 3000;
    }
}
