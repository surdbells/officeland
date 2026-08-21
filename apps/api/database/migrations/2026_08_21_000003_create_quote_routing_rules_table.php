<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-category quote notification routing (§11d).
 *
 * ── Why a table rather than a config key ─────────────────────────────────
 * Routing is a personnel decision, not an infrastructure one: it changes when
 * somebody joins, leaves or swaps desks. Config-file routing means every one
 * of those needs a developer and a deploy, and the predictable result is that
 * it stops being maintained and leads keep arriving at a former colleague's
 * inbox. A sales manager has to be able to change this themselves.
 *
 * The DEFAULT recipient deliberately stays in config (MAIL_SALES_ADDRESS). It
 * is the address the whole system falls back to, it changes about never, and
 * having two places that could define it is how it ends up defined twice and
 * differently.
 *
 * ── Keyed by category_id, not by path ────────────────────────────────────
 * The match itself is a path prefix (see QuoteRouting), but the RULE points at
 * a category row. Storing the path would silently stop matching the day
 * somebody moves or renames a category — and it would fail open, sending
 * furniture leads to the general inbox with no error anywhere. The FK follows
 * the move instead.
 *
 * Categories soft-delete, so the cascade below only fires on a hard delete or
 * a purge. A trashed category stops routing anyway, because QuoteRouting reads
 * the path through the relation and a trashed category resolves to null — the
 * rule stops matching and the lead falls back to the default inbox.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('quote_routing_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // One rule per category. Two rules for the same category would be
            // resolved by insertion order, which is not a decision anybody made.
            $table->unsignedBigInteger('category_id')->unique();

            $table->string('recipient');

            // Kept rather than deleted, so a rule can be paused over a holiday
            // and put back without retyping — and so the audit log shows the
            // pause rather than a disappearance.
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('quote_routing_rules');
    }
};
