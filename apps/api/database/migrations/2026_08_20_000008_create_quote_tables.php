<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quote pipeline.
 *
 * Scope reflects three client decisions:
 *   D-003  no customer accounts — quoting is fully anonymous, no customers table
 *   D-004  the request form captures email, phone and an optional message only
 *   D-005  lead capture only — sales prices and responds internally, so there
 *          is no pricing UI, no priced PDF and no tokenised accept/reject flow
 *
 * What survives from section 7.2 is the basket, the request, an immutable
 * snapshot of what was requested, and an append-only event log.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('quote_baskets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            // The cookie holds a signed reference to this basket, never its
            // contents (MASTER BUILD PROMPT section 5.2). Stored hashed so a
            // database disclosure does not hand over live basket sessions.
            $table->string('session_token_hash', 64)->unique();

            $table->enum('status', ['active', 'submitted', 'expired'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::connection($this->connection)->create('quote_basket_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('basket_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();

            // Institutional buyers work from requisition lists, so a free-text
            // line is a first-class case ("120x pack A4 paper, brand flexible").
            // Capped and sanitised hard.
            $table->text('free_text_description')->nullable();

            $table->unsignedInteger('qty')->default(1);
            $table->text('customer_note')->nullable();
            $table->timestamps();

            $table->foreign('basket_id')->references('id')->on('quote_baskets')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->index('basket_id');
        });

        // A line must be either a catalogue reference or a free-text request,
        // never neither. Enforced in the database because the API is not the
        // only writer — the importer and admin can create lines too.
        DB::connection($this->connection)->statement("
            ALTER TABLE quote_basket_items
            ADD CONSTRAINT quote_basket_items_has_subject
            CHECK (product_id IS NOT NULL OR nullif(trim(free_text_description), '') IS NOT NULL)
        ");

        DB::connection($this->connection)->statement(
            'ALTER TABLE quote_basket_items ADD CONSTRAINT quote_basket_items_qty_positive CHECK (qty >= 1)'
        );

        Schema::connection($this->connection)->create('quotes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            // OL-Q-YYYY-NNNNNN, gapless per year.
            $table->string('reference', 20)->unique();

            // D-004: three fields. Name and company were recommended and not
            // adopted; without company, institutional enquiries cannot be
            // qualified or routed by customer type.
            $table->string('email');
            $table->string('phone', 32);
            $table->text('message')->nullable();

            // D-005: lead capture. Statuses describe sales follow-up, not a
            // pricing workflow.
            $table->enum('status', ['new', 'acknowledged', 'contacted', 'won', 'lost', 'spam'])
                ->default('new');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->enum('source', ['web', 'phone', 'walk_in', 'email'])->default('web');

            // Attribution. Section 14.10 wants quote conversion by landing page
            // as a merchandising input, which requires capturing it at submit.
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->text('referrer')->nullable();
            $table->text('landing_page')->nullable();

            // Anti-abuse forensics, not analytics. Retention is governed by the
            // NDPA schedule in the privacy policy (Phase 12).
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index('email');
        });

        // Gapless per-year reference numbering. A sequence is the only way to
        // get this without a race under concurrent submissions; the year reset
        // is handled by the application reading and restarting the sequence.
        DB::connection($this->connection)->statement('CREATE SEQUENCE IF NOT EXISTS quote_reference_seq START 1');

        // Immutable snapshot. Never a live join: the catalogue changes, and a
        // quote must continue to show what was actually requested
        // (MASTER BUILD PROMPT section 7.2).
        Schema::connection($this->connection)->create('quote_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id');

            // Retained for reporting only. Nulled if the product is deleted;
            // the snapshot below remains intact.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();

            $table->string('product_name_snapshot');
            $table->string('sku_snapshot')->nullable();
            $table->string('mpn_snapshot')->nullable();
            $table->string('brand_name_snapshot')->nullable();
            $table->text('spec_snapshot')->nullable();
            $table->text('product_url_snapshot')->nullable();
            $table->text('free_text_description')->nullable();

            $table->unsignedInteger('qty');
            $table->text('customer_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('quote_id')->references('id')->on('quotes')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index(['quote_id', 'sort_order']);
        });

        // Append-only audit. Every mutation of a quote writes an event: status
        // transitions, emails sent, admin notes (section 7.2). A dropped quote
        // email is a lost order, so "did we actually send it" must be provable.
        Schema::connection($this->connection)->create('quote_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id');
            $table->string('type', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->uuid('trace_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('quote_id')->references('id')->on('quotes')->cascadeOnDelete();
            $table->index(['quote_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('quote_events');
        Schema::connection($this->connection)->dropIfExists('quote_items');
        DB::connection($this->connection)->statement('DROP SEQUENCE IF EXISTS quote_reference_seq');
        Schema::connection($this->connection)->dropIfExists('quotes');
        Schema::connection($this->connection)->dropIfExists('quote_basket_items');
        Schema::connection($this->connection)->dropIfExists('quote_baskets');
    }
};
