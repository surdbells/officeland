<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Media\MediaStorage;
use App\Models\Media;
use App\Models\MediaDerivative;
use Illuminate\Support\Facades\DB;
use Jcupitt\Vips\Image as VipsImage;
use RuntimeException;
use Tests\TestCase;

final class MediaPipelineTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('media_derivatives')->delete();
        DB::table('media')->delete();

        $this->workspace = sys_get_temp_dir().'/media-test-'.bin2hex(random_bytes(4));
        mkdir($this->workspace, 0o775, true);

        config(['media.root' => $this->workspace.'/store']);
    }

    protected function tearDown(): void
    {
        DB::table('media_derivatives')->delete();
        DB::table('media')->delete();

        $this->deleteTree($this->workspace);

        parent::tearDown();
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** Produces a distinct, decodable JPEG. */
    private function makeImage(string $name, int $width = 900, int $height = 700, int $seed = 1): string
    {
        $path = $this->workspace.'/'.$name;

        VipsImage::gaussnoise($width, $height, ['sigma' => 10 + $seed])
            ->cast('uchar')
            ->writeToFile($path.'[Q=90]');

        return $path;
    }

    // -----------------------------------------------------------------
    // Validation — 4a
    // -----------------------------------------------------------------

    public function test_it_ingests_a_valid_image(): void
    {
        $media = app(MediaIngestor::class)->ingest(
            $this->makeImage('valid.jpg'),
            ['alt_text' => 'A product photograph'],
        );

        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame(900, $media->width);
        $this->assertSame(700, $media->height);
        $this->assertSame(64, strlen($media->checksum_sha256));
    }

    /**
     * MIME is determined by content. A file renamed to .jpg is still not a
     * JPEG, and trusting the extension is how upload handlers get exploited.
     */
    public function test_it_rejects_a_non_image_disguised_by_extension(): void
    {
        $path = $this->workspace.'/not-really.jpg';
        file_put_contents($path, "#!/bin/sh\necho pwned\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported file type|not a decodable image/');

        app(MediaIngestor::class)->ingest($path);
    }

    /**
     * SVG is an XML document that can carry script and external references.
     * Accepting it means owning a sanitiser indefinitely, and nothing in this
     * catalogue needs vector uploads.
     */
    public function test_it_rejects_svg(): void
    {
        $path = $this->workspace.'/logo.svg';
        file_put_contents(
            $path,
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SVG is not accepted|Unsupported file type/');

        app(MediaIngestor::class)->ingest($path);
    }

    public function test_it_rejects_an_image_below_the_minimum_dimension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/minimum/');

        app(MediaIngestor::class)->ingest($this->makeImage('tiny.jpg', 50, 50));
    }

    public function test_it_rejects_an_empty_file(): void
    {
        $path = $this->workspace.'/empty.jpg';
        touch($path);

        $this->expectException(RuntimeException::class);

        app(MediaIngestor::class)->ingest($path);
    }

    // -----------------------------------------------------------------
    // Deduplication and content addressing — 4b
    // -----------------------------------------------------------------

    /**
     * Suppliers ship one photograph across a whole product family, and Icecat
     * serves the same image to every reseller. At 75,000 originals the
     * duplicate rate is material, so identical bytes must be stored once.
     */
    public function test_identical_bytes_produce_one_media_record(): void
    {
        $path = $this->makeImage('dupe.jpg');
        $ingestor = app(MediaIngestor::class);

        $first = $ingestor->ingest($path, ['alt_text' => 'first']);
        $second = $ingestor->ingest($path, ['alt_text' => 'second']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Media::query()->count());
    }

    public function test_different_images_produce_different_records(): void
    {
        $ingestor = app(MediaIngestor::class);

        $ingestor->ingest($this->makeImage('a.jpg', 900, 700, 1), ['alt_text' => 'a']);
        $ingestor->ingest($this->makeImage('b.jpg', 900, 700, 99), ['alt_text' => 'b']);

        $this->assertSame(2, Media::query()->count());
    }

    /**
     * The stored path must contain the content hash. That is what makes a
     * derivative URL immutable, which is what permits max-age=31536000.
     */
    public function test_storage_is_content_addressed(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('addressed.jpg'), ['alt_text' => 'x']);
        $checksum = $media->checksum_sha256;

        $this->assertStringContainsString(substr($checksum, 0, 2).'/'.substr($checksum, 2, 2), $media->path);
        $this->assertStringContainsString($checksum, $media->path);
        $this->assertTrue(app(MediaStorage::class)->exists($media->path));
    }

    /**
     * EXIF routinely carries GPS coordinates and camera serial numbers. None
     * of it belongs on a public product image.
     */
    public function test_metadata_is_stripped_from_the_stored_original(): void
    {
        $source = $this->makeImage('with-exif.jpg');

        $media = app(MediaIngestor::class)->ingest($source, ['alt_text' => 'x']);
        $stored = app(MediaStorage::class)->absolute($media->path);

        $image = VipsImage::newFromFile($stored);

        $this->assertNotContains('exif-data', $image->getFields());
    }

    // -----------------------------------------------------------------
    // Derivatives — 4c
    // -----------------------------------------------------------------

    public function test_it_generates_every_variant_in_every_format(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('full.jpg', 2000, 1500), ['alt_text' => 'x']);

        $result = app(DerivativeGenerator::class)->generate($media);

        $expected = count(config('media.variants')) * count(config('media.formats'));

        $this->assertSame($expected, $result['generated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame($expected, $media->derivatives()->count());
    }

    /**
     * Regression guard. VipsImage::thumbnail() returns a lazily evaluated
     * image; writing it to three formats re-runs the pipeline against a source
     * opened for sequential access, and the second and third writes fail with
     * "error in tile" — reported as a warning on stderr, not an exception, so
     * derivatives went missing silently.
     */
    public function test_all_three_formats_are_written_for_each_variant(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('formats.jpg', 2000, 1500), ['alt_text' => 'x']);

        app(DerivativeGenerator::class)->generate($media);

        foreach (array_keys(config('media.variants')) as $variant) {
            foreach (array_keys(config('media.formats')) as $format) {
                $this->assertSame(
                    1,
                    MediaDerivative::query()
                        ->where('media_id', $media->id)
                        ->where('variant_key', $variant)
                        ->where('format', $format)
                        ->count(),
                    "missing {$variant}/{$format}",
                );
            }
        }
    }

    /** Resumability: a job killed halfway must not redo completed work. */
    public function test_generation_is_idempotent(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('idem.jpg', 1200, 900), ['alt_text' => 'x']);
        $generator = app(DerivativeGenerator::class);

        $first = $generator->generate($media);
        $second = $generator->generate($media);

        $this->assertGreaterThan(0, $first['generated']);
        $this->assertSame(0, $second['generated']);
        $this->assertSame($first['generated'], $second['skipped']);
    }

    public function test_a_missing_derivative_is_regenerated_on_the_next_run(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('gap.jpg', 1200, 900), ['alt_text' => 'x']);
        $generator = app(DerivativeGenerator::class);
        $storage = app(MediaStorage::class);

        $generator->generate($media);

        // Simulate an interrupted run: the row exists, the file does not.
        $orphan = $media->derivatives()->first();
        $storage->delete($orphan->path);

        $result = $generator->generate($media);

        $this->assertSame(1, $result['generated']);
        $this->assertTrue($storage->exists($orphan->path));
    }

    /**
     * Enlarging a 400px supplier image to 1600px produces a bigger file that
     * looks worse — against an 800 KB PDP budget that is pure loss.
     */
    public function test_it_never_upscales_beyond_the_original(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('small.jpg', 500, 400), ['alt_text' => 'x']);

        app(DerivativeGenerator::class)->generate($media);

        foreach ($media->derivatives as $derivative) {
            $this->assertLessThanOrEqual(
                500,
                $derivative->width,
                "{$derivative->variant_key}/{$derivative->format} was upscaled",
            );
        }
    }

    public function test_avif_is_smaller_than_jpeg_for_the_same_variant(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('compare.jpg', 2000, 1500), ['alt_text' => 'x']);

        app(DerivativeGenerator::class)->generate($media);

        $avif = $media->derivatives()->where('variant_key', 'pdp')->where('format', 'avif')->first();
        $jpeg = $media->derivatives()->where('variant_key', 'pdp')->where('format', 'jpeg')->first();

        // This is why AVIF is offered first in the <picture> fallback order.
        $this->assertLessThan((int) $jpeg->bytes, (int) $avif->bytes);
    }

    // -----------------------------------------------------------------
    // Alt text — 4f
    // -----------------------------------------------------------------

    public function test_media_without_alt_text_cannot_be_attached(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('noalt.jpg'));

        $this->assertFalse($media->hasAltText());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/alt text/');

        $media->assertAttachable();
    }

    public function test_media_with_alt_text_can_be_attached(): void
    {
        $media = app(MediaIngestor::class)->ingest(
            $this->makeImage('withalt.jpg'),
            ['alt_text' => 'Mesh-back ergonomic task chair, front view'],
        );

        $media->assertAttachable();

        $this->assertTrue($media->hasAltText());
    }

    public function test_whitespace_only_alt_text_does_not_count(): void
    {
        $media = app(MediaIngestor::class)->ingest($this->makeImage('blankalt.jpg'), ['alt_text' => '   ']);

        $this->assertFalse($media->hasAltText());
    }

    /** Feeds the Filament missing-alt-text report of section 12.6. */
    public function test_the_missing_alt_text_report_finds_them(): void
    {
        app(MediaIngestor::class)->ingest($this->makeImage('r1.jpg', 900, 700, 1), ['alt_text' => 'described']);
        app(MediaIngestor::class)->ingest($this->makeImage('r2.jpg', 900, 700, 2));
        app(MediaIngestor::class)->ingest($this->makeImage('r3.jpg', 900, 700, 3), ['alt_text' => '  ']);

        $this->assertSame(2, Media::query()->missingAltText()->count());
    }
}
