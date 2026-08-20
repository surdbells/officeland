<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards against the suite running against a non-test database.
 *
 * RefreshDatabase drops and rebuilds every table. Pointed at the development
 * or production database it destroys it, silently and instantly — there is no
 * error, because dropping tables you have permission to drop is not an error.
 *
 * This actually happened during Phase 2: phpunit.xml selected
 * `officeland_test`, but PHPUnit's <env force="true"> does not write $_SERVER,
 * which is where Docker Compose injects DB_DATABASE and where Laravel's
 * environment repository looks first. The suite ran against `officeland`.
 *
 * These assertions are cheap and permanent. Never delete them.
 */
final class TestDatabaseIsolationTest extends TestCase
{
    public function test_the_suite_is_pointed_at_the_test_database(): void
    {
        $configured = config('database.connections.pgsql.database');

        $this->assertSame(
            'officeland_test',
            $configured,
            'The test suite is not pointed at officeland_test. RefreshDatabase would destroy the target database.',
        );
    }

    public function test_the_connection_actually_reports_the_test_database(): void
    {
        // Assert against the live connection, not just configuration —
        // configuration can be right while the connection is not.
        $actual = DB::connection('pgsql')->select('select current_database() as db')[0]->db;

        $this->assertSame('officeland_test', $actual);
    }

    public function test_the_direct_connection_is_also_isolated(): void
    {
        // Migrations run over pgsql_direct. If only the pooled connection were
        // isolated, migrate:fresh would still rebuild the wrong database.
        $actual = DB::connection('pgsql_direct')->select('select current_database() as db')[0]->db;

        $this->assertSame('officeland_test', $actual);
    }

    public function test_the_environment_is_testing(): void
    {
        $this->assertSame('testing', app()->environment());
    }
}
