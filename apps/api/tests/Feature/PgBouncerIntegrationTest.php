<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gap G8 — the PgBouncer gate.
 *
 * Transaction pooling changes what the application may do, and every failure
 * it causes is intermittent and load-dependent, which is the worst possible
 * way to discover them. Section 19.2 requires integration tests to run through
 * the pooler for exactly this reason: a suite that connects direct to
 * PostgreSQL cannot catch any of this.
 *
 * Three things are proven here:
 *   1. parameterised queries survive the pooler (PDO::ATTR_EMULATE_PREPARES)
 *   2. a request that writes then reads sees its own write ('sticky' => true)
 *   3. migrations ran on the direct connection, bypassing the pooler
 */
final class PgBouncerIntegrationTest extends TestCase
{
    /**
     * RefreshDatabase is deliberately not used.
     *
     * These tests exist to observe how a POOLED connection behaves. Wrapping
     * them in a long-lived transaction changes exactly the thing under test:
     * PgBouncer pins a server connection for the duration of a transaction, so
     * a suite-wide transaction hides the connection reuse that transaction
     * pooling is all about. Rows written here are removed in tearDown.
     *
     * @var list<string>
     */
    private array $createdBrandUuids = [];

    protected function tearDown(): void
    {
        if ($this->createdBrandUuids !== []) {
            DB::connection('pgsql_direct')->table('brands')
                ->whereIn('uuid', $this->createdBrandUuids)->delete();
        }

        parent::tearDown();
    }

    public function test_the_application_connection_goes_through_pgbouncer(): void
    {
        $port = config('database.connections.pgsql.port');

        $this->assertSame(
            '6432',
            (string) $port,
            'The application connection must target PgBouncer, not PostgreSQL directly.',
        );
    }

    public function test_the_direct_connection_bypasses_pgbouncer(): void
    {
        $port = config('database.connections.pgsql_direct.port');

        $this->assertSame(
            '5432',
            (string) $port,
            'Migrations must connect direct: DDL does not behave correctly through a transaction pooler.',
        );
    }

    /**
     * Section 10.1 offers two ways to survive transaction pooling: PDO
     * emulation, or PgBouncer's own prepared-statement tracking. We use
     * PgBouncer's (max_prepared_statements = 200, PgBouncer 1.25.2), and
     * emulation must stay OFF.
     *
     * Enabling both looks like belt-and-braces and is in fact broken:
     * emulation interpolates bindings into the SQL string, turning a PHP
     * boolean into a bare integer `1`, which PostgreSQL refuses to coerce to
     * boolean. Every insert touching a boolean column then fails with
     * SQLSTATE[42804]. This was found during Phase 2 by the taxonomy seeder.
     */
    public function test_emulated_prepares_are_disabled_on_the_pooled_connection(): void
    {
        $options = config('database.connections.pgsql.options');

        $this->assertArrayHasKey(\PDO::ATTR_EMULATE_PREPARES, $options);
        $this->assertFalse(
            $options[\PDO::ATTR_EMULATE_PREPARES],
            'Emulated prepares break boolean binding on PostgreSQL; PgBouncer tracks prepared statements instead.',
        );
    }

    /**
     * Regression guard for the failure above: a boolean must round-trip.
     */
    public function test_booleans_bind_correctly_through_the_pooler(): void
    {
        $uuid = (string) Str::uuid();
        $this->createdBrandUuids[] = $uuid;

        DB::connection('pgsql')->table('brands')->insert([
            'uuid' => $uuid,
            'name' => 'Boolean Binding',
            'slug' => 'boolean-binding-'.substr($uuid, 0, 8),
            'is_featured' => true,
            'is_indexable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::connection('pgsql')->table('brands')->where('uuid', $uuid)->first();

        $this->assertTrue($row->is_featured);
        $this->assertFalse($row->is_indexable);
    }

    public function test_parameterised_queries_work_through_the_pooler(): void
    {
        $result = DB::connection('pgsql')->select(
            'select ?::int as answer, ?::text as label',
            [42, 'officeland'],
        );

        $this->assertSame(42, $result[0]->answer);
        $this->assertSame('officeland', $result[0]->label);
    }

    /**
     * Repeated parameterised execution is where a misconfigured pooler
     * actually breaks — the first call succeeds and later ones fail once the
     * server connection has been handed to another client.
     */
    public function test_repeated_parameterised_queries_remain_stable(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $result = DB::connection('pgsql')->select('select ?::int as n', [$i]);
            $this->assertSame($i, $result[0]->n, "parameterised query failed on iteration {$i}");
        }
    }

    /**
     * A quote submission that writes and then reads must not observe a stale
     * row. This is the case 'sticky' => true exists for, and the reason it
     * needs proving through the pooler rather than assuming.
     */
    public function test_a_write_is_visible_to_a_subsequent_read_in_the_same_request(): void
    {
        $uuid = (string) Str::uuid();
        $this->createdBrandUuids[] = $uuid;

        DB::connection('pgsql')->table('brands')->insert([
            'uuid' => $uuid,
            'name' => 'Read Your Own Writes',
            'slug' => 'read-your-own-writes-'.substr($uuid, 0, 8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $found = DB::connection('pgsql')->table('brands')->where('uuid', $uuid)->first();

        $this->assertNotNull($found, 'A write was not visible to a subsequent read in the same request.');
        $this->assertSame('Read Your Own Writes', $found->name);
    }

    public function test_search_path_is_not_set_per_session(): void
    {
        // Session-level SET does not persist under transaction pooling, so
        // search_path is bound to the role instead (ALTER ROLE ... SET).
        // Laravel must therefore not be configured to issue it on connect.
        $this->assertNull(
            config('database.connections.pgsql.search_path'),
            'search_path must be null on the pooled connection; it is set via ALTER ROLE.',
        );
    }

    public function test_the_role_level_search_path_is_in_effect(): void
    {
        $result = DB::connection('pgsql')->select('show search_path');

        $this->assertStringContainsString('public', $result[0]->search_path);
    }
}
