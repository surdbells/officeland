<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guard for the transaction failure found in Phase 4.
 *
 * Every DB::transaction() containing a model write failed with:
 *
 *     SQLSTATE[25P02] current transaction is aborted, commands ignored until
 *     end of transaction block
 *
 * The cause was PgBouncer's max_prepared_statements = 200. Inside a
 * transaction a plain SELECT succeeded and the next PREPARED statement
 * reported the transaction as already aborted. Setting it to 0 resolves it.
 *
 * Section 10.1 offers two ways to survive transaction pooling and NEITHER
 * works as written: PgBouncer prepared-statement tracking breaks transactions
 * (this), and PDO::ATTR_EMULATE_PREPARES breaks boolean binding (Phase 2).
 * The working configuration is neither — see infra/pgbouncer/pgbouncer.ini.
 *
 * This is the G8 class of bug. The original G8 tests missed it because they
 * exercised parameterised queries with no transaction, which is exactly the
 * combination that still worked.
 */
final class PgBouncerTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('media')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('media')->delete();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function mediaAttributes(string $seed): array
    {
        return [
            'disk' => 'media',
            'path' => "aa/bb/{$seed}/original.jpg",
            'mime' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'bytes' => 1024,
            'checksum_sha256' => hash('sha256', $seed),
            'alt_text' => 'Regression probe',
            'source' => 'manual',
        ];
    }

    /**
     * The exact failure: an Eloquent create inside a transaction.
     */
    public function test_an_eloquent_create_inside_a_transaction_succeeds(): void
    {
        $media = DB::transaction(
            fn (): Media => Media::create($this->mediaAttributes('transactional'))
        );

        $this->assertNotNull($media->id);
        $this->assertDatabaseHas('media', ['checksum_sha256' => hash('sha256', 'transactional')]);
    }

    public function test_a_transaction_survives_schema_introspection(): void
    {
        DB::transaction(function (): void {
            // This is what aborted the transaction: Eloquent introspects the
            // table to honour preventSilentlyDiscardingAttributes.
            $columns = DB::connection('pgsql')->getSchemaBuilder()->getColumns('media');

            $this->assertNotEmpty($columns);

            Media::create($this->mediaAttributes('introspection'));
        });

        $this->assertDatabaseHas('media', ['checksum_sha256' => hash('sha256', 'introspection')]);
    }

    public function test_multiple_writes_in_one_transaction_commit_together(): void
    {
        DB::transaction(function (): void {
            foreach (['multi-a', 'multi-b', 'multi-c'] as $seed) {
                Media::create($this->mediaAttributes($seed));
            }
        });

        $this->assertSame(3, Media::query()->count());
    }

    public function test_a_failed_transaction_rolls_back_completely(): void
    {
        try {
            DB::transaction(function (): void {
                Media::create($this->mediaAttributes('rollback-a'));

                // Duplicate checksum — the unique constraint rejects it.
                Media::create($this->mediaAttributes('rollback-a'));
            });
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame(0, Media::query()->count(), 'A failed transaction must leave nothing behind.');
    }

    /**
     * A quote submission writes and then reads within one request. This is the
     * behaviour `sticky` existed to guarantee; without a split it is simply
     * how a single connection behaves, and it must stay true when the replica
     * returns.
     */
    public function test_a_write_is_visible_to_a_later_read_in_the_same_request(): void
    {
        $uuid = (string) Str::uuid();

        DB::transaction(function () use ($uuid): void {
            Media::create($this->mediaAttributes('read-own-write') + ['uuid' => $uuid]);
        });

        $this->assertNotNull(Media::query()->where('uuid', $uuid)->first());
    }
}
