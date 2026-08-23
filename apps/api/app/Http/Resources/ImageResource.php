<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use App\Models\MediaDerivative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One image, shaped for a `<picture>` element (MASTER BUILD PROMPT §12.5).
 *
 * Not product-specific, despite where it started: a category hero is the same
 * question. The only product-only field is `role`, which comes from the
 * product_media pivot and is simply absent on a relation that has none.
 *
 * ── Why the server builds the srcsets ────────────────────────────────────
 * The client could compose these URLs from a checksum and the variant table,
 * but then the variant table would exist in two places and drift. Worse, the
 * client cannot know which derivatives actually EXIST: generation is resumable
 * and throttled (Phase 4), so a product ingested minutes ago may have thumb and
 * card but not zoom. A srcset advertising a 404 is worse than a narrower one.
 * Only rows in media_derivatives are emitted.
 *
 * ── Why width and height are always present ──────────────────────────────
 * They are the CLS fix. An <img> without intrinsic dimensions reflows the page
 * when it loads, and CLS is a Core Web Vital the Phase 8 gate measures. The
 * storefront sets them as attributes and derives aspect-ratio from them.
 *
 * @mixin Media
 */
final class ImageResource extends JsonResource
{
    /**
     * Format preference, best first — the `<source>` order of §12.5. A browser
     * takes the first type it understands, so AVIF must precede WebP, and JPEG
     * is the `<img>` fallback rather than a `<source>`.
     */
    private const FORMAT_ORDER = ['avif', 'webp', 'jpeg'];

    private const FALLBACK_FORMAT = 'jpeg';

    /** The variant a plain `src` points at: large enough to be useful, small enough to ship. */
    private const FALLBACK_VARIANT = 'pdp';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $derivatives = $this->groupByFormat();
        $fallback = $this->fallbackDerivative($derivatives);

        return [
            'uuid' => $this->uuid,

            /*
             * Alt text is guaranteed non-empty by Media::assertAttachable(),
             * which is enforced at attach time rather than in a form (§12.6),
             * so no default is invented here. An invented alt is worse than a
             * missing one: it is a lie to a screen reader.
             */
            'alt' => (string) $this->alt_text,

            /*
             * The derivative's dimensions when there is one, because that is
             * what the browser will actually load; the original's otherwise,
             * so the aspect ratio is still reserved while generation catches
             * up.
             */
            'width' => $fallback !== null ? $fallback->width : $this->width,
            'height' => $fallback !== null ? $fallback->height : $this->height,

            'role' => $this->whenPivotLoaded(
                'product_media',
                fn (): string => (string) $this->resource->getAttribute('pivot')?->role,
            ),

            'src' => $fallback === null ? null : $this->url($fallback),

            'sources' => $this->sources($derivatives),
        ];
    }

    /**
     * One `<source>` per format that has at least one derivative, each with a
     * width-descriptor srcset.
     *
     * @param  array<string, list<MediaDerivative>>  $derivatives
     * @return list<array{type: string, srcset: string}>
     */
    private function sources(array $derivatives): array
    {
        $sources = [];

        foreach (self::FORMAT_ORDER as $format) {
            if ($format === self::FALLBACK_FORMAT || ($derivatives[$format] ?? []) === []) {
                continue;
            }

            $sources[] = [
                'type' => (string) config("media.formats.{$format}.mime"),
                'srcset' => $this->srcset($derivatives[$format]),
            ];
        }

        // The fallback format is emitted last and as a srcset on the <img>
        // itself, so a browser that understands neither AVIF nor WebP still
        // gets a responsive image rather than the largest one.
        if (($derivatives[self::FALLBACK_FORMAT] ?? []) !== []) {
            $sources[] = [
                'type' => (string) config('media.formats.'.self::FALLBACK_FORMAT.'.mime'),
                'srcset' => $this->srcset($derivatives[self::FALLBACK_FORMAT]),
            ];
        }

        return $sources;
    }

    /**
     * @param  list<MediaDerivative>  $derivatives
     */
    private function srcset(array $derivatives): string
    {
        $entries = [];

        foreach ($derivatives as $derivative) {
            $entries[] = $this->url($derivative).' '.$derivative->width.'w';
        }

        return implode(', ', $entries);
    }

    /**
     * Derivatives grouped by format and sorted by width.
     *
     * The `og` variant is excluded: it is a 1200x630 CROP for social cards, so
     * putting it in a srcset would let a browser pick a crop of the product as
     * though it were the product.
     *
     * @return array<string, list<MediaDerivative>>
     */
    private function groupByFormat(): array
    {
        $grouped = [];

        if (! $this->relationLoaded('derivatives')) {
            return $grouped;
        }

        foreach ($this->derivatives as $derivative) {
            /** @var MediaDerivative $derivative */
            if ($derivative->variant_key === 'og') {
                continue;
            }

            $grouped[$derivative->format][] = $derivative;
        }

        foreach ($grouped as $format => $list) {
            usort($list, static fn (MediaDerivative $a, MediaDerivative $b): int => $a->width <=> $b->width);
            $grouped[$format] = $list;
        }

        return $grouped;
    }

    /**
     * @param  array<string, list<MediaDerivative>>  $derivatives
     */
    private function fallbackDerivative(array $derivatives): ?MediaDerivative
    {
        $candidates = $derivatives[self::FALLBACK_FORMAT] ?? [];

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $derivative) {
            if ($derivative->variant_key === self::FALLBACK_VARIANT) {
                return $derivative;
            }
        }

        // The preferred variant may not have been generated yet; the widest
        // that exists is the closest honest answer.
        return $candidates[array_key_last($candidates)];
    }

    private function url(MediaDerivative $derivative): string
    {
        return rtrim((string) config('media.public_prefix'), '/').'/'.ltrim($derivative->path, '/');
    }
}
