<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * The concurrency half of the PgBouncer gate (D-024).
 *
 * ── Why this test exists ─────────────────────────────────────────────────
 * PgBouncerIntegrationTest and PgBouncerTransactionTest both pass while the
 * application is silently broken, because both drive ONE client connection at
 * a time. Transaction pooling only reassigns a server connection when another
 * client wants one, so a single-threaded suite never triggers the reassignment
 * that the whole configuration exists to survive.
 *
 * The bug this guards against was found by the Phase 7 prerender, where twenty
 * concurrent workers hit the API and 64 of 136 pages rendered "not found". The
 * API logged, on request after request:
 *
 *     ERROR: prepared statement "pdo_stmt_00000001" does not exist
 *
 * pdo_pgsql had issued Parse('pdo_stmt_00000001') on one server connection and
 * Bind/Execute on another. Nothing in the suite noticed for four phases.
 *
 * ── What actually reproduces it ──────────────────────────────────────────
 * Not concurrency. RE-EXECUTION across a server-connection reassignment.
 *
 * pdo_pgsql defers the parse until the first execute, so prepare() on its own
 * sends nothing to the server, and a statement executed exactly once cannot
 * fail this way — its parse, bind and execute all travel inside one implicit
 * transaction, for which PgBouncer pins a single server connection.
 *
 * The failure needs a statement that is parsed, released back to the pool,
 * handed to another client, and only then executed again. That is exactly the
 * shape of an Octane worker reusing a PDO connection across requests, and it
 * is the shape reproduced below: deterministically, in one process, with no
 * timing dependency.
 *
 * A test like this is worthless unless it fails when the fix is removed. Both
 * re-execution cases here were verified by setting
 * PGSQL_ATTR_DISABLE_PREPARES to false, whereupon they fail with the error
 * above. An earlier draft that executed each statement only once passed
 * against the broken configuration and was replaced.
 */
final class PgBouncerConcurrencyTest extends TestCase
{
    /** Comfortably above officeland_test's pool_size of 10. */
    private const CLIENTS = 16;

    /** @var list<PDO> */
    private array $connections = [];

    protected function tearDown(): void
    {
        $this->connections = [];

        parent::tearDown();
    }

    public function test_statements_survive_server_connection_reassignment(): void
    {
        $statements = [];

        // Phase 1: prepare AND execute once. The first execute is what forces
        // the parse onto whichever server connection is serving this client.
        // Preparing alone would send nothing and prove nothing.
        foreach (range(1, self::CLIENTS) as $client) {
            $connection = $this->connect();
            $this->connections[] = $connection;

            $statement = $connection->prepare('select :n::int as n');
            $statement->execute([':n' => $client]);
            $statement->fetchColumn();

            $statements[$client] = $statement;
        }

        // Phase 2: churn the pool. Every client above is now idle, so these
        // queries are served by the very connections that hold the parses.
        foreach (range(1, self::CLIENTS) as $ignored) {
            $churn = $this->connect();
            $churn->query('select 1')?->fetchColumn();
            $this->connections[] = $churn;
        }

        // Phase 3: re-execute. Each statement must survive having had its
        // server connection handed to somebody else in between.
        foreach ($statements as $client => $statement) {
            try {
                $statement->execute([':n' => $client]);
            } catch (PDOException $e) {
                $this->fail(
                    "Client {$client} could not re-execute a statement it prepared: {$e->getMessage()}. ".
                    'This is the transaction-pooling failure described in D-024. Check that '.
                    'PDO::PGSQL_ATTR_DISABLE_PREPARES is still true in config/database.php.',
                );
            }

            $this->assertSame(
                $client,
                (int) $statement->fetchColumn(),
                "Client {$client} got another client's result.",
            );
        }
    }

    /** The single-client form of the same failure, which is quicker to read. */
    public function test_a_statement_can_be_re_executed_after_other_clients_intervene(): void
    {
        $subject = $this->connect();
        $statement = $subject->prepare('select count(*) from categories where depth = :depth');

        $statement->execute([':depth' => 1]);
        $first = (int) $statement->fetchColumn();

        // Push the subject's server connection back into the pool and let
        // other clients churn through it.
        foreach (range(1, self::CLIENTS) as $ignored) {
            $other = $this->connect();
            $other->query('select 1')?->fetchColumn();
            $this->connections[] = $other;
        }

        $statement->execute([':depth' => 1]);

        $this->assertSame($first, (int) $statement->fetchColumn());
    }

    /**
     * Booleans are the reason EMULATE_PREPARES cannot be the fix, so the
     * boundary is asserted rather than left as a comment.
     *
     * Driven through the query builder rather than raw PDO on purpose. Laravel
     * casts a PHP boolean to an int in prepareBindings(), so the server sees
     * the text '1' and parses it as a boolean. Raw PDO binds an unconverted
     * false as the empty string, which fails for a reason that has nothing to
     * do with pooling and would make this a test of PDO, not of the app.
     */
    public function test_boolean_bindings_still_bind_as_booleans(): void
    {
        $indexable = DB::table('categories')->where('is_indexable', true)->count();
        $notIndexable = DB::table('categories')->where('is_indexable', false)->count();
        $total = DB::table('categories')->count();

        $this->assertSame($total, $indexable + $notIndexable);
    }

    private function connect(): PDO
    {
        $config = config('database.connections.pgsql');

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            is_array($config['write']['host']) ? $config['write']['host'][0] : $config['write']['host'],
            $config['port'],
            $config['database'],
        );

        // The application's own options, so the test cannot pass with a
        // configuration the application does not use.
        return new PDO($dsn, $config['username'], $config['password'], $config['options'] + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
