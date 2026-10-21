<?php

declare(strict_types=1);

namespace App\Brand;

use RuntimeException;

/**
 * Generates a clean monogram placeholder logo for a brand.
 *
 * Not every brand has a real logo to source, but the storefront's brand rail
 * and megamenu look unfinished with bare text, so every brand gets at least
 * this: its initials in white on a colour deterministically derived from its
 * name (so the same brand is always the same colour, and the set looks varied
 * rather than one flat blue wall). A real logo, when available, is preferred and
 * simply replaces it.
 *
 * Returns a PNG the caller feeds through the normal media pipeline, so the
 * generated logo gets the same content-addressing and <picture> derivatives as
 * any uploaded image.
 */
final class BrandLogoGenerator
{
    private const SIZE = 512;

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    /**
     * A calm, legible palette. White text sits on every one of these at AA
     * contrast, and they read as a brand system rather than a random scatter.
     *
     * @var list<array{int, int, int}>
     */
    private const PALETTE = [
        [0, 70, 191],    // brand blue
        [30, 64, 175],   // indigo
        [15, 118, 110],  // teal
        [4, 120, 87],    // emerald
        [146, 64, 14],   // amber-brown
        [153, 27, 27],   // red
        [88, 28, 135],   // purple
        [55, 65, 81],    // slate
        [157, 23, 77],   // pink-rose
        [3, 105, 161],   // sky
        [63, 98, 18],    // lime-olive
        [120, 53, 15],   // sienna
    ];

    /** Returns an absolute path to a generated PNG. The caller owns the file. */
    public function monogram(string $name): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD is required to generate brand logos.');
        }

        $image = imagecreatetruecolor(self::SIZE, self::SIZE);

        [$r, $g, $b] = $this->colourFor($name);
        $bg = imagecolorallocate($image, $r, $g, $b);
        imagefilledrectangle($image, 0, 0, self::SIZE, self::SIZE, $bg);

        $white = imagecolorallocate($image, 255, 255, 255);
        $initials = $this->initials($name);
        $fontSize = strlen($initials) > 1 ? 180 : 230;

        // Centre the text using its real bounding box (imagettfbbox gives the
        // ink extents, including the baseline offset).
        $box = imagettfbbox($fontSize, 0, self::FONT, $initials);
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        $x = (int) ((self::SIZE - $textWidth) / 2 - $box[0]);
        $y = (int) ((self::SIZE - $textHeight) / 2 - $box[7]);

        imagettftext($image, $fontSize, 0, $x, $y, $white, self::FONT, $initials);

        $path = tempnam(sys_get_temp_dir(), 'brandlogo').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /** One or two uppercase initials from the brand name. */
    private function initials(string $name): string
    {
        $words = preg_split('/[\s\-_]+/', trim($name)) ?: [];
        $words = array_values(array_filter($words));

        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        $single = $words[0] ?? ($name !== '' ? $name : '?');

        return strtoupper(mb_substr($single, 0, 2));
    }

    /**
     * Deterministic colour from the name, so a brand keeps the same one.
     *
     * @return array{int, int, int}
     */
    private function colourFor(string $name): array
    {
        $hash = crc32(mb_strtolower(trim($name)));

        return self::PALETTE[$hash % count(self::PALETTE)];
    }
}
