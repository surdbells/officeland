<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Attaches generated placeholder imagery to the demo catalogue.
 *
 * ── Why generate images rather than commit them ──────────────────────────
 * Committing binaries to satisfy a seeder is how repositories rot, and a
 * checked-in JPEG would bypass the thing worth exercising. This runs the real
 * Phase 4 path — ingest, strip metadata, content-address by SHA-256, generate
 * 5 variants x 3 formats — so the storefront's `<picture>` markup is built
 * from derivatives that were actually produced by libvips, not from rows
 * inserted to look like they were.
 *
 * It is also the only automated coverage that Phase 4 and Phase 8 agree with
 * each other: DerivativeGenerator writes `media_derivatives`, and
 * ProductImageResource reads it. Nothing before this asserted they match.
 *
 * ── What the images are ──────────────────────────────────────────────────
 * Flat colour panels with the product's model code drawn on them. Deliberately
 * ugly: nobody should mistake seeded data for photography, and a placeholder
 * that looks like a real product photo eventually reaches production.
 *
 * Run after DemoCatalogueSeeder. Idempotent: a product that already has media
 * is skipped, and the content-addressed store deduplicates identical bytes
 * anyway.
 *
 * Deliberately silent. Seeder::$command is a typed, non-nullable property that
 * is simply left uninitialised when a seeder is constructed outside artisan,
 * so every way of writing progress output here is either a nullsafe call or an
 * isset() on a property PHPStan has been told cannot be null. Per-product
 * progress is not worth this repository's first static-analysis ignore.
 */
final class DemoMediaSeeder extends Seeder
{
    /**
     * Distinct enough to tell cards apart at a glance in a grid.
     *
     * @var list<array{int<0, 255>, int<0, 255>, int<0, 255>}>
     */
    private const PALETTE = [
        [0x1F, 0x3A, 0x5F],
        [0x2E, 0x5E, 0x4E],
        [0x6B, 0x2D, 0x3C],
        [0x4A, 0x3B, 0x6B],
        [0x5C, 0x4A, 0x1F],
    ];

    /** Square, and large enough that the 1600px `zoom` variant is not upscaled. */
    private const CANVAS = 1600;

    public function __construct(
        private readonly MediaIngestor $ingestor,
        private readonly DerivativeGenerator $generator,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoMediaSeeder must never run in production.');
        }

        if (! extension_loaded('gd')) {
            // GD only draws the source panel; libvips still does every
            // derivative. Failing loudly beats seeding a catalogue with no
            // images and leaving someone to wonder why the grid is empty.
            throw new RuntimeException('DemoMediaSeeder needs ext-gd to draw its placeholder sources.');
        }

        $products = Product::query()->with('media')->orderBy('id')->get();

        foreach ($products->values() as $index => $product) {
            if ($product->media->isNotEmpty()) {
                continue;
            }

            $this->attach($product, $index);
        }
    }

    private function attach(Product $product, int $index): void
    {
        $label = (string) ($product->mpn ?? $product->sku ?? $product->slug);
        $colour = self::PALETTE[$index % count(self::PALETTE)];

        $source = $this->draw($label, $colour);

        try {
            $media = $this->ingestor->ingest($source, [
                'original_filename' => $product->slug.'.jpg',
                // Alt text is required before media may be attached
                // (Media::assertAttachable, section 12.6). Descriptive of the
                // product, because that is what a screen reader will read.
                'alt_text' => $product->name,
                'source' => 'manual',
            ]);

            $media->assertAttachable();

            $this->generator->generate($media);

            $product->media()->syncWithoutDetaching([
                $media->id => ['role' => 'primary', 'sort_order' => 0],
            ]);
        } finally {
            @unlink($source);
        }
    }

    /**
     * Draws a square panel with the model code on it.
     *
     * Square because a mixed catalogue of chairs and cartridges looks broken in
     * a grid unless the aspect ratio is uniform, and 1600px so the `zoom`
     * variant has real pixels to work with rather than being upscaled.
     *
     * Every GD call is checked. They return false on failure, and an unchecked
     * false propagates into the next call as a colour index or an image handle,
     * producing an image that is subtly wrong rather than an error that says so.
     *
     * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $colour
     */
    private function draw(string $label, array $colour): string
    {
        $image = imagecreatetruecolor(self::CANVAS, self::CANVAS);

        if ($image === false) {
            throw new RuntimeException('GD could not allocate the placeholder canvas.');
        }

        $background = imagecolorallocate($image, $colour[0], $colour[1], $colour[2]);
        $foreground = imagecolorallocate($image, 242, 242, 242);

        if ($background === false || $foreground === false) {
            throw new RuntimeException('GD could not allocate the placeholder colours.');
        }

        imagefilledrectangle($image, 0, 0, self::CANVAS, self::CANVAS, $background);

        /*
         * Built-in font 5 is tiny at 1600px, so the label is drawn small and
         * scaled up. Crude, but it avoids depending on a font file being
         * present in the container.
         */
        $stripWidth = max(1, imagefontwidth(5) * max(1, strlen($label)));
        $stripHeight = max(1, imagefontheight(5));

        $strip = imagecreatetruecolor($stripWidth, $stripHeight);

        if ($strip === false) {
            throw new RuntimeException('GD could not allocate the label strip.');
        }

        imagefilledrectangle($strip, 0, 0, $stripWidth, $stripHeight, $background);
        imagestring($strip, 5, 0, 0, $label, $foreground);

        $targetWidth = (int) (self::CANVAS * 0.6);
        $targetHeight = max(1, (int) ($targetWidth * $stripHeight / $stripWidth));

        imagecopyresampled(
            $image,
            $strip,
            (int) ((self::CANVAS - $targetWidth) / 2),
            (int) ((self::CANVAS - $targetHeight) / 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $stripWidth,
            $stripHeight,
        );

        imagedestroy($strip);

        $path = tempnam(sys_get_temp_dir(), 'demo-media-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the placeholder.');
        }

        $path .= '.jpg';

        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return $path;
    }
}
