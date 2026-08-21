<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-year counters behind the `OL-Q-YYYY-NNNNNN` reference (§9f).
 *
 * ── Why a table and not a sequence ───────────────────────────────────────
 * §9f asks for a reference that is "gapless per year via sequence". Those two
 * requirements are in conflict: a PostgreSQL sequence is explicitly NOT
 * gapless. `nextval()` is non-transactional by design — that is what makes it
 * fast and lock-free — so a rolled-back or failed insert burns its number
 * permanently. Restarting a sequence per year also cannot be done atomically
 * alongside the insert that needs it.
 *
 * A counter row locked with `SELECT ... FOR UPDATE` inside the same
 * transaction as the insert IS gapless: the number is only consumed if the
 * quote is committed, and a rollback releases it for the next caller.
 *
 * ── What it costs ────────────────────────────────────────────────────────
 * Quote submissions serialise against each other for the duration of one
 * insert. At lead volume — tens per day, not thousands per second — that is
 * unmeasurable, and quote submission is not on the hot path of anything. It
 * would be the wrong trade for orders; it is the right one here.
 *
 * The year is the primary key, so the "restart each year" behaviour is a new
 * row rather than an operation someone has to remember to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_reference_counters', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();

            // Bigint rather than integer: the column is trivial to widen now
            // and painful to widen once it is in a unique reference someone
            // has printed on a purchase order.
            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_reference_counters');
    }
};
