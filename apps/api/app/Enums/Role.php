<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Admin roles (MASTER BUILD PROMPT section 11).
 *
 * Enforced by policies, never by hiding UI. A hidden button is a UI
 * preference; a policy is authorisation, and only the second one survives
 * someone typing the URL.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case CatalogueManager = 'catalogue_manager';
    case Sales = 'sales';
    case ContentEditor = 'content_editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::CatalogueManager => 'Catalogue manager',
            self::Sales => 'Sales',
            self::ContentEditor => 'Content editor',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything, including users and settings.',
            self::CatalogueManager => 'Products, categories, brands, attributes and media.',
            self::Sales => 'Quote leads only. No catalogue access.',
            self::ContentEditor => 'Pages, guides, category copy and SEO fields.',
            self::Viewer => 'Read-only across the panel.',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function managesCatalogue(): bool
    {
        return in_array($this, [self::SuperAdmin, self::CatalogueManager], true);
    }

    public function managesContent(): bool
    {
        return in_array($this, [self::SuperAdmin, self::ContentEditor], true);
    }

    public function managesQuotes(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Sales], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }
}
