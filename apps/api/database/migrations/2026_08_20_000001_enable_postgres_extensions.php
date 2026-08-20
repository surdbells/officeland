<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Required extensions (MASTER BUILD PROMPT section 10.3).
 *
 * Added via migration, never by hand on the server, so every environment is
 * reproducible from the repository.
 *
 * ltree is deliberately absent: the category tree uses a materialized path.
 * ltree labels permit only [A-Za-z0-9_], and every slug in our taxonomy is
 * hyphenated, so ltree would require a second transliterated representation
 * translated on every read and write — permanent friction for a 108-row table.
 * See docs/ARCHITECTURE.md section 3.
 */
return new class extends Migration
{
    /**
     * Migrations connect direct to PostgreSQL, bypassing PgBouncer.
     * DDL and long transactions do not behave correctly through a transaction
     * pooler (docs/ARCHITECTURE.md section 5).
     */
    protected $connection = 'pgsql_direct';

    private const EXTENSIONS = [
        'pg_trgm',   // trigram similarity; typo-tolerant search, dedupe matching
        'unaccent',  // accent folding in the FTS pipeline
        'citext',    // case-insensitive email storage
        'pgcrypto',  // token hashing
    ];

    public function up(): void
    {
        foreach (self::EXTENSIONS as $extension) {
            DB::connection($this->connection)
                ->statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    public function down(): void
    {
        // Dropped in reverse order. CASCADE is deliberately NOT used: if an
        // index or generated column still depends on an extension, failing
        // loudly is correct — silently dropping dependent objects is not.
        foreach (array_reverse(self::EXTENSIONS) as $extension) {
            DB::connection($this->connection)
                ->statement("DROP EXTENSION IF EXISTS {$extension}");
        }
    }
};
