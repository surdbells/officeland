<?php

declare(strict_types=1);

namespace App\Icecat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;

/**
 * Icecat's suppliers reference: every brand Icecat knows, each with its logo.
 *
 * The per-product <Supplier> element only carries logo URLs on the higher
 * access tiers; on the open (freexml) tier it usually omits them, which is why
 * brands kept falling back to a generated monogram. This reference file, by
 * contrast, lists the logo for every supplier regardless of tier, so it is the
 * reliable source for a brand's real logo. It is small and changes rarely, so
 * it is downloaded once and cached for a week.
 *
 * The lookup is by Icecat supplier id first (config('icecat.suppliers') maps our
 * brands to those ids) and by brand name as a fallback.
 */
final class IcecatSuppliers
{
    /** @var array<int, string>|null  supplier id => best logo URL */
    private static ?array $byId = null;

    /** @var array<string, string>|null  normalised name => best logo URL */
    private static ?array $byName = null;

    /**
     * Ensure the gzipped suppliers reference is cached locally. Returns the
     * local path, or null on failure (e.g. the IP is not whitelisted).
     */
    public static function ensureCached(bool $refresh = false): ?string
    {
        $path = (string) config('icecat.suppliers_cache');
        $ttl = (int) config('icecat.suppliers_ttl_hours', 168) * 3600;
        $fresh = is_file($path) && (time() - (int) filemtime($path)) < $ttl;

        if ($fresh && ! $refresh) {
            return $path;
        }

        @mkdir(dirname($path), 0775, true);

        try {
            $response = Http::withBasicAuth((string) config('icecat.username'), (string) config('icecat.password'))
                ->timeout(300)
                ->sink($path)
                ->get((string) config('icecat.endpoints.suppliers_list'));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_file($path) || (filesize($path) ?: 0) < 1000) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /** The logo URL for an Icecat supplier id, or null. */
    public static function logoForId(int $supplierId, bool $refresh = false): ?string
    {
        self::load($refresh);

        return self::$byId[$supplierId] ?? null;
    }

    /** The logo URL for a brand name (fuzzy, case/spacing-insensitive), or null. */
    public static function logoForName(string $name, bool $refresh = false): ?string
    {
        self::load($refresh);

        $key = self::normalise($name);

        return $key === '' ? null : (self::$byName[$key] ?? null);
    }

    /**
     * Parse the reference once into id => logo and name => logo maps. A supplier
     * with no usable logo is simply absent from the maps.
     */
    private static function load(bool $refresh = false): void
    {
        if (self::$byId !== null && ! $refresh) {
            return;
        }

        self::$byId = [];
        self::$byName = [];

        $path = self::ensureCached($refresh);
        if ($path === null) {
            return;
        }

        $reader = new XMLReader;
        if (@$reader->open('compress.zlib://'.$path, null, LIBXML_NONET) === false) {
            return;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Supplier') {
                    continue;
                }

                $attrs = [];
                if ($reader->hasAttributes) {
                    while ($reader->moveToNextAttribute()) {
                        $attrs[$reader->name] = $reader->value;
                    }
                    $reader->moveToElement();
                }

                $logo = self::bestLogo($attrs);
                if ($logo === null) {
                    continue;
                }

                $id = (int) ($attrs['ID'] ?? $attrs['Id'] ?? 0);
                if ($id > 0) {
                    self::$byId[$id] = $logo;
                }

                $name = self::normalise((string) ($attrs['Name'] ?? ''));
                if ($name !== '') {
                    self::$byName[$name] = $logo;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * The best logo URL among a supplier's attributes: prefer the high-res one,
     * then medium, then the plain pic, then low. Any http(s) attribute whose
     * name mentions "Logo" counts, so a tier-specific attribute name still works.
     *
     * @param  array<string, string>  $attrs
     */
    private static function bestLogo(array $attrs): ?string
    {
        foreach (['LogoHighPic', 'LogoMediumPic', 'LogoPic', 'LogoLowPic'] as $name) {
            $url = trim((string) ($attrs[$name] ?? ''));
            if ($url !== '' && Str::startsWith($url, 'http')) {
                return $url;
            }
        }

        foreach ($attrs as $name => $value) {
            $value = trim((string) $value);
            if (Str::contains($name, 'Logo', ignoreCase: true) && Str::startsWith($value, 'http')) {
                return $value;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(trim($name))) ?? '';
    }
}
