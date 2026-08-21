<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The review trail behind a product description (§12b, SEO_SPEC §7).
 *
 * ── Why any of this is needed ────────────────────────────────────────────
 * D-010 makes all 15,000 products indexable at launch, and §14.7 asks for 120+
 * words of original copy on each. At that scale the copy will be generated from
 * attributes, and generated copy is exactly what §14.3 forbids shipping —
 * *never a boilerplate string with the name swapped in*. So the system needs to
 * know, per product, **who wrote the description and whether a human has looked
 * at it since**. Without that the difference between "written" and "generated
 * and never read" is invisible, and it is the whole difference between a page
 * worth indexing and 15,000 pages of spun boilerplate.
 *
 * ── Timestamps rather than a status enum ─────────────────────────────────
 * The rule is "a human approved it *after* the machine last touched it". An
 * enum cannot express the ordering, so regenerating a description would leave
 * it sitting on `approved` with nobody having read the new text. Comparing
 * timestamps makes re-generation and re-flagging invalidate a prior approval
 * automatically, which is the safe direction.
 *
 * `box_contents` is here because §14.7 lists an "in the box" list among the
 * product minimums and no column carried it. It is a distinct kind of fact —
 * not a filterable specification — so it is a column rather than an attribute.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->table('products', function (Blueprint $table): void {
            $table->text('box_contents')->nullable()->after('warranty_text');

            // Set by the drafter. Non-null means a machine wrote what is
            // currently in `description_html`.
            $table->timestamp('description_generated_at')->nullable();

            // Set by the similarity gate when this description is too close to
            // another in the same family (SEO_SPEC §7.3).
            $table->timestamp('description_flagged_at')->nullable();
            $table->decimal('description_similarity', 4, 3)->nullable();
            $table->unsignedBigInteger('description_similar_to_id')->nullable();

            // A human read it and put their name to it. Must be LATER than
            // both of the above for the product to be indexable.
            $table->timestamp('description_approved_at')->nullable();
            $table->unsignedBigInteger('description_approved_by')->nullable();

            $table->foreign('description_similar_to_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('description_approved_by')->references('id')->on('users')->nullOnDelete();

            // The review queue's working set: everything awaiting a human.
            $table->index(['description_approved_at', 'description_generated_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('products', function (Blueprint $table): void {
            $table->dropForeign(['description_similar_to_id']);
            $table->dropForeign(['description_approved_by']);
            $table->dropIndex(['description_approved_at', 'description_generated_at']);

            $table->dropColumn([
                'box_contents',
                'description_generated_at',
                'description_flagged_at',
                'description_similarity',
                'description_similar_to_id',
                'description_approved_at',
                'description_approved_by',
            ]);
        });
    }
};
