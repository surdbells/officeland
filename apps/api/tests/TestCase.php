<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run schema builds on the direct connection.
     *
     * The suite deliberately exercises the application through PgBouncer
     * (section 19.2) — that is the only way to catch the failures transaction
     * pooling introduces. But DDL must not go through the pooler: a
     * `migrate:fresh` routed through transaction pooling aborts mid-run with
     * SQLSTATE[25P02] and every subsequent statement in the transaction fails.
     *
     * So: schema over pgsql_direct, queries over pgsql. Both point at the same
     * database, which is exactly the split production uses.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return [
            '--database' => 'pgsql_direct',
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
        ];
    }
}
