<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Rendering\StaticSite;
use RuntimeException;
use Tests\TestCase;

/**
 * The static writer (Phase 8e).
 *
 * Caddy serves these files with no application in front of them, so a mistake
 * here is served at full speed and cached at the edge. None of it is visible
 * from the application: the only symptom is a wrong page.
 */
final class StaticSiteTest extends TestCase
{
    private string $root;

    private StaticSite $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/static-site-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        $this->site = new StaticSite($this->root);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_a_route_becomes_an_index_file(): void
    {
        $this->site->write('/c/office-furniture/', '<html><body>chairs</body></html>');

        $this->assertFileExists($this->root.'/c/office-furniture/index.html');
        $this->assertStringContainsString('chairs', (string) file_get_contents($this->root.'/c/office-furniture/index.html'));
    }

    public function test_the_root_route_becomes_the_root_index(): void
    {
        $this->site->write('/', '<html><body>home</body></html>');

        $this->assertFileExists($this->root.'/index.html');
    }

    /**
     * A directory with an index.html is what makes the trailing-slash URL work
     * without a redirect (D-028).
     */
    public function test_a_paginated_route_nests_under_page(): void
    {
        $this->site->write('/c/office-furniture/page/2/', '<html><body>page two</body></html>');

        $this->assertFileExists($this->root.'/c/office-furniture/page/2/index.html');
    }

    /** A route naming a file is written as that file, not as a directory. */
    public function test_a_file_route_is_written_as_a_file(): void
    {
        $this->site->write('/sitemap-products.xml', '<urlset></urlset>');

        $this->assertFileExists($this->root.'/sitemap-products.xml');
        $this->assertDirectoryDoesNotExist($this->root.'/sitemap-products.xml/');
    }

    public function test_writing_twice_replaces_rather_than_appends(): void
    {
        $this->site->write('/p/thing/', '<html>old</html>');
        $this->site->write('/p/thing/', '<html>new</html>');

        $contents = (string) file_get_contents($this->root.'/p/thing/index.html');

        $this->assertSame('<html>new</html>', $contents);
    }

    /**
     * The temporary file must be a SIBLING of the target. rename() is atomic
     * only within a filesystem, and a temp file elsewhere degrades to
     * copy-then-unlink, reintroducing the torn read this exists to prevent.
     */
    public function test_no_temporary_files_are_left_behind(): void
    {
        $this->site->write('/p/thing/', '<html>content</html>');

        $entries = array_values(array_diff(
            (array) scandir($this->root.'/p/thing'),
            ['.', '..'],
        ));

        $this->assertSame(['index.html'], $entries, 'A temporary file survived the write.');
    }

    /**
     * The queue is filled by the application, but a traversal here would let a
     * catalogue edit write anywhere the process can reach.
     */
    public function test_it_refuses_to_escape_the_static_root(): void
    {
        $this->expectException(RuntimeException::class);

        $this->site->write('/../../etc/passwd', 'nope');
    }

    public function test_it_refuses_a_route_that_is_not_absolute(): void
    {
        $this->expectException(RuntimeException::class);

        $this->site->write('c/office-furniture/', 'nope');
    }

    public function test_it_reports_whether_a_route_exists(): void
    {
        $this->assertFalse($this->site->exists('/p/thing/'));

        $this->site->write('/p/thing/', '<html>content</html>');

        $this->assertTrue($this->site->exists('/p/thing/'));
    }

    /**
     * Expanding a `page/*` marker has to find the pages that ALREADY exist:
     * those are exactly the ones that would otherwise be left stale when the
     * page boundaries shift (D-032).
     */
    public function test_it_finds_existing_paginated_pages(): void
    {
        $this->site->write('/c/furniture/', '<html>1</html>');
        $this->site->write('/c/furniture/page/2/', '<html>2</html>');
        $this->site->write('/c/furniture/page/3/', '<html>3</html>');

        $pages = $this->site->pagesUnder('/c/furniture/');

        sort($pages);

        $this->assertSame(
            ['/c/furniture/', '/c/furniture/page/2/', '/c/furniture/page/3/'],
            $pages,
        );
    }

    public function test_a_listing_with_no_pages_returns_only_itself(): void
    {
        $this->site->write('/c/furniture/', '<html>1</html>');

        $this->assertSame(['/c/furniture/'], $this->site->pagesUnder('/c/furniture/'));
    }

    /** Subtree expansion, for when the resolver hits its cascade cap. */
    public function test_it_lists_every_route_under_a_prefix(): void
    {
        $this->site->write('/p/one/', '<html>1</html>');
        $this->site->write('/p/two/', '<html>2</html>');
        $this->site->write('/c/elsewhere/', '<html>3</html>');

        $routes = $this->site->routesUnder('/p');

        $this->assertSame(['/p/one/', '/p/two/'], $routes);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
